FROM php:8.2-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends openssh-client git unzip wget aria2 ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Match typical host user ownership for bind-mounted paths (e.g. /media/usb_ssd).
RUN groupadd -g 1000 ubuntu || true \
    && useradd -m -u 1000 -g 1000 -s /bin/bash ubuntu || true \
    && sed -i 's/^user = .*/user = ubuntu/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^group = .*/group = ubuntu/' /usr/local/etc/php-fpm.d/www.conf

# Enforce pool runtime user/group explicitly (higher-priority include file).
RUN printf "[www]\nuser = ubuntu\ngroup = ubuntu\n" > /usr/local/etc/php-fpm.d/zz-ubuntu-user.conf

# Allow larger multipart chunks to reduce request overhead during uploads.
RUN printf "upload_max_filesize=512M\npost_max_size=512M\nmemory_limit=1024M\n" > /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /app

COPY docker/php/init-ssh-and-run.sh /usr/local/bin/init-ssh-and-run.sh
COPY docker/php/run-over-ssh.sh /usr/local/bin/run-over-ssh.sh
RUN chmod +x /usr/local/bin/init-ssh-and-run.sh /usr/local/bin/run-over-ssh.sh
