<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ─── Icon SVG snippets ─── */
const ICO_PLUS   = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>';
const ICO_TRASH  = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';
const ICO_EDIT   = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>';
const ICO_SAVE   = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
const ICO_CANCEL = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
const ICO_SEARCH = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
const ICO_SEND   = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>';
const ICO_EYE    = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
const ICO_OK     = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
const ICO_REJECT = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
const ICO_KEY    = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>';
const ICO_DL     = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
const ICO_PRINT  = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>';
const ICO_GEN    = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>';
const ICO_BELL   = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>';
const ICO_OK_ALL = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/><polyline points="20 12 9 23 4 18"/></svg>';

/* ─── UPF Rank seniority (highest first) ─── */
const UPF_RANKS = [
    'IGP','DIGP','CJS','AIGP','SCP','CP','ACP','SSP','SP','ASP',
    'IP','AIP','HCM','HC','S/SGT','SGT','CPL','L/CPL','PC','PPC','SPC','CIVILIAN',
];

/** Returns a SQL CASE expression for ORDER BY rank seniority (alias defaults to 'e') */
function rank_order_sql(string $col = 'e.rank'): string {
    $cases = '';
    foreach (UPF_RANKS as $i => $r) {
        $esc = str_replace("'", "''", $r);
        $cases .= "WHEN '{$esc}' THEN " . ($i + 1) . " ";
    }
    return "CASE {$col} {$cases}ELSE 99 END";
}

