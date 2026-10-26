<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$user = require_login();
$page = 'statistics';
$page_title = 'Personnel Statistics';
$pdo = db();
[$scopeW, $scopeP] = scope_where($user, 'e');

function stats_dimensions(): array {
    $regionJoin = 'LEFT JOIN regions rg ON rg.id=e.region_id';
    $divJoin    = 'LEFT JOIN divisions dv ON dv.id=e.division_id';
    $stationJoin= 'LEFT JOIN stations st ON st.id=e.station_id';
    $postJoin   = 'LEFT JOIN posts pt ON pt.id=e.post_id';
    $dirName    = "COALESCE(NULLIF(TRIM(e.directorate),''),'Unassigned')";
    $unitName   = "COALESCE(NULLIF(TRIM(e.unit),''),'Unassigned')";
    $rankName   = "COALESCE(NULLIF(TRIM(e.rank),''),'Unassigned')";

    return [
        'region' => [
            'label' => 'Regions', 'singular' => 'Region', 'column' => 'Region',
            'joins' => $regionJoin,
            'group' => 'COALESCE(e.region_id,0)',
            'name'  => "COALESCE(rg.name,'Unassigned')",
            'parent'=> "''",
        ],
        'division' => [
            'label' => 'Divisions / Districts', 'singular' => 'Division / District', 'column' => 'Division / District',
            'joins' => $regionJoin . ' ' . $divJoin,
            'group' => "COALESCE(e.region_id,0) || ':' || COALESCE(e.division_id,0)",
            'name'  => "COALESCE(dv.name,'Unassigned')",
            'parent'=> "COALESCE(rg.name,'Unassigned')",
        ],
        'station' => [
            'label' => 'Stations', 'singular' => 'Station', 'column' => 'Station',
            'joins' => $regionJoin . ' ' . $divJoin . ' ' . $stationJoin,
            'group' => "COALESCE(e.region_id,0) || ':' || COALESCE(e.division_id,0) || ':' || COALESCE(e.station_id,0)",
            'name'  => "COALESCE(st.name,'Unassigned')",
            'parent'=> "COALESCE(dv.name,'Unassigned') || ' / ' || COALESCE(rg.name,'Unassigned')",
        ],
        'post' => [
            'label' => 'Posts', 'singular' => 'Post', 'column' => 'Post',
            'joins' => $regionJoin . ' ' . $divJoin . ' ' . $stationJoin . ' ' . $postJoin,
            'group' => "COALESCE(e.region_id,0) || ':' || COALESCE(e.division_id,0) || ':' || COALESCE(e.station_id,0) || ':' || COALESCE(e.post_id,0)",
            'name'  => "COALESCE(pt.name,'Unassigned')",
            'parent'=> "COALESCE(st.name,'Unassigned') || ' / ' || COALESCE(dv.name,'Unassigned')",
        ],
        'directorate' => [
            'label' => 'Directorates', 'singular' => 'Directorate', 'column' => 'Directorate',
            'joins' => '',
            'group' => $dirName,
            'name'  => $dirName,
            'parent'=> "''",
        ],
        'unit' => [
            'label' => 'Units', 'singular' => 'Unit', 'column' => 'Unit',
            'joins' => '',
            'group' => $dirName . " || ':' || " . $unitName,
            'name'  => $unitName,
            'parent'=> $dirName,
        ],
        'rank' => [
            'label' => 'Ranks', 'singular' => 'Rank', 'column' => 'Rank',
            'joins' => '',
            'group' => $rankName,
            'name'  => $rankName,
            'parent'=> "''",
        ],
    ];
}

function stats_rank_canonical(string $rank): string {
    $raw = trim($rank);
    if ($raw === '') return 'Unassigned';
    $upper = strtoupper($raw);
    foreach (UPF_RANKS as $known) {
        if (strtoupper($known) === $upper) return $known;
    }
    foreach (UPF_RANK_ALIASES as $alias => $canon) {
        if (strcasecmp($alias, $raw) === 0) return $canon;
    }
    return $raw;
}

