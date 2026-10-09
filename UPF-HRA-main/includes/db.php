<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (!is_dir(dirname(DB_PATH))) mkdir(dirname(DB_PATH), 0775, true);
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        init_schema($pdo);
        migrate($pdo);
    }
    return $pdo;
}

/* ─── Schema bootstrap (idempotent) ─── */
function init_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS regions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            code TEXT NOT NULL UNIQUE,
            location TEXT
        );
        CREATE TABLE IF NOT EXISTS divisions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            region_id INTEGER NOT NULL REFERENCES regions(id),
            name TEXT NOT NULL,
            code TEXT NOT NULL UNIQUE
        );
        CREATE TABLE IF NOT EXISTS stations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            division_id INTEGER NOT NULL REFERENCES divisions(id),
            name TEXT NOT NULL,
            code TEXT NOT NULL UNIQUE
        );
        CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            station_id INTEGER NOT NULL REFERENCES stations(id),
            name TEXT NOT NULL,
            code TEXT NOT NULL UNIQUE
        );
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            full_name TEXT NOT NULL,
            role TEXT NOT NULL,
            region_id    INTEGER REFERENCES regions(id),
            division_id  INTEGER REFERENCES divisions(id),
            station_id   INTEGER REFERENCES stations(id),
            post_id      INTEGER REFERENCES posts(id)
        );
        CREATE TABLE IF NOT EXISTS employees (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            service_no TEXT NOT NULL UNIQUE,
            full_name TEXT NOT NULL,
            gender TEXT NOT NULL CHECK(gender IN ('M','F')),
            rank TEXT NOT NULL,
            region_id    INTEGER REFERENCES regions(id),
            division_id  INTEGER REFERENCES divisions(id),
            station_id   INTEGER REFERENCES stations(id),
            post_id      INTEGER REFERENCES posts(id),
            phone TEXT,
            active INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE IF NOT EXISTS daily_status (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
            date TEXT NOT NULL,
            status TEXT NOT NULL CHECK(status IN ('present','awol','leave','sick','suspended','disciplinary','on_duty','on_course','deserted','special_assignment','undeployed')),
            notes TEXT,
            recorded_by INTEGER REFERENCES users(id),
            auto_status INTEGER NOT NULL DEFAULT 0,
            UNIQUE(employee_id, date)
        );
        CREATE TABLE IF NOT EXISTS reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id       INTEGER REFERENCES posts(id),
            station_id    INTEGER REFERENCES stations(id),
            division_id   INTEGER REFERENCES divisions(id),
            region_id     INTEGER REFERENCES regions(id),
            scope_level   TEXT NOT NULL DEFAULT 'post',
            date TEXT NOT NULL,
            generated_by  INTEGER REFERENCES users(id),
            generated_at  TEXT NOT NULL,
            summary_json  TEXT NOT NULL,
            status        TEXT NOT NULL DEFAULT 'approved',
            reviewed_by   INTEGER REFERENCES users(id),
            reviewed_at   TEXT,
            review_notes  TEXT
        );
        CREATE TABLE IF NOT EXISTS leave_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id   INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
            post_id       INTEGER REFERENCES posts(id),
            station_id    INTEGER REFERENCES stations(id),
            division_id   INTEGER REFERENCES divisions(id),
            region_id     INTEGER REFERENCES regions(id),
            leave_type    TEXT NOT NULL DEFAULT 'Annual',
            start_date    TEXT NOT NULL,
            end_date      TEXT NOT NULL,
            reason        TEXT NOT NULL,
            status        TEXT NOT NULL DEFAULT 'pending',
            submitted_by  INTEGER REFERENCES users(id),
            submitted_at  TEXT NOT NULL,
            reviewed_by   INTEGER REFERENCES users(id),
            reviewed_at   TEXT,
            review_notes  TEXT
        );
        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            message TEXT NOT NULL,
            link TEXT,
            kind TEXT NOT NULL DEFAULT 'info',
            audience TEXT NOT NULL,
            target_user_id    INTEGER REFERENCES users(id),
            target_role       TEXT,
            target_region_id  INTEGER REFERENCES regions(id),
            created_by        INTEGER REFERENCES users(id),
            created_at        TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS notification_reads (
            notification_id INTEGER NOT NULL REFERENCES notifications(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL,
            read_at TEXT NOT NULL,
            PRIMARY KEY (notification_id, user_id)
        );
    ");

    // Seed on empty DB
    if ((int)$pdo->query("SELECT COUNT(*) FROM regions")->fetchColumn() === 0) {
        seed_data($pdo);
    }
}

