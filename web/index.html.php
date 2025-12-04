<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="referrer" content="no-referrer"/>
        <title>opi</title>
        <link rel="stylesheet" href="/vendor/twbs/bootstrap/dist/css/bootstrap.css">
        <!-- Plyr Video Player -->
        <link rel="stylesheet" href="https://cdn.plyr.io/3.7.8/plyr.css" />
        <style>
            .video-modal .modal-dialog {
                max-width: 90vw;
            }
            .video-modal .modal-body {
                padding: 0;
                background: #000;
            }
            .video-modal .plyr {
                --plyr-color-main: #0d6efd;
            }
            .btn-play-video {
                cursor: pointer;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <?php
                /** @var string $body */
                echo $body
            ?>
        </div>
        <script src="/vendor/twbs/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
        <script src="https://cdn.plyr.io/3.7.8/plyr.polyfilled.js"></script>
    </body>
</html>