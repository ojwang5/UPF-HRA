<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$user = require_login();
$pdo  = db();
[$scopeW, $scopeP] = scope_where($user, 'e');

$format        = $_POST['format'] ?? $_GET['format'] ?? 'csv';
$search        = trim($_POST['q'] ?? $_GET['q'] ?? '');
$requestedCols = $_POST['cols'] ?? $_GET['cols'] ?? null;
$statusFilter  = $_GET['status'] ?? $_POST['status'] ?? '';   // e.g. present, awol, sick …
$dateFilter    = $_GET['date']   ?? $_POST['date']   ?? date('Y-m-d');
$validStatuses = ['present','awol','leave','sick','suspended','disciplinary','on_duty','on_course','deserted','unrecorded'];
if ($statusFilter !== '' && !in_array($statusFilter, $validStatuses, true)) $statusFilter = '';

/* ── Column definitions ── */
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
// When filtering by status, inject a status column automatically
if ($statusFilter !== '') {
    $allColDefs = ['service_no' => 'File/Force No', 'rank' => 'Rank', 'full_name' => 'Full Name',
                   'gender' => 'Gender', 'ds_status' => 'Status', 'ds_notes' => 'Notes',
                   'directorate' => 'Directorate', 'unit' => 'Unit',
                   'station_name' => 'Station', 'post_name' => 'Post',
                   'email' => 'Email', 'phone' => 'Phone'];
}

$selectedCols = is_array($requestedCols)
    ? array_filter($requestedCols, fn($c) => isset($allColDefs[$c]))
    : array_keys($allColDefs);
if (empty($selectedCols)) $selectedCols = array_keys($allColDefs);

/* ── Build query ── */
$where  = "e.active=1 AND $scopeW";
$params = $scopeP;

if ($statusFilter === 'unrecorded') {
    // Employees with NO record on that date
    $where .= " AND NOT EXISTS (SELECT 1 FROM daily_status ds WHERE ds.employee_id=e.id AND ds.date=?)";
    $params[] = $dateFilter;
    $dsSelect  = "NULL AS ds_status, NULL AS ds_notes";
    $dsJoin    = "";
} elseif ($statusFilter !== '') {
    // Employees with a specific status on that date
    $where    .= " AND ds.status=?";
    $params[]  = $statusFilter;
    $dsSelect  = "ds.status AS ds_status, ds.notes AS ds_notes";
    $dsJoin    = "INNER JOIN daily_status ds ON ds.employee_id=e.id AND ds.date=?";
    array_unshift($params, $dateFilter);   // date goes before scope params in JOIN
    // Re-build: date first, then scope
    $params = array_merge([$dateFilter], $scopeP, [$statusFilter]);
} else {
    $dsSelect = "";
    $dsJoin   = "";
}

if ($search !== '') {
    $where .= ' AND (e.full_name LIKE ? OR e.service_no LIKE ? OR e.rank LIKE ? OR e.directorate LIKE ?)';
    $s = "%$search%";
    array_push($params, $s, $s, $s, $s);
}

$extraSel = $dsSelect ? ", $dsSelect" : "";
$sql = "SELECT e.service_no, e.rank, e.full_name, e.gender, e.directorate, e.unit,
               e.email, e.phone,
               rg.name AS region_name, dv.name AS division_name,
               st.name AS station_name, pt.name AS post_name
               $extraSel
        FROM employees e
        $dsJoin
        LEFT JOIN regions   rg ON rg.id=e.region_id
        LEFT JOIN divisions dv ON dv.id=e.division_id
        LEFT JOIN stations  st ON st.id=e.station_id
        LEFT JOIN posts     pt ON pt.id=e.post_id
        WHERE $where
        ORDER BY rg.name, dv.name, st.name, pt.name, e.full_name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

/* ── Human-readable labels for title ── */
$statusLabels = ['present'=>'Present','awol'=>'AWOL','leave'=>'On Leave','sick'=>'Sick',
                 'suspended'=>'Suspended','disciplinary'=>'Under Disciplinary','on_duty'=>'On Duty',
                 'on_course'=>'On Course','deserted'=>'Deserted','unrecorded'=>'Unrecorded'];
$statusLabel = $statusFilter !== '' ? ($statusLabels[$statusFilter] ?? ucfirst($statusFilter)) : 'All Personnel';
$dateFmt     = date('Y-m-d', strtotime($dateFilter));
$title       = 'UPF Personnel — '.$statusLabel.' — '.$dateFmt;

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
$reportScope = user_scope_label($user);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($title) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,sans-serif;font-size:11px;color:#111;background:#fff;padding:18px 24px}

