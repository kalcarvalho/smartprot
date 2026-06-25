#!/bin/bash
set -e
mkdir -p /tmp/deploy4
cd /tmp/deploy4
mkdir -p web/resources/views/components/layouts web/resources/views/devices
cp /mnt/c/Dev/smartprot/web/resources/views/components/layouts/app.blade.php web/resources/views/components/layouts/app.blade.php
cp /mnt/c/Dev/smartprot/web/resources/views/devices/show.blade.php web/resources/views/devices/show.blade.php
tar czf /tmp/deploy4.tar.gz web
scp -i ~/.ssh/id_rsa /tmp/deploy4.tar.gz opc@92.5.2.201:/tmp/deploy4.tar.gz
ssh -i ~/.ssh/id_rsa opc@92.5.2.201 'cd /home/opc/smartprot && tar xzf /tmp/deploy4.tar.gz && rm /tmp/deploy4.tar.gz && docker exec smartprot-app php artisan view:clear && echo DEPLOYED'
rm -rf /tmp/deploy4 /tmp/deploy4.tar.gz
