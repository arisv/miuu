#!/usr/bin/env bash

function info {
  echo " "
  echo "--> $1"
  echo " "
}

#== Provision script ==

info "Provision-script user: `whoami`"

info "Restart web-stack"
service nginx restart
service mysql restart

mkdir -p /tmp/app/{cache,logs}/{prod,dev}
chmod 777 -R /tmp/app