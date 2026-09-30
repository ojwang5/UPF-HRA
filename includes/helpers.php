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
        'directorate_commander',
        'unit_commander'     => 'region',
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

/* ═══ Hierarchical report workflow ═════════════════════════════════════
 * Reports flow: post → station → division → region → HQ.
 * Every report awaiting action at a level shows the reviewer two choices:
 * "Continue & forward" (+ optional comments) or "Revert for correction".
 * HQ (superadmin) can approve or revert. The Super Admin may switch the
 * whole force to direct submission (report_submission_mode = 'direct'),
 * in which case every generated report jumps straight to HQ.
 *
 * current_level stores the level currently reviewing: post|station|division|region|hq.
 * A reverted report returns to current_level = scope_level (its originator).
 */

const REPORT_LEVELS = ['post' => 0, 'station' => 1, 'division' => 2, 'region' => 3, 'hq' => 4];
const REPORT_LEVEL_LABELS = [
    'post' => 'Post', 'station' => 'Station', 'division' => 'Division',
    'region' => 'Region', 'hq' => 'HQ / Headquarters',
];

/** Next (higher) level in the chain. */
function report_level_next(string $lvl): string {
    $map = ['post' => 'station', 'station' => 'division', 'division' => 'region', 'region' => 'hq', 'hq' => 'hq'];
    return $map[$lvl] ?? 'hq';
}

/** The reporting scope a given role generates (post/station/division/region/directorate/unit/hq). */
function report_scope_for_user(array $user): string {
    return match($user['role']) {
        'superadmin'             => 'hq',
        'regional_commander'     => 'region',
        'division_commander'     => 'division',
        'directorate_commander'  => 'directorate',
        'unit_commander'         => 'unit',
        'station_commander'      => 'station',
        default                  => 'post',
    };
}

/** The role that reviews reports sitting at a given level. */
function report_review_role(string $lvl): string {
    return match($lvl) {
        'station'  => 'station_commander',
        'division' => 'division_commander',
        'region'   => 'regional_commander',
        'hq'       => 'superadmin',
        default    => '',
    };
}

/** The command level the acting user manages (for "is this report mine to act on?"). */
function report_level_for_user(array $user): string {
    return match($user['role']) {
        'superadmin'         => 'hq',
        'regional_commander' => 'region',
        'division_commander' => 'division',
        'station_commander'  => 'station',
        'post_commander'     => 'post',
        default              => '',
    };
}

/** True if the current user is allowed to take action on a given report row. */
function can_act_on_report(array $user, array $report): bool {
    $level = report_level_for_user($user);
    if (!$level || ($report['current_level'] ?? '') !== $level) return false;
    return match($level) {
        'hq'       => true,
        'region'   => (int)($report['region_id']  ?? 0)  === (int)$user['region_id'],
        'division' => (int)($report['division_id'] ?? 0) === (int)$user['division_id'],
        'station'  => (int)($report['station_id']  ?? 0) === (int)$user['station_id'],
        'post'     => (int)($report['post_id']     ?? 0) === (int)$user['post_id'],
        default    => false,
    };
}

/** Compact label for a report's origin unit (from its scope_level). */
function report_unit_label(PDO $pdo, array $r): string {
    $scope = $r['scope_level'] ?? 'post';
    if ($scope === 'force' || $scope === 'hq') return 'Force (HQ)';
    if ($scope === 'directorate' || $scope === 'unit') {
        $id  = (int)($r[$scope . '_id'] ?? 0);
        if (!$id) return '—';
        $tbl = $scope === 'directorate' ? 'directorates' : 'units';
        $name = $pdo->query("SELECT name FROM {$tbl} WHERE id={$id}")->fetchColumn();
        return $name ?: '—';
    }
    $col = ['region' => 'rg', 'division' => 'dv', 'station' => 'st', 'post' => 'pt'][$scope] ?? 'pt';
    $tbl = ['rg' => 'regions', 'dv' => 'divisions', 'st' => 'stations', 'pt' => 'posts'][$col];
    $id  = (int)($r[$scope . '_id'] ?? 0);
    if (!$id) return '—';
    $name = $pdo->query("SELECT name FROM {$tbl} WHERE id={$id}")->fetchColumn();
    return $name ?: '—';
}