/* ─── Migrations ─── */
function migrate(PDO $pdo): void {
    $v = (int)$pdo->query("PRAGMA user_version")->fetchColumn();

    if ($v < 1) {
        // Legacy v1 from old schema (branches-based) — skip to v2
        $pdo->exec("PRAGMA user_version = 1");
        $v = 1;
    }

    if ($v < 2) {
        // v2: migrate from old branches-based schema to full hierarchy
        $pdo->exec("PRAGMA foreign_keys = OFF");
        $pdo->exec("BEGIN");
        try {
            _migrate_v2($pdo);
            $pdo->exec("PRAGMA user_version = 2");
            $pdo->exec("COMMIT");
            $v = 2;
        } catch (\Throwable $e) {
            $pdo->exec("ROLLBACK");
            $pdo->exec("PRAGMA foreign_keys = ON");
            throw $e;
        }
        $pdo->exec("PRAGMA foreign_keys = ON");
    }

    if ($v < 3) {
        // v3: add email, directorate, unit columns to employees
        $cols = array_column($pdo->query("PRAGMA table_info(employees)")->fetchAll(), 'name');
        if (!in_array('email',       $cols)) $pdo->exec("ALTER TABLE employees ADD COLUMN email TEXT");
        if (!in_array('directorate', $cols)) $pdo->exec("ALTER TABLE employees ADD COLUMN directorate TEXT");
        if (!in_array('unit',        $cols)) $pdo->exec("ALTER TABLE employees ADD COLUMN unit TEXT");
        $pdo->exec("PRAGMA user_version = 3");
        $v = 3;
    }

    if ($v < 4) {
        // v4: add photo_path to employees; recreate daily_status to allow 'deserted' status
        $pdo->exec("PRAGMA foreign_keys = OFF");
        $pdo->exec("BEGIN");
        try {
            $empCols = array_column($pdo->query("PRAGMA table_info(employees)")->fetchAll(), 'name');
            if (!in_array('photo_path', $empCols)) {
                $pdo->exec("ALTER TABLE employees ADD COLUMN photo_path TEXT");
            }
            // Recreate daily_status with deserted added to CHECK
            $pdo->exec("CREATE TABLE IF NOT EXISTS daily_status_v4 (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
                date TEXT NOT NULL,
                status TEXT NOT NULL CHECK(status IN ('present','awol','leave','sick','suspended','disciplinary','on_duty','on_course','deserted')),
                notes TEXT,
                recorded_by INTEGER REFERENCES users(id),
                UNIQUE(employee_id, date)
            )");
            $pdo->exec("INSERT OR IGNORE INTO daily_status_v4 SELECT * FROM daily_status");
            $pdo->exec("DROP TABLE daily_status");
            $pdo->exec("ALTER TABLE daily_status_v4 RENAME TO daily_status");
            $pdo->exec("PRAGMA user_version = 4");
            $pdo->exec("COMMIT");
        } catch (\Throwable $e) {
            $pdo->exec("ROLLBACK");
            $pdo->exec("PRAGMA foreign_keys = ON");
            throw $e;
        }
        $pdo->exec("PRAGMA foreign_keys = ON");
    }

    if ($v < 5) {
        // v5: add email and phone columns to users table
        $uCols = array_column($pdo->query("PRAGMA table_info(users)")->fetchAll(), 'name');
        if (!in_array('email', $uCols)) $pdo->exec("ALTER TABLE users ADD COLUMN email TEXT");
        if (!in_array('phone', $uCols)) $pdo->exec("ALTER TABLE users ADD COLUMN phone TEXT");
        $pdo->exec("PRAGMA user_version = 5");
    }

    if ($v < 6) {
        // v6: settings store + communications log
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                key   TEXT PRIMARY KEY,
                value TEXT
            );
            CREATE TABLE IF NOT EXISTS communications (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                subject          TEXT NOT NULL,
                body             TEXT NOT NULL,
                sender_id        INTEGER REFERENCES users(id),
                recipient_type   TEXT NOT NULL DEFAULT 'all',
                recipient_id     INTEGER,
                recipient_label  TEXT,
                channel          TEXT NOT NULL DEFAULT 'email',
                sent_at          TEXT NOT NULL,
                status           TEXT NOT NULL DEFAULT 'pending',
                total_recipients INTEGER DEFAULT 0,
                delivered        INTEGER DEFAULT 0,
                failed           INTEGER DEFAULT 0,
                error_log        TEXT
            );
        ");
        $pdo->exec("PRAGMA user_version = 6");
    }

    if ($v < 7) {
        // v7: activity log table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS activity_log (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id      INTEGER REFERENCES users(id),
                user_name    TEXT,
                action       TEXT NOT NULL,
                entity_type  TEXT,
                entity_id    INTEGER,
                entity_label TEXT,
                details      TEXT,
                ip_address   TEXT,
                created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime'))
            );
        ");
        $pdo->exec("PRAGMA user_version = 7");
    }

    if ($v < 8) {
        // v8: directorates & units tables
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS directorates (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT NOT NULL UNIQUE,
                code        TEXT NOT NULL UNIQUE,
                description TEXT,
                active      INTEGER NOT NULL DEFAULT 1
            );
            CREATE TABLE IF NOT EXISTS units (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                directorate_id  INTEGER NOT NULL REFERENCES directorates(id),
                name            TEXT NOT NULL,
                code            TEXT NOT NULL UNIQUE,
                description     TEXT,
                active          INTEGER NOT NULL DEFAULT 1
            );
        ");
        // Seed default directorates
        $dirs = [
            ['Operations','OPS'],['Criminal Investigations','CID'],['Special Branch','SB'],
            ['Traffic','TRF'],['Fire Brigade','FIRE'],['Marine','MARINE'],
            ['Administration','ADMIN'],['Finance','FIN'],['Human Resource','HR'],
            ['Training','TRN'],['Logistics','LOG'],['Media','MEDIA'],
            ['Legal','LEGAL'],['ICT','ICT'],['Other','OTHER'],
        ];
        $dStmt = $pdo->prepare("INSERT OR IGNORE INTO directorates (name,code) VALUES (?,?)");
        foreach ($dirs as [$dn,$dc]) $dStmt->execute([$dn,$dc]);
        // Seed default units under Operations
        $opsId = (int)$pdo->query("SELECT id FROM directorates WHERE code='OPS'")->fetchColumn();
        if ($opsId) {
            $units = [
                ['General Duty','GD'],['Flying Squad','FS'],['Anti-Stock Theft','AST'],
                ['Anti-Terrorism','ATC'],['Border Security','BS'],['K9 Unit','K9'],
                ['Rapid Response','RR'],['VIP Protection','VIP'],['Community Policing','CP'],
            ];
            $uStmt = $pdo->prepare("INSERT OR IGNORE INTO units (directorate_id,name,code) VALUES (?,?,?)");
            foreach ($units as [$un,$uc]) $uStmt->execute([$opsId,$un,$uc]);
        }
        $pdo->exec("PRAGMA user_version = 8");
    }

    if ($v < 9) {
        _migrate_v9($pdo);
    }

    if ($v < 10) {
        _migrate_v10($pdo);
    }

    if ($v < 11) {
        _migrate_v11($pdo);
    }

    if ($v < 12) {
        _migrate_v12($pdo);
    }
}

