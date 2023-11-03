#!/bin/bash

nginx_config_file="/etc/nginx/sites-enabled/default"

config_block="location /protected-files/ {
    internal;
    alias /app/storage/;
}"

awk -v block="$config_block" '/error_log/ {print block} 1' "$nginx_config_file" > temp_file
mv temp_file "$nginx_config_file"

