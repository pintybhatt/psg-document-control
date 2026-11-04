#!/bin/sh
# Root-owned forced command. Never evaluate SSH_ORIGINAL_COMMAND or accept arguments.
set -eu
[ "$#" -eq 0 ] && [ "${SSH_ORIGINAL_COMMAND:-}" = deploy-dcms-uat ] || exit 1
exec /usr/bin/sudo -n -- /usr/local/sbin/dcms-uat-deploy
