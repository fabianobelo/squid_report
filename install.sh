#!/bin/sh
# install.sh - instalador do Squid Report para pfSense
#
# Uso (no shell do pfSense, dentro do "sh" para evitar o "!" do tcsh):
#   fetch -o - https://raw.githubusercontent.com/SEU_USUARIO/SEU_REPO/main/install.sh | sh
#
# Ou baixando antes:
#   fetch https://raw.githubusercontent.com/SEU_USUARIO/SEU_REPO/main/install.sh
#   sh install.sh

set -e

REPO_RAW="https://raw.githubusercontent.com/SEU_USUARIO/SEU_REPO/main"

echo "== Squid Report para pfSense - instalador =="
echo "Baixando de: $REPO_RAW"
echo

mkdir -p /usr/local/pfSense/squid_report
mkdir -p /var/db/squid_report

echo "-> squid_report.php"
fetch -q -o /usr/local/www/squid_report.php "$REPO_RAW/files/usr/local/www/squid_report.php"

echo "-> squid_report_lib.php"
fetch -q -o /usr/local/pfSense/squid_report/squid_report_lib.php "$REPO_RAW/files/usr/local/pfSense/squid_report/squid_report_lib.php"

echo "-> squid_report_parser.php"
fetch -q -o /usr/local/pfSense/squid_report/squid_report_parser.php "$REPO_RAW/files/usr/local/pfSense/squid_report/squid_report_parser.php"

echo
echo "Arquivos copiados. Registrando menu e cron..."
fetch -q -o /tmp/pfsense_register.php "$REPO_RAW/scripts/pfsense_register.php"
php -f /tmp/pfsense_register.php
rm -f /tmp/pfsense_register.php

echo
echo "== Instalacao concluida =="
echo "Acesse: Status > Relatorios Squid"
echo
echo "Antes de usar, confira se os caminhos de log no topo de"
echo "/usr/local/pfSense/squid_report/squid_report_parser.php batem com o"
echo "seu ambiente (access.log do Squid e block.log do SquidGuard),"
echo "e que o SquidGuard esta com enable_log/enable_guilog ativado."
