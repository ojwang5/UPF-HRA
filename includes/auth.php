<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

/* ─── Role meta ─── */
const ROLE_LABELS = [
    'superadmin'          => 'Super Admin',
    'regional_commander'  => 'Regional Commander',
    'division_commander'  => 'Division Commander',
    'station_commander'   => 'Station Commander',
    'post_commander'      => 'Post Commander',
    'officer'             => 'Field Officer',
];
const ROLE_RANKS = [
    'superadmin' => 6, 'regional_commander' => 5, 'division_commander' => 4,
    'station_commander' => 3, 'post_commander' => 2, 'officer' => 1,
];

function role_label(string $role): string { return ROLE_LABELS[$role] ?? ucfirst($role); }
function role_rank(string $role): int { return ROLE_RANKS[$role] ?? 0; }

/* ─── Session helpers ─── */
function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $stmt = db()->prepare("
        SELECT u.*,
               rg.name AS region_name, rg.code AS region_code,
               dv.name AS division_name,
               st.name AS station_name,
               pt.name AS post_name
        FROM users u
        LEFT JOIN regions   rg ON rg.id = u.region_id
        LEFT JOIN divisions dv ON dv.id = u.division_id
        LEFT JOIN stations  st ON st.id = u.station_id
        LEFT JOIN posts     pt ON pt.id = u.post_id
        WHERE u.id = ?
    ");
    $stmt->execute([(int)$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function require_login(): array {
    $u = current_user();
    if (!$u) { header('Location: /login.php'); exit; }
    return $u;
}

function require_role(array $roles): array {
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) { http_response_code(403); exit('Access denied.'); }
    return $u;
}

function require_min_rank(string $minRole): array {
    $u = require_login();
    if (role_rank($u['role']) < role_rank($minRole)) { http_response_code(403); exit('Access denied.'); }
    return $u;
}

function login(string $username, string $password): bool {
    $stmt = db()->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $u = $stmt->fetch();
    if ($u && password_verify($password, $u['password_hash'])) {
        $_SESSION['user_id'] = (int)$u['id'];
        return true;
    }
    return false;
}

function logout(): void { $_SESSION = []; session_destroy(); }

/* ─── Hierarchy predicates ─── */
function is_superadmin(array $u): bool { return $u['role'] === 'superadmin'; }
function is_commander(array $u): bool  { return role_rank($u['role']) >= role_rank('post_commander'); }
function is_officer(array $u): bool    { return $u['role'] === 'officer'; }

/**
 * Returns [WHERE_clause, params_array] that filters an employees table alias by the user's scope.
 * @param string $a  Table alias for employees
 */
function scope_where(array $user, string $a = 'e'): array {
    return match($user['role']) {
        'superadmin'         => ['1=1', []],
        'regional_commander' => ["{$a}.region_id=?",   [(int)$user['region_id']]],
        'division_commander' => ["{$a}.division_id=?", [(int)$user['division_id']]],
        'station_commander'  => ["{$a}.station_id=?",  [(int)$user['station_id']]],
        'post_commander',
        'officer'            => ["{$a}.post_id=?",     [(int)$user['post_id']]],
        default              => ['0=1', []],
    };
}

/** Returns [WHERE_clause, params] for scoping any table that has region/division/station/post_id columns. */
function scope_where_for(array $user, string $a = 'r'): array {
    return match($user['role']) {
        'superadmin'         => ['1=1', []],
        'regional_commander' => ["{$a}.region_id=?",   [(int)$user['region_id']]],
        'division_commander' => ["{$a}.division_id=?", [(int)$user['division_id']]],
        'station_commander'  => ["{$a}.station_id=?",  [(int)$user['station_id']]],
        'post_commander',
        'officer'            => ["{$a}.post_id=?",     [(int)$user['post_id']]],
        default              => ['0=1', []],
    };
}

/** Can user manage (create/edit/delete) a target user? */
function can_manage_user(array $actor, array $target): bool {
    if ($actor['role'] === 'superadmin') return true;
    if (role_rank($actor['role']) <= role_rank($target['role'])) return false;
    // Must be in same or sub-scope
    return user_shares_scope($actor, $target);
}

function user_shares_scope(array $actor, array $target): bool {
    return match($actor['role']) {
        'regional_commander' => (int)$actor['region_id']   === (int)$target['region_id'],
        'division_commander' => (int)$actor['division_id'] === (int)$target['division_id'],
        'station_commander'  => (int)$actor['station_id']  === (int)$target['station_id'],
        'post_commander'     => (int)$actor['post_id']     === (int)$target['post_id'],
        default              => false,
    };
}

/** Roles a given actor is allowed to create. */
function creatable_roles(array $actor): array {
    $all = ['superadmin','regional_commander','division_commander','station_commander','post_commander','officer'];
    $rank = role_rank($actor['role']);
    return array_filter($all, fn($r) => role_rank($r) < $rank);
}

/** Scope display label for topbar */
function user_scope_label(array $u): string {
    return $u['post_name'] ?? $u['station_name'] ?? $u['division_name'] ?? $u['region_name'] ?? 'HQ';
}
