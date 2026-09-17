FROM php:8.4-fpm

# ffmpeg/ffprobe for php-ffmpeg video thumbnails; git + unzip for composer.
RUN apt-get update \
 && apt-get install -y --no-install-recommends ffmpeg git unzip \
 && rm -rf /var/lib/apt/lists/*

# gd is built with jpeg/png/webp/freetype by this installer. ctype, curl, iconv, json,
# mbstring, openssl, xml, fileinfo and tokenizer already ship with the base image.
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions gd exif intl pdo_mysql zip opcache xdebug

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY conf.d/ /usr/local/etc/php/conf.d/

# Named account for the host uid so tools that resolve $HOME or whoami work.
ARG UID=1000
ARG GID=1000
RUN groupadd -g ${GID} app || true \
 && useradd -u ${UID} -g ${GID} -m -s /bin/bash app || true

WORKDIR /app