/** Log a user action to the activity_log table — never throws */
function log_activity(string $action, string $entityType = '', string $entityLabel = '', int $entityId = 0, string $details = ''): void {
    try {
        $u = current_user();
        db()->prepare("INSERT INTO activity_log (user_id,user_name,action,entity_type,entity_id,entity_label,details,ip_address,created_at) VALUES (?,?,?,?,?,?,?,?,datetime('now','localtime'))")
            ->execute([
                $u ? (int)$u['id'] : null,
                $u ? $u['full_name'] : 'System',
                $action,
                $entityType  ?: null,
                $entityId    ?: null,
                $entityLabel ?: null,
                $details     ?: null,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
    } catch (\Throwable $e) { /* silent — never break the app */ }
}

const ALL_STATUSES = ['present','awol','leave','sick','suspended','disciplinary','on_duty','on_course','deserted','special_assignment','undeployed'];
const STATUS_LABELS = [
    'present'           => 'Present',
    'awol'              => 'AWOL',
    'leave'             => 'On Leave',
    'sick'              => 'Sick',
    'suspended'         => 'Suspended',
    'disciplinary'      => 'Disciplinary',
    'on_duty'           => 'On Duty',
    'on_course'         => 'On Course',
    'deserted'          => 'Deserted',
    'special_assignment'=> 'Special Assignment',
    'undeployed'        => 'Undeployed',
];
const STATUS_BADGE_CLASS = [
    'present'           => 'badge-present',
    'awol'              => 'badge-awol',
    'leave'             => 'badge-leave',
    'sick'              => 'badge-sick',
    'suspended'         => 'badge-suspended',
    'disciplinary'      => 'badge-disciplinary',
    'on_duty'           => 'badge-on_duty',
    'on_course'         => 'badge-on_course',
    'deserted'          => 'badge-deserted',
    'special_assignment'=> 'badge-special_assignment',
    'undeployed'        => 'badge-undeployed',
];

function status_label(string $s): string { return STATUS_LABELS[$s] ?? ucfirst($s); }
function status_badge(string $s): string {
    $cls = STATUS_BADGE_CLASS[$s] ?? 'badge';
    return '<span class="badge '.$cls.'">'.e(status_label($s)).'</span>';
}

/** Badge for a transfer lifecycle status. */
function transfer_status_badge(string $s): string {
    [$l,$c] = match($s) {
        'pending'   => ['Pending','badge-sick'],
        'approved'  => ['Approved','badge-on_course'],
        'rejected'  => ['Rejected','badge-awol'],
        'executed'  => ['Executed','badge-present'],
        'cancelled' => ['Cancelled','badge-admin'],
        default     => [ucfirst($s) ?: '—','badge'],
    };
    return '<span class="badge '.$c.'">'.e($l).'</span>';
}

/** Badge showing whether a transferred officer reported at the new workplace. */
function transfer_report_badge(string $s): string {
    return $s === 'reported'
        ? '<span class="badge badge-present">Reported</span>'
        : '<span class="badge badge-sick">Pending report</span>';
}

/**
 * Returns aggregate stats for the user's scope, grouped by the level below theirs.
 * superadmin => group by region
 * regional_commander => group by division
 * division_commander => group by station
 * station_commander/post_commander/officer => group by post
 */
function hierarchy_summary(PDO $pdo, string $date, array $user): array {
    [$scopeWhere, $scopeParams] = scope_where($user, 'e');

    // Determine grouping level
    $groupLevel = match($user['role']) {
        'superadmin'         => 'region',
        'regional_commander' => 'division',
        'division_commander' => 'station',
        default              => 'post',
    };

    $groupCol   = "{$groupLevel}s.id";
    $groupName  = "{$groupLevel}s.name";
    $joinClause = build_hierarchy_joins($groupLevel);

    $sql = "
        SELECT {$groupCol} AS unit_id, {$groupName} AS unit_name, '{$groupLevel}' AS unit_level,
               COUNT(e.id) AS total,
               SUM(CASE WHEN e.gender='M' THEN 1 ELSE 0 END) AS male,
               SUM(CASE WHEN e.gender='F' THEN 1 ELSE 0 END) AS female,
               SUM(CASE WHEN ds.status='present'      THEN 1 ELSE 0 END) AS present,
               SUM(CASE WHEN ds.status='awol'         THEN 1 ELSE 0 END) AS awol,
               SUM(CASE WHEN ds.status='leave'        THEN 1 ELSE 0 END) AS on_leave,
               SUM(CASE WHEN ds.status='sick'         THEN 1 ELSE 0 END) AS sick,
               SUM(CASE WHEN ds.status='suspended'    THEN 1 ELSE 0 END) AS suspended,
               SUM(CASE WHEN ds.status='disciplinary' THEN 1 ELSE 0 END) AS disciplinary,
               SUM(CASE WHEN ds.status='on_duty'      THEN 1 ELSE 0 END) AS on_duty,
               SUM(CASE WHEN ds.status='on_course'    THEN 1 ELSE 0 END) AS on_course
        FROM employees e
        {$joinClause}
        LEFT JOIN daily_status ds ON ds.employee_id=e.id AND ds.date=:d
        WHERE e.active=1 AND {$scopeWhere}
        GROUP BY unit_id
        ORDER BY unit_name
    ";

    $sqlFinal = "
        SELECT {$groupCol} AS unit_id, {$groupName} AS unit_name, '{$groupLevel}' AS unit_level,
               COUNT(e.id) AS total,
               SUM(CASE WHEN e.gender='M' THEN 1 ELSE 0 END) AS male,
               SUM(CASE WHEN e.gender='F' THEN 1 ELSE 0 END) AS female,
               SUM(CASE WHEN ds.status='present'      THEN 1 ELSE 0 END) AS present,
               SUM(CASE WHEN ds.status='awol'         THEN 1 ELSE 0 END) AS awol,
               SUM(CASE WHEN ds.status='leave'        THEN 1 ELSE 0 END) AS on_leave,
               SUM(CASE WHEN ds.status='sick'         THEN 1 ELSE 0 END) AS sick,
               SUM(CASE WHEN ds.status='suspended'    THEN 1 ELSE 0 END) AS suspended,
               SUM(CASE WHEN ds.status='disciplinary' THEN 1 ELSE 0 END) AS disciplinary,
               SUM(CASE WHEN ds.status='on_duty'      THEN 1 ELSE 0 END) AS on_duty,
               SUM(CASE WHEN ds.status='on_course'    THEN 1 ELSE 0 END) AS on_course,
               SUM(CASE WHEN ds.status='deserted'     THEN 1 ELSE 0 END) AS deserted,
               SUM(CASE WHEN ds.status='special_assignment' THEN 1 ELSE 0 END) AS special_assignment,
               SUM(CASE WHEN ds.status='undeployed'   THEN 1 ELSE 0 END) AS undeployed
        FROM employees e
        {$joinClause}
        LEFT JOIN daily_status ds ON ds.employee_id=e.id AND ds.date=?
        WHERE e.active=1 AND {$scopeWhere}
        GROUP BY unit_id
        ORDER BY unit_name
    ";
    $stmt = $pdo->prepare($sqlFinal);
    $stmt->execute(array_merge([$date], $scopeParams));
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        foreach (['total','male','female','present','awol','on_leave','sick','suspended','disciplinary','on_duty','on_course','deserted','special_assignment','undeployed'] as $k) {
            $r[$k] = (int)($r[$k] ?? 0);
        }
        $r['unrecorded'] = $r['total'] - ($r['present']+$r['awol']+$r['on_leave']+$r['sick']+$r['suspended']+$r['disciplinary']+$r['on_duty']+$r['on_course']+$r['deserted']+$r['special_assignment']+$r['undeployed']);
    }
    return $rows;
}

function build_hierarchy_joins(string $upTo): string {
    $joins = "LEFT JOIN posts     pt ON pt.id=e.post_id
              LEFT JOIN stations  st ON st.id=e.station_id
              LEFT JOIN divisions dv ON dv.id=e.division_id
              LEFT JOIN regions   rg ON rg.id=e.region_id";
    return match($upTo) {
        'region'   => "LEFT JOIN regions   regions ON regions.id=e.region_id",
        'division' => "LEFT JOIN regions rg ON rg.id=e.region_id LEFT JOIN divisions divisions ON divisions.id=e.division_id",
        'station'  => "LEFT JOIN divisions dv ON dv.id=e.division_id LEFT JOIN stations stations ON stations.id=e.station_id",
        default    => "LEFT JOIN stations st ON st.id=e.station_id LEFT JOIN posts posts ON posts.id=e.post_id",
    };
}

function sum_totals(array $rows): array {
    $t = ['total'=>0,'male'=>0,'female'=>0,'present'=>0,'awol'=>0,'on_leave'=>0,'sick'=>0,'suspended'=>0,'disciplinary'=>0,'on_duty'=>0,'on_course'=>0,'deserted'=>0,'special_assignment'=>0,'undeployed'=>0,'unrecorded'=>0];
    foreach ($rows as $r) foreach ($t as $k=>$_) $t[$k] += ($r[$k] ?? 0);
    return $t;
}

function flash(string $key, ?string $msg = null): ?string {
    if ($msg !== null) { $_SESSION['_flash'][$key] = $msg; return null; }
    $val = $_SESSION['_flash'][$key] ?? null;
    if (isset($_SESSION['_flash'][$key])) unset($_SESSION['_flash'][$key]);
    return $val;
}

/** Returns list of unit options for filter dropdowns based on user scope */
function scope_units(PDO $pdo, array $user): array {
    return match($user['role']) {
        'superadmin'         => $pdo->query("SELECT id, name, 'region' AS level FROM regions ORDER BY name")->fetchAll(),
        'regional_commander' => $pdo->prepare("SELECT id, name, 'division' AS level FROM divisions WHERE region_id=? ORDER BY name")->execute([$user['region_id']]) ? $pdo->query("SELECT id, name, 'division' AS level FROM divisions WHERE region_id={$user['region_id']} ORDER BY name")->fetchAll() : [],
        'division_commander' => $pdo->query("SELECT id, name, 'station' AS level FROM stations WHERE division_id={$user['division_id']} ORDER BY name")->fetchAll(),
        default              => $pdo->query("SELECT id, name, 'post' AS level FROM posts WHERE station_id={$user['station_id']} ORDER BY name")->fetchAll(),
    };
}

/* ═══ Auto status engine ═══════════════════════════════════════════
 * Derives a personnel's daily status from dated records that cover the
 * given date: approved leave (day count), on-course (duration),
 * suspension/disciplinary cases, special assignments and undeployments.
 * Manual daily-status entries are never overwritten — only rows that
 * are missing or were previously auto-set are refreshed.               */

const AUTO_STATUS_PRIORITY = ['disciplinary','suspended','on_course','special_assignment','leave','undeployed'];

/** Strict YYYY-MM-DD validation — guards interpolated date literals. */
function valid_date(string $d): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    [$y,$m,$day] = array_map('intval', explode('-', $d));
    return checkdate($m, $day, $y);
}

