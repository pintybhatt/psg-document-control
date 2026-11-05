#!/bin/sh
# Install as a root-owned SSH forced command for the Production deploy account.
set -eu
[ "$#" -eq 0 ] && [ "${SSH_ORIGINAL_COMMAND:-}" = deploy-dcms-prd ] || exit 1
exec /usr/bin/sudo -n -- /usr/local/sbin/dcms-prd-deploy
