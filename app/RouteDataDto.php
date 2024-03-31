<?php

namespace App;

class RouteDataDto
{
    public string $method;
    public string $templatePath;
    public $handler;
    public array $params;
}