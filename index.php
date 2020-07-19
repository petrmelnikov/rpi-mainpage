<?php

//require_once __DIR__ . '/vendor/autoload.php';

$action = !empty($_GET['action']) ? $_GET['action'] : '';

function buildContentRaw($command) {
    return
        '<br>' .
        '<pre>' .
        shell_exec($command) .
        '</pre>' .
        '<br>';
}

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
            buildContentRaw('df -h | grep \'Use\|usb_hdd\' 2>&1'),
            buildContentRaw('top -b -n 1 2>&1 | head -20 2>&1'),
            buildContentRaw('/opt/vc/bin/vcgencmd measure_temp 2>&1'),
        ];
        break;
}

require_once 'web/main.html.php';
