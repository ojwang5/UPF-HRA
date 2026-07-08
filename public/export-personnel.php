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
$logoB64 = 'data:image/jpeg;base64,' . base64_encode(file_get_contents(__DIR__ . '/assets/logo.jpg'));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($title) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,sans-serif;font-size:11px;color:#111;background:#fff}
.cover{display:flex;align-items:center;gap:20px;padding:20px 20px 16px;border-bottom:3px solid #d4a017;margin-bottom:16px;background:linear-gradient(135deg,#1f3559 0%,#15243d 100%);color:#fff}
.cover-logo{width:70px;height:70px;border-radius:50%;border:2px solid #d4a017;object-fit:cover;flex-shrink:0;background:#fff;padding:2px}
.cover-text{flex:1}
.cover-text .org{font-size:16px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase}
.cover-text .sys{font-size:11px;opacity:.85;margin-top:2px;letter-spacing:.5px}
.cover-text .motto{font-size:9px;font-style:italic;color:#d4a017;letter-spacing:3px;margin-top:4px}
.cover-meta{text-align:right;font-size:10px;opacity:.8;line-height:1.6}
.cover-meta strong{color:#d4a017;font-size:12px;display:block;margin-bottom:4px}
table{width:100%;border-collapse:collapse;margin-bottom:20px}
th{background:#1f3559;color:#fff;padding:7px 9px;font-size:10px;text-align:left;letter-spacing:.5px;text-transform:uppercase}
td{padding:5px 9px;border-bottom:1px solid #e5e9f0;font-size:10px}
tr:nth-child(even) td{background:#f8f9fc}
.summary{font-size:11px;color:#555;margin-bottom:12px;padding:8px 20px;background:#f8f9fc;border-left:3px solid #1f3559}
.print-btn{display:inline-block;margin-left:12px;padding:4px 12px;background:#1f3559;color:#fff;border:none;border-radius:5px;font-size:11px;cursor:pointer;text-decoration:none}
.footer-bar{text-align:center;font-size:9px;color:#aaa;padding:14px 20px;border-top:1px solid #e5e9f0;margin-top:10px}
@media print{
  body{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .no-print{display:none !important}
  .cover{-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style>
</head>
<body>
<div class="cover">
  <img src="<?= $logoB64 ?>" alt="UPF Logo" class="cover-logo">
  <div class="cover-text">
    <div class="org">Uganda Police Force</div>
    <div class="sys">MDD Management System — Personnel Register</div>
    <div class="motto">Protect &amp; Serve</div>
  </div>
  <div class="cover-meta">
    <strong><?= htmlspecialchars(user_scope_label($user)) ?></strong>
    Generated: <?= date('j F Y') ?><br>
    <?= date('H:i') ?> hrs &nbsp;·&nbsp; <?= htmlspecialchars($user['full_name']) ?><br>
    <?= htmlspecialchars(role_label($user['role'])) ?>
    <?php if ($search): ?><br>Filter: "<?= htmlspecialchars($search) ?>"<?php endif; ?>
  </div>
</div>

<div class="summary no-print" style="padding:8px 20px 10px;display:flex;align-items:center;gap:10px">
  <strong><?= count($rows) ?></strong> personnel record(s)
  <a href="javascript:window.print()" class="print-btn">🖨 Print / Save as PDF</a>
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
