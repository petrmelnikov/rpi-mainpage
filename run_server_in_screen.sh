#!sh
screen -d -m
screen -S rpi_mainpage_php_server -X stuff "php -S localhost:8000^M"