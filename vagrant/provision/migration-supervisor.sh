apt install supervisor
touch /etc/supervisor/conf.d/messenger-worker.conf

mkdir -p /app/var/log

sudo cat << EOF > /etc/supervisor/conf.d/messenger-worker.conf
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

systemctl restart supervisor