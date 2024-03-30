#!sh
screen -d -m -S rpi_mainpage_php_server
screen -S rpi_mainpage_php_server -X stuff "php -S localhost:8111^M"