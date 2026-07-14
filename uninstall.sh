#!/bin/sh
# uninstall.sh - remove o Squid Report do pfSense
#
# Uso:
#   fetch -o - https://raw.githubusercontent.com/SEU_USUARIO/SEU_REPO/main/uninstall.sh | sh
#
# Para tambem apagar o historico coletado (banco/cache), rode com PURGE=1:
#   fetch -o - .../uninstall.sh | PURGE=1 sh

set -e

REPO_RAW="https://raw.githubusercontent.com/SEU_USUARIO/SEU_REPO/main"

echo "== Squid Report para pfSense - desinstalador =="

echo "-> Removendo menu, cron e metadados do config.xml..."
fetch -q -o /tmp/pfsense_unregister.php "$REPO_RAW/scripts/pfsense_unregister.php"
php -f /tmp/pfsense_unregister.php
rm -f /tmp/pfsense_unregister.php

echo "-> Removendo arquivos PHP..."
rm -f /usr/local/www/squid_report.php
rm -rf /usr/local/pfSense/squid_report

if [ "$PURGE" = "1" ]; then
    echo "-> PURGE=1: removendo tambem dados coletados (/var/db/squid_report)..."
    rm -rf /var/db/squid_report
else
    echo "-> Dados coletados mantidos em /var/db/squid_report (rode com PURGE=1 para apagar)."
fi

echo
echo "== Desinstalacao concluida =="
echo "Se necessario, reinicie o Web GUI: Diagnostics > Restart Web GUI"
