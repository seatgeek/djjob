<?php

# This system is mostly a port of delayed_job: http://github.com/tobi/delayed_job

class DJException extends Exception { }

class DJRetryException extends DJException {

    private $delay_seconds = 7200;

    public function setDelay($delay) {
        $this->delay_seconds = $delay;
    }
    public function getDelay() {
        return $this->delay_seconds;
    }
}

class DJBase {

    // error severity levels
    const CRITICAL = 4;
    const    ERROR = 3;
    const     WARN = 2;
    const     INFO = 1;
    const    DEBUG = 0;

    private static $log_level = self::DEBUG;

    private static $db = null;
    protected static $jobsTable = "";

    private static $dsn = "";
    private static $user = "";
    private static $password = "";
    private static $retries = 3; //default retries

    // use either `configure` or `setConnection`, depending on if
    // you already have a PDO object you can re-use

    public static function configure(){
        $args = func_get_args();
        $numArgs = func_num_args();

        switch ($numArgs) {
            case 1:{
                if (is_array($args[0])){
                    self::configureWithOptions($args[0]);
                } else {
                    self::configureWithDsnAndOptions($args[0]);
                }
                break;
            }
            case 2:{
                if (is_array($args[0])){
                    self::configureWithOptions($args[0], $args[1]);
                } else {
                    self::configureWithDsnAndOptions($args[0], $args[1]);
                }
                break;
            }
            case 3: {
                self::configureWithDsnAndOptions($args[0], $args[1], $args[2]);
                break;
            }
        }
    }

    protected static function configureWithDsnAndOptions($dsn, array $options = array(), $jobsTable = 'jobs') {
        if (!isset($options['mysql_user'])){
            throw new DJException("Please provide the database user in configure options array.");
        }
        if (!isset($options['mysql_pass'])){
            throw new DJException("Please provide the database password in configure options array.");
        }

        self::$dsn = $dsn;
        self::$jobsTable = $jobsTable;

        self::$user = $options['mysql_user'];
        self::$password = $options['mysql_pass'];

        // searches for retries
        if (isset($options['retries'])){
            self::$retries = (int) $options['retries'];
        }
    }

    protected static function configureWithOptions(array $options, $jobsTable = 'jobs') {

        if (!isset($options['driver'])){
            throw new DJException("Please provide the database driver used in configure options array.");
        }
        if (!isset($options['user'])){
            throw new DJException("Please provide the database user in configure options array.");
        }
        if (!isset($options['password'])){
            throw new DJException("Please provide the database password in configure options array.");
        }

        self::$user = $options['user'];
        self::$password = $options['password'];
        self::$jobsTable = $jobsTable;

        self::$dsn = $options['driver'] . ':';
        foreach ($options as $key => $value) {
            // skips options already used
            if ($key == 'driver' || $key == 'user' || $key == 'password') {
                continue;
            }

            // searches for retries
            if ($key == 'retries'){
                self::$retries = (int) $value;
                continue;
            }

            self::$dsn .= $key . '=' . $value . ';';
        }
    }

    public static function setLogLevel($const) {
        self::$log_level = $const;
    }

    public static function setConnection(PDO $db) {
        self::$db = $db;
    }

    protected static function getConnection() {
        if (self::$db === null) {
            try {
                self::$db = new PDO(self::$dsn, self::$user, self::$password);
                self::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (PDOException $e) {
                throw new Exception("DJJob couldn't connect to the database. PDO said [{$e->getMessage()}]");
            }
        }
        return self::$db;
    }

    public static function runQuery($sql, $params = array()) {
        for ($attempts = 0; $attempts < self::$retries; $attempts++) {
            try {
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
            catch (PDOException $e) {
                // Catch "MySQL server has gone away" error.
                if ($e->errorInfo[1] == 2006) {
                    self::$db = null;
                }
                // Throw all other errors as expected.
                else {
                    throw $e;
                }
            }
        }

        throw new DJException("DJJob exhausted retries connecting to database");
    }

    public static function runUpdate($sql, $params = array()) {
        for ($attempts = 0; $attempts < self::$retries; $attempts++) {
            try {
                $stmt = self::getConnection()->prepare($sql);
                $stmt->execute($params);
                return $stmt->rowCount();
            }
            catch (PDOException $e) {
                // Catch "MySQL server has gone away" error.
                if ($e->errorInfo[1] == 2006) {
                    self::$db = null;
                }
                // Throw all other errors as expected.
                else {
                    throw $e;
                }
            }
        }

        throw new DJException("DJJob exhausted retries connecting to database");
    }

    protected static function log($mesg, $severity=self::CRITICAL) {
        if ($severity >= self::$log_level) {
            printf("[%s] %s\n", date('c'), $mesg);
        }
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
            "max_attempts" => 5,
            "fail_on_output" => false
        ), $options);
        list($this->queue, $this->count, $this->sleep, $this->max_attempts, $this->fail_on_output) =
            array($options["queue"], $options["count"], $options["sleep"], $options["max_attempts"], $options["fail_on_output"]);

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

        $this->log("[WORKER] Received received {$signal}... Shutting down", self::INFO);
        $this->releaseLocks();
        die(0);
    }

