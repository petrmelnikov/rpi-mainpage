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
            /* Double-tap seek zones */
            .video-container {
                position: relative;
            }
            .plyr {
                position: relative;
            }
            .seek-zone {
                position: absolute;
                top: 0;
                bottom: 80px; /* Above controls */
                width: 35%;
                z-index: 2000; /* High z-index to ensure it's above everything */
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                pointer-events: auto;
                background: rgba(0,0,0,0); /* Transparent background to ensure click capture */
            }
            .seek-zone-left {
                left: 0;
                border-radius: 0 50% 50% 0;
            }
            .seek-zone-right {
                right: 0;
                border-radius: 50% 0 0 50%;
            }
            .seek-indicator {
                display: none;
                flex-direction: column;
                align-items: center;
                color: white;
                text-shadow: 0 0 10px rgba(0,0,0,0.8);
                animation: seekPulse 0.3s ease-out;
                pointer-events: none;
            }
            .seek-indicator.show {
                display: flex;
            }
            .seek-indicator svg {
                width: 40px;
                height: 40px;
                fill: white;
            }
            .seek-indicator span {
                font-size: 14px;
                font-weight: bold;
                margin-top: 5px;
            }
            @keyframes seekPulse {
                0% { transform: scale(0.8); opacity: 0; }
                50% { transform: scale(1.2); opacity: 1; }
                100% { transform: scale(1); opacity: 1; }
            }
            .seek-ripple {
                position: absolute;
                border-radius: 50%;
                background: rgba(255,255,255,0.3);
                transform: scale(0);
                animation: ripple 0.4s ease-out;
                pointer-events: none;
            }
            @keyframes ripple {
                to { transform: scale(4); opacity: 0; }
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