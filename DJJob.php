<?php

# This system is mostly a port of delayed_job: http://github.com/tobi/delayed_job

class DJException extends Exception { }

class DJRetryException extends DJException { }

class DJBase {
    
    private static $db = null;
    
    private static $dsn = "";
    private static $options = array(
      "mysql_user" => null,
      "mysql_pass" => null,
    );
    
    private static $jobsTableName = 'jobs';
    
    // use either `configure` or `setConnection`, depending on if 
    // you already have a PDO object you can re-use
    public static function configure($dsn, $options = array()) {
        self::$dsn = $dsn;
        self::$options = array_merge(self::$options, $options);
    }

    public static function setConnection(PDO $db) {
        self::$db = $db;
    }
    
    protected static function getConnection() {
        if (self::$db === null) {
            if (!self::$dsn) {
                throw new DJException("Please tell DJJob how to connect to your database by calling DJJob::configure(\$dsn, [\$options = array()]) or re-using an existing PDO connection by calling DJJob::setConnection(\$pdoObject). If you're using MySQL you'll need to pass the db credentials as separate 'mysql_user' and 'mysql_pass' options. This is a PDO limitation, see [http://stackoverflow.com/questions/237367/why-is-php-pdo-dsn-a-different-format-for-mysql-versus-postgresql] for an explanation.");
            }
            try {
                // http://stackoverflow.com/questions/237367/why-is-php-pdo-dsn-a-different-format-for-mysql-versus-postgresql
                if (self::$options["mysql_user"] !== null) {
                    self::$db = new PDO(self::$dsn, self::$options["mysql_user"], self::$options["mysql_pass"]);
                } else {
                    self::$db = new PDO(self::$dsn);
                }
                self::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (PDOException $e) {
                throw new Exception("DJJob couldn't connect to the database. PDO said [{$e->getMessage()}]");
            }
        }
        return self::$db;
    }

    public static function setJobsTableName($jobsTableName) {
      self::$jobsTableName = $jobsTableName;
    }
    
    public static function getJobsTableName() {
      return self::$jobsTableName;
    }
    
    public static function runQuery($sql, $params = array()) {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        
        $ret = array();
        if ($stmt->rowCount()) {
            // calling fetchAll on a result set with no rows throws a
            // "general error" exception
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $ret []= $r;
        }
        
        $stmt->closeCursor();
        return $ret;
    }
    
    public static function runUpdate($sql, $params = array()) {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    
    protected static function log($mesg) {
        echo $mesg . "\n";
    }
}

class DJWorker extends DJBase {
    # This is a singleton-ish thing. It wouldn't really make sense to
    # instantiate more than one in a single request (or commandline task)
    
    public function __construct($options = array()) {
        $options = array_merge(array(
            "queue" => "default",
            "count" => 0,
            "sleep" => 5,
            "max_attempts" => 5
        ), $options);
        list($this->queue, $this->count, $this->sleep, $this->max_attempts, $this->jobs_table) =
          array($options["queue"], $options["count"], $options["sleep"], $options["max_attempts"], 
                self::getJobsTableName());

        list($hostname, $pid) = array(trim(`hostname`), getmypid());
        $this->name = "host::$hostname pid::$pid";

        if (function_exists("pcntl_signal")) {
            pcntl_signal(SIGTERM, array($this, "handleSignal"));
            pcntl_signal(SIGINT, array($this, "handleSignal"));
        }
    }
    
    public function handleSignal($signo) {
        $signals = array(
            SIGTERM => "SIGTERM",
            SIGINT  => "SIGINT"
        );
        $signal = $signals[$signo];
        
        $this->log("* [WORKER] Received received {$signal}... Shutting down");
        $this->releaseLocks();
        die(0);
    }
    
    public function releaseLocks() {
        $this->runUpdate("
            UPDATE " . $this->jobs_table . "
            SET locked_at = NULL, locked_by = NULL
            WHERE locked_by = ?",
            array($this->name)
        );
    }
    
    public function getNewJob() {
        # we can grab a locked job if we own the lock
        $rs = $this->runQuery("
            SELECT id
            FROM   " . $this->jobs_table . " 
            WHERE  queue = ?
            AND    (run_at IS NULL OR NOW() >= run_at)
            AND    (locked_at IS NULL OR locked_by = ?)
            AND    failed_at IS NULL
            AND    attempts < ?
            ORDER BY RAND()
            LIMIT  5
        ", array($this->queue, $this->name, $this->max_attempts));
        
        foreach ($rs as $r) {
            $job = new DJJob($this->name, $r["id"], array(
                "max_attempts" => $this->max_attempts
            ));
            if ($job->acquireLock()) return $job;
        }
        
        return false;
    }
    
    public function start() {
        $this->log("* [JOB] Starting worker {$this->name} on queue::{$this->queue}");
        
        $count = 0;
        $job_count = 0;
        try {
            while ($this->count == 0 || $count < $this->count) {
                if (function_exists("pcntl_signal_dispatch")) pcntl_signal_dispatch();

                $count += 1;
                $job = $this->getNewJob($this->queue);

                if (!$job) {
                    $this->log("* [JOB] Failed to get a job, queue::{$this->queue} may be empty");
                    sleep($this->sleep);
                    continue;
                }

                $job_count += 1;
                $job->run();
            }
        } catch (Exception $e) {
            $this->log("* [JOB] unhandled exception::\"{$e->getMessage()}\"");
        }

        $this->log("* [JOB] worker shutting down after running {$job_count} jobs, over {$count} polling iterations");
    }
}

class DJJob extends DJBase {
    
    public function __construct($worker_name, $job_id, $options = array()) {
        $options = array_merge(array(
            "max_attempts" => 5
        ), $options);
        $this->worker_name = $worker_name;
        $this->job_id = $job_id;
        $this->max_attempts = $options["max_attempts"];
    }
    
    public function run() {
        # pull the handler from the db
        $handler = $this->getHandler();
        if (!is_object($handler)) {
            $this->log("* [JOB] bad handler for job::{$this->job_id}");
            $this->finishWithError("bad handler for job::{$this->job_id}");
            return false;
        }
        
        # run the handler
        try {
            $handler->perform();
            
            # cleanup
            $this->finish();
            return true;
            
        } catch (DJRetryException $e) {
            
            # signal that this job should be retried later
            $this->retryLater();
            return false;
            
        } catch (Exception $e) {
            
            $this->finishWithError($e->getMessage());
            return false;
            
        }
    }
    
    public function acquireLock() {
        $this->log("* [JOB] attempting to acquire lock for job::{$this->job_id} on {$this->worker_name}");
        
        $lock = $this->runUpdate("
            UPDATE " . self::getJobsTableName() . " 
            SET    locked_at = NOW(), locked_by = ?
            WHERE  id = ? AND (locked_at IS NULL OR locked_by = ?) AND failed_at IS NULL
        ", array($this->worker_name, $this->job_id, $this->worker_name));
        
        if (!$lock) {
            $this->log("* [JOB] failed to acquire lock for job::{$this->job_id}");
            return false;
        }
        
        return true;
    }
    
    public function releaseLock() {
        $this->runUpdate("
            UPDATE " . self::getJobsTableName() . " 
            SET locked_at = NULL, locked_by = NULL
            WHERE id = ?",
            array($this->job_id)
        );
    }
    
    public function finish() {
        $this->runUpdate(
            "DELETE FROM " . self::getJobsTableName() . " WHERE id = ?", 
            array($this->job_id)
        );
        $this->log("* [JOB] completed job::{$this->job_id}");
    }
    
    public function finishWithError($error) {
        $this->runUpdate("
            UPDATE " . self::getJobsTableName() . "
            SET attempts = attempts + 1,
                failed_at = IF(attempts >= ?, NOW(), NULL),
                error = IF(attempts >= ?, ?, NULL)
            WHERE id = ?",
            array(
                $this->max_attempts,
                $this->max_attempts,
                $error,
                $this->job_id
            )
        );
        $this->log("* [JOB] failure in job::{$this->job_id}");
        $this->releaseLock();
    }
    
    public function retryLater() {
        $this->runUpdate("
            UPDATE " . self::getJobsTableName() . "
            SET run_at = DATE_ADD(NOW(), INTERVAL 2 HOUR),
                attempts = attempts + 1
            WHERE id = ?",
            array($this->job_id)
        );
        $this->releaseLock();
    }
    
    public function getHandler() {
        $rs = $this->runQuery(
            "SELECT handler FROM " . self::getJobsTableName() . " WHERE id = ?", 
            array($this->job_id)
        );
        foreach ($rs as $r) return unserialize($r["handler"]);
        return false;
    }
    
    public static function enqueue($handler, $queue = "default", $run_at = null) {
        $affected = self::runUpdate(
            "INSERT INTO " . self::getJobsTableName() . " (handler, queue, run_at, created_at) VALUES(?, ?, ?, NOW())",
            array(serialize($handler), (string) $queue, $run_at)
        );
        
        if ($affected < 1) {
            self::log("* [JOB] failed to enqueue new job");
            return false;
        }
        
        return true;
    }
    
    public static function bulkEnqueue($handlers, $queue = "default", $run_at = null) {
        $sql = "INSERT INTO " . self::getJobsTableName() . " (handler, queue, run_at, created_at) VALUES";
        $sql .= implode(",", array_fill(0, count($handlers), "(?, ?, ?, NOW())"));
        
        $parameters = array();
        foreach ($handlers as $handler) {
            $parameters []= serialize($handler);
            $parameters []= (string) $queue;
            $parameters []= $run_at;
        }
        $affected = self::runUpdate($sql, $parameters);
        
        if ($affected < 1) {
            self::log("* [JOB] failed to enqueue new jobs");
            return false;
        }
        
        if ($affected != count($handlers))
            self::log("* [JOB] failed to enqueue some new jobs");
        
        return true;
    }
    
    public static function status($queue = "default") {
        $rs = self::runQuery("
            SELECT COUNT(*) as total, COUNT(failed_at) as failed, COUNT(locked_at) as locked
            FROM " . self::getJobsTableName() . "
            WHERE queue = ?
        ", array($queue));
        $rs = $rs[0];
        
        $failed = $rs["failed"];
        $locked = $rs["locked"];
        $total  = $rs["total"];
        $outstanding = $total - $locked - $failed;
        
        return array(
            "outstanding" => $outstanding,
            "locked" => $locked,
            "failed" => $failed,
            "total"  => $total
        );
    }

}
