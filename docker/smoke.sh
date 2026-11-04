#!/bin/sh
# The Python driver validates the environment before any Docker invocation.
set -eu
umask 077
exec /usr/bin/python3 -I "$(CDPATH= cd -- "$(dirname "$0")" && pwd)/smoke.py" "$@"
