#!/bin/bash
git pull
composer install
php artisan migrate --force
npm install
npm run production
