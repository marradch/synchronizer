#!/bin/bash
sudo git pull
sudo php composer.phar install
sudo php artisan migrate --force
sudo npm install
sudo npm run production
