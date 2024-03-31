#!sh
screen -d -m -S rpi_mainpage_php_server
screen -S rpi_mainpage_php_server -X stuff "php -S 0.0.0.0:80^M"