/**
 * Computes the effective auto-derived status for every active employee in scope.
 * @return array employee_id => status|null  (null when no record covers the date)
 */
function derive_auto_statuses(PDO $pdo, string $date, array $scopeEmployees): array {
    if (!$scopeEmployees || !valid_date($date)) return [];
    $ids = array_map('intval', array_column($scopeEmployees, 'id'));
    $in  = implode(',', $ids);

    // Approved leave covering the date (status picked from approved requests only)
    $leave = [];
    foreach ($pdo->query("SELECT employee_id FROM leave_requests WHERE status='approved' AND '$date' BETWEEN start_date AND end_date AND employee_id IN ($in)") as $r) {
        $leave[(int)$r['employee_id']] = true;
    }
    $course = [];
    foreach ($pdo->query("SELECT employee_id FROM on_courses WHERE status='active' AND '$date' BETWEEN start_date AND end_date AND employee_id IN ($in)") as $r) {
        $course[(int)$r['employee_id']] = true;
    }
    $susp = []; $disc = [];
    foreach ($pdo->query("SELECT employee_id, case_type FROM discipline_cases WHERE status='open' AND '$date' BETWEEN start_date AND COALESCE(end_date,'9999-12-31') AND employee_id IN ($in)") as $r) {
        if ($r['case_type']==='disciplinary') $disc[(int)$r['employee_id']] = true; else $susp[(int)$r['employee_id']] = true;
    }
    $spAsg = [];
    foreach ($pdo->query("SELECT employee_id FROM special_assignments WHERE status='active' AND '$date' BETWEEN start_date AND COALESCE(end_date,'9999-12-31') AND employee_id IN ($in)") as $r) {
        $spAsg[(int)$r['employee_id']] = true;
    }
    $undep = [];
    foreach ($pdo->query("SELECT employee_id FROM undeployments WHERE status='active' AND '$date' BETWEEN start_date AND COALESCE(end_date,'9999-12-31') AND employee_id IN ($in)") as $r) {
        $undep[(int)$r['employee_id']] = true;
    }

    $out = [];
    foreach ($ids as $id) {
        foreach (AUTO_STATUS_PRIORITY as $st) {
            $hit = match($st) {
                'disciplinary'       => isset($disc[$id]),
                'suspended'          => isset($susp[$id]),
                'on_course'          => isset($course[$id]),
                'special_assignment' => isset($spAsg[$id]),
                'leave'              => isset($leave[$id]),
                'undeployed'         => isset($undep[$id]),
                default              => false,
            };
            if ($hit) { $out[$id] = $st; continue 2; }
        }
        $out[$id] = null;
    }
    return $out;
}

