#!sh
php \
	-d post_max_size=512M \
	-d upload_max_filesize=512M \
	-d memory_limit=1024M \
	-S localhost:8000
