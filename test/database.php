<?php

define("DJJOB_DSN", "");
define("DJJOB_MYSQL_USERNAME", "");
define("DJJOB_MYSQL_PASSWORD", "");

require dirname(__FILE__) . "/../DJJob.php";

class HelloWorldJob {
    public function __construct($name) {
        $this->name = $name;
    }
    public function perform() {
        echo "Hello {$this->name}!\n";
        sleep(1);
    }
}

var_dump(DJJob::status("event_scrape"));

DJJob::enqueue(new HelloWorldJob("delayed_job"));
DJJob::bulkEnqueue(array(
    new HelloWorldJob("shopify"),
    new HelloWorldJob("github"),
));

$worker = new DJWorker(array("count" => 3));
$worker->start();

var_dump(DJJob::status("event_scrape"));
