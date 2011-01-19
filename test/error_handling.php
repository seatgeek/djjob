<?php

require dirname(__FILE__) . "/../DJJob.php";

DJJob::configure("mysql:host=127.0.0.1;dbname=djjob_test", "root", "cdale150", "mike@seatgeek.com");

DJJob::runQuery("
DROP TABLE IF EXISTS `jobs`;
CREATE TABLE `jobs` (
`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
`handler` VARCHAR(2000) NOT NULL,
`queue` VARCHAR(255) NOT NULL DEFAULT 'default',
`run_at` DATETIME NULL,
`locked_at` DATETIME NULL,
`locked_by` VARCHAR(255) NULL,
`failed_at` DATETIME NULL,
`error` VARCHAR(2000) NULL,
`created_at` DATETIME NOT NULL
) ENGINE = MEMORY;
");

class IntentionalFailureJob {
    public function __construct() {}

    public function perform() {
        throw new Exception('Should email this');
    }
}

class FatalErrorJob {
    public function __construct() {}

    public function perform() {
        echo $missing; // intentional
    }
}

var_dump(DJJob::status());

DJJob::bulkEnqueue(array(
    new IntentionalFailureJob(),
    new FatalErrorJob()
));

$worker = new DJWorker(array("count" => 5));
$worker->start();

var_dump(DJJob::status());
