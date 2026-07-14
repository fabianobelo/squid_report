<?php
/*
 * squid_report_parser.php
 * Roda via cron. Ingere access.log + squidGuard block.log, materializa o
 * cruzamento IP<->usuario, e gera o cache.json (visao padrao, todos usuarios).
 */

require_once('/usr/local/pfSense/squid_report/squid_report_lib.php');

$CONFIG = [
    'access_log'      => '/var/squid/logs/access.log',
    'squidguard_log'  => '/var/squidGuard/log/block.log',
    'state_file'      => '/var/db/squid_report/parser.state',
    'sg_state_file'   => '/var/db/squid_report/sg_parser.state',
    'db_file'         => '/var/db/squid_report/squid_report.db',
    'cache_json'      => '/var/db/squid_report/cache.json',
    'retention_days'  => 90,
];

function ensure_dirs($cfg) {
    foreach ([$cfg['state_file'], $cfg['sg_state_file'], $cfg['db_file'], $cfg['cache_json']] as $f) {
        $dir = dirname($f);
        if (!is_dir($dir)) mkdir($dir, 0750, true);
    }
}

function open_db($cfg) {
    $isNew = !file_exists($cfg['db_file']);
    $db = new SQLite3($cfg['db_file']);
    $db->busyTimeout(5000);
    if ($isNew) {
        $db->exec("CREATE TABLE hits (
            ts INTEGER NOT NULL, day TEXT NOT NULL, client TEXT NOT NULL,
            action TEXT NOT NULL, method TEXT, bytes INTEGER NOT NULL,
            url TEXT, host TEXT
        )");
        $db->exec("CREATE INDEX idx_day ON hits(day)");
        $db->exec("CREATE INDEX idx_client ON hits(client)");
    }
    $db->exec("CREATE TABLE IF NOT EXISTS sg_hits (
        ts INTEGER NOT NULL, day TEXT NOT NULL, acl TEXT, category TEXT,
        blocked INTEGER NOT NULL, user TEXT, client_ip TEXT, host TEXT, method TEXT
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sg_day ON sg_hits(day)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sg_cat ON sg_hits(category)");
    return $db;
}

function load_state($file) {
    if (!file_exists($file)) return ['inode' => null, 'offset' => 0];
    $data = json_decode(file_get_contents($file), true);
    return $data ?: ['inode' => null, 'offset' => 0];
}

function save_state($file, $inode, $offset) {
    file_put_contents($file, json_encode(['inode' => $inode, 'offset' => $offset]));
}

function parse_access_line($line) {
    $parts = preg_split('/\s+/', trim($line));
    if (count($parts) < 10) return null;
    [$ts, $elapsed, $client, $actionCode, $bytes, $method, $url, $ident, $peer, $type] = array_slice($parts, 0, 10);
    $action = explode('/', $actionCode)[0];
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) $host = preg_replace('/:\d+$/', '', $url);
    return [
        'ts' => (int)floatval($ts), 'client' => $client,
        'action' => $action, 'method' => $method,
        'bytes' => (int)$bytes, 'url' => $url, 'host' => $host,
    ];
}

function parse_squidguard_line($line) {
    $re = '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2}) \[\d+\] Request\(([^\/]*)\/([^\/]*)\/[^\)]*\) (\S+) (\S+)\/(\S+) (\S+) (\S+) \S+$/';
    if (!preg_match($re, trim($line), $m)) return null;
    [, $date, $time, $acl, $category, $host, $ip1, $ip2, $user, $method] = $m;
    $ts = strtotime("$date $time");
    $blocked = ($category !== 'none' && $category !== '-') ? 1 : 0;
    return [
        'ts' => $ts, 'acl' => $acl, 'category' => $category, 'blocked' => $blocked,
        'user' => $user, 'client_ip' => $ip1, 'host' => preg_replace('/:\d+$/', '', $host),
        'method' => $method,
    ];
}

