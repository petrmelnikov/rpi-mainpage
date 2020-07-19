<head>
    <meta name="referrer" content="no-referrer"/>
<!--    <script src="/vendor/components/jquery/jquery.min.js"></script>-->
<!--    <script src="/vendor/twbs/bootstrap/dist/js/bootstrap.bundle.js"></script>-->
    <link rel="stylesheet" href="/vendor/twbs/bootstrap/dist/css/bootstrap.css">
</head>
<!--
<iframe src=iframe.php style="border:none;height:30;width:110">
</iframe>
--!>
<body>
    <div class="container">
        <div class="row">
            <div class="col-sm">
                <a class="btn btn-primary" href="/">index</a>
                <a class="btn btn-primary" href="/media/">media</a>
                <a class="btn btn-primary" href="//pi.lan:8080">qbittorrent</a>
                <a class="btn btn-primary" href="//gta.pi.lan/">gta</a>
                <a class="btn btn-primary" href="//router.lan/">router</a>
            </div>
        </div>
        <div class="row">
            <div class="col-sm">
                    <?php
                    if (is_array($content)) {
                        ?>
                        <table class="table">
                        <?php
                        foreach ($content as $row) {
                            ?>
                            <tr>
                                <td><?= $row ?></td>
                            </tr>
                            <?php
                        }
                        ?>
                        </table>
                        <?php
                    } else {
                        echo $content;
                    }
                    ?>
            </div>
        </div>
        <div class="row">
            <div class="col-sm">
                <a class="btn btn-primary" href="/?action=git-pull">git pull</a>
                <a class="btn btn-primary" href="/?action=wake-up-my-pc">wake up my pc</a>
            </div>
        </div>
    </div>

</body>
