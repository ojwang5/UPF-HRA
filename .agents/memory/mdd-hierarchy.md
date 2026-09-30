---
name: MDD Hierarchy Schema
description: Police hierarchy structure, roles, statuses, and access control patterns for the MDD Manager app.
---

# MDD Manager — Hierarchy Architecture

## Hierarchy tables
regions → divisions → stations → posts (4 separate tables, not self-referencing)

## Employee scoping
Employees carry all 4 IDs: `region_id`, `division_id`, `station_id`, `post_id` (denormalized).
This lets `scope_where(user, alias)` produce a single `=?` WHERE clause for any role level.

## Roles (ROLE_RANKS order 1–8)
officer=1, post_commander=2, station_commander=3, unit_commander=3, division_commander=4, regional_commander=5, directorate_commander=5, superadmin=6

## Functional command roles (v12)
`directorate_commander`/`unit_commander` (ROLE_LABELS + ROLE_RANKS in auth.php) manage personnel by
**directorate/unit**, not geography. `users.unit_id`, `reports.directorate_id/unit_id`,
`leave_requests.directorate_id/unit_id` added in `_migrate_v12`. Employees still store directorate/unit as
TEXT names, so `scope_where` matches `e.directorate=?`/`e.unit=?` (NOT ids). `current_user()` joins
directorates+units to expose `directorate_name`, `unit_name`. Dedicated console: `public/directorate.php`
(nav item `My Directorate`, role-gated). Demo: dir_ops/dir123, unit_gd/unit123.

## 8 employee statuses
present, awol, leave, sick, suspended, disciplinary, on_duty, on_course
Stored in `daily_status.status` with CHECK constraint.

## Key helpers (includes/)
- `scope_where(user, alias)` — returns [WHERE_sql, params] for filtering employees
- `scope_where_for(user, alias)` — same but for reports/leave_requests tables
- `hierarchy_summary(pdo, date, user)` — groups stats at level below user's role
- `can_manage_user(actor, target)` — checks role rank + shared scope
- `creatable_roles(actor)` — roles actor is allowed to create

## DB migration
v2 migration handles old branches→regions conversion. Always uses fresh seed now (old DB deleted).
If adding new columns to employees/users, do it in migrate() v3+ with ALTER TABLE ADD COLUMN.

## Demo accounts
admin/admin123 (superadmin), rcmd_kla/rcmd123, rcmd_rwz/rcmd123, rcmd_nky/rcmd123,
dcmd_kcd/dcmd123, scmd_cps/scmd123, pcmd_cps1/pcmd123, offr_cps1/offr123, dir_ops/dir123, unit_gd/unit123

**Why denormalize IDs on employees:** SQLite lacks lateral joins; keeping all 4 IDs on employees means every scope filter is O(1) index lookup with no multi-level join chain.

**Why separate tables not self-referencing:** Cleaner typed foreign keys, easier to add level-specific columns later, avoids recursive CTE complexity in SQLite.
