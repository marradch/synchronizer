#!/bin/bash
git pull
composer install
php artisan migrate
npm install
npm run production
