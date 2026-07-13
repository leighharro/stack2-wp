#!/bin/sh
set -e

CERT_DIR=/usr/local/share/ca-certificates/stack2-dev
if [ -d "$CERT_DIR" ] && [ -n "$(ls -A "$CERT_DIR" 2>/dev/null)" ]; then
    update-ca-certificates
fi

exec docker-entrypoint.sh "$@"
