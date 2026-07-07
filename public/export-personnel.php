<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$user = require_login();
$pdo  = db();
[$scopeW, $scopeP] = scope_where($user, 'e');

$format = $_POST['format'] ?? $_GET['format'] ?? 'csv';
$search = trim($_POST['q'] ?? $_GET['q'] ?? '');
$requestedCols = $_POST['cols'] ?? $_GET['cols'] ?? null;

$allColDefs = [
  'service_no'    => 'File/Force No',
  'rank'          => 'Rank',
  'full_name'     => 'Full Name',
  'gender'        => 'Gender',
  'directorate'   => 'Directorate',
  'unit'          => 'Unit',
  'region_name'   => 'Region',
  'division_name' => 'Division',
  'station_name'  => 'Station',
  'post_name'     => 'Post',
  'email'         => 'Email',
  'phone'         => 'Phone',
];

$selectedCols = is_array($requestedCols)
    ? array_filter($requestedCols, fn($c) => isset($allColDefs[$c]))
    : array_keys($allColDefs);
if (empty($selectedCols)) $selectedCols = array_keys($allColDefs);

// Build query
$where  = "e.active=1 AND $scopeW";
$params = $scopeP;
if ($search !== '') {
    $where .= ' AND (e.full_name LIKE ? OR e.service_no LIKE ? OR e.rank LIKE ? OR e.directorate LIKE ?)';
    $s = "%$search%";
    array_push($params, $s, $s, $s, $s);
}

$stmt = $pdo->prepare("SELECT e.service_no, e.rank, e.full_name, e.gender, e.directorate, e.unit,
                              e.email, e.phone,
                              rg.name AS region_name, dv.name AS division_name,
                              st.name AS station_name, pt.name AS post_name
                       FROM employees e
                       LEFT JOIN regions   rg ON rg.id=e.region_id
                       LEFT JOIN divisions dv ON dv.id=e.division_id
                       LEFT JOIN stations  st ON st.id=e.station_id
                       LEFT JOIN posts     pt ON pt.id=e.post_id
                       WHERE $where ORDER BY rg.name, dv.name, st.name, pt.name, e.full_name");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$title = 'UPF Personnel Export — '.date('Y-m-d');

/* ── CSV ── */
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="personnel-'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel
    fputcsv($out, array_values(array_intersect_key($allColDefs, array_flip($selectedCols))));
    foreach ($rows as $r) {
        $row = [];
        foreach ($selectedCols as $col) $row[] = $r[$col] ?? '';
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

/* ── Excel (HTML-table with XLS MIME) ── */
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="personnel-'.date('Y-m-d').'.xls"');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
    echo '<Worksheet ss:Name="Personnel"><Table>';
    // Header row
    echo '<Row>';
    foreach ($selectedCols as $col) echo '<Cell><Data ss:Type="String">'.htmlspecialchars($allColDefs[$col]).'</Data></Cell>';
    echo '</Row>';
    foreach ($rows as $r) {
        echo '<Row>';
        foreach ($selectedCols as $col) echo '<Cell><Data ss:Type="String">'.htmlspecialchars($r[$col]??'').'</Data></Cell>';
        echo '</Row>';
    }
    echo '</Table></Worksheet></Workbook>';
    exit;
}

/* ── PDF (print-ready HTML) ── */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($title) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,sans-serif;font-size:11px;color:#111;background:#fff}
.cover{text-align:center;padding:30px 20px;border-bottom:2px solid #1f3559;margin-bottom:16px}
.cover h1{font-size:17px;font-weight:700;color:#1f3559;letter-spacing:1px;margin-bottom:4px}
.cover .sub{font-size:11px;color:#555}
.cover .meta{font-size:10px;color:#888;margin-top:6px}
table{width:100%;border-collapse:collapse;margin-bottom:20px}
th{background:#1f3559;color:#fff;padding:6px 8px;font-size:10px;text-align:left;letter-spacing:.5px}
td{padding:5px 8px;border-bottom:1px solid #e5e9f0;font-size:10px}
tr:nth-child(even) td{background:#f8f9fc}
.summary{font-size:11px;color:#555;margin-bottom:12px}
@media print{
  body{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .no-print{display:none}
}
</style>
</head>
<body>
<div class="cover">
  <h1>UGANDA POLICE FORCE — MDD MANAGEMENT SYSTEM</h1>
  <div class="sub">Personnel Register</div>
  <div class="meta">Scope: <?= htmlspecialchars(user_scope_label($user)) ?> &nbsp;|&nbsp; Generated: <?= date('j F Y, H:i') ?> &nbsp;|&nbsp; By: <?= htmlspecialchars($user['full_name']) ?> (<?= htmlspecialchars(role_label($user['role'])) ?>)</div>
  <?php if ($search): ?><div class="meta">Search filter: "<?= htmlspecialchars($search) ?>"</div><?php endif; ?>
</div>

<div class="summary no-print" style="padding:0 20px 10px">
  <strong><?= count($rows) ?></strong> personnel record(s) | <a href="javascript:window.print()">🖨 Print / Save as PDF</a>
</div>

<div style="padding:0 10px">
<table>
  <thead><tr>
    <?php foreach ($selectedCols as $col): ?><th><?= htmlspecialchars($allColDefs[$col]) ?></th><?php endforeach; ?>
  </tr></thead>
  <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
      <?php foreach ($selectedCols as $col): ?>
      <td><?= htmlspecialchars($r[$col] ?? '') ?></td>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
    <tr><td colspan="<?= count($selectedCols) ?>" style="text-align:center;padding:20px;color:#888">No records found.</td></tr>
    <?php endif; ?>
  </tbody>
</table>
</div>

<script>window.onload=function(){ if(window.location.search.includes('autoprint')) window.print(); }</script>
</body>
</html>
