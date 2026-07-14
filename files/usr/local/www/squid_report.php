<?php
/*
 * squid_report.php
 * Pagina de relatorio do Squid/SquidGuard, com filtros de usuario/periodo.
 * Instalar em: /usr/local/www/squid_report.php
 */

require_once("guiconfig.inc");
require_once('/usr/local/pfSense/squid_report/squid_report_lib.php');

$pgtitle = ["Status", "Squid Report"];
$cache_file = "/var/db/squid_report/cache.json";
$db_file = "/var/db/squid_report/squid_report.db";

$dateRe = '/^\d{4}-\d{2}-\d{2}$/';
$today = date('Y-m-d');
$default_start = date('Y-m-d', strtotime('-6 days'));

$userFilter = isset($_GET['user']) && $_GET['user'] !== '' ? $_GET['user'] : null;
$startDate = isset($_GET['start']) && preg_match($dateRe, $_GET['start']) ? $_GET['start'] : null;
$endDate = isset($_GET['end']) && preg_match($dateRe, $_GET['end']) ? $_GET['end'] : null;
$isFiltered = ($userFilter !== null) || ($startDate !== null) || ($endDate !== null);

// para exibir nos campos de data mesmo quando nao filtrado (visao padrao)
$displayStart = $startDate ?? $default_start;
$displayEnd = $endDate ?? $today;
if ($displayStart > $displayEnd) { $tmp = $displayStart; $displayStart = $displayEnd; $displayEnd = $tmp; }

$data = null;
$usersList = [];

if ($isFiltered) {
    // consulta ao vivo no sqlite, respeitando o filtro escolhido
    $db = sr_open_db_readonly($db_file);
    if ($db) {
        $data = sr_build_report_data($db, $displayStart, $displayEnd, $userFilter);
        $usersList = sr_list_users($db);
        $db->close();
    }
} else {
    // caminho rapido: usa o cache gerado pelo cron
    if (file_exists($cache_file)) {
        $data = json_decode(file_get_contents($cache_file), true);
        $usersList = $data['users_list'] ?? [];
    }
}

include("head.inc");
?>

<body>
<?php include("fbegin.inc"); ?>

<style>
@media print {
    #squidReportFilterForm, .nav-tabs, #mobile-nav, .navbar, #widget-header,
    .sidebar, .breadcrumb, footer { display: none !important; }
    .panel { border: none; box-shadow: none; }
}
</style>

<div class="panel panel-default">
    <div class="panel-heading"><h2 class="panel-title">Relatorio Squid / SquidGuard</h2></div>
    <div class="panel-body" style="padding:30px 40px;">

        <form id="squidReportFilterForm" method="get" class="form-inline" style="margin-bottom:15px;">
            <div class="form-group" style="margin-right:10px;">
                <label style="margin-right:5px;">Usuario</label>
                <select name="user" class="form-control" onchange="this.form.submit()">
                    <option value="">Todos</option>
<?php foreach ($usersList as $u): ?>
                    <option value="<?= htmlspecialchars($u) ?>" <?= ($userFilter === $u) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($u) ?>
                    </option>
<?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-right:10px;">
                <label style="margin-right:5px;">De</label>
                <input type="date" name="start" class="form-control" value="<?= htmlspecialchars($displayStart) ?>"
                       max="<?= htmlspecialchars($today) ?>">
            </div>
            <div class="form-group" style="margin-right:10px;">
                <label style="margin-right:5px;">Ate</label>
                <input type="date" name="end" class="form-control" value="<?= htmlspecialchars($displayEnd) ?>"
                       max="<?= htmlspecialchars($today) ?>">
            </div>
            <button type="submit" class="btn btn-default" style="margin-right:10px;">Filtrar</button>
            <div class="btn-group" style="margin-right:10px;" role="group">
                <button type="button" class="btn btn-default" onclick="srSetRange(0)">Hoje</button>
                <button type="button" class="btn btn-default" onclick="srSetRange(6)">7 dias</button>
                <button type="button" class="btn btn-default" onclick="srSetRange(29)">30 dias</button>
            </div>
            <button type="button" class="btn btn-default" onclick="window.print()">
                <i class="fa fa-file-pdf-o"></i> Exportar PDF
            </button>
<?php if ($isFiltered): ?>
            <a href="squid_report.php" class="btn btn-default">Limpar filtros</a>
<?php endif; ?>
        </form>
        <script>
        function srSetRange(daysBack) {
            var end = new Date();
            var start = new Date();
            start.setDate(end.getDate() - daysBack);
            function fmt(d) { return d.toISOString().slice(0, 10); }
            document.querySelector('input[name="start"]').value = fmt(start);
            document.querySelector('input[name="end"]').value = fmt(end);
            document.getElementById('squidReportFilterForm').submit();
        }
        </script>

