<?php

class HelloWorldJob {

    public function __construct($name) {
        $this->name = $name;
    }

    public function perform() {
        echo "Hello {$this->name}!\n";
    }

}