    public function releaseLocks() {
        $this->runUpdate("
            UPDATE " . self::$jobsTable . "
            SET locked_at = NULL, locked_by = NULL
            WHERE locked_by = ?",
            array($this->name)
        );
    }

    /**
     * Returns a new job ordered by most recent first
     * why this?
     *     run newest first, some jobs get left behind
     *     run oldest first, all jobs get left behind
     * @return DJJob
     */
    public function getNewJob() {
        # we can grab a locked job if we own the lock
        $rs = $this->runQuery("
            SELECT id
            FROM   " . self::$jobsTable . "
            WHERE  queue = ?
            AND    (run_at IS NULL OR NOW() >= run_at)
            AND    (locked_at IS NULL OR locked_by = ?)
            AND    failed_at IS NULL
            AND    attempts < ?
            ORDER BY created_at DESC
            LIMIT  10
        ", array($this->queue, $this->name, $this->max_attempts));

        // randomly order the 10 to prevent lock contention among workers
        shuffle($rs);

        foreach ($rs as $r) {
            $job = new DJJob($this->name, $r["id"], array(
                "max_attempts" => $this->max_attempts,
                "fail_on_output" => $this->fail_on_output
            ));
            if ($job->acquireLock()) return $job;
        }

        return false;
    }

    public function start() {
        $this->log("[JOB] Starting worker {$this->name} on queue::{$this->queue}", self::INFO);

        $count = 0;
        $job_count = 0;
        try {
            while ($this->count == 0 || $count < $this->count) {
                if (function_exists("pcntl_signal_dispatch")) pcntl_signal_dispatch();

                $count += 1;
                $job = $this->getNewJob($this->queue);

                if (!$job) {
                    $this->log("[JOB] Failed to get a job, queue::{$this->queue} may be empty", self::DEBUG);
                    sleep($this->sleep);
                    continue;
                }

                $job_count += 1;
                $job->run();
            }
        } catch (Exception $e) {
            $this->log("[JOB] unhandled exception::\"{$e->getMessage()}\"", self::ERROR);
        }

        $this->log("[JOB] worker shutting down after running {$job_count} jobs, over {$count} polling iterations", self::INFO);
    }
}

class DJJob extends DJBase {

    public function __construct($worker_name, $job_id, $options = array()) {
        $options = array_merge(array(
            "max_attempts" => 5,
            "fail_on_output" => false
        ), $options);
        $this->worker_name = $worker_name;
        $this->job_id = $job_id;
        $this->max_attempts = $options["max_attempts"];
        $this->fail_on_output = $options["fail_on_output"];
    }

    public function run() {
        # pull the handler from the db
        $handler = $this->getHandler();
        if (!is_object($handler)) {
            $msg = "[JOB] bad handler for job::{$this->job_id}";
            $this->finishWithError($msg);
            return false;
        }

        # run the handler
        try {

            if ($this->fail_on_output) {
                ob_start();                
            }

            $handler->perform();

            if ($this->fail_on_output) {
                $output = ob_get_contents();
                ob_end_clean();

                if (!empty($output)) {
                    throw new Exception("Job produced unexpected output: $output");
                }
            }

            # cleanup
            $this->finish();
            return true;

        } catch (DJRetryException $e) {
            # attempts hasn't been incremented yet.
            $attempts = $this->getAttempts()+1;

            $msg = "Caught DJRetryException \"{$e->getMessage()}\" on attempt $attempts/{$this->max_attempts}.";

            if($attempts == $this->max_attempts) {
                $msg = "[JOB] job::{$this->job_id} $msg Giving up.";
                $this->finishWithError($msg, $handler);
            } else {
                $this->log("[JOB] job::{$this->job_id} $msg Try again in {$e->getDelay()} seconds.", self::WARN);
                $this->retryLater($e->getDelay());
            }
            return false;

        } catch (Exception $e) {

            $this->finishWithError($e->getMessage(), $handler);
            return false;

        }
    }

