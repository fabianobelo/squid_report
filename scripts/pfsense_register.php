<?php
/*
 * pfsense_register.php
 * Executado UMA VEZ apos os arquivos serem copiados pelo install.sh.
 * Registra:
 *   - entrada de menu em installedpackages/menu (Status > Relatorios Squid)
 *   - entrada de pacote em installedpackages/package (metadados, sem menu aninhado)
 *   - job de cron (a cada 5 minutos) rodando o parser
 * Idempotente: pode ser rodado de novo sem duplicar nada.
 */

require_once('globals.inc');
require_once('config.inc');

global $config;

$PKG_NAME = 'squid_report';
$MENU_NAME = 'Relatorios Squid';
$CRON_CMD = '/usr/local/bin/php -f /usr/local/pfSense/squid_report/squid_report_parser.php >> /var/log/squid_report.log 2>&1';

// --- metadados do "pacote" (usado so para aparecer listado, sem instalar via pkg) ---
if (empty($config['installedpackages']['package'])) {
    $config['installedpackages']['package'] = [];
}
$pkgExists = false;
foreach ($config['installedpackages']['package'] as $pkg) {
    if ($pkg['name'] === $PKG_NAME) { $pkgExists = true; break; }
}
if (!$pkgExists) {
    $config['installedpackages']['package'][] = [
        'name'          => $PKG_NAME,
        'internal_name' => $PKG_NAME,
        'descr'         => 'Relatorio de trafego Squid/SquidGuard com graficos (open source)',
        'website'       => 'https://github.com/SEU_USUARIO/SEU_REPO',
        'version'       => '1.0',
    ];
    echo "Pacote registrado em installedpackages/package.\n";
} else {
    echo "Pacote ja registrado, mantendo.\n";
}

// --- menu (IMPORTANTE: fica solto dentro de installedpackages, NAO dentro de <package>) ---
if (empty($config['installedpackages']['menu'])) {
    $config['installedpackages']['menu'] = [];
}
foreach ($config['installedpackages']['menu'] as $i => $m) {
    if ($m['name'] === $MENU_NAME) {
        unset($config['installedpackages']['menu'][$i]);
    }
}
$config['installedpackages']['menu'] = array_values($config['installedpackages']['menu']);
$config['installedpackages']['menu'][] = [
    'name'        => $MENU_NAME,
    'tooltiptext' => 'Relatorio de trafego, bloqueios e redes sociais do Squid/SquidGuard',
    'section'     => 'Status',
    'url'         => '/squid_report.php',
];
echo "Menu registrado: Status > $MENU_NAME.\n";

// --- cron (*/5 min) ---
if (empty($config['cron']['item'])) {
    $config['cron']['item'] = [];
}
$cronExists = false;
foreach ($config['cron']['item'] as $item) {
    if (isset($item['command']) && trim($item['command']) === $CRON_CMD) {
        $cronExists = true;
        break;
    }
}
if (!$cronExists) {
    $config['cron']['item'][] = [
        'minute'  => '*/5',
        'hour'    => '*',
        'mday'    => '*',
        'month'   => '*',
        'wday'    => '*',
        'who'     => 'root',
        'command' => $CRON_CMD,
    ];
    echo "Cron registrado (*/5 min).\n";
} else {
    echo "Cron ja registrado, mantendo.\n";
}

write_config("Instalado/atualizado pacote squid_report (open source)");

// garante diretorio de dados e roda o parser uma vez para popular o cache
if (!is_dir('/var/db/squid_report')) {
    mkdir('/var/db/squid_report', 0750, true);
}

echo "\nRegistro concluido. Rodando o parser pela primeira vez...\n";
passthru('/usr/local/bin/php -f /usr/local/pfSense/squid_report/squid_report_parser.php');

echo "\nInstalacao concluida. Acesse Status > $MENU_NAME no menu do pfSense.\n";
