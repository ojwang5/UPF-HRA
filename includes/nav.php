<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

/**
 * The single source of truth for the sidebar menu.
 *
 * Each entry:
 *   href     — URL of the module
 *   label    — menu caption
 *   min_rank — minimum role rank required to see the entry (see ROLE_RANKS)
 *   roles    — instead of min_rank, an explicit list of roles allowed
 *   icon     — inline SVG markup
 *
 * Kept outside the header template so other modules (e.g. the HRAS Assistant)
 * can reason about what a given role is able to reach.
 */
function all_nav(): array {
    return [
  'dashboard'    => ['href'=>'/','label'=>'Dashboard','min_rank'=>1,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>'],
  'employees'    => ['href'=>'/employees.php','label'=>'Personnel','min_rank'=>1,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 1 1 0 7.75"/></svg>'],
  'daily'        => ['href'=>'/daily-status.php','label'=>'Daily Status','min_rank'=>1,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y="2" x2="16" y2="6"/><line x1="8" y="2" x2="8" y2="6"/><line x1="3" y="10" x2="21" y2="10"/><path d="M9 16l2 2 4-4"/></svg>'],
  'reports'      => ['href'=>'/reports.php','label'=>'Reports','min_rank'=>2,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y="13" x2="15" y2="13"/><line x1="9" y="17" x2="15" y2="17"/></svg>'],
  'leave'        => ['href'=>'/leave-requests.php','label'=>'Leave Management','min_rank'=>1,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>'],
  'transfers'    => ['href'=>'/transfers.php','label'=>'Transfers','min_rank'=>2,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h18"/><path d="M15 6l6 6-6 6"/><path d="M9 6l-6 6 6 6"/></svg>'],
  'assignments'  => ['href'=>'/special-assignments.php','label'=>'Special Assignments','min_rank'=>2,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><polyline points="12 11 9 14 12 17 15 14 12 11"/></svg>'],
  'courses'      => ['href'=>'/courses.php','label'=>'On Course','min_rank'=>2,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 1-4 4v14a3 3 0 0 1 3-3h7z"/></svg>'],
  'discipline'   => ['href'=>'/discipline.php','label'=>'Suspension / Discipline','min_rank'=>2,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>'],
  'undeployed'   => ['href'=>'/undeployed.php','label'=>'Undeployed','min_rank'=>3,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 1 1 0 7.75"/></svg>'],
  'history'      => ['href'=>'/history.php','label'=>'History','min_rank'=>3,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><polyline points="3 3 3 8 8 8"/><polyline points="12 7 12 12 15 15"/></svg>'],
  'users'        => ['href'=>'/users.php','label'=>'Users','min_rank'=>2,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>'],
  'hierarchy'    => ['href'=>'/hierarchy.php','label'=>'Structure','min_rank'=>6,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="2"/><path d="M12 7v4m0 0H8a2 2 0 0 0-2 2v1m6-3h4a2 2 0 0 1 2 2v1"/><circle cx="6" cy="17" r="2"/><circle cx="12" cy="17" r="2"/><circle cx="18" cy="17" r="2"/></svg>'],
  'directorate'  => ['href'=>'/directorate.php','label'=>'My Directorate','roles'=>['directorate_commander','unit_commander'],
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 9h1"/><path d="M14 9h1"/><path d="M9 13h1"/><path d="M14 13h1"/><rect x="8" y="16" width="8" height="5"/></svg>'],
  'notifications'=> ['href'=>'/notifications.php','label'=>'Notifications','min_rank'=>1,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>'],
  'activity'      => ['href'=>'/activity-log.php','label'=>'Activity Log','min_rank'=>4,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>'],
  'communications'=> ['href'=>'/communications.php','label'=>'Communications','min_rank'=>3,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>'],
  'settings'      => ['href'=>'/settings.php','label'=>'Settings','min_rank'=>6,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-1.82.33 1.65 1.65 0 0 0-.33 1.82l.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>'],
    ];
}

/**
 * The nav entries a given user is allowed to see, in menu order.
 * @return array<string,array>
 */
function nav_for_user(array $user): array {
    $rank = role_rank((string)($user['role'] ?? ''));
    $role = (string)($user['role'] ?? '');
    $out  = [];
    foreach (all_nav() as $k => $n) {
        if (isset($n['roles'])) {
            if (!in_array($role, $n['roles'], true)) continue;
            if ($k === 'directorate' && $role === 'unit_commander') $n['label'] = 'My Unit';
        } elseif ($rank < (int)$n['min_rank']) {
            continue;
        }
        $n['key'] = $k;
        $out[$k]  = $n;
    }
    return $out;
}
