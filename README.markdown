DJJob
=====

DJJob allows PHP web applications to process long-running tasks asynchronously. It is a PHP port of [delayed_job](http://github.com/tobi/delayed_job) (developed at Shopify), which has been used in production at SeatGeek since April 2010.

Like delayed_job, DJJob uses a `jobs` table for persisting and tracking pending, in-progress, and failed jobs.

Requirements
------------

- PHP5
- PDO (Ships with PHP >= 5.1)
- (Optional) PCNTL library

Setup
-----

Import the sql database table.

```
mysql db < jobs.sql
```

The `jobs` table structure looks like:

```sql
CREATE TABLE `jobs` (
`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
`handler` TEXT NOT NULL,
`queue` VARCHAR(255) NOT NULL DEFAULT 'default',
`attempts` INT UNSIGNED NOT NULL DEFAULT 0,
`run_at` DATETIME NULL,
`locked_at` DATETIME NULL,
`locked_by` VARCHAR(255) NULL,
`failed_at` DATETIME NULL,
`error` TEXT NULL,
`created_at` DATETIME NOT NULL
) ENGINE = INNODB;
```

> You may need to use BLOB as the column type for `handler` if you are passing in serialized blobs of data instead of record ids. For more information, see [this link](https://php.net/manual/en/function.serialize.php#refsect1-function.serialize-returnvalues) This may be the case for errors such as the following: `unserialize(): Error at offset 2010 of 2425 bytes`

Tell DJJob how to connect to your database:

```php
DJJob::configure([
    'driver' => 'mysql',
    'host' => '127.0.0.1',
    'dbname' => 'djjob',
    'user' => 'root',
    'password' => 'topsecret',
]);
```


Usage
-----

Jobs are PHP objects that respond to a method `perform`. Jobs are serialized and stored in the database.

```php
<?php
// Job class
class HelloWorldJob {
    public function __construct($name) {
        $this->name = $name;
    }
    public function perform() {
        echo "Hello {$this->name}!\n";
    }
}

// enqueue a new job
DJJob::enqueue(new HelloWorldJob("delayed_job"));
```

Unlike delayed_job, DJJob does not have the concept of task priority (not yet at least). Instead, it supports multiple queues. By default, jobs are placed on the "default" queue. You can specifiy an alternative queue like:

```php
DJJob::enqueue(new SignupEmailJob("dev@seatgeek.com"), "email");
```

At SeatGeek, we run an email-specific queue. Emails have a `sendLater` method which places a job on the `email` queue. Here's a simplified version of our base `Email` class:

```php
class Email {
    public function __construct($recipient) {
        $this->recipient = $recipient;
    }
    public function send() {
        // do some expensive work to build the email: geolocation, etc..
        // use mail api to send this email
    }
    public function perform() {
        $this->send();
    }
    public function sendLater() {
        DJJob::enqueue($this, "email");
    }
}
```

Because `Email` has a `perform` method, all instances of the email class are also jobs.

Running the jobs
----------------

Running a worker is as simple as:

```php
$worker = new DJWorker($options);
$worker->start();
```

Initializing your environment, connecting to the database, etc. is up to you. We use symfony's task system to run workers, here's an example of our jobs:worker task:

```php
<?php
class jobsWorkerTask extends sfPropelBaseTask {
  protected function configure() {
    $this->namespace        = 'jobs';
    $this->name             = 'worker';
    $this->briefDescription = '';
    $this->detailedDescription = <<<EOF
The [jobs:worker|INFO] task runs jobs created by the DJJob system.
Call it with:

  [php symfony jobs:worker|INFO]
EOF;
    $this->addArgument('application', sfCommandArgument::OPTIONAL, 'The application name', 'customer');
    $this->addOption('env', null, sfCommandOption::PARAMETER_REQUIRED, 'The environment', 'dev');
    $this->addOption('connection', null, sfCommandOption::PARAMETER_REQUIRED, 'The connection name', 'propel');
    $this->addOption('queue', null, sfCommandOption::PARAMETER_REQUIRED, 'The queue to pull jobs from', 'default');
    $this->addOption('count', null, sfCommandOption::PARAMETER_REQUIRED, 'The number of jobs to run before exiting (0 for unlimited)', 0);
    $this->addOption('sleep', null, sfCommandOption::PARAMETER_REQUIRED, 'Seconds to sleep after finding no new jobs', 5);
}

  protected function execute($arguments = array(), $options = array()) {
    // Database initialization
    $databaseManager = new sfDatabaseManager($this->configuration);
    $connection = Propel::getConnection($options['connection'] ? $options['connection'] : '');

    $worker = new DJWorker($options);
    $worker->start();
  }
}
```

The worker will exit if the database has any connectivity problems. We use [god](http://god.rubyforge.org/) to manage our workers, including restarting them when they exit for any reason.

Changes
-------

- Eliminated Propel dependency by switching to PDO