function stats_rank_position(string $rank): int {
    $needle = strtoupper(stats_rank_canonical($rank));
    foreach (UPF_RANKS as $i => $r) {
        if (strtoupper($r) === $needle) return $i;
    }
    return 999;
}

function stats_group_count(PDO $pdo, string $scopeW, array $scopeP, array $def): int {
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT {$def['group']}) FROM employees e {$def['joins']} WHERE e.active=1 AND $scopeW");
    $stmt->execute($scopeP);
    return (int)$stmt->fetchColumn();
}

$dimensions = stats_dimensions();
$dimension = (string)($_GET['dimension'] ?? 'region');
if (!isset($dimensions[$dimension])) $dimension = 'region';

$sort = (string)($_GET['sort'] ?? ($dimension === 'rank' ? 'rank' : 'name_asc'));
$sortOptions = [
    'name_asc'   => 'Name A-Z',
    'name_desc'  => 'Name Z-A',
    'total_desc' => 'Highest strength',
    'total_asc'  => 'Lowest strength',
];
if ($dimension === 'rank') $sortOptions = ['rank' => 'Rank seniority'] + $sortOptions;
if (!isset($sortOptions[$sort])) $sort = $dimension === 'rank' ? 'rank' : 'name_asc';
$search = trim((string)($_GET['q'] ?? ''));

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM employees e WHERE e.active=1 AND $scopeW");
$totalStmt->execute($scopeP);
$totalPersonnel = (int)$totalStmt->fetchColumn();

$rankStmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(e.rank),''),'Unassigned') AS rnk, COUNT(*) AS cnt
                           FROM employees e WHERE e.active=1 AND $scopeW GROUP BY rnk");
$rankStmt->execute($scopeP);
$rankCounts = [];
foreach ($rankStmt->fetchAll() as $r) {
    $rank = stats_rank_canonical((string)$r['rnk']);
    $rankCounts[$rank] = ($rankCounts[$rank] ?? 0) + (int)$r['cnt'];
}
uksort($rankCounts, function ($a, $b) {
    $pa = stats_rank_position($a);
    $pb = stats_rank_position($b);
    return $pa <=> $pb ?: strcasecmp($a, $b);
});
$rankCols = array_keys($rankCounts);
$rankTotal = array_sum($rankCounts);

$dimCounts = [];
foreach ($dimensions as $key => $def) {
    $dimCounts[$key] = stats_group_count($pdo, $scopeW, $scopeP, $def);
}
$dimCounts['rank'] = count($rankCols);

$def = $dimensions[$dimension];
$aggStmt = $pdo->prepare("SELECT {$def['group']} AS gid, {$def['name']} AS gname, {$def['parent']} AS gparent,
                                 COALESCE(NULLIF(TRIM(e.rank),''),'Unassigned') AS rnk, COUNT(*) AS cnt
                          FROM employees e {$def['joins']}
                          WHERE e.active=1 AND $scopeW
                          GROUP BY 1,2,3,4
                          ORDER BY gname COLLATE NOCASE");
$aggStmt->execute($scopeP);
$groups = [];
foreach ($aggStmt->fetchAll() as $r) {
    $rank = stats_rank_canonical((string)$r['rnk']);
    $key = $dimension === 'rank'
        ? 'rank|' . $rank
        : (string)$r['gid'] . '|' . (string)$r['gname'];
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'name' => $dimension === 'rank' ? $rank : (string)$r['gname'],
            'parent' => (string)$r['gparent'],
            'counts' => [],
            'total' => 0,
        ];
    }
    $count = (int)$r['cnt'];
    $groups[$key]['counts'][$rank] = ($groups[$key]['counts'][$rank] ?? 0) + $count;
    $groups[$key]['total'] += $count;
}
$groups = array_values($groups);

if ($search !== '') {
    $groups = array_values(array_filter($groups, function ($g) use ($search) {
        return stripos($g['name'], $search) !== false || stripos($g['parent'], $search) !== false;
    }));
}

