#!/usr/bin/env bash
set -e
cd /home/houdaifayahia/clickDz/clickdzDoctor/clickdzDoctor
php artisan install:features --answers="$(cat install-answers.json)" --no-interaction