/* ─── v12: functional command roles (Directorate / Unit Commander) ─── */
function _migrate_v12(PDO $pdo): void {
    $pdo->exec("BEGIN");
    try {
        // Users: functional command binds an account to a directorate and optional unit.
        $uCols = array_column($pdo->query("PRAGMA table_info(users)")->fetchAll(), 'name');
        if (!in_array('unit_id', $uCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN unit_id INTEGER REFERENCES units(id)");
        }

        // Reports + leave requests: record the functional origin so directorate/unit
        // commanders can see their own command's reports and leave records.
        $rCols = array_column($pdo->query("PRAGMA table_info(reports)")->fetchAll(), 'name');
        if (!in_array('directorate_id', $rCols, true)) {
            $pdo->exec("ALTER TABLE reports ADD COLUMN directorate_id INTEGER REFERENCES directorates(id)");
        }
        if (!in_array('unit_id', $rCols, true)) {
            $pdo->exec("ALTER TABLE reports ADD COLUMN unit_id INTEGER REFERENCES units(id)");
        }
        $lCols = array_column($pdo->query("PRAGMA table_info(leave_requests)")->fetchAll(), 'name');
        if (!in_array('directorate_id', $lCols, true)) {
            $pdo->exec("ALTER TABLE leave_requests ADD COLUMN directorate_id INTEGER REFERENCES directorates(id)");
        }
        if (!in_array('unit_id', $lCols, true)) {
            $pdo->exec("ALTER TABLE leave_requests ADD COLUMN unit_id INTEGER REFERENCES units(id)");
        }

        // Demo functional command accounts (idempotent) + a few demo personnel so
        // the new roles can see staff on a fresh seed. On real databases we only
        // create the accounts (guarded by $createdOps) and never reshuffle staff.
        $opsId    = (int)$pdo->query("SELECT id FROM directorates WHERE code='OPS'")->fetchColumn();
        $gdId     = $opsId ? (int)$pdo->query("SELECT id FROM units WHERE code='GD'")->fetchColumn() : 0;
        $dirExists = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE username='dir_ops'")->fetchColumn() > 0;
        $createdOps = $opsId && !$dirExists;
        if ($createdOps) {
            $pdo->prepare("INSERT INTO users (username,password_hash,full_name,role,directorate_id,unit_id) VALUES (?,?,?,?,?,?)")
                ->execute(['dir_ops', password_hash('dir123', PASSWORD_DEFAULT), 'Operations Directorate Commander', 'directorate_commander', $opsId, null]);
            if ($gdId && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE username='unit_gd'")->fetchColumn() === 0) {
                $pdo->prepare("INSERT INTO users (username,password_hash,full_name,role,directorate_id,unit_id) VALUES (?,?,?,?,?,?)")
                    ->execute(['unit_gd', password_hash('unit123', PASSWORD_DEFAULT), 'General Duty Unit Commander', 'unit_commander', $opsId, $gdId]);
            }
            if ((int)$pdo->query("SELECT COUNT(*) FROM employees WHERE directorate='Operations'")->fetchColumn() === 0) {
                $pdo->exec("UPDATE employees SET directorate='Operations', unit='General Duty' WHERE id IN (SELECT id FROM employees WHERE active=1 ORDER BY service_no LIMIT 8)");
            }
        } elseif ((int)$pdo->query("SELECT COUNT(*) FROM users WHERE username='dir_ops'")->fetchColumn() === 0 && $opsId) {
            $pdo->prepare("INSERT INTO users (username,password_hash,full_name,role,directorate_id,unit_id) VALUES (?,?,?,?,?,?)")
                ->execute(['dir_ops', password_hash('dir123', PASSWORD_DEFAULT), 'Operations Directorate Commander', 'directorate_commander', $opsId, null]);
        }

        $pdo->exec("PRAGMA user_version = 12");
        $pdo->exec("COMMIT");
    } catch (\Throwable $e) {
        $pdo->exec("ROLLBACK");
        throw $e;
    }
}

/* ─── v11: hierarchical report workflow, leave destinations/countdown, course & school registries ─── */
function _migrate_v11(PDO $pdo): void {
    $pdo->exec("BEGIN");
    try {
        // Reports: track which command level each report currently awaits review at,
        // how many times it has been re-submitted, and a log of every workflow action.
        $rCols = array_column($pdo->query("PRAGMA table_info(reports)")->fetchAll(), 'name');
        if (!in_array('current_level', $rCols, true)) {
            $pdo->exec("ALTER TABLE reports ADD COLUMN current_level TEXT");
        }
        if (!in_array('revision', $rCols, true)) {
            $pdo->exec("ALTER TABLE reports ADD COLUMN revision INTEGER NOT NULL DEFAULT 0");
        }
        // Back-fill: pending reports go to HQ, everything else is settled.
        $pdo->exec("UPDATE reports SET current_level = CASE status
                        WHEN 'pending_superadmin' THEN 'hq'
                        WHEN 'pending_commander'  THEN 'region'
                        ELSE NULL END
                    WHERE current_level IS NULL");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS report_actions (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                report_id   INTEGER NOT NULL REFERENCES reports(id) ON DELETE CASCADE,
                action      TEXT NOT NULL,
                from_level  TEXT,
                to_level    TEXT,
                user_id     INTEGER REFERENCES users(id),
                notes       TEXT,
                created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
            );
            CREATE INDEX IF NOT EXISTS idx_report_actions_report ON report_actions(report_id);
        ");

        // Leave: record where the officer is going + flag commanders notified on expiry.
        $lcCols = array_column($pdo->query("PRAGMA table_info(leave_requests)")->fetchAll(), 'name');
        if (!in_array('destination', $lcCols, true)) {
            $pdo->exec("ALTER TABLE leave_requests ADD COLUMN destination TEXT");
        }
        if (!in_array('expiry_notified', $lcCols, true)) {
            $pdo->exec("ALTER TABLE leave_requests ADD COLUMN expiry_notified INTEGER NOT NULL DEFAULT 0");
        }

        // Course + training school registries (managed centrally; on-course rows still store free text).
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS courses (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                name          TEXT NOT NULL UNIQUE,
                nature        TEXT NOT NULL DEFAULT 'Professional',
                duration_days INTEGER,
                created_by    INTEGER REFERENCES users(id),
                created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime'))
            );
            CREATE TABLE IF NOT EXISTS training_schools (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT NOT NULL UNIQUE,
                place       TEXT,
                created_by  INTEGER REFERENCES users(id),
                created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
            );
        ");
        $pdo->exec("PRAGMA user_version = 11");
        $pdo->exec("COMMIT");
    } catch (\Throwable $e) {
        $pdo->exec("ROLLBACK");
        throw $e;
    }
}

/* ─── v10: transfer reporting workflow + directorate-targeted notifications ─── */
function _migrate_v10(PDO $pdo): void {
    $pdo->exec("BEGIN");
    try {
        // User accounts may be attached to a directorate so directorate-level
        // notifications (e.g. transfer alerts) can reach the right personnel.
        $uCols = array_column($pdo->query("PRAGMA table_info(users)")->fetchAll(), 'name');
        if (!in_array('directorate_id', $uCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN directorate_id INTEGER REFERENCES directorates(id)");
        }

        // Transfers: track whether the transferred officer reported for duty at
        // the new place of work (keeps an accurate personnel transfer record).
        $tCols = array_column($pdo->query("PRAGMA table_info(transfers)")->fetchAll(), 'name');
        if (!in_array('report_status', $tCols, true)) {
            $pdo->exec("ALTER TABLE transfers ADD COLUMN report_status TEXT NOT NULL DEFAULT 'pending' CHECK(report_status IN ('pending','reported'))");
            $pdo->exec("ALTER TABLE transfers ADD COLUMN reported_by   INTEGER REFERENCES users(id)");
            $pdo->exec("ALTER TABLE transfers ADD COLUMN reported_at   TEXT");
            $pdo->exec("ALTER TABLE transfers ADD COLUMN report_notes  TEXT");
        }

        // Notifications: allow targeting an entire directorate / unit.
        $nCols = array_column($pdo->query("PRAGMA table_info(notifications)")->fetchAll(), 'name');
        if (!in_array('target_directorate_id', $nCols, true)) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN target_directorate_id INTEGER REFERENCES directorates(id)");
        }

        $pdo->exec("PRAGMA user_version = 10");
        $pdo->exec("COMMIT");
    } catch (\Throwable $e) {
        $pdo->exec("ROLLBACK");
        throw $e;
    }
}

/* ─── v9: assignments, courses, discipline, undeployed, transfers, leave adjustments ─── */
function _migrate_v9(PDO $pdo): void {
    $pdo->exec("BEGIN");
    try {
        // Annual leave entitlement per employee
        $empCols = array_column($pdo->query("PRAGMA table_info(employees)")->fetchAll(), 'name');
        if (!in_array('annual_leave_days', $empCols)) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN annual_leave_days INTEGER NOT NULL DEFAULT 30");
        }

        // Extend daily_status CHECK to include special_assignment + undeployed
        $pdo->exec("CREATE TABLE IF NOT EXISTS daily_status_v9 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
            date TEXT NOT NULL,
            status TEXT NOT NULL CHECK(status IN ('present','awol','leave','sick','suspended','disciplinary','on_duty','on_course','deserted','special_assignment','undeployed')),
            notes TEXT,
            recorded_by INTEGER REFERENCES users(id),
            auto_status INTEGER NOT NULL DEFAULT 0,
            UNIQUE(employee_id, date)
        )");
        $dsColsV4 = array_column($pdo->query("PRAGMA table_info(daily_status)")->fetchAll(), 'name');
        $hasAuto  = in_array('auto_status', $dsColsV4, true);
        if ($hasAuto) {
            $pdo->exec("INSERT OR IGNORE INTO daily_status_v9 (id,employee_id,date,status,notes,recorded_by,auto_status)
                        SELECT id,employee_id,date,status,notes,recorded_by,auto_status FROM daily_status");
        } else {
            $pdo->exec("INSERT OR IGNORE INTO daily_status_v9 (id,employee_id,date,status,notes,recorded_by,auto_status)
                        SELECT id,employee_id,date,status,notes,recorded_by,0 FROM daily_status");
        }
        $pdo->exec("DROP TABLE daily_status");
        $pdo->exec("ALTER TABLE daily_status_v9 RENAME TO daily_status");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_daily_status_date ON daily_status(date)");

        // Special assignments (officers detached on special duty)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS special_assignments (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id  INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
                title        TEXT NOT NULL,
                nature       TEXT,
                place        TEXT,
                start_date   TEXT NOT NULL,
                end_date     TEXT,
                status       TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','completed','cancelled')),
                notes        TEXT,
                created_by   INTEGER REFERENCES users(id),
                created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                ended_at     TEXT,
                end_remarks  TEXT
            )
        ");

        // On-course records (training management)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS on_courses (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id     INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
                course_name     TEXT NOT NULL,
                course_nature   TEXT NOT NULL DEFAULT 'Professional',
                school          TEXT,
                place           TEXT,
                start_date      TEXT NOT NULL,
                end_date        TEXT NOT NULL,
                duration_days   INTEGER,
                sponsor         TEXT,
                status          TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','completed','withdrawn')),
                notes           TEXT,
                created_by      INTEGER REFERENCES users(id),
                created_at      TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                completed_at    TEXT
            )
        ");

        // Suspension & disciplinary case management
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS discipline_cases (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id    INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
                case_type      TEXT NOT NULL CHECK(case_type IN ('suspension','disciplinary')),
                case_ref       TEXT,
                offence        TEXT NOT NULL,
                start_date     TEXT NOT NULL,
                end_date       TEXT,
                status         TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','closed','reinstated')),
                outcome        TEXT,
                notes          TEXT,
                created_by     INTEGER REFERENCES users(id),
                created_at     TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                closed_at      TEXT
            )
        ");

        // Undeployed personnel (on strength but not deployed)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS undeployments (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id    INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
                reason         TEXT NOT NULL,
                start_date     TEXT NOT NULL,
                end_date       TEXT,
                status         TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','redeployed')),
                notes          TEXT,
                created_by     INTEGER REFERENCES users(id),
                created_at     TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                redeployed_at  TEXT
            )
        ");

        // Transfer management — hierarchy (region/division/station/post) or directorate/unit
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS transfers (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id         INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
                transfer_scope      TEXT NOT NULL DEFAULT 'hierarchy' CHECK(transfer_scope IN ('hierarchy','directorate')),
                from_region_id      INTEGER, from_division_id INTEGER, from_station_id INTEGER, from_post_id INTEGER,
                to_region_id        INTEGER REFERENCES regions(id),
                to_division_id      INTEGER REFERENCES divisions(id),
                to_station_id       INTEGER REFERENCES stations(id),
                to_post_id          INTEGER REFERENCES posts(id),
                from_directorate_id INTEGER REFERENCES directorates(id),
                from_unit_id        INTEGER REFERENCES units(id),
                to_directorate_id   INTEGER REFERENCES directorates(id),
                to_unit_id          INTEGER REFERENCES units(id),
                reason              TEXT,
                transfer_date       TEXT NOT NULL,
                effective_date      TEXT NOT NULL,
                status              TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected','executed','cancelled')),
                requested_by        INTEGER REFERENCES users(id),
                requested_at        TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                reviewed_by         INTEGER REFERENCES users(id),
                reviewed_at         TEXT,
                review_notes        TEXT,
                executed_by         INTEGER REFERENCES users(id),
                executed_at         TEXT
            )
        ");

        // Leave adjustments (+ credit / − deduct days against entitlement)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS leave_adjustments (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id  INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
                days         INTEGER NOT NULL,
                reason       TEXT NOT NULL,
                created_by   INTEGER REFERENCES users(id),
                created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime'))
            )
        ");
        $pdo->exec("PRAGMA user_version = 9");
        $pdo->exec("COMMIT");
    } catch (\Throwable $e) {
        $pdo->exec("ROLLBACK");
        throw $e;
    }
}

