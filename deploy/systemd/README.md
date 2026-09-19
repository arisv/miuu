# systemd units

`miu-purge.timer` runs `bin/console app:purge` every 10 minutes. The command deletes the files of
users whose purge grace period (`PURGE_GRACE_TIME` in `.env`) has passed and clears their
`purge_ts`. Overlapping runs are safe: each user is guarded by a database lock (`lock_keys`).

Install on the server (paths assume the app in `/var/www/miuu`, running as `nginx`; adjust
`User=`/`Group=` and `WorkingDirectory=` otherwise):

```sh
sudo cp deploy/systemd/miu-purge.service deploy/systemd/miu-purge.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now miu-purge.timer
systemctl list-timers miu-purge.timer     # next run
journalctl -u miu-purge.service -n 50     # last runs
```

Run it by hand with `php bin/console app:purge -v` (`--dry-run` lists what would go).