/* ── Official UPF Header ── */
.upf-header{border-bottom:2.5px solid #111;padding-bottom:10px;margin-bottom:0}
.upf-main-title{text-align:center;font-size:14px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px}
.upf-identity{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:10px}
.upf-contact{font-size:9.5px;line-height:1.75}
.upf-logo-wrap{text-align:center}
.upf-logo-wrap img{width:72px;height:72px;object-fit:contain}
.upf-address{text-align:right;font-size:9.5px;line-height:1.75}
.upf-report-label{text-align:center;margin-top:10px;padding:6px 0;border-top:1px solid #111;border-bottom:2px solid #111}
.upf-report-label .rpt-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.3px}
.upf-report-label .rpt-meta{font-size:10px;margin-top:2px}

/* ── Table ── */
table{width:100%;border-collapse:collapse;margin-top:14px;font-size:9.5px}
th{background:#1a2236;color:#fff;padding:6px 8px;text-align:left;font-size:9px;text-transform:uppercase;letter-spacing:.4px;border:1px solid #1a2236}
td{border:1px solid #c8cfd8;padding:4px 8px}
tr:nth-child(even) td{background:#f5f7fa}

/* ── Controls & footer ── */
.report-meta{font-size:9.5px;color:#555;margin:8px 0 12px;line-height:1.6}
.no-print-bar{margin-top:18px;display:flex;gap:10px;align-items:center}
.btn-print{padding:8px 18px;background:#1a2236;color:#fff;border:0;border-radius:5px;font-size:11px;cursor:pointer;font-weight:600}
.page-footer{margin-top:22px;border-top:1px solid #ccc;padding-top:6px;text-align:center;font-size:8.5px;color:#888}

@media print{
  body{padding:10px 14px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .no-print{display:none!important}
}
</style>
</head>
<body>

<!-- ═══ Official UPF Header ═══ -->
<div class="upf-header">
  <div class="upf-main-title">UGANDA POLICE FORCE-(UPF)</div>
  <div class="upf-identity">
    <div class="upf-contact">
      Telegram: "GENPOL"<br>
      Telephone: 0414-233814/0414-250613<br>
      Fax: (0414)-255630<br>
      E-mail: upf@upf.go.ug<br>
      Website: www.upf.go.ug
    </div>
    <div class="upf-logo-wrap">
      <img src="<?= $logoB64 ?>" alt="UPF Crest">
    </div>
    <div class="upf-address">
      Plot 1-3/5 Naguru Area<br>
      Police Headquarters<br>
      P.O. Box 7055 Kampala, Uganda<br>
      www.upf.go.ug
    </div>
  </div>
  <div class="upf-report-label">
    <div class="rpt-title">HUMAN RESOURCE ADMINISTRATION MANAGEMENT SYSTEM<?= $reportScope && $reportScope !== 'HQ' ? ' — '.htmlspecialchars($reportScope) : '' ?></div>
    <div class="rpt-meta">Generated on: <?= date('j/F/Y') ?><?php if ($search): ?> &nbsp;|&nbsp; Filter: "<?= htmlspecialchars($search) ?>"<?php endif; ?></div>
  </div>
</div>

<div class="report-meta">
  Generated by: <strong><?= htmlspecialchars($user['full_name']) ?></strong> (<?= htmlspecialchars(role_label($user['role'])) ?>)
  &nbsp;·&nbsp; Printed: <?= date('j F Y, H:i') ?> hrs
  &nbsp;·&nbsp; Total records: <strong><?= count($rows) ?></strong>
</div>

<table>
  <thead><tr>
    <th>#</th>
    <?php foreach ($selectedCols as $col): ?>
    <th><?= htmlspecialchars($allColDefs[$col]) ?></th>
    <?php endforeach; ?>
  </tr></thead>
  <tbody>
    <?php foreach ($rows as $i => $r): ?>
    <tr>
      <td><?= $i + 1 ?></td>
      <?php foreach ($selectedCols as $col): ?>
      <td><?= htmlspecialchars($r[$col] ?? '') ?></td>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
    <tr><td colspan="<?= count($selectedCols) + 1 ?>" style="text-align:center;padding:20px;color:#888">No records found.</td></tr>
    <?php endif; ?>
  </tbody>
</table>

<div class="page-footer">
  Uganda Police Force — Human Resoure Management System &nbsp;·&nbsp; <?= date('Y') ?> &nbsp;·&nbsp; PROTECT &amp; SERVE
</div>

<div class="no-print no-print-bar">
  <button class="btn-print" onclick="window.print()">🖨 Print / Save as PDF</button>
</div>

<script>window.onload=function(){ if(window.location.search.includes('autoprint')) window.print(); }</script>
</body>
</html>
