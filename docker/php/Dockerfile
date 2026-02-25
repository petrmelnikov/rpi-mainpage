FROM php:8.2-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends openssh-client git unzip wget aria2 ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Match typical host user ownership for bind-mounted paths (e.g. /media/usb_ssd).
RUN groupadd -g 1000 ubuntu || true \
    && useradd -m -u 1000 -g 1000 -s /bin/bash ubuntu || true \
    && sed -i 's/^user = .*/user = ubuntu/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^group = .*/group = ubuntu/' /usr/local/etc/php-fpm.d/www.conf

WORKDIR /app

COPY docker/php/init-ssh-and-run.sh /usr/local/bin/init-ssh-and-run.sh
COPY docker/php/run-over-ssh.sh /usr/local/bin/run-over-ssh.sh
RUN chmod +x /usr/local/bin/init-ssh-and-run.sh /usr/local/bin/run-over-ssh.sh
