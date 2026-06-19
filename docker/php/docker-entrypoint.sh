#!/bin/sh
set -e

mkdir -p /var/www/html/storage/uv-cache
chown -R www-data:www-data /var/www/html/storage/uv-cache

exec "$@"
