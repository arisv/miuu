#!/usr/bin/env bash

function info {
  echo " "
  echo "--> $1"
  echo " "
}

#== Import script args ==

timezone=$(echo "$1")

#== Provision script ==

info "Provision-script user: `whoami`"

export DEBIAN_FRONTEND=noninteractive

info "Configure timezone"
timedatectl set-timezone ${timezone} --no-ask-password

info "Prepare root password for MySQL"
debconf-set-selections <<< "mysql-community-server mysql-community-server/root-pass password \"''\""
debconf-set-selections <<< "mysql-community-server mysql-community-server/re-root-pass password \"''\""
echo "Done!"

info "Update OS software"
apt-get update
apt-get upgrade -y

info "Adding PHP PPA..."
apt install software-properties-common
add-apt-repository ppa:ondrej/php

info "Installing software"
apt-get install -y nginx mysql-server php8.2-fpm php8.2-zip php8.2-mysql php8.2-curl php8.2-mbstring php8.2-gd php8.2-xdebug php8.2-xml dos2unix php8.2-common

info "Installing composer"
EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"

if [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]
then
    info 'ERROR: Invalid installer checksum'
    rm composer-setup.php
else
    php composer-setup.php
    rm composer-setup.php
    sudo mv composer.phar /usr/local/bin/composer
    sudo chmod +x /usr/local/bin/composer
fi

info "Setting document root to public directory"
rm -rf /var/www/html
ln -fs /app/public/ /var/www/html

info "Configuring nginx"
rm /etc/nginx/sites-enabled/default
sudo tee /etc/nginx/sites-enabled/default > /dev/null << 'EOF'
server {
  listen 80;
  server_name miuuvg.local;
  client_max_body_size 100M;

  root /var/www/html;

  location / {
    # try to serve file directly, fallback to rewrite
    try_files $uri @rewriteapp;
    autoindex on;
  }
  
  location @rewriteapp {
    rewrite ^(.*)$ /index.php/$1 last;
  }
  
  # Pass the PHP scripts to FastCGI server
  location ~ ^/(index|check|config)\.php(/|$) {
    fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
    fastcgi_split_path_info ^(.+\.php)(/.*)$;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
    fastcgi_param DOCUMENT_ROOT $realpath_root;
  }

  error_log /var/log/nginx/project_error.log;
  access_log /var/log/nginx/project_access.log;
}
EOF

info "Enabling xdebug"
cat << EOF > /etc/php/8.0/mods-available/xdebug.ini
zend_extension=xdebug.so
xdebug.mode = debug
xdebug.client_host = 10.0.2.2
xdebug.client_port = 9003
xdebug.log = /var/log/xdebug.log
xdebug.discover_client_host = 1
xdebug.idekey = VSCODE
EOF
info "Done!"


info "Configure MySQL"
sed -i "s/.*bind-address.*/bind-address = 0.0.0.0/" /etc/mysql/mysql.conf.d/mysqld.cnf
mysql -uroot <<< "CREATE USER 'root'@'%' IDENTIFIED WITH mysql_native_password BY ''"
mysql -uroot <<< "GRANT ALL PRIVILEGES ON *.* TO 'root'@'%'"
mysql -uroot <<< "DROP USER 'root'@'localhost'"
mysql -uroot <<< "FLUSH PRIVILEGES"
echo "Done!"

info "Disabling mysql full group by mode"
touch /etc/mysql/conf.d/disable_strict_mode.cnf
cat << EOF > /etc/mysql/conf.d/disable_strict_mode.cnf
[mysqld]
sql_mode=STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION
EOF

info "Setting up supervisor"
apt install supervisor
touch /etc/supervisor/conf.d/messenger-worker.conf

cat << EOF > /etc/supervisor/conf.d/messenger-worker.conf
[program:messenger-event-bus]
command=php /app/bin/console messenger:consume event_bus --time-limit=3600 -vv --no-debug --limit=10
user=www-data
environment=SF_VAGRANT_MODE="1"
numprocs=1
startsecs=0
autostart=true
autorestart=true
startretries=2
process_name=%(program_name)s_%(process_num)02d
stderr_logfile=/app/var/log/event_bus.err.log
stderr_logfile_maxbytes=5MB
stderr_logfile_backups=5
stdout_logfile=/app/var/log/event_bus.out.log
stdout_logfile_maxbytes=5MB
stdout_logfile_backups=5

[group:messenger-consume]
programs=messenger-event-bus
priority=999
EOF

sudo supervisorctl stop all
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start messenger-consume:*
echo "Done!"