usort($groups, function ($a, $b) use ($sort) {
    if ($sort === 'total_desc') {
        return ($b['total'] <=> $a['total']) ?: strcasecmp($a['name'], $b['name']);
    }
    if ($sort === 'total_asc') {
        return ($a['total'] <=> $b['total']) ?: strcasecmp($a['name'], $b['name']);
    }
    if ($sort === 'rank') {
        return (stats_rank_position($a['name']) <=> stats_rank_position($b['name'])) ?: strcasecmp($a['name'], $b['name']);
    }
    if ($sort === 'name_desc') {
        return strcasecmp($b['name'], $a['name']) ?: strcasecmp($b['parent'], $a['parent']);
    }
    return strcasecmp($a['name'], $b['name']) ?: strcasecmp($a['parent'], $b['parent']);
});

$filteredTotal = 0;
$rankTotals = array_fill_keys($rankCols, 0);
foreach ($groups as $g) {
    $filteredTotal += $g['total'];
    foreach ($rankCols as $rank) {
        $rankTotals[$rank] += (int)($g['counts'][$rank] ?? 0);
    }
}
$groupCount = count($groups);
$avgPerGroup = $groupCount ? round($filteredTotal / $groupCount, 1) : 0;

$exportRows = [];
$exportRows[] = [APP_ORG];
$exportRows[] = [APP_NAME];
$exportRows[] = ['Personnel Statistics by ' . $def['label']];
$exportRows[] = ['Scope: ' . user_scope_label($user)];
$exportRows[] = ['Generated: ' . date('Y-m-d H:i') . ' by ' . $user['full_name']];
if ($search !== '') $exportRows[] = ['Search: ' . $search];
$exportRows[] = [];
if ($dimension !== 'rank') {
    $exportRows[] = ['Rank', 'Count', 'Share %'];
    foreach ($rankCounts as $rank => $count) {
        $exportRows[] = [$rank, $count, ($rankTotal ? round($count / $rankTotal * 100, 1) : 0) . '%'];
    }
    $exportRows[] = ['TOTAL', $rankTotal, '100%'];
    $exportRows[] = [];
}
if ($dimension === 'rank') {
    $exportRows[] = ['Rank', 'Count', 'Share %'];
    foreach ($groups as $g) {
        $exportRows[] = [$g['name'], $g['total'], ($filteredTotal ? round($g['total'] / $filteredTotal * 100, 1) : 0) . '%'];
    }
    $exportRows[] = ['TOTAL', $filteredTotal, $filteredTotal ? '100%' : '0%'];
} else {
    $exportRows[] = array_merge([$def['column'], 'Context'], $rankCols, ['Total', 'Share %']);
    foreach ($groups as $g) {
        $row = [$g['name'], $g['parent']];
        foreach ($rankCols as $rank) $row[] = (int)($g['counts'][$rank] ?? 0);
        $row[] = $g['total'];
        $row[] = ($filteredTotal ? round($g['total'] / $filteredTotal * 100, 1) : 0) . '%';
        $exportRows[] = $row;
    }
    $totalRow = ['TOTAL', ''];
    foreach ($rankCols as $rank) $totalRow[] = $rankTotals[$rank];
    $totalRow[] = $filteredTotal;
    $totalRow[] = $filteredTotal ? '100%' : '0%';
    $exportRows[] = $totalRow;
}

$format = (string)($_GET['format'] ?? '');
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="personnel-statistics-' . $dimension . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    foreach ($exportRows as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}

if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="personnel-statistics-' . $dimension . '-' . date('Y-m-d') . '.xls"');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
    echo '<Worksheet ss:Name="Personnel Statistics"><Table>';
    foreach ($exportRows as $row) {
        echo '<Row>';
        foreach ($row as $value) {
            $isNumber = is_int($value) || (is_string($value) && $value !== '' && ctype_digit($value));
            echo '<Cell><Data ss:Type="' . ($isNumber ? 'Number' : 'String') . '">' . htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Data></Cell>';
        }
        echo '</Row>';
    }
    echo '</Table></Worksheet></Workbook>';
    exit;
}

