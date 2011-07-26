<?php

require dirname(__FILE__) . "/../DJJob.php";

$mysql_jobs_table = "jobs";

DJJob::configure("mysql:host=localhost;dbname=djjob", array(
  "mysql_user" => "root",
  "mysql_pass" => "",
  "mysql_jobs_table" => $mysql_jobs_table,
));

DJJob::runQuery("
DROP TABLE IF EXISTS `" . $mysql_jobs_table . "`;
CREATE TABLE `" . $mysql_jobs_table . "` (
`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
`handler` VARCHAR(255) NOT NULL,
`queue` VARCHAR(255) NOT NULL DEFAULT 'default',
`attempts` INT UNSIGNED NOT NULL DEFAULT 0,
`run_at` DATETIME NULL,
`locked_at` DATETIME NULL,
`locked_by` VARCHAR(255) NULL,
`failed_at` DATETIME NULL,
`error` VARCHAR(255) NULL,
`created_at` DATETIME NOT NULL
) ENGINE = MEMORY;
");

class HelloWorldJob {
    public function __construct($name) {
        $this->name = $name;
    }
    public function perform() {
        echo "Hello {$this->name}!\n";
        sleep(1);
    }
}

class FailingJob {
    public function perform() {
        sleep(1);
        throw new Exception("Uh oh");
    }
}

var_dump(DJJob::status());

DJJob::enqueue(new HelloWorldJob("delayed_job"));
DJJob::bulkEnqueue(array(
    new HelloWorldJob("shopify"),
    new HelloWorldJob("github"),
));
DJJob::enqueue(new FailingJob());

$worker = new DJWorker(array("count" => 2, "max_attempts" => 2, "sleep" => 10));
$worker->start();

var_dump(DJJob::status());
