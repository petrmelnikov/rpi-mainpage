#!sh
screen -d -m
screen -S my_php_server -X stuff "php -S localhost:8000^M"