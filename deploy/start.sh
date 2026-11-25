#!/bin/sh
set -eu
mkdir -p /var/data/database /var/data/sessions
if [ ! -f /var/data/database/parking.db ]; then
    cp /opt/parking-seed/parking.db /var/data/database/parking.db
fi
chown -R www-data:www-data /var/data
exec apache2-foreground
