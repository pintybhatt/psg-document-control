#!/bin/sh
# Install root-owned as /usr/local/sbin/dcms-uat-deploy. No caller environment survives.
set -eu
[ "$#" -eq 0 ] || exit 1
exec /usr/bin/env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/root \
    /usr/bin/python3 -I -B /srv/dcms-uat/release/docker/deploy-uat-manual.py
