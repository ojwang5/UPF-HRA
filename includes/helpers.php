<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

const ALL_STATUSES = ['present','awol','leave','sick','suspended','disciplinary','on_duty','on_course'];
const STATUS_LABELS = [
    'present'      => 'Present',
    'awol'         => 'AWOL',
    'leave'        => 'On Leave',
    'sick'         => 'Sick',
    'suspended'    => 'Suspended',
    'disciplinary' => 'Disciplinary',
    'on_duty'      => 'On Duty',
    'on_course'    => 'On Course',
];
const STATUS_BADGE_CLASS = [
    'present'      => 'badge-present',
    'awol'         => 'badge-awol',
    'leave'        => 'badge-leave',
    'sick'         => 'badge-sick',
    'suspended'    => 'badge-suspended',
    'disciplinary' => 'badge-disciplinary',
    'on_duty'      => 'badge-on_duty',
    'on_course'    => 'badge-on_course',
];

function status_label(string $s): string { return STATUS_LABELS[$s] ?? ucfirst($s); }
function status_badge(string $s): string {
    $cls = STATUS_BADGE_CLASS[$s] ?? 'badge';
    return '<span class="badge '.$cls.'">'.e(status_label($s)).'</span>';
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
               SUM(CASE WHEN ds.status='on_course'    THEN 1 ELSE 0 END) AS on_course
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
        foreach (['total','male','female','present','awol','on_leave','sick','suspended','disciplinary','on_duty','on_course'] as $k) {
            $r[$k] = (int)($r[$k] ?? 0);
        }
        $r['unrecorded'] = $r['total'] - ($r['present']+$r['awol']+$r['on_leave']+$r['sick']+$r['suspended']+$r['disciplinary']+$r['on_duty']+$r['on_course']);
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
    $t = ['total'=>0,'male'=>0,'female'=>0,'present'=>0,'awol'=>0,'on_leave'=>0,'sick'=>0,'suspended'=>0,'disciplinary'=>0,'on_duty'=>0,'on_course'=>0,'unrecorded'=>0];
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
