#!/usr/bin/env bash
# Container start-up: config cache, migrations + demo seed + AI retention, then PHP-FPM, Nginx and,
# with BROADCAST_CONNECTION=reverb, the Reverb WebSocket server.
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

# Nginx proxies /app to Reverb on this port; the API publishes to it directly.
REVERB_INTERNAL_PORT=8080
if [[ "${BROADCAST_CONNECTION:-}" == "reverb" ]]; then
    for var in REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET; do
        [[ -n "${!var:-}" ]] || fail "$var is not set (BROADCAST_CONNECTION=reverb)."
    done
    # The API publishes to the Reverb next to it, never through the public host.
    export REVERB_HOST=127.0.0.1 REVERB_PORT="$REVERB_INTERNAL_PORT" REVERB_SCHEME=http
fi

for dir in storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs bootstrap/cache /tmp/nginx; do
    mkdir -p "$dir"
done

for dir in storage storage/framework/views storage/logs bootstrap/cache /tmp/nginx; do
    [[ -w "$dir" ]] || fail "$dir is not writable by $(id -un)."
done

log "Caching configuration, routes, events and views."
php artisan optimize

log "Running migrations, the demo seed and the AI retention."
php artisan pulseboard:release

sed -e "s/__PORT__/${PORT}/g" -e "s/__REVERB_PORT__/${REVERB_INTERNAL_PORT}/g" \
    /etc/nginx/pulseboard.conf.template > /tmp/nginx/nginx.conf
nginx -t -q -e /dev/stderr -c /tmp/nginx/nginx.conf

# SIGQUIT is the graceful shutdown signal for PHP-FPM and Nginx; Reverb only handles SIGTERM.
quit_pids=()
term_pids=()

if [[ "${BROADCAST_CONNECTION:-}" == "reverb" ]]; then
    log "Starting Reverb on 127.0.0.1:${REVERB_INTERNAL_PORT}."
    php artisan reverb:start --host=127.0.0.1 --port="$REVERB_INTERNAL_PORT" --no-interaction &
    term_pids+=("$!")
fi

log "Starting PHP-FPM and Nginx on port ${PORT}."
php-fpm --nodaemonize &
quit_pids+=("$!")

nginx -e /dev/stderr -c /tmp/nginx/nginx.conf -g 'daemon off;' &
quit_pids+=("$!")

stopping=0

stop_all() {
    kill -QUIT "${quit_pids[@]}" 2>/dev/null || true
    if ((${#term_pids[@]})); then
        kill -TERM "${term_pids[@]}" 2>/dev/null || true
    fi
}

# shellcheck disable=SC2329 # invoked by the trap below
shutdown() {
    stopping=1
    stop_all
}
trap shutdown TERM INT QUIT

# Stop the container as soon as any process exits, so Render restarts it.
set +e
wait -n "${quit_pids[@]}" "${term_pids[@]}"
status=$?
stop_all
wait

if [[ "$stopping" == 1 ]]; then
    exit 0
fi

log "A server process exited unexpectedly (status ${status})."
exit "$status"