$exportQs = ['dimension' => $dimension, 'sort' => $sort];
if ($search !== '') $exportQs['q'] = $search;
$csvUrl = '/statistics.php?' . http_build_query(array_merge($exportQs, ['format' => 'csv']));
$excelUrl = '/statistics.php?' . http_build_query(array_merge($exportQs, ['format' => 'excel']));

$tabUrl = function (string $key) use ($dimensions, $sort, $search): string {
    $qs = ['dimension' => $key];
    $allowed = $key === 'rank'
        ? ['rank', 'name_asc', 'name_desc', 'total_desc', 'total_asc']
        : ['name_asc', 'name_desc', 'total_desc', 'total_asc'];
    $qs['sort'] = in_array($sort, $allowed, true) ? $sort : ($key === 'rank' ? 'rank' : 'name_asc');
    if ($search !== '') $qs['q'] = $search;
    return '/statistics.php?' . http_build_query($qs);
};
$resetUrl = '/statistics.php?dimension=' . urlencode($dimension);

include __DIR__ . '/../includes/header.php';
?>
<style>
  .statistics-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 14px}
  .statistics-tab{display:inline-flex;align-items:center;gap:7px;padding:7px 11px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--navy-800);font-size:12px;font-weight:600;text-decoration:none;transition:background .12s,border-color .12s,color .12s}
  .statistics-tab:hover{background:var(--navy-50);border-color:var(--primary);text-decoration:none}
  .statistics-tab.is-active{background:var(--primary);border-color:var(--primary);color:#fff}
  .statistics-tab .n{min-width:20px;padding:1px 5px;border-radius:999px;background:var(--navy-100);color:var(--navy-800);font-size:10px;text-align:center}
  .statistics-tab.is-active .n{background:rgba(255,255,255,.2);color:#fff}
  .rank-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(145px,1fr));gap:8px}
  .rank-card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:12px 13px;box-shadow:var(--shadow-sm);min-width:0}
  .rank-card .rk{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .rank-card .rv{font-size:24px;font-weight:700;color:var(--navy-800);line-height:1.15;margin-top:3px}
  .rank-card .rp{font-size:10.5px;color:var(--muted);margin-top:2px}
  .rank-card .rbar{height:5px;border-radius:999px;background:var(--navy-50);overflow:hidden;margin-top:8px}
  .rank-card .rbar span{display:block;height:100%;border-radius:999px;background:var(--primary)}
  .statistics-table th.num,.statistics-table td.num{text-align:right;white-space:nowrap}
  .statistics-table td.is-zero{color:var(--muted)}
  .statistics-table th:first-child,.statistics-table td:first-child{position:sticky;left:0;background:var(--card);z-index:1}
  .statistics-table th:first-child{z-index:2}
  .statistics-table tfoot td{font-weight:700;background:var(--navy-50)}
  .statistics-table tfoot td:first-child{background:var(--navy-50)}
  .statistics-tools{display:flex;justify-content:space-between;align-items:flex-end;gap:10px 16px;flex-wrap:wrap;margin-bottom:12px}
  .statistics-tools .filter-bar{flex:1 1 520px;margin:0}
  .statistics-export{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  @media (max-width:560px){
    .statistics-table th:first-child,.statistics-table td:first-child{position:static}
    .statistics-export{width:100%}
    .statistics-export .btn-icon{flex:1;justify-content:center}
  }
  @media print{
    .topbar,.sidebar,.page-header .action-bar,.statistics-tabs,.statistics-tools,.pager{display:none!important}
    .content{padding:0!important}
    .card{break-inside:avoid;box-shadow:none}
    .rank-grid{grid-template-columns:repeat(4,1fr)}
    body{background:#fff;color:#111}
    .statistics-table th:first-child,.statistics-table td:first-child{position:static}
  }
</style>

<div class="page-header">
  <div>
    <h1>Personnel Statistics</h1>
    <div class="desc">Strength by rank across regions, divisions, stations, posts, directorates and units — <?= e(user_scope_label($user)) ?></div>
  </div>
  <div class="action-bar">
    <a class="btn-icon bi-secondary btn-labeled" href="<?= e($csvUrl) ?>" title="Export current breakdown as CSV"><?= ICO_DL ?><span>CSV</span></a>
    <a class="btn-icon bi-secondary btn-labeled" href="<?= e($excelUrl) ?>" title="Export current breakdown as Excel"><?= ICO_DL ?><span>Excel</span></a>
    <button class="btn-icon bi-secondary btn-labeled" type="button" onclick="window.print()" title="Print / save as PDF"><?= ICO_PRINT ?><span>Print</span></button>
  </div>
</div>

<div class="grid grid-4" style="margin-bottom:12px">
  <div class="stat stat-on_duty">
    <div>
      <div class="label">Total personnel</div>
      <div class="value"><?= number_format($totalPersonnel) ?></div>
      <div class="hint">Active personnel in scope</div>
    </div>
    <div class="icon" style="color:#0891b2">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 1 1 0 7.75"/></svg>
    </div>
  </div>
  <div class="stat stat-leave">
    <div>
      <div class="label">Ranks represented</div>
      <div class="value"><?= number_format(count($rankCols)) ?></div>
      <div class="hint">Distinct ranks in scope</div>
    </div>
    <div class="icon" style="color:#2563eb">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.4 5.2 5.6.7-4.1 3.9 1 5.6-4.9-2.7-4.9 2.7 1-5.6L4 7.9l5.6-.7z"/></svg>
    </div>
  </div>
  <div class="stat stat-present">
    <div>
      <div class="label"><?= e($def['label']) ?> in view</div>
      <div class="value"><?= number_format($groupCount) ?></div>
      <div class="hint"><?= $search !== '' ? 'Matching current search' : 'Groups in scope' ?></div>
    </div>
    <div class="icon" style="color:#16a34a">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 9h1"/><path d="M14 9h1"/><path d="M9 13h1"/><path d="M14 13h1"/><rect x="8" y="16" width="8" height="5"/></svg>
    </div>
  </div>
  <div class="stat stat-sick">
    <div>
      <div class="label">Average per <?= e(strtolower($def['singular'])) ?></div>
      <div class="value"><?= number_format($avgPerGroup, 1) ?></div>
      <div class="hint"><?= number_format($filteredTotal) ?> personnel in view</div>
    </div>
    <div class="icon" style="color:#d97706">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>
    </div>
  </div>
</div>

<div class="card" style="margin-bottom:12px">
  <div class="chr">
    <h3>Strength by Rank <span class="badge badge-admin"><?= number_format($rankTotal) ?></span></h3>
  </div>
  <div class="rank-grid">
    <?php foreach ($rankCounts as $rank => $count): $pct = $rankTotal ? round($count / $rankTotal * 100, 1) : 0; ?>
    <div class="rank-card">
      <div class="rk" title="<?= e($rank) ?>"><?= e($rank) ?></div>
      <div class="rv"><?= number_format($count) ?></div>
      <div class="rp"><?= number_format($pct, 1) ?>% of strength</div>
      <div class="rbar"><span style="width:<?= max(0, min(100, $pct)) ?>%"></span></div>
    </div>
    <?php endforeach; ?>
    <?php if (!$rankCounts): ?><div class="muted" style="padding:12px 2px">No active personnel in your command scope.</div><?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="chr">
    <div>
      <h3>Breakdown by <?= e($def['label']) ?></h3>
      <div class="muted" style="font-size:12px;margin-top:2px"><?= number_format($filteredTotal) ?> personnel shown across <?= number_format($groupCount) ?> <?= e(strtolower($def['label'])) ?></div>
    </div>
  </div>

  <div class="statistics-tabs">
    <?php foreach ($dimensions as $key => $dim): ?>
    <a class="statistics-tab <?= $key === $dimension ? 'is-active' : '' ?>" href="<?= e($tabUrl($key)) ?>">
      <?= e($dim['label']) ?><span class="n"><?= number_format($dimCounts[$key]) ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <div class="statistics-tools">
    <form method="get" action="/statistics.php" class="filter-bar">
      <input type="hidden" name="dimension" value="<?= e($dimension) ?>">
      <div class="filter-field search">
        <label for="stat-q">Search <?= e(strtolower($def['singular'])) ?></label>
        <div class="filter-search">
          <?= ICO_SEARCH ?>
          <input type="search" id="stat-q" name="q" value="<?= e($search) ?>" placeholder="Search name or context...">
        </div>
      </div>
      <div class="filter-field">
        <label for="stat-sort">Sort by</label>
        <select name="sort" id="stat-sort">
          <?php foreach ($sortOptions as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $sort === $key ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-actions">
        <button class="btn-icon bi-primary btn-labeled" type="submit" title="Apply"><?= ICO_SEARCH ?><span>Apply</span></button>
        <?php if ($search !== ''): ?>
        <a class="btn-icon bi-secondary btn-labeled" href="<?= e($resetUrl) ?>" title="Clear search"><?= ICO_CANCEL ?><span>Reset</span></a>
        <?php endif; ?>
      </div>
    </form>
    <div class="statistics-export">
      <a class="btn-icon bi-secondary btn-labeled" href="<?= e($csvUrl) ?>" title="Export CSV"><?= ICO_DL ?><span>CSV</span></a>
      <a class="btn-icon bi-secondary btn-labeled" href="<?= e($excelUrl) ?>" title="Export Excel"><?= ICO_DL ?><span>Excel</span></a>
      <button class="btn-icon bi-secondary btn-labeled" type="button" onclick="window.print()" title="Print / PDF"><?= ICO_PRINT ?><span>Print</span></button>
    </div>
  </div>

  <?php $showRankMatrix = $dimension !== 'rank'; ?>
  <div class="table-wrap">
    <table class="statistics-table">
      <thead>
        <tr>
          <th><?= e($def['column']) ?></th>
          <?php if ($showRankMatrix): foreach ($rankCols as $rank): ?><th class="num"><?= e($rank) ?></th><?php endforeach; endif; ?>
          <th class="num">Total</th>
          <th class="num">Share</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($groups as $g): ?>
        <tr>
          <td>
            <strong><?= e($g['name']) ?></strong>
            <?php if ($g['parent'] !== ''): ?><div class="muted" style="font-size:11px"><?= e($g['parent']) ?></div><?php endif; ?>
          </td>
          <?php if ($showRankMatrix): foreach ($rankCols as $rank): $value = (int)($g['counts'][$rank] ?? 0); ?>
          <td class="num <?= $value ? '' : 'is-zero' ?>"><?= $value ?: '—' ?></td>
          <?php endforeach; endif; ?>
          <td class="num"><strong><?= number_format($g['total']) ?></strong></td>
          <td class="num"><?= $filteredTotal ? number_format($g['total'] / $filteredTotal * 100, 1) : '0.0' ?>%</td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$groups): ?>
        <tr><td colspan="<?= ($showRankMatrix ? count($rankCols) : 0) + 3 ?>" style="text-align:center;color:var(--muted);padding:24px"><?= $search !== '' ? 'No groups match your search.' : 'No personnel data available in your command scope.' ?></td></tr>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <td>TOTAL</td>
          <?php if ($showRankMatrix): foreach ($rankCols as $rank): ?><td class="num"><?= number_format($rankTotals[$rank]) ?></td><?php endforeach; endif; ?>
          <td class="num"><?= number_format($filteredTotal) ?></td>
          <td class="num"><?= $filteredTotal ? '100.0%' : '0.0%' ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