function _migrate_v2(PDO $pdo): void {
    // Check if old schema (branches table) exists
    $hasBranches = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='branches'")->fetchColumn();

    /* 1. Create hierarchy tables if not present */
    $pdo->exec("CREATE TABLE IF NOT EXISTS regions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, code TEXT NOT NULL UNIQUE, location TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS divisions (id INTEGER PRIMARY KEY AUTOINCREMENT, region_id INTEGER NOT NULL, name TEXT NOT NULL, code TEXT NOT NULL UNIQUE)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS stations (id INTEGER PRIMARY KEY AUTOINCREMENT, division_id INTEGER NOT NULL, name TEXT NOT NULL, code TEXT NOT NULL UNIQUE)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS posts (id INTEGER PRIMARY KEY AUTOINCREMENT, station_id INTEGER NOT NULL, name TEXT NOT NULL, code TEXT NOT NULL UNIQUE)");

    /* 2. Populate regions from old branches */
    if ($hasBranches && (int)$pdo->query("SELECT COUNT(*) FROM regions")->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO regions (name, code, location) SELECT name, code, location FROM branches");
    }

    /* 3. Build division/station/post chain for each region */
    $branchPostMap = []; // region_code => [first_post_id, ...]
    foreach ($pdo->query("SELECT * FROM regions") as $reg) {
        $rc = $reg['code'];
        $rid = (int)$reg['id'];
        // Two divisions per region
        $divDefs = [
            [$rc.'CD', $reg['name'] . ' Central Division'],
            [$rc.'ED', $reg['name'] . ' East Division'],
        ];
        foreach ($divDefs as [$dcode, $dname]) {
            $pdo->prepare("INSERT OR IGNORE INTO divisions (region_id, name, code) VALUES (?,?,?)")->execute([$rid, $dname, $dcode]);
            $did = (int)$pdo->query("SELECT id FROM divisions WHERE code='$dcode'")->fetchColumn();
            // Two stations per division
            for ($si = 1; $si <= 2; $si++) {
                $scode = $dcode.'S'.$si;
                $sname = $dname . ' Station '.$si;
                $pdo->prepare("INSERT OR IGNORE INTO stations (division_id, name, code) VALUES (?,?,?)")->execute([$did, $sname, $scode]);
                $stid = (int)$pdo->query("SELECT id FROM stations WHERE code='$scode'")->fetchColumn();
                // Two posts per station
                for ($pi = 1; $pi <= 2; $pi++) {
                    $pcode = $scode.'P'.$pi;
                    $pname = $sname . ' Post '.$pi;
                    $pdo->prepare("INSERT OR IGNORE INTO posts (station_id, name, code) VALUES (?,?,?)")->execute([$stid, $pname, $pcode]);
                    $ptid = (int)$pdo->query("SELECT id FROM posts WHERE code='$pcode'")->fetchColumn();
                    if (!isset($branchPostMap[$rc])) $branchPostMap[$rc] = [];
                    $branchPostMap[$rc][] = ['post_id'=>$ptid,'station_id'=>$stid,'division_id'=>$did,'region_id'=>$rid];
                }
            }
        }
    }

    /* 4. Recreate users table with new role set + scope columns */
    $pdo->exec("CREATE TABLE users_v2 (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        full_name TEXT NOT NULL,
        role TEXT NOT NULL,
        region_id INTEGER,
        division_id INTEGER,
        station_id INTEGER,
        post_id INTEGER
    )");

    // Migrate old users if they exist
    $hasBranchIdOnUsers = false;
    if ($hasBranches) {
        foreach ($pdo->query("PRAGMA table_info(users)") as $c) {
            if ($c['name'] === 'branch_id') $hasBranchIdOnUsers = true;
        }
    }
    if ($hasBranchIdOnUsers) {
        $oldUsers = $pdo->query("SELECT u.*, b.code AS bcode FROM users u LEFT JOIN branches b ON b.id=u.branch_id")->fetchAll();
        $insert = $pdo->prepare("INSERT INTO users_v2 (id, username, password_hash, full_name, role, region_id, division_id, station_id, post_id) VALUES (?,?,?,?,?,?,?,?,?)");
        foreach ($oldUsers as $u) {
            $newRole = match($u['role'] ?? '') {
                'admin' => 'superadmin',
                'manager' => 'regional_commander',
                'officer' => 'post_commander',
                default => 'officer',
            };
            $rid = null; $did = null; $sid = null; $pid = null;
            if ($u['bcode'] && isset($branchPostMap[$u['bcode']])) {
                $first = $branchPostMap[$u['bcode']][0];
                if ($newRole === 'regional_commander') { $rid = $first['region_id']; }
                elseif ($newRole === 'post_commander') { $rid=$first['region_id']; $did=$first['division_id']; $sid=$first['station_id']; $pid=$first['post_id']; }
            }
            $insert->execute([$u['id'], $u['username'], $u['password_hash'], $u['full_name'], $newRole, $rid, $did, $sid, $pid]);
        }
    } else {
        // Fresh run — copy from current users table (already v2 shape or empty)
        $cols = [];
        foreach ($pdo->query("PRAGMA table_info(users)") as $c) $cols[] = $c['name'];
        if (in_array('role', $cols)) {
            $common = implode(',', array_map(fn($c)=>'"'.$c.'"', array_intersect($cols, ['id','username','password_hash','full_name','role','region_id','division_id','station_id','post_id'])));
            $pdo->exec("INSERT INTO users_v2 ($common) SELECT $common FROM users");
        }
    }
    $pdo->exec("DROP TABLE IF EXISTS users");
    $pdo->exec("ALTER TABLE users_v2 RENAME TO users");

    /* 5. Rebuild employees with hierarchy columns */
    $empCols = [];
    foreach ($pdo->query("PRAGMA table_info(employees)") as $c) $empCols[$c['name']] = true;
    $hasOldEmp = isset($empCols['branch_id']);

    if ($hasOldEmp) {
        $pdo->exec("CREATE TABLE employees_v2 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            service_no TEXT NOT NULL UNIQUE,
            full_name TEXT NOT NULL,
            gender TEXT NOT NULL CHECK(gender IN ('M','F')),
            rank TEXT NOT NULL,
            region_id INTEGER, division_id INTEGER, station_id INTEGER, post_id INTEGER,
            phone TEXT, active INTEGER NOT NULL DEFAULT 1
        )");
        $oldEmps = $pdo->query("SELECT e.*, b.code AS bcode FROM employees e LEFT JOIN branches b ON b.id=e.branch_id")->fetchAll();
        $ins = $pdo->prepare("INSERT INTO employees_v2 (id,service_no,full_name,gender,rank,region_id,division_id,station_id,post_id,phone,active) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($oldEmps as $e) {
            $sc = $e['bcode'];
            $chain = $branchPostMap[$sc][0] ?? ['region_id'=>null,'division_id'=>null,'station_id'=>null,'post_id'=>null];
            $ins->execute([$e['id'],$e['service_no'],$e['full_name'],$e['gender'],$e['rank'],$chain['region_id'],$chain['division_id'],$chain['station_id'],$chain['post_id'],$e['phone'],$e['active']]);
        }
        $pdo->exec("DROP TABLE employees");
        $pdo->exec("ALTER TABLE employees_v2 RENAME TO employees");
    } elseif (!isset($empCols['post_id'])) {
        foreach (['region_id','division_id','station_id','post_id'] as $col) {
            if (!isset($empCols[$col])) $pdo->exec("ALTER TABLE employees ADD COLUMN $col INTEGER");
        }
    }

    /* 6. Rebuild daily_status with extended statuses */
    $pdo->exec("CREATE TABLE daily_status_v2 (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
        date TEXT NOT NULL,
        status TEXT NOT NULL CHECK(status IN ('present','awol','leave','sick','suspended','disciplinary','on_duty','on_course')),
        notes TEXT,
        recorded_by INTEGER REFERENCES users(id),
        UNIQUE(employee_id, date)
    )");
    $pdo->exec("INSERT INTO daily_status_v2 (id,employee_id,date,status,notes,recorded_by)
        SELECT id,employee_id,date,status,notes,recorded_by FROM daily_status
        WHERE status IN ('present','awol','leave','sick')");
    $pdo->exec("DROP TABLE daily_status");
    $pdo->exec("ALTER TABLE daily_status_v2 RENAME TO daily_status");

    /* 7. Rebuild reports with hierarchy columns */
    $repCols = [];
    foreach ($pdo->query("PRAGMA table_info(reports)") as $c) $repCols[$c['name']] = true;
    if (!isset($repCols['scope_level'])) {
        $pdo->exec("CREATE TABLE reports_v2 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER, station_id INTEGER, division_id INTEGER, region_id INTEGER,
            scope_level TEXT NOT NULL DEFAULT 'post',
            date TEXT NOT NULL, generated_by INTEGER, generated_at TEXT NOT NULL,
            summary_json TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'approved',
            reviewed_by INTEGER, reviewed_at TEXT, review_notes TEXT
        )");
        $oldReps = $pdo->query("SELECT r.*, b.code AS bcode FROM reports r LEFT JOIN branches b ON b.id=r.branch_id")->fetchAll();
        $ins = $pdo->prepare("INSERT INTO reports_v2 (id,region_id,scope_level,date,generated_by,generated_at,summary_json,status) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($oldReps as $r) {
            $rid = null;
            if (!empty($r['bcode']) && $regions = $pdo->query("SELECT id FROM regions WHERE code='".$r['bcode']."'")->fetchColumn()) {
                $rid = (int)$regions;
            }
            $ins->execute([$r['id'], $rid, 'region', $r['date'], $r['generated_by'], $r['generated_at'], $r['summary_json'], $r['status']]);
        }
        $pdo->exec("DROP TABLE reports");
        $pdo->exec("ALTER TABLE reports_v2 RENAME TO reports");
    }

    /* 8. Rebuild leave_requests with hierarchy columns */
    $lrCols = [];
    foreach ($pdo->query("PRAGMA table_info(leave_requests)") as $c) $lrCols[$c['name']] = true;
    if (!isset($lrCols['post_id'])) {
        $pdo->exec("CREATE TABLE leave_requests_v2 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id INTEGER NOT NULL, post_id INTEGER, station_id INTEGER, division_id INTEGER, region_id INTEGER,
            leave_type TEXT NOT NULL DEFAULT 'Annual',
            start_date TEXT NOT NULL, end_date TEXT NOT NULL, reason TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            submitted_by INTEGER, submitted_at TEXT NOT NULL,
            reviewed_by INTEGER, reviewed_at TEXT, review_notes TEXT
        )");
        // Copy what we can
        $pdo->exec("INSERT INTO leave_requests_v2 (id,employee_id,leave_type,start_date,end_date,reason,status,submitted_by,submitted_at)
            SELECT id,employee_id,COALESCE(leave_type,'Annual'),start_date,end_date,reason,
                   CASE status WHEN 'approved' THEN 'approved' WHEN 'rejected_manager' THEN 'rejected' WHEN 'rejected_admin' THEN 'rejected' ELSE 'pending' END,
                   submitted_by,submitted_at FROM leave_requests");
        $pdo->exec("DROP TABLE leave_requests");
        $pdo->exec("ALTER TABLE leave_requests_v2 RENAME TO leave_requests");
    }

    /* 9. Drop old branches table */
    $pdo->exec("DROP TABLE IF EXISTS branches");

    /* 10. Seed demo hierarchy users */
    _seed_hierarchy_users($pdo, $branchPostMap);
}

function _seed_hierarchy_users(PDO $pdo, array $branchPostMap): void {
    // Ensure superadmin exists
    if (!(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='superadmin'")->fetchColumn()) {
        $pdo->prepare("INSERT OR IGNORE INTO users (username,password_hash,full_name,role) VALUES (?,?,?,?)")
            ->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'Super Administrator', 'superadmin']);
    } else {
        // Upgrade existing admin
        $pdo->exec("UPDATE users SET role='superadmin' WHERE role='admin'");
    }
    $chains = $branchPostMap; // region_code => [{post_id,...},...]
    // Demo users per region
    $demos = [
        'KLA' => [
            ['rcmd_kla','rcmd123','Kampala RC','regional_commander','region'],
            ['dcmd_kcd','dcmd123','Kampala Central DC','division_commander','divCD'],
            ['scmd_cps','scmd123','CPS Station Cmd','station_commander','staS1'],
            ['pcmd_cps1','pcmd123','CPS Post Commander','post_commander','pst0'],
            ['offr_cps1','offr123','CPS Field Officer','officer','pst0'],
        ],
        'RWZ' => [['rcmd_rwz','rcmd123','Rwizi RC','regional_commander','region']],
        'NKY' => [['rcmd_nky','rcmd123','N.Kyoga RC','regional_commander','region']],
    ];
    foreach ($demos as $rc => $users) {
        if (!isset($chains[$rc])) continue;
        $first = $chains[$rc][0];
        $regId = $first['region_id']; $divId = $first['division_id']; $stId = $first['station_id']; $pstId = $first['post_id'];
        foreach ($users as [$uname,$pw,$name,$role,$scope]) {
            if ((int)$pdo->prepare("SELECT COUNT(*) FROM users WHERE username=?")->execute([$uname]) && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE username='$uname'")->fetchColumn()) continue;
            [$rid,$did,$sid,$pid] = match($scope) {
                'region'  => [$regId, null, null, null],
                'divCD'   => [$regId, $divId, null, null],
                'staS1'   => [$regId, $divId, $stId, null],
                'pst0'    => [$regId, $divId, $stId, $pstId],
                default   => [null,null,null,null],
            };
            $pdo->prepare("INSERT OR IGNORE INTO users (username,password_hash,full_name,role,region_id,division_id,station_id,post_id) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$uname, password_hash($pw, PASSWORD_DEFAULT), $name, $role, $rid, $did, $sid, $pid]);
        }
    }
}

/* ─── Fresh seed (called only on empty DB) ─── */
function seed_data(PDO $pdo): void {
    $regionDefs = [
        ['Kampala Metropolitan', 'KLA', 'Kampala'],
        ['Rwizi', 'RWZ', 'Mbarara'],
        ['Northern Kyoga', 'NKY', 'Lira'],
    ];
    $rstmt = $pdo->prepare("INSERT INTO regions (name,code,location) VALUES (?,?,?)");
    foreach ($regionDefs as $r) $rstmt->execute($r);

    $regions = [];
    foreach ($pdo->query("SELECT * FROM regions") as $r) $regions[$r['code']] = (int)$r['id'];

    // Divisions, stations, posts per region
    $hierarchy = []; // region_code => [{region_id,division_id,station_id,post_id},...]
    $divDefs = [
        'KLA'=>[['Kampala Central Division','KLA-CD'],['Kampala East Division','KLA-ED']],
        'RWZ'=>[['Mbarara Division','RWZ-MB'],['Ibanda Division','RWZ-IB']],
        'NKY'=>[['Lira Division','NKY-LR'],['Oyam Division','NKY-OY']],
    ];
    $staDefs = [
        'KLA-CD'=>[['Central Police Station','KLA-CPS'],['Kampala South Station','KLA-KSS']],
        'KLA-ED'=>[['Nakawa Station','KLA-NKW'],['Kireka Station','KLA-KRK']],
        'RWZ-MB'=>[['Mbarara Central Station','RWZ-MCS'],['Ruti Station','RWZ-RTI']],
        'RWZ-IB'=>[['Ibanda Station','RWZ-IBS'],['Bushenyi Station','RWZ-BSH']],
        'NKY-LR'=>[['Lira Central Station','NKY-LCS'],['Ojwina Station','NKY-OJW']],
        'NKY-OY'=>[['Oyam Station','NKY-OYS'],['Apac Station','NKY-APC']],
    ];

    $dstmt = $pdo->prepare("INSERT INTO divisions (region_id,name,code) VALUES (?,?,?)");
    $sstmt = $pdo->prepare("INSERT INTO stations (division_id,name,code) VALUES (?,?,?)");
    $pstmt = $pdo->prepare("INSERT INTO posts (station_id,name,code) VALUES (?,?,?)");

    foreach ($regions as $rc => $rid) {
        foreach ($divDefs[$rc] as [$dname,$dcode]) {
            $dstmt->execute([$rid,$dname,$dcode]);
            $did = (int)$pdo->lastInsertId();
            foreach ($staDefs[$dcode] as [$sname,$scode]) {
                $sstmt->execute([$did,$sname,$scode]);
                $stid = (int)$pdo->lastInsertId();
                for ($pi=1; $pi<=2; $pi++) {
                    $pstmt->execute([$stid,"$sname Post $pi","$scode-P$pi"]);
                    $ptid = (int)$pdo->lastInsertId();
                    if (!isset($hierarchy[$rc])) $hierarchy[$rc]=[];
                    $hierarchy[$rc][] = ['region_id'=>$rid,'division_id'=>$did,'station_id'=>$stid,'post_id'=>$ptid,'post_code'=>"$scode-P$pi",'sta_code'=>$scode,'div_code'=>$dcode];
                }
            }
        }
    }

    // Superadmin
    $pdo->prepare("INSERT INTO users (username,password_hash,full_name,role) VALUES (?,?,?,?)")
        ->execute(['admin', password_hash('admin123',PASSWORD_DEFAULT), 'Super Administrator', 'superadmin']);

    // Regional commanders
    $rcDefs = ['KLA'=>['rcmd_kla','Kampala RC'],'RWZ'=>['rcmd_rwz','Rwizi RC'],'NKY'=>['rcmd_nky','N.Kyoga RC']];
    $ustmt = $pdo->prepare("INSERT INTO users (username,password_hash,full_name,role,region_id,division_id,station_id,post_id) VALUES (?,?,?,?,?,?,?,?)");
    foreach ($rcDefs as $rc => [$uname,$name]) {
        $rid = $regions[$rc];
        $ustmt->execute([$uname,password_hash('rcmd123',PASSWORD_DEFAULT),$name,'regional_commander',$rid,null,null,null]);
    }

    // Division commander (KLA-CD)
    $klaFirst = $hierarchy['KLA'][0];
    $klaCDDiv = $pdo->query("SELECT id FROM divisions WHERE code='KLA-CD'")->fetchColumn();
    $ustmt->execute(['dcmd_kcd',password_hash('dcmd123',PASSWORD_DEFAULT),'Kampala Central DC','division_commander',$klaFirst['region_id'],(int)$klaCDDiv,null,null]);

    // Station commander (KLA-CPS)
    $klaCPSSta = (int)$pdo->query("SELECT id FROM stations WHERE code='KLA-CPS'")->fetchColumn();
    $klaCPSP1  = (int)$pdo->query("SELECT id FROM posts WHERE code='KLA-CPS-P1'")->fetchColumn();
    $ustmt->execute(['scmd_cps',password_hash('scmd123',PASSWORD_DEFAULT),'KLA CPS Station Cmd','station_commander',$klaFirst['region_id'],(int)$klaCDDiv,$klaCPSSta,null]);

    // Post commander + officer
    $ustmt->execute(['pcmd_cps1',password_hash('pcmd123',PASSWORD_DEFAULT),'KLA-CPS Post 1 Commander','post_commander',$klaFirst['region_id'],(int)$klaCDDiv,$klaCPSSta,$klaCPSP1]);
    $ustmt->execute(['offr_cps1',password_hash('offr123',PASSWORD_DEFAULT),'KLA-CPS Field Officer','officer',$klaFirst['region_id'],(int)$klaCDDiv,$klaCPSSta,$klaCPSP1]);

    // Employees (12 per region, assigned to first post of their region)
    $ranks = ['Constable','Corporal','Sergeant','Inspector','ASP','SP'];
    $firstNames = ['John','Mary','Peter','Grace','Samuel','Esther','David','Joyce','Robert','Sarah','Moses','Ruth','James','Agnes','Paul','Joan'];
    $lastNames  = ['Okello','Nakato','Mugisha','Akello','Kato','Namukasa','Wasswa','Nakimera','Opio','Kintu','Bwambale','Nabukenya'];
    $estmt = $pdo->prepare("INSERT INTO employees (service_no,full_name,gender,rank,region_id,division_id,station_id,post_id,phone) VALUES (?,?,?,?,?,?,?,?,?)");
    $sn = 10001;
    $today = date('Y-m-d');
    $dsStmt = $pdo->prepare("INSERT INTO daily_status (employee_id,date,status,recorded_by) VALUES (?,?,?,1)");
    $allStatuses = ['present','present','present','present','present','awol','leave','sick','on_duty','on_course','suspended','disciplinary'];

    foreach ($regions as $rc => $rid) {
        $chain = $hierarchy[$rc][0];
        for ($i = 0; $i < 12; $i++) {
            $g = $i % 3 === 0 ? 'F' : 'M';
            $name = $firstNames[array_rand($firstNames)].' '.$lastNames[array_rand($lastNames)];
            $estmt->execute(['UPF'.$sn++, $name, $g, $ranks[array_rand($ranks)], $chain['region_id'], $chain['division_id'], $chain['station_id'], $chain['post_id'], '+25670'.random_int(1000000,9999999)]);
            $eid = (int)$pdo->lastInsertId();
            $dsStmt->execute([$eid, $today, $allStatuses[$i % count($allStatuses)]]);
        }
    }
}