/** Notifies every user account that reviews a given level for this report's scope. */
function notify_report_level(PDO $pdo, string $level, array $report, string $title, string $msg, array $opts = []): void {
    require_once __DIR__ . '/notifications.php';
    $role = report_review_role($level);
    if (!$role) return;
    $sql = "SELECT id, full_name FROM users WHERE role='{$role}'";
    switch ($level) {
        case 'station':  $sql .= " AND station_id="  . (int)$report['station_id'];  break;
        case 'division': $sql .= " AND division_id=" . (int)$report['division_id']; break;
        case 'region':   $sql .= " AND region_id="   . (int)$report['region_id'];   break;
        case 'post':     $sql .= " AND post_id="     . (int)$report['post_id'];     break;
    }
    foreach ($pdo->query($sql)->fetchAll() as $u) {
        notify($title, $msg, 'user', array_merge($opts, ['target_user_id' => (int)$u['id']]));
    }
}

/* ═══ Leave countdown ═════════════════════════════════════════════════
 * Counts down the days remaining on an approved leave towards 0 and
 * notifies commanders once an officer's approved leave is about to end
 * or has already expired (checked once per request).                  */

/**
 * Remaining days (0 = end/expired) for an approved leave request today.
 * Before the leave starts the full issued days are shown.
 */
function leave_countdown(array $req): int {
    $today = date('Y-m-d');
    if ($today < $req['start_date']) return leave_days_between($req['start_date'], $req['end_date']);
    if ($today > $req['end_date'])   return 0;
    return leave_days_between($today, $req['end_date']);
}

/**
 * One-shot notifications for approved leave ending within 5 days (or already over).
 * Marks expiry_notified=1 so commanders are alerted exactly once per request.
 */
function run_leave_countdown_notifications(PDO $pdo): void {
    require_once __DIR__ . '/notifications.php';
    $today = date('Y-m-d');
    $soon  = date('Y-m-d', strtotime('+5 days'));
    $rows = $pdo->query("SELECT lr.*, e.full_name, e.region_id, e.division_id, e.station_id, e.post_id
                        FROM leave_requests lr JOIN employees e ON e.id=lr.employee_id
                        WHERE lr.status='approved' AND lr.expiry_notified=0
                          AND lr.end_date <= '{$soon}'")
        ->fetchAll();
    foreach ($rows as $r) {
        $days = leave_countdown($r);
        $expired = $today > $r['end_date'];
        $verb = $expired ? 'has EXPIRED' : ($days <= 2 ? 'ends in '.$days.' day'.($days === 1 ? '' : 's') : 'will end in '.$days.' days');
        $msg = $r['full_name'].' — approved '.$r['leave_type'].' leave '.$verb." (was due {$r['end_date']}).";
        // Commanders chain above the officer's post
        $chain = $pdo->prepare("SELECT id, full_name, role FROM users WHERE role=? AND station_id=?");
        $chain->execute(['station_commander', (int)$r['station_id']]);
        foreach ($chain->fetchAll() as $c) notify('Leave expiring — '.$r['full_name'], $msg, 'user', ['target_user_id'=>(int)$c['id'], 'kind'=>'leave', 'link'=>'/leave-requests.php']);
        $chain = $pdo->prepare("SELECT id, full_name, role FROM users WHERE role=? AND division_id=?");
        $chain->execute(['division_commander', (int)$r['division_id']]);
        foreach ($chain->fetchAll() as $c) notify('Leave expiring — '.$r['full_name'], $msg, 'user', ['target_user_id'=>(int)$c['id'], 'kind'=>'leave', 'link'=>'/leave-requests.php']);
        $chain = $pdo->prepare("SELECT id, full_name, role FROM users WHERE role=? AND region_id=?");
        $chain->execute(['regional_commander', (int)$r['region_id']]);
        foreach ($chain->fetchAll() as $c) notify('Leave expiring — '.$r['full_name'], $msg, 'user', ['target_user_id'=>(int)$c['id'], 'kind'=>'leave', 'link'=>'/leave-requests.php']);
        notify_superadmins('Leave expiring — '.$r['full_name'], $msg, ['kind'=>'leave', 'link'=>'/leave-requests.php']);
        $pdo->prepare("UPDATE leave_requests SET expiry_notified=1 WHERE id=?")->execute([(int)$r['id']]);
    }
}