/**
 * Runs the auto engine for a date within the user's scope: writes derived
 * statuses into daily_status (flagged auto_status=1), removes stale auto rows.
 * @return int number of rows written/cleared
 */
function run_auto_status(PDO $pdo, string $date, array $user): int {
    [$scopeW, $scopeP] = scope_where($user, 'e');
    $stmt = $pdo->prepare("SELECT e.id FROM employees e WHERE e.active=1 AND $scopeW");
    $stmt->execute($scopeP);
    $emps = $stmt->fetchAll();
    if (!$emps) return 0;

    $derived = derive_auto_statuses($pdo, $date, $emps);

    // Existing rows for this date
    $existing = [];
    foreach ($pdo->query("SELECT id, employee_id, status, auto_status FROM daily_status WHERE date='$date'") as $r) {
        $existing[(int)$r['employee_id']] = $r;
    }

    $upsert = $pdo->prepare("INSERT INTO daily_status (employee_id,date,status,auto_status,recorded_by)
                             VALUES (:eid,:d,:st,1,NULL)
                             ON CONFLICT(employee_id,date) DO UPDATE SET status=excluded.status, auto_status=1");
    $clear  = $pdo->prepare("DELETE FROM daily_status WHERE employee_id=? AND date=? AND auto_status=1");
    $n = 0;
    foreach ($derived as $eid => $st) {
        $cur = $existing[$eid] ?? null;
        if ($cur && !(int)$cur['auto_status']) continue;      // manual entry wins
        if ($cur && (string)$cur['status'] === (string)$st) continue; // already correct
        if ($st === null) { $clear->execute([$eid, $date]); $n++; }
        else { $upsert->execute([':eid'=>$eid, ':d'=>$date, ':st'=>$st]); $n++; }
    }
    return $n;
}

/* ═══ Leave balances & adjustments ══════════════════════════════════ */

/** Days between two inclusive dates. */
function leave_days_between(string $start, string $end): int {
    $s = strtotime($start); $e = strtotime($end);
    if ($s === false || $e === false || $e < $s) return 0;
    return (int)floor(($e - $s) / 86400) + 1;
}

/**
 * Leave ledger for one or all employees (current year by default).
 * Returns [employee_id => ['entitlement'=>n,'adjust'=>n,'taken'=>n,'pending'=>n,'remaining'=>n]]
 */
function leave_balances(PDO $pdo, array $employeeIds, ?string $year = null): array {
    $year  = $year ?? date('Y');
    $yStart = $year.'-01-01';
    $yEnd   = $year.'-12-31';

    $out = [];
    foreach ($employeeIds as $id) {
        $out[(int)$id] = ['entitlement'=>0,'adjust'=>0,'taken'=>0,'pending'=>0,'remaining'=>0];
    }
    if (!$out) return $out;

    $in = implode(',', array_map('intval', array_keys($out)));

    foreach ($pdo->query("SELECT id, annual_leave_days FROM employees WHERE id IN ($in)") as $r) {
        $out[(int)$r['id']]['entitlement'] = (int)($r['annual_leave_days'] ?? 30);
    }
    foreach ($pdo->query("SELECT employee_id, SUM(days) d FROM leave_adjustments WHERE employee_id IN ($in) GROUP BY employee_id") as $r) {
        $out[(int)$r['employee_id']]['adjust'] = (int)$r['d'];
    }
    // Taken: approved leaves overlapping the year — count days falling inside the year
    foreach ($pdo->query("SELECT employee_id, start_date, end_date FROM leave_requests WHERE status='approved' AND employee_id IN ($in) AND start_date <= '$yEnd' AND end_date >= '$yStart'") as $r) {
        $from = max($r['start_date'], $yStart);
        $to   = min($r['end_date'], $yEnd);
        $out[(int)$r['employee_id']]['taken'] += leave_days_between($from, $to);
    }
    foreach ($pdo->query("SELECT employee_id, start_date, end_date FROM leave_requests WHERE status='pending' AND employee_id IN ($in) AND start_date <= '$yEnd' AND end_date >= '$yStart'") as $r) {
        $from = max($r['start_date'], $yStart);
        $to   = min($r['end_date'], $yEnd);
        $out[(int)$r['employee_id']]['pending'] += leave_days_between($from, $to);
    }
    foreach ($out as &$b) {
        $b['remaining'] = $b['entitlement'] + $b['adjust'] - $b['taken'];
    }
    return $out;
}

/**
 * Whether a user role is allowed to approve a leave request of the given type.
 * Regular leave: station commander and above (rank >= 3).
 * Study leave: reserved for regional commander and above (rank >= 5).
 */
function can_approve_leave(array $user, string $leaveType = 'Annual'): bool {
    $type = strtolower(trim($leaveType));
    if ($type === 'study') {
        return role_rank($user['role']) >= role_rank('regional_commander');
    }
    return role_rank($user['role']) >= role_rank('station_commander');
}