function process_access_log($cfg, $db) {
    if (!file_exists($cfg['access_log'])) return 0;
    $stat = stat($cfg['access_log']);
    $state = load_state($cfg['state_file']);
    $fh = fopen($cfg['access_log'], 'r');
    if (!$fh) return 0;
    $offset = ($state['inode'] !== $stat['ino'] || $stat['size'] < $state['offset']) ? 0 : $state['offset'];
    fseek($fh, $offset);

    $stmt = $db->prepare("INSERT INTO hits (ts, day, client, action, method, bytes, url, host)
                           VALUES (:ts, :day, :client, :action, :method, :bytes, :url, :host)");
    $count = 0;
    $db->exec('BEGIN');
    while (($line = fgets($fh)) !== false) {
        $row = parse_access_line($line);
        if (!$row) continue;
        $day = date('Y-m-d', $row['ts']);
        $stmt->bindValue(':ts', $row['ts'], SQLITE3_INTEGER);
        $stmt->bindValue(':day', $day, SQLITE3_TEXT);
        $stmt->bindValue(':client', $row['client'], SQLITE3_TEXT);
        $stmt->bindValue(':action', $row['action'], SQLITE3_TEXT);
        $stmt->bindValue(':method', $row['method'], SQLITE3_TEXT);
        $stmt->bindValue(':bytes', $row['bytes'], SQLITE3_INTEGER);
        $stmt->bindValue(':url', $row['url'], SQLITE3_TEXT);
        $stmt->bindValue(':host', $row['host'], SQLITE3_TEXT);
        $stmt->execute();
        $stmt->reset();
        $count++;
    }
    $db->exec('COMMIT');
    $newOffset = ftell($fh);
    fclose($fh);
    save_state($cfg['state_file'], $stat['ino'], $newOffset);
    return $count;
}

function process_squidguard_log($cfg, $db) {
    if (!file_exists($cfg['squidguard_log'])) return 0;
    $stat = stat($cfg['squidguard_log']);
    $state = load_state($cfg['sg_state_file']);
    $fh = fopen($cfg['squidguard_log'], 'r');
    if (!$fh) return 0;
    $offset = ($state['inode'] !== $stat['ino'] || $stat['size'] < $state['offset']) ? 0 : $state['offset'];
    fseek($fh, $offset);

    $stmt = $db->prepare("INSERT INTO sg_hits (ts, day, acl, category, blocked, user, client_ip, host, method)
                           VALUES (:ts, :day, :acl, :category, :blocked, :user, :client_ip, :host, :method)");
    $count = 0;
    $db->exec('BEGIN');
    while (($line = fgets($fh)) !== false) {
        $row = parse_squidguard_line($line);
        if (!$row) continue;
        $day = date('Y-m-d', $row['ts']);
        $stmt->bindValue(':ts', $row['ts'], SQLITE3_INTEGER);
        $stmt->bindValue(':day', $day, SQLITE3_TEXT);
        $stmt->bindValue(':acl', $row['acl'], SQLITE3_TEXT);
        $stmt->bindValue(':category', $row['category'], SQLITE3_TEXT);
        $stmt->bindValue(':blocked', $row['blocked'], SQLITE3_INTEGER);
        $stmt->bindValue(':user', $row['user'], SQLITE3_TEXT);
        $stmt->bindValue(':client_ip', $row['client_ip'], SQLITE3_TEXT);
        $stmt->bindValue(':host', $row['host'], SQLITE3_TEXT);
        $stmt->bindValue(':method', $row['method'], SQLITE3_TEXT);
        $stmt->execute();
        $stmt->reset();
        $count++;
    }
    $db->exec('COMMIT');
    $newOffset = ftell($fh);
    fclose($fh);
    save_state($cfg['sg_state_file'], $stat['ino'], $newOffset);
    return $count;
}

function purge_old($cfg, $db) {
    $cutoff = date('Y-m-d', strtotime("-{$cfg['retention_days']} days"));
    $db->exec("DELETE FROM hits WHERE day < '$cutoff'");
    $db->exec("DELETE FROM sg_hits WHERE day < '$cutoff'");
}

// --- main ---
ensure_dirs($CONFIG);
$db = open_db($CONFIG);
$n1 = process_access_log($CONFIG, $db);
$n2 = process_squidguard_log($CONFIG, $db);
purge_old($CONFIG, $db);
sr_rebuild_ip_user_map($db);

$since7 = date('Y-m-d', strtotime('-6 days'));
$today = date('Y-m-d');
$summary = sr_build_report_data($db, $since7, $today, null);
$summary['users_list'] = sr_list_users($db);
file_put_contents($CONFIG['cache_json'], json_encode($summary, JSON_PRETTY_PRINT));

$db->close();
echo "OK: $n1 linhas access.log, $n2 linhas squidGuard block.log\n";
