#!/bin/sh
# Destructive cleanup is confined to a new, randomly named disposable project.
set -eu
[ "${DCMS_DISPOSABLE_SMOKE:-}" = 1 ] || { echo 'Requires DCMS_DISPOSABLE_SMOKE=1 on an approved local Docker daemon.' >&2; exit 1; }
[ "$#" = 1 ] || { echo 'Usage: smoke.sh LOCAL_IMAGE' >&2; exit 1; }
export DOCKER_HOST=unix:///var/run/docker.sock
unset DOCKER_CONTEXT
umask 077
repository=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
scratch=$(mktemp -d /tmp/dcms-smoke-XXXXXXXX)
project="dcms-smoke-$(basename "$scratch" | tr '[:upper:]' '[:lower:]')"
compose() { docker compose --project-name "$project" --env-file "$scratch/compose.env" -f "$repository/compose.release.yaml" -f "$scratch/smoke.yaml" "$@"; }
cleanup() {
    result=$?
    trap - EXIT INT TERM
    compose down --volumes --remove-orphans || result=1
    rm -rf "$scratch"
    exit "$result"
}
python3 - "$scratch" "$1" <<'PY'
import base64, pathlib, secrets, sys
p = pathlib.Path(sys.argv[1])
password = secrets.token_hex(32)
(p/'password').write_text(password)
(p/'root-password').write_text(secrets.token_hex(32))
(p/'app.env').write_text('\n'.join([
    'APP_ENV=testing', 'APP_DEBUG=false', 'APP_KEY=base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),
    'APP_URL=http://localhost', 'DB_CONNECTION=mysql', 'DB_HOST=db', 'DB_DATABASE=dcms_smoke',
    'DB_USERNAME=dcms_smoke', 'DB_PASSWORD='+password, 'QUEUE_CONNECTION=sync',
    'CACHE_STORE=database', 'SESSION_DRIVER=database', 'LOG_CHANNEL=stderr', 'MAIL_MAILER=array',
])+'\n')
(p/'compose.env').write_text('\n'.join([
    'DCMS_ENVIRONMENT=testing', 'DCMS_IMAGE='+sys.argv[2], 'DCMS_HTTP_PORT=0',
    'DCMS_APP_ENV_FILE='+str(p/'app.env'), 'DCMS_DB_DATABASE=dcms_smoke', 'DCMS_DB_USERNAME=dcms_smoke',
    'DCMS_DB_PASSWORD_FILE='+str(p/'password'), 'DCMS_DB_ROOT_PASSWORD_FILE='+str(p/'root-password'),
])+'\n')
# Disposable smoke only: avoid privileged trigger definers. Operational binary log policy needs approval.
(p/'smoke.yaml').write_text('services:\n  db:\n    command: ["--skip-log-bin", "--mysqlx=OFF", "--character-set-server=utf8mb4", "--collation-server=utf8mb4_unicode_ci"]\n')
PY
trap cleanup EXIT INT TERM
compose config --quiet
compose up -d --wait --wait-timeout 180 db
compose run --rm --no-deps app php artisan migrate --force --no-interaction
compose up -d --wait --wait-timeout 120 app
compose exec -T app php artisan migrate:status --no-interaction
compose exec -T app sh -eu -c '
    test ! -e .env
    test ! -e public/storage
    test -s public/build/manifest.json
    test ! -d node_modules
    test ! -d vendor/phpunit
    test ! -x /usr/local/bin/composer
    test "$(id -u)" = 33
    test -z "$(find storage/app/document-vault -type f -print -quit)"
    printf "disposable-private-probe" > storage/app/document-vault/smoke-probe
    curl --fail --silent --output /dev/null http://127.0.0.1:8080/up
    code=$(curl --silent --output /dev/null --write-out "%{http_code}" http://127.0.0.1:8080/storage/smoke-probe)
    test "$code" = 403
'
compose up -d --force-recreate --wait app
compose exec -T app sh -eu -c 'test "$(cat storage/app/document-vault/smoke-probe)" = disposable-private-probe; rm storage/app/document-vault/smoke-probe'
compose stop
printf '%s\n' 'Disposable smoke passed; exit trap removes only this random project.'
