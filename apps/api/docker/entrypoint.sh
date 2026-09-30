#!/usr/bin/env bash
# Container start-up: config cache, migrations + demo seed, then PHP-FPM and Nginx.
# Any failing step stops the container, so Render keeps the previous deploy live.
set -Eeuo pipefail

cd /var/www/html

log() {
    echo "[entrypoint] $*" >&2
}

fail() {
    log "$*"
    exit 1
}

PORT="${PORT:-10000}"
[[ "$PORT" =~ ^[0-9]+$ ]] || fail "PORT must be a number (got '$PORT')."
[[ -n "${APP_KEY:-}" ]] || fail "APP_KEY is not set."

for dir in storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs bootstrap/cache /tmp/nginx; do
    mkdir -p "$dir"
done

for dir in storage storage/framework/views storage/logs bootstrap/cache /tmp/nginx; do
    [[ -w "$dir" ]] || fail "$dir is not writable by $(id -un)."
done

log "Caching configuration, routes, events and views."
php artisan optimize

log "Running migrations and the demo seed."
php artisan pulseboard:release

sed "s/__PORT__/${PORT}/g" /etc/nginx/pulseboard.conf.template > /tmp/nginx/nginx.conf
nginx -t -q -e /dev/stderr -c /tmp/nginx/nginx.conf

log "Starting PHP-FPM and Nginx on port ${PORT}."
php-fpm --nodaemonize &
fpm_pid=$!

nginx -e /dev/stderr -c /tmp/nginx/nginx.conf -g 'daemon off;' &
nginx_pid=$!

stopping=0

# SIGQUIT is the graceful shutdown signal for both processes.
# shellcheck disable=SC2329 # invoked by the trap below
shutdown() {
    stopping=1
    kill -QUIT "$fpm_pid" "$nginx_pid" 2>/dev/null || true
}
trap shutdown TERM INT QUIT

# Stop the container as soon as either process exits, so Render restarts it.
set +e
wait -n "$fpm_pid" "$nginx_pid"
status=$?
kill -QUIT "$fpm_pid" "$nginx_pid" 2>/dev/null
wait

if [[ "$stopping" == 1 ]]; then
    exit 0
fi

log "A server process exited unexpectedly (status ${status})."
exit "$status"
