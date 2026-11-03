#!/bin/sh
# Uso: nexo-cron <nome> <script.php> [--secret]
# Executa cron/<script.php> como www-data e envia a saída para o log do container (Coolify > Logs).
name="$1"
script="$2"
flag="${3:-}"

[ -r /etc/nexo-env ] && . /etc/nexo-env

{
    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] cron:$name início"
    if [ "$flag" = "--secret" ]; then
        runuser -u www-data -- php "/var/www/html/cron/$script" "${NEXO_CRON_SECRET:-}" 2>&1
    else
        runuser -u www-data -- php "/var/www/html/cron/$script" 2>&1
    fi
    status=$?
    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] cron:$name fim (exit=$status)"
} > /proc/1/fd/1 2>&1

exit "$status"
