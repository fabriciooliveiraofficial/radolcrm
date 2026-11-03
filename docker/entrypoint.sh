#!/bin/sh
set -e

# Segredo dos crons: usa o configurado no Coolify (NEXO_CRON_SECRET) ou gera um aleatório a cada boot.
if [ -z "${NEXO_CRON_SECRET:-}" ]; then
    NEXO_CRON_SECRET=$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')
    export NEXO_CRON_SECRET
fi

# O cron não herda o ambiente do container: grava as variáveis NEXO_* para o wrapper carregar.
(
    umask 077
    printenv | grep '^NEXO_' | sed "s/'/'\\\\''/g" | sed "s/^\([^=]*\)=\(.*\)\$/export \1='\2'/" > /etc/nexo-env
)

cron
echo "[nexo] agendador iniciado: financial 07:00 UTC, câmbio 07:15 UTC, WhatsApp a cada 30 min"

exec apache2-foreground
