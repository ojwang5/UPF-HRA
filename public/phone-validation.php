<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/sms.php';

$user = require_login();
$page = 'phone_validation';
$page_title = 'Phone Validation';
$pdo = db();
[$scopeW, $scopeP] = scope_where($user, 'e');
$canNormalize = role_rank($user['role']) > role_rank('officer');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canNormalize) {
        http_response_code(403);
        flash('err', 'You do not have permission to update personnel phone numbers.');
        header('Location: /phone-validation.php');
        exit;
    }

    $employeeId = (int)($_POST['employee_id'] ?? 0);
    $phoneInput = trim((string)($_POST['phone'] ?? ''));
    $stmt = $pdo->prepare("SELECT e.phone, e.service_no, e.full_name
                           FROM employees e
                           WHERE e.id = ? AND $scopeW");
    $stmt->execute(array_merge([$employeeId], $scopeP));
    $employee = $stmt->fetch();

    if (!$employee) {
        flash('err', 'Personnel record not found in your command scope.');
    } elseif ($phoneInput === '' || ($normalized = normalize_sms_phone($phoneInput)) === null) {
        flash('err', 'Enter a valid phone number. Ugandan numbers can be entered as 07XXXXXXXX or +2567XXXXXXXX.');
    } else {
        $update = $pdo->prepare("UPDATE employees AS e SET phone = ?
                                 WHERE e.id = ? AND $scopeW AND e.phone IS ?");
        $update->execute(array_merge([$normalized, $employeeId], $scopeP, [$employee['phone']]));
        if ($update->rowCount() !== 1) {
            flash('err', 'Phone number was not updated. Reload the page and try again.');
        } else {
            log_activity(
                'Personnel phone number validated and normalized',
                'employee',
                personnel_label([
                    'service_no' => $employee['service_no'],
                    'full_name' => $employee['full_name'],
                ]),
                $employeeId
            );
            flash('msg', 'Phone number format validated and saved as ' . $normalized . '.');
        }
    }

    $redirect = '/phone-validation.php';
    $query = array_filter([
        'q' => trim((string)($_POST['q'] ?? '')),
        'status' => trim((string)($_POST['status'] ?? '')),
        'pg' => max(1, (int)($_POST['pg'] ?? 1)),
    ], static fn($value) => $value !== '' && $value !== 1);
    if ($query) $redirect .= '?' . http_build_query($query);
    header('Location: ' . $redirect);
    exit;
}

$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? 'all');
if (!in_array($statusFilter, ['all', 'valid', 'invalid', 'missing'], true)) $statusFilter = 'all';
$pageNum = max(1, (int)($_GET['pg'] ?? 1));
$where = $scopeW;
$params = $scopeP;
if ($search !== '') {
    $where .= ' AND (e.full_name LIKE ? OR e.service_no LIKE ? OR e.phone LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}

$stmt = $pdo->prepare("SELECT e.id, e.service_no, e.full_name, e.rank, e.phone
                       FROM employees e
                       WHERE $where
                       ORDER BY " . personnel_order_sql('e'));
$stmt->execute($params);
$employees = [];
$counts = ['valid' => 0, 'invalid' => 0, 'missing' => 0];
foreach ($stmt->fetchAll() as $employee) {
    $rawPhone = trim((string)($employee['phone'] ?? ''));
    $normalizedPhone = $rawPhone !== '' ? normalize_sms_phone($rawPhone) : null;
    $phoneStatus = $rawPhone === '' ? 'missing' : ($normalizedPhone === null ? 'invalid' : 'valid');
    $counts[$phoneStatus]++;
    if ($statusFilter !== 'all' && $phoneStatus !== $statusFilter) continue;
    $employee['normalized_phone'] = $normalizedPhone;
    $employee['phone_status'] = $phoneStatus;
    $employees[] = $employee;
}

$perPage = 30;
$total = count($employees);
$pages = max(1, (int)ceil($total / $perPage));
$pageNum = min($pageNum, $pages);
$visibleEmployees = array_slice($employees, ($pageNum - 1) * $perPage, $perPage);
$queryBase = array_filter(['q' => $search, 'status' => $statusFilter !== 'all' ? $statusFilter : null]);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>Personnel Phone Validation</h1>
    <div class="desc">Check phone number format and normalize numbers for SMS addressing</div>
  </div>
</div>

<div class="card" style="margin-bottom:12px">
  <div style="padding:16px">
    <strong>Important:</strong> Format validation checks that a number can be normalized to an international format.
    It does not confirm that the number is active, belongs to the personnel member, or will receive SMS.
    Carrier delivery failures must be resolved with the personnel member or mobile operator.
  </div>
</div>

<div class="grid grid-4" style="margin-bottom:12px">
  <?php foreach ([
      'valid' => ['Valid format', 'badge-present'],
      'invalid' => ['Invalid format', 'badge-awol'],
      'missing' => ['Missing number', 'badge-sick'],
  ] as $key => [$label, $class]): ?>
  <a class="stat" href="/phone-validation.php?<?= e(http_build_query(array_filter(['q' => $search, 'status' => $key]))) ?>" style="text-decoration:none;color:inherit">
    <div><div class="label"><?= e($label) ?></div><div class="value"><?= number_format($counts[$key]) ?></div></div>
    <span class="badge <?= e($class) ?>"><?= e(strtoupper($key)) ?></span>
  </a>
  <?php endforeach; ?>
</div>

<div class="card" style="margin-bottom:12px">
  <form method="get" class="filter-bar" style="padding:14px">
    <div class="filter-field search">
      <label for="phone-q">Search personnel</label>
      <div class="filter-search">
        <input type="search" id="phone-q" name="q" value="<?= e($search) ?>" placeholder="Name, file/force number, or phone">
      </div>
    </div>
    <div class="filter-field">
      <label for="phone-status">Number status</label>
      <select id="phone-status" name="status">
        <?php foreach (['all' => 'All numbers', 'valid' => 'Valid format', 'invalid' => 'Invalid format', 'missing' => 'Missing number'] as $value => $label): ?>
        <option value="<?= e($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-actions"><button class="btn-icon bi-primary btn-labeled" type="submit"><span>Filter</span></button></div>
  </form>
</div>

<div class="card">
  <div class="chr">
    <h3>Personnel phone numbers <span class="badge badge-admin"><?= number_format($total) ?></span></h3>
    <span class="muted">Showing <?= $total ? (($pageNum - 1) * $perPage + 1) : 0 ?>–<?= min($pageNum * $perPage, $total) ?> of <?= number_format($total) ?></span>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>File/Force No</th><th>Rank</th><th>Full Name</th><th>Saved phone</th><th>Format check</th><th>International format</th><?php if ($canNormalize): ?><th>Action</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($visibleEmployees as $employee): ?>
        <tr>
          <td style="font-family:monospace;font-size:12px"><?= e($employee['service_no'] ?? '—') ?></td>
          <td><?= e($employee['rank'] ?? '—') ?></td>
          <td><strong><?= e($employee['full_name']) ?></strong></td>
          <td><?= e($employee['phone'] ?? '—') ?></td>
          <td>
            <?php if ($employee['phone_status'] === 'valid'): ?>
              <span class="badge badge-present">Valid format</span>
            <?php elseif ($employee['phone_status'] === 'invalid'): ?>
              <span class="badge badge-awol">Invalid format</span>
            <?php else: ?>
              <span class="badge badge-sick">Missing</span>
            <?php endif; ?>
          </td>
          <td><?= e($employee['normalized_phone'] ?? '—') ?></td>
          <?php if ($canNormalize): ?>
          <td>
            <form method="post" style="display:flex;gap:6px;align-items:center;margin:0">
              <?= csrf_field() ?>
              <input type="hidden" name="employee_id" value="<?= (int)$employee['id'] ?>">
              <input type="hidden" name="q" value="<?= e($search) ?>">
              <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
              <input type="hidden" name="pg" value="<?= $pageNum ?>">
              <input type="tel" name="phone" value="<?= e($employee['normalized_phone'] ?? $employee['phone'] ?? '') ?>" required placeholder="+256 700 000000" aria-label="Corrected phone number for <?= e($employee['full_name']) ?>" style="max-width:180px">
              <button type="submit" class="btn-icon bi-secondary bi-sm" title="Validate and save this phone number"><?= ICO_SAVE ?></button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$visibleEmployees): ?><tr><td colspan="<?= $canNormalize ? 7 : 6 ?>" style="text-align:center;color:var(--muted);padding:24px">No personnel numbers match this filter.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <div class="action-bar" style="justify-content:center;padding:14px">
    <?php if ($pageNum > 1): $prev = $queryBase; $prev['pg'] = $pageNum - 1; ?>
    <a class="btn-icon bi-secondary" href="/phone-validation.php?<?= e(http_build_query($prev)) ?>">Previous</a>
    <?php endif; ?>
    <span class="muted">Page <?= $pageNum ?> of <?= $pages ?></span>
    <?php if ($pageNum < $pages): $next = $queryBase; $next['pg'] = $pageNum + 1; ?>
    <a class="btn-icon bi-secondary" href="/phone-validation.php?<?= e(http_build_query($next)) ?>">Next</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
