#!/bin/sh
# Install root-owned as /usr/local/sbin/dcms-prd-deploy; sudo permits no arguments.
set -eu
[ "$#" -eq 0 ] || exit 1
exec /usr/bin/env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/root \
    /usr/bin/python3 -I -B /srv/dcms-prd/release/docker/deploy-prd.py
