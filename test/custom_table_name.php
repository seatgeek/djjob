<?php

function assert_handler($file, $line, $code, $desc = null) {
    printf("Assertion failed at %s:%s: %s: %s\n", $file, $line, $code, $desc);
}

assert_options(ASSERT_ACTIVE, 1);
assert_options(ASSERT_WARNING, 0);
assert_options(ASSERT_QUIET_EVAL, 1);
assert_options(ASSERT_CALLBACK, 'assert_handler');

date_default_timezone_set('America/New_York');

require dirname(__FILE__) . "/../DJJob.php";

DJJob::configure([
    'driver'   => 'mysql',
    'host'     => '127.0.0.1',
    'dbname'   => 'djjob',
    'user'     => 'root',
    'password' => 'root',
], 'my_jobs');

DJJob::runQuery("
DROP TABLE IF EXISTS `my_jobs`;
CREATE TABLE `my_jobs` (
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

$status = DJJob::status();

assert('$status["outstanding"] == 0', "Initial outstanding status is incorrect");
assert('$status["locked"] == 0', "Initial locked status is incorrect");
assert('$status["failed"] == 0', "Initial failed status is incorrect");
assert('$status["total"] == 0', "Initial total status is incorrect");

printf("=====================\nStarting run of DJJob\n=====================\n\n");

DJJob::enqueue(new HelloWorldJob("delayed_job"));
DJJob::bulkEnqueue(array(
    new HelloWorldJob("shopify"),
    new HelloWorldJob("github"),
));
DJJob::enqueue(new FailingJob());

$worker = new DJWorker(array("count" => 5, "max_attempts" => 2, "sleep" => 10));
$worker->start();
printf("\n============\nRun complete\n============\n\n");

$status = DJJob::status();

assert('$status["outstanding"] == 0', "Final outstanding status is incorrect");
assert('$status["locked"] == 0', "Final locked status is incorrect");
assert('$status["failed"] == 1', "Final failed status is incorrect");
assert('$status["total"] == 1', "Final total status is incorrect");
