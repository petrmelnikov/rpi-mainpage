<?php

namespace App;

class App
{
    public string $appRoot;

    private function __construct(){}

    static public function getInstance(): App
    {
        static $instance = null;
        if ($instance === null) {
            $instance = new App();
        }
        return $instance;
    }
}