<?php if (!$data): ?>
        <div class="alert alert-warning">
            Cache ainda nao gerado. Aguarde a proxima execucao do cron
            (squid_report_parser.php) ou execute manualmente:<br>
            <code>php -f /usr/local/pfSense/squid_report/squid_report_parser.php</code>
        </div>
<?php else: ?>
        <p class="text-muted">
            Atualizado em: <?= htmlspecialchars($data['generated_at']) ?>
            &nbsp;|&nbsp; <?= htmlspecialchars($data['note']) ?>
<?php if ($userFilter): ?>
            &nbsp;|&nbsp; <strong>Filtrado por: <?= htmlspecialchars($userFilter) ?></strong>
<?php endif; ?>
        </p>

        <div class="row" style="margin-bottom:20px;">
            <div class="col-sm-3"><div class="widgetbg" style="padding:15px; text-align:center;">
                <div style="font-size:12px; color:#888;">Trafego (periodo)</div>
                <div style="font-size:22px;"><?= round(($data['totals_7d']['bytes'] ?? 0) / 1073741824, 2) ?> GB</div>
            </div></div>
            <div class="col-sm-3"><div class="widgetbg" style="padding:15px; text-align:center;">
                <div style="font-size:12px; color:#888;">Requisicoes (periodo)</div>
                <div style="font-size:22px;"><?= number_format($data['totals_7d']['hits'] ?? 0, 0, ',', '.') ?></div>
            </div></div>
            <div class="col-sm-3"><div class="widgetbg" style="padding:15px; text-align:center;">
                <div style="font-size:12px; color:#888;">Bloqueios (periodo)</div>
                <div style="font-size:22px; color:#c0392b;"><?= number_format($data['totals_7d']['blocked'] ?? 0, 0, ',', '.') ?></div>
            </div></div>
            <div class="col-sm-3"><div class="widgetbg" style="padding:15px; text-align:center;">
                <div style="font-size:12px; color:#888;">Usuarios ativos</div>
                <div style="font-size:22px;"><?= number_format($data['totals_7d']['users'] ?? 0, 0, ',', '.') ?></div>
            </div></div>
        </div>

        <h4>Banda por dia</h4>
        <div style="position:relative; height:220px; margin-bottom:30px;">
            <canvas id="bwChart"></canvas>
        </div>

        <div class="row">
            <div class="col-sm-6">
                <h4>Top usuarios (GB)</h4>
                <div style="position:relative; height:220px;">
                    <canvas id="usersChart"></canvas>
                </div>
                <table class="table table-condensed" style="margin-top:10px; font-size:12px;">
                    <thead><tr><th>Usuario</th><th>IP(s) usado(s)</th></tr></thead>
                    <tbody>
<?php foreach (($data['top_users'] ?? []) as $u): ?>
                        <tr>
                            <td><?= htmlspecialchars($u['user']) ?></td>
                            <td class="text-muted"><?= htmlspecialchars($u['ips']) ?></td>
                        </tr>
<?php endforeach; ?>
                    </tbody>
                </table>
<?php if (!empty($data['unidentified_traffic']['bytes'])): ?>
                <p class="text-muted" style="font-size:12px;">
                    Trafego nao identificado (sem usuario mapeado):
                    <?= round($data['unidentified_traffic']['bytes'] / 1073741824, 2) ?> GB
                    em <?= number_format($data['unidentified_traffic']['hits'], 0, ',', '.') ?> requisicoes.
                </p>
<?php endif; ?>
            </div>
            <div class="col-sm-6">
                <h4>Top sites (requisicoes)</h4>
                <table class="table table-striped">
                    <thead><tr><th>Host</th><th>Hits</th></tr></thead>
                    <tbody>
<?php foreach (($data['top_sites'] ?? []) as $site): ?>
                        <tr>
                            <td><?= htmlspecialchars($site['host']) ?></td>
                            <td><?= number_format($site['hits'], 0, ',', '.') ?></td>
                        </tr>
<?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row" style="margin-top:20px;">
            <div class="col-sm-6">
                <h4>Categorias bloqueadas (SquidGuard)</h4>
                <div style="position:relative; height:220px;">
                    <canvas id="catChart"></canvas>
                </div>
            </div>
            <div class="col-sm-6">
                <h4>Usuarios mais bloqueados</h4>
                <table class="table table-striped">
                    <thead><tr><th>Usuario</th><th>Bloqueios</th></tr></thead>
                    <tbody>
