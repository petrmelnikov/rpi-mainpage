<head>
    <meta name="referrer" content="no-referrer"/>
</head>
<!--
<iframe src=iframe.php style="border:none;height:30;width:110">
</iframe>
--!>
<a href="/media/">media</a>
<a href="//pi:8080">qbittorrent</a>
<a href="//gta.pi/">gta</a>

<pre>
    <?php
        echo shell_exec('df -h | grep \'Use\|usb_hdd\'');
        echo "\n";
        echo shell_exec('top -b -n 1 | head -20');
        echo "\n";
        echo shell_exec('/opt/vc/bin/vcgencmd measure_temp');
    ?>
</pre>