<?php

//require_once __DIR__ . '/vendor/autoload.php';

$action = !empty($_GET['action']) ? $_GET['action'] : '';
switch ($action) {
    case 'git-pull':
        $content = shell_exec('git pull 2>&1');
        break;
    case 'wake-up-my-pc':
        $content = shell_exec('etherwake 00:D8:61:9F:EF:C9 2>&1');
        break;
    case 'index':
    default:
        $content = [
            '<pre>' .
            shell_exec('df -h | grep \'Use\|usb_hdd\'') .
            '</pre>',
            "\n" .
            '<pre>' .
            shell_exec('top -b -n 1 | head -20') .
            '</pre>',
            "\n" .
            '<pre>' .
            shell_exec('/opt/vc/bin/vcgencmd measure_temp') .
            '</pre>'
        ];
        break;
}

require_once 'web/main.html.php';