<?php foreach (($data['top_blocked_users'] ?? []) as $u): ?>
                        <tr>
                            <td><?= htmlspecialchars($u['user']) ?></td>
                            <td style="color:#c0392b;"><?= number_format($u['hits'], 0, ',', '.') ?></td>
                        </tr>
<?php endforeach; ?>
<?php if (empty($data['top_blocked_users'])): ?>
                        <tr><td colspan="2" class="text-muted">Sem bloqueios no periodo.</td></tr>
<?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row" style="margin-top:20px;">
            <div class="col-sm-6">
                <h4>Redes sociais - acessos por dia</h4>
                <div style="position:relative; height:220px;">
                    <canvas id="socialChart"></canvas>
                </div>
            </div>
            <div class="col-sm-6">
                <h4>Redes sociais - por dominio</h4>
                <table class="table table-striped">
                    <thead><tr><th>Dominio</th><th>Hits</th><th>Bloqueados</th></tr></thead>
                    <tbody>
<?php foreach (($data['social']['top_domains'] ?? []) as $d): ?>
                        <tr>
                            <td><?= htmlspecialchars($d['host']) ?></td>
                            <td><?= number_format($d['hits'], 0, ',', '.') ?></td>
                            <td style="color:#c0392b;"><?= number_format($d['blocked_hits'], 0, ',', '.') ?></td>
                        </tr>
<?php endforeach; ?>
<?php if (empty($data['social']['top_domains'])): ?>
                        <tr><td colspan="3" class="text-muted">Sem acessos a redes sociais no periodo.</td></tr>
<?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <h4 style="margin-top:20px;">Redes sociais - usuarios que mais acessam</h4>
        <table class="table table-striped">
            <thead><tr><th>Usuario</th><th>Hits</th><th>Bloqueados</th></tr></thead>
            <tbody>
<?php foreach (($data['social']['top_users'] ?? []) as $u): ?>
                <tr>
                    <td><?= htmlspecialchars($u['user']) ?></td>
                    <td><?= number_format($u['hits'], 0, ',', '.') ?></td>
                    <td style="color:#c0392b;"><?= number_format($u['blocked_hits'], 0, ',', '.') ?></td>
                </tr>
<?php endforeach; ?>
<?php if (empty($data['social']['top_users'])): ?>
                <tr><td colspan="3" class="text-muted">Sem acessos a redes sociais no periodo.</td></tr>
<?php endif; ?>
            </tbody>
        </table>

        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <script>
        var dailyData = <?= json_encode($data['daily']) ?>;
        var usersData = <?= json_encode($data['top_users']) ?>;
        var catData = <?= json_encode($data['top_blocked_categories'] ?? []) ?>;
        var socialDaily = <?= json_encode($data['social']['daily'] ?? []) ?>;

        new Chart(document.getElementById('bwChart'), {
            type: 'line',
            data: {
                labels: dailyData.map(d => d.day),
                datasets: [{
                    label: 'GB', data: dailyData.map(d => +(d.bytes / 1073741824).toFixed(2)),
                    borderColor: '#2a78d6', backgroundColor: 'rgba(42,120,214,0.1)',
                    fill: true, tension: 0.3
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, layout: { padding: 12 } }
        });

        new Chart(document.getElementById('usersChart'), {
            type: 'bar',
            data: {
                labels: usersData.map(u => u.user),
                datasets: [{
                    label: 'GB', data: usersData.map(u => +(u.bytes / 1073741824).toFixed(2)),
                    backgroundColor: '#2a78d6'
                }]
            },
            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                       layout: { padding: 12 },
                       plugins: { legend: { display: false } } }
        });

        new Chart(document.getElementById('catChart'), {
            type: 'doughnut',
            data: {
                labels: catData.map(c => c.category),
                datasets: [{
                    data: catData.map(c => c.hits),
                    backgroundColor: ['#2a78d6','#1baf7a','#eda100','#e34948','#4a3aa7','#e87ba4','#eb6834','#888787']
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, layout: { padding: 12 } }
        });

        new Chart(document.getElementById('socialChart'), {
            type: 'bar',
            data: {
                labels: socialDaily.map(d => d.day),
                datasets: [
                    { label: 'Liberado', data: socialDaily.map(d => d.allowed), backgroundColor: '#1baf7a' },
                    { label: 'Bloqueado', data: socialDaily.map(d => d.blocked), backgroundColor: '#e34948' }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                layout: { padding: 12 },
                scales: { x: { stacked: true }, y: { stacked: true } }
            }
        });
        </script>
<?php endif; ?>

    </div>
</div>

<?php include("foot.inc"); ?>
</body>
</html>
