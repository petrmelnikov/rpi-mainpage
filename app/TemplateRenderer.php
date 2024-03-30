<?php

namespace App;

class TemplateRenderer
{
    public static function render(string $templatePath, array $params): string
    {
        ob_start();
        extract($params);
        require $templatePath;
        return ob_get_clean();
    }
}