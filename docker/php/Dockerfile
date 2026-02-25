FROM php:8.2-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends openssh-client git unzip wget aria2 ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY docker/php/init-ssh-and-run.sh /usr/local/bin/init-ssh-and-run.sh
COPY docker/php/run-over-ssh.sh /usr/local/bin/run-over-ssh.sh
RUN chmod +x /usr/local/bin/init-ssh-and-run.sh /usr/local/bin/run-over-ssh.sh
