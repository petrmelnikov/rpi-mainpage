<head>
    <meta name="referrer" content="no-referrer"/>
</head>
<!--
<iframe src=iframe.php style="border:none;height:30;width:110">
</iframe>
--!>
<a href="/media/">media</a>
<a href="//pi.lan:8080">qbittorrent</a>
<a href="//gta.pi.lan/">gta</a>
<br>
<?php

$action = !empty($_GET['action']) ? $_GET['action'] : '';
switch ($action) {
    case 'git-pull':
        echo shell_exec('git pull 2>&1');
        break;
    case 'wake-up-my-pc':
        echo shell_exec('etherwake 00:D8:61:9F:EF:C9 2>&1');
        break;
    case 'index':
    default:
        echo '<pre>';
        echo shell_exec('df -h | grep \'Use\|usb_hdd\'');
        echo "\n";
        echo shell_exec('top -b -n 1 | head -20');
        echo "\n";
        echo shell_exec('/opt/vc/bin/vcgencmd measure_temp');
        echo '</pre>';
        break;
}

?>
<br>
<a href="/">index</a>
<a href="/?action=git-pull">git pull</a>
<a href="/?action=wake-up-my-pc">wake up my pc</a>

