<?php
/*
 * pfsense_unregister.php
 * Remove a entrada de menu, o job de cron e os metadados do pacote do
 * config.xml. NAO apaga os arquivos PHP nem os dados coletados
 * (isso e feito pelo uninstall.sh, com opcao de manter o historico).
 */

require_once('globals.inc');
require_once('config.inc');

global $config;

$PKG_NAME = 'squid_report';
$MENU_NAME = 'Relatorios Squid';
$CRON_CMD = '/usr/local/bin/php -f /usr/local/pfSense/squid_report/squid_report_parser.php >> /var/log/squid_report.log 2>&1';

$changed = false;

if (!empty($config['installedpackages']['menu'])) {
    foreach ($config['installedpackages']['menu'] as $i => $m) {
        if ($m['name'] === $MENU_NAME) {
            unset($config['installedpackages']['menu'][$i]);
            $changed = true;
        }
    }
    $config['installedpackages']['menu'] = array_values($config['installedpackages']['menu']);
}

if (!empty($config['installedpackages']['package'])) {
    foreach ($config['installedpackages']['package'] as $i => $pkg) {
        if ($pkg['name'] === $PKG_NAME) {
            unset($config['installedpackages']['package'][$i]);
            $changed = true;
        }
    }
    $config['installedpackages']['package'] = array_values($config['installedpackages']['package']);
}

if (!empty($config['cron']['item'])) {
    foreach ($config['cron']['item'] as $i => $item) {
        if (isset($item['command']) && trim($item['command']) === $CRON_CMD) {
            unset($config['cron']['item'][$i]);
            $changed = true;
        }
    }
    $config['cron']['item'] = array_values($config['cron']['item']);
}

if ($changed) {
    write_config("Removido pacote squid_report");
    echo "Menu, cron e metadados removidos do config.xml.\n";
} else {
    echo "Nada encontrado para remover (ja estava desinstalado?).\n";
}
