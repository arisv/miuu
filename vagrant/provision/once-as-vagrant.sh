#!/usr/bin/env bash

function info {
  echo " "
  echo "--> $1"
  echo " "
}

#== Provision script ==

info "Provision-script user: `whoami`"

info "Installing node and friends"
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.0/install.sh | bash
source ~/.nvm/nvm.sh
nvm install --lts
npm install --global yarn

info "Setting the project up"
cd /app
composer install
php bin/console doctrine:database:create
php bin/console doctrine:schema:update -f
yarn encore dev

info "Enabling colorized prompt for guest console"
sed -i "s/#force_color_prompt=yes/force_color_prompt=yes/" /home/vagrant/.bashrc
