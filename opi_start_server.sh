#!sh
screen -d -m -S rpi_mainpage_php_server
screen -S rpi_mainpage_php_server -X stuff "sudo php -d post_max_size=512M -d upload_max_filesize=512M -d memory_limit=1024M -S 0.0.0.0:80^M"
