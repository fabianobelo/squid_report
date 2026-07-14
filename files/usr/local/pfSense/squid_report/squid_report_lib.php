<?php
/*
 * squid_report_lib.php
 * Funcoes compartilhadas entre o parser (cron) e a pagina de relatorio.
 * Instalar em: /usr/local/pfSense/squid_report/squid_report_lib.php
 */

// Dominios considerados "redes sociais" para o grafico dedicado.
// Ajuste essa lista conforme necessario.
define('SQUID_REPORT_SOCIAL_DOMAINS', [
    'facebook.com', 'instagram.com', 'x.com', 'twitter.com', 'tiktok.com',
    'linkedin.com', 'pinterest.com', 'threads.net', 'youtube.com', 'snapchat.com',
]);

function sr_social_like_clauses($column) {
    $parts = [];
    foreach (SQUID_REPORT_SOCIAL_DOMAINS as $d) {
        $parts[] = "$column LIKE '%" . SQLite3::escapeString($d) . "%'";
    }
    return implode(' OR ', $parts);
}

function sr_open_db_readonly($dbFile) {
    if (!file_exists($dbFile)) return null;
    return new SQLite3($dbFile, SQLITE3_OPEN_READONLY);
}

// Materializa a tabela ip_user_day: para cada dia+ip, qual foi o usuario
// que mais apareceu no log do SquidGuard naquele ip naquele dia.
// Usa o truque "greatest-n-per-group" (sem depender de window functions,
// que podem nao existir em builds mais antigos de sqlite3).
function sr_rebuild_ip_user_map($db) {
    $db->exec("DROP TABLE IF EXISTS ip_user_day");
    $db->exec("CREATE TABLE ip_user_day (day TEXT, client_ip TEXT, user TEXT, cnt INTEGER)");
    $db->exec("
        CREATE TEMP TABLE IF NOT EXISTS tmp_counts AS
        SELECT day, client_ip, user, COUNT(*) AS cnt
        FROM sg_hits
        WHERE user IS NOT NULL AND user != '-' AND user != ''
        GROUP BY day, client_ip, user
    ");
    $db->exec("
        INSERT INTO ip_user_day (day, client_ip, user, cnt)
        SELECT t1.day, t1.client_ip, t1.user, t1.cnt
        FROM tmp_counts t1
        LEFT JOIN tmp_counts t2
            ON t1.day = t2.day AND t1.client_ip = t2.client_ip AND t1.cnt < t2.cnt
        WHERE t2.day IS NULL
    ");
    $db->exec("DROP TABLE tmp_counts");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ipuserday ON ip_user_day(day, client_ip)");
}

// Retorna a lista de usuarios distintos vistos, para popular o filtro.
function sr_list_users($db) {
    $users = [];
    $res = $db->query("SELECT DISTINCT user FROM sg_hits
                        WHERE user IS NOT NULL AND user != '-' AND user != ''
                        ORDER BY user");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $users[] = $r['user'];
    return $users;
}

// Monta todo o payload do relatorio (usado tanto para o cache.json quanto
// para requisicoes filtradas em tempo real). $userFilter = null para todos.
function sr_build_report_data($db, $sinceDay, $untilDay = null, $userFilter = null) {
    if ($untilDay === null) $untilDay = date('Y-m-d');
    $untilEsc = SQLite3::escapeString($untilDay);
    $dayBoundHits = "AND h.day <= '$untilEsc'";
    $dayBoundSg = "AND day <= '$untilEsc'";

    $userEsc = $userFilter !== null ? SQLite3::escapeString($userFilter) : null;
    $userJoinFilter = $userFilter !== null ? "AND m.user = '$userEsc'" : "";
    $sgUserFilter = $userFilter !== null ? "AND user = '$userEsc'" : "";

    $summary = [];

    // banda diaria (respeita filtro de usuario via join com ip_user_day)
    $daily = [];
    $res = $db->query("
        SELECT h.day, SUM(h.bytes) AS bytes, COUNT(*) AS hits
        FROM hits h
        LEFT JOIN ip_user_day m ON m.day = h.day AND m.client_ip = h.client
        WHERE h.day >= '$sinceDay' $dayBoundHits $userJoinFilter
        " . ($userFilter !== null ? "" : "") . "
        GROUP BY h.day ORDER BY h.day
    ");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $daily[] = $r;
    $summary['daily'] = $daily;

    // totais
    $totBw = $db->querySingle("
        SELECT SUM(h.bytes) AS bytes, COUNT(*) AS hits, COUNT(DISTINCT h.client) AS ips
        FROM hits h
        LEFT JOIN ip_user_day m ON m.day = h.day AND m.client_ip = h.client
        WHERE h.day >= '$sinceDay' $dayBoundHits $userJoinFilter
    ", true);

    $totUsers = $db->querySingle("
        SELECT COUNT(DISTINCT user) AS n FROM sg_hits
        WHERE day >= '$sinceDay' $dayBoundSg AND user IS NOT NULL AND user != '-' AND user != '' $sgUserFilter
    ", true);

    $totBlocked = $db->querySingle("
        SELECT COUNT(*) AS blocked FROM sg_hits
        WHERE day >= '$sinceDay' $dayBoundSg AND blocked = 1 $sgUserFilter
    ", true);

    $summary['totals_7d'] = [
        'bytes'   => $totBw['bytes'] ?? 0,
        'hits'    => $totBw['hits'] ?? 0,
        'users'   => $totUsers['n'] ?? 0,
        'blocked' => $totBlocked['blocked'] ?? 0,
    ];

    // top usuarios por banda (nome + lista de IPs usados), exclui trafego
    // sem usuario identificado (maquinas/servicos sem auth)
    $topUsers = [];
    $res = $db->query("
        SELECT m.user AS user, SUM(h.bytes) AS bytes,
               GROUP_CONCAT(DISTINCT h.client) AS ips
        FROM hits h
        JOIN ip_user_day m ON m.day = h.day AND m.client_ip = h.client
        WHERE h.day >= '$sinceDay' $dayBoundHits $userJoinFilter
        GROUP BY m.user ORDER BY bytes DESC LIMIT 8
    ");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $topUsers[] = $r;
    $summary['top_users'] = $topUsers;

    // trafego sem usuario identificado (para nao esconder o dado, so nao
    // misturar com o ranking nominal)
    $unidentified = $db->querySingle("
        SELECT SUM(h.bytes) AS bytes, COUNT(*) AS hits
        FROM hits h
        LEFT JOIN ip_user_day m ON m.day = h.day AND m.client_ip = h.client
        WHERE h.day >= '$sinceDay' $dayBoundHits AND m.user IS NULL
    ", true);
    $summary['unidentified_traffic'] = $unidentified;

    // top sites
    $siteFilterJoin = $userFilter !== null
        ? "JOIN ip_user_day m ON m.day = h.day AND m.client_ip = h.client AND m.user = '$userEsc'"
        : "";
    $topSites = [];
    $res = $db->query("
        SELECT h.host AS host, COUNT(*) AS hits
        FROM hits h $siteFilterJoin
        WHERE h.day >= '$sinceDay' $dayBoundHits AND h.host != ''
        GROUP BY h.host ORDER BY hits DESC LIMIT 10
    ");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $topSites[] = $r;
    $summary['top_sites'] = $topSites;

    // categorias bloqueadas
    $topCategories = [];
    $res = $db->query("
        SELECT category, COUNT(*) AS hits FROM sg_hits
        WHERE day >= '$sinceDay' $dayBoundSg AND blocked = 1 $sgUserFilter
        GROUP BY category ORDER BY hits DESC LIMIT 8
    ");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $topCategories[] = $r;
    $summary['top_blocked_categories'] = $topCategories;

    // usuarios mais bloqueados
    $topBlockedUsers = [];
    $res = $db->query("
        SELECT user, COUNT(*) AS hits FROM sg_hits
        WHERE day >= '$sinceDay' $dayBoundSg AND blocked = 1 AND user != '-' AND user != '' $sgUserFilter
        GROUP BY user ORDER BY hits DESC LIMIT 8
    ");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $topBlockedUsers[] = $r;
    $summary['top_blocked_users'] = $topBlockedUsers;

    // redes sociais: diario (bloqueado vs liberado) + top dominios + top usuarios
    $socialLike = sr_social_like_clauses('host');
    $socialDaily = [];
    $res = $db->query("
        SELECT day,
               SUM(CASE WHEN blocked = 1 THEN 1 ELSE 0 END) AS blocked,
               SUM(CASE WHEN blocked = 0 THEN 1 ELSE 0 END) AS allowed
        FROM sg_hits
        WHERE day >= '$sinceDay' $dayBoundSg $sgUserFilter AND ($socialLike)
        GROUP BY day ORDER BY day
    ");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $socialDaily[] = $r;

    $socialTopDomains = [];
    $res = $db->query("
        SELECT host, COUNT(*) AS hits,
               SUM(CASE WHEN blocked = 1 THEN 1 ELSE 0 END) AS blocked_hits
        FROM sg_hits
        WHERE day >= '$sinceDay' $dayBoundSg $sgUserFilter AND ($socialLike)
        GROUP BY host ORDER BY hits DESC LIMIT 8
    ");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $socialTopDomains[] = $r;

    $socialTopUsers = [];
    $res = $db->query("
        SELECT user, COUNT(*) AS hits,
               SUM(CASE WHEN blocked = 1 THEN 1 ELSE 0 END) AS blocked_hits
        FROM sg_hits
        WHERE day >= '$sinceDay' $dayBoundSg AND user != '-' AND user != '' $sgUserFilter AND ($socialLike)
        GROUP BY user ORDER BY hits DESC LIMIT 8
    ");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $socialTopUsers[] = $r;

    $summary['social'] = [
        'daily'        => $socialDaily,
        'top_domains'  => $socialTopDomains,
        'top_users'    => $socialTopUsers,
    ];

    $summary['generated_at'] = date('c');
    $summary['user_filter'] = $userFilter;
    $summary['note'] = 'Bloqueios via log dedicado do SquidGuard. Usuarios identificados por cruzamento IP-usuario do mesmo log; trafego sem match fica em "unidentified_traffic".';

    return $summary;
}
