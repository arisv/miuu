#!/usr/bin/env bash

sudo apt-get install -y php8.2-fpm php8.2-zip php8.2-mysql php8.2-curl php8.2-mbstring php8.2-gd php8.2-xdebug php8.2-xml php8.2-common php8.2-intl

sudo systemctl disable php8.0-fpm
sudo systemctl enable php8.2-fpm

sudo sed -i "s/fastcgi_pass unix:\/var\/run\/php\/php8.0-fpm.sock/fastcgi_pass unix:\/var\/run\/php\/php8.2-fpm.sock/" /etc/nginx/sites-enabled/default