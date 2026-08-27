FROM php:8.2-fpm-bookworm

# Official Jellyfin build, pinned per architecture. These values are build args so an
# update is explicit and checksum-verified instead of silently following "latest".
ARG JELLYFIN_FFMPEG_VERSION=7.1.4-3
ARG JELLYFIN_FFMPEG_AMD64_SHA256=0cf62bc2423822c9ec7a38dbc8f526d9a58671bd01843daa7817ece35619fc1c
ARG JELLYFIN_FFMPEG_ARM64_SHA256=9de240f98cc49db7ebc649c72c37d3ef154170d37ec2fbc68483cc84744804bb
ARG MALI_PACKAGE_TAG=v1.9-1-20260312-bd33ee2
ARG MALI_PACKAGE_VERSION=1.9-1_arm64
ARG MALI_PACKAGE_CONFIG=valhall-g610-g24p0-gbm
ARG MALI_PACKAGE_SHA256=32ffe853e8d56295284637252f1da15dd868a8f7c6b8da6b9f77616ba285eb1a

RUN apt-get update \
    && apt-get install -y --no-install-recommends openssh-client git unzip wget aria2 ca-certificates libzip-dev util-linux \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    architecture="$(dpkg --print-architecture)"; \
    case "$architecture" in \
        amd64) checksum="$JELLYFIN_FFMPEG_AMD64_SHA256" ;; \
        arm64) checksum="$JELLYFIN_FFMPEG_ARM64_SHA256" ;; \
        *) echo "Unsupported jellyfin-ffmpeg architecture: $architecture" >&2; exit 1 ;; \
    esac; \
    package="jellyfin-ffmpeg7_${JELLYFIN_FFMPEG_VERSION}-bookworm_${architecture}.deb"; \
    url="https://github.com/jellyfin/jellyfin-ffmpeg/releases/download/v${JELLYFIN_FFMPEG_VERSION}/${package}"; \
    wget --https-only --output-document="/tmp/${package}" "$url"; \
    echo "${checksum}  /tmp/${package}" | sha256sum --check --strict; \
    apt-get update; \
    apt-get install -y --no-install-recommends "/tmp/${package}"; \
    rm -f "/tmp/${package}"; \
    rm -rf /var/lib/apt/lists/*; \
    ln -sfn /usr/lib/jellyfin-ffmpeg/ffmpeg /usr/local/bin/ffmpeg; \
    ln -sfn /usr/lib/jellyfin-ffmpeg/ffprobe /usr/local/bin/ffprobe; \
    /usr/local/bin/ffmpeg -version; \
    /usr/local/bin/ffprobe -version

# Match Jellyfin's RK3588 image: the G610 userspace runtime exposes OpenCL for
# tone mapping. It is architecture-gated, so normal amd64 development stays usable.
RUN set -eux; \
    architecture="$(dpkg --print-architecture)"; \
    if [ "$architecture" = arm64 ]; then \
        package="libmali-${MALI_PACKAGE_CONFIG}_${MALI_PACKAGE_VERSION}.deb"; \
        url="https://github.com/tsukumijima/libmali-rockchip/releases/download/${MALI_PACKAGE_TAG}/${package}"; \
        wget --https-only --output-document="/tmp/${package}" "$url"; \
        echo "${MALI_PACKAGE_SHA256}  /tmp/${package}" | sha256sum --check --strict; \
        apt-get update; \
        apt-get install -y --no-install-recommends "/tmp/${package}"; \
        rm -f "/tmp/${package}"; \
        rm -rf /var/lib/apt/lists/*; \
    fi

# Composer via official installer (arch-independent phar, works on any platform).
RUN php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');" \
    && php -r "if (hash_file('sha384', '/tmp/composer-setup.php') !== trim(file_get_contents('https://composer.github.io/installer.sig'))) { fwrite(STDERR, 'Composer installer checksum mismatch'.PHP_EOL); exit(1); }" \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm /tmp/composer-setup.php

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
