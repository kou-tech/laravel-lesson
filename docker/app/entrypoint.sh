#!/bin/bash
set -e

if [ ! -f vendor/autoload.php ]; then
    composer setup
fi

exec "$@"
