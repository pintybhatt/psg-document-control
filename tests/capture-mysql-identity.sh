#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
project=$(docker --host unix:///var/run/docker.sock inspect dcms-mysql84-tests --format '{{index .Config.Labels "com.docker.compose.project"}}')
if [ "$project" != dcms-mysql84-tests ]; then
    printf '%s\n' 'Refusing a container outside the dedicated DCMS test project.' >&2
    exit 1
fi
temporary=storage/framework/testing/mysql84/server-uuid.new
trap 'rm -f "$temporary"' EXIT
docker --host unix:///var/run/docker.sock exec dcms-mysql84-tests sh -c 'export MYSQL_PWD="$(cat /run/secrets/mysql_root_password)"; exec mysql --user=root --batch --skip-column-names -e "SELECT @@server_uuid"' > "$temporary"
if [ -e storage/framework/testing/mysql84/server-uuid ]; then
    cmp storage/framework/testing/mysql84/server-uuid "$temporary"
else
    install -m 0644 "$temporary" storage/framework/testing/mysql84/server-uuid
fi
printf '%s\n' 'Dedicated MySQL server identity captured and verified.'