    public function acquireLock() {
        $this->log("[JOB] attempting to acquire lock for job::{$this->job_id} on {$this->worker_name}", self::INFO);

        $lock = $this->runUpdate("
            UPDATE " . self::$jobsTable . "
            SET    locked_at = NOW(), locked_by = ?
            WHERE  id = ? AND (locked_at IS NULL OR locked_by = ?) AND failed_at IS NULL
        ", array($this->worker_name, $this->job_id, $this->worker_name));

        if (!$lock) {
            $this->log("[JOB] failed to acquire lock for job::{$this->job_id}", self::INFO);
            return false;
        }

        return true;
    }

    public function releaseLock() {
        $this->runUpdate("
            UPDATE " . self::$jobsTable . "
            SET locked_at = NULL, locked_by = NULL
            WHERE id = ?",
            array($this->job_id)
        );
    }

    public function finish() {
        $this->runUpdate(
            "DELETE FROM " . self::$jobsTable . " WHERE id = ?",
            array($this->job_id)
        );
        $this->log("[JOB] completed job::{$this->job_id}", self::INFO);
    }

    public function finishWithError($error, $handler = null) {
        $this->runUpdate("
            UPDATE " . self::$jobsTable . "
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
        $this->log($error, self::ERROR);
        $this->log("[JOB] failure in job::{$this->job_id}", self::ERROR);
        $this->releaseLock();

        if ($handler && ($this->getAttempts() == $this->max_attempts) && method_exists($handler, '_onDjjobRetryError')) {
          $handler->_onDjjobRetryError($error);
        }
    }

    public function retryLater($delay) {
        $this->runUpdate("
            UPDATE " . self::$jobsTable . "
            SET run_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                attempts = attempts + 1
            WHERE id = ?",
            array(
              $delay,
              $this->job_id
            )
        );
        $this->releaseLock();
    }

    public function getHandler() {
        $rs = $this->runQuery(
            "SELECT handler FROM " . self::$jobsTable . " WHERE id = ?",
            array($this->job_id)
        );
        foreach ($rs as $r) return unserialize($r["handler"]);
        return false;
    }

    public function getAttempts() {
        $rs = $this->runQuery(
            "SELECT attempts FROM " . self::$jobsTable . " WHERE id = ?",
            array($this->job_id)
        );
        foreach ($rs as $r) return $r["attempts"];
        return false;
    }

    public static function enqueue($handler, $queue = "default", $run_at = null) {
        $affected = self::runUpdate(
            "INSERT INTO " . self::$jobsTable . " (handler, queue, run_at, created_at) VALUES(?, ?, ?, NOW())",
            array(serialize($handler), (string) $queue, $run_at)
        );

        if ($affected < 1) {
            self::log("[JOB] failed to enqueue new job", self::ERROR);
            return false;
        }

        return self::getConnection()->lastInsertId(); // return the job ID, for manipulation later
    }

    public static function bulkEnqueue($handlers, $queue = "default", $run_at = null) {
        $sql = "INSERT INTO " . self::$jobsTable . " (handler, queue, run_at, created_at) VALUES";
        $sql .= implode(",", array_fill(0, count($handlers), "(?, ?, ?, NOW())"));

        $parameters = array();
        foreach ($handlers as $handler) {
            $parameters []= serialize($handler);
            $parameters []= (string) $queue;
            $parameters []= $run_at;
        }
        $affected = self::runUpdate($sql, $parameters);

        if ($affected < 1) {
            self::log("[JOB] failed to enqueue new jobs", self::ERROR);
            return false;
        }

        if ($affected != count($handlers))
            self::log("[JOB] failed to enqueue some new jobs", self::ERROR);

        return true;
    }

    public static function status($queue = "default") {
        $rs = self::runQuery("
            SELECT COUNT(*) as total, COUNT(failed_at) as failed, COUNT(locked_at) as locked
            FROM `" . self::$jobsTable . "`
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
