# Uganda Police Force — MDD Manager System
## Technical & User Documentation

**Version:** 2.0  
**Classification:** Internal Use — Uganda Police Force  
**Prepared by:** ICT Directorate  
**Date:** July 2026

---

## Table of Contents

1. [System Overview](#1-system-overview)
2. [Technology Stack](#2-technology-stack)
3. [System Architecture](#3-system-architecture)
4. [Geographic Hierarchy](#4-geographic-hierarchy)
5. [User Roles & Access Control](#5-user-roles--access-control)
6. [Database Schema](#6-database-schema)
7. [Module Reference](#7-module-reference)
   - 7.1 Authentication
   - 7.2 Dashboard
   - 7.3 Personnel Management
   - 7.4 Daily Status (MDD)
   - 7.5 Reports
   - 7.6 Leave Requests
   - 7.7 History
   - 7.8 User Accounts
   - 7.9 Organisational Structure
   - 7.10 Notifications
   - 7.11 Activity Log
   - 7.12 Communications
   - 7.13 Settings
   - 7.14 Bulk Import
   - 7.15 Data Export
8. [UPF Rank Order](#8-upf-rank-order)
9. [Personnel Statuses](#9-personnel-statuses)
10. [Security Model](#10-security-model)
11. [File & Directory Structure](#11-file--directory-structure)
12. [Installation & Setup](#12-installation--setup)
13. [Demo Accounts](#13-demo-accounts)
14. [Glossary](#14-glossary)

---

## 1. System Overview

The **MDD Manager System** (Morning Duty Deployment Manager) is a web-based Human Resource Management System purpose-built for the **Uganda Police Force (UPF)**. It enables commanders at every level of the police hierarchy to track, record, and report the daily duty deployment status of personnel under their command.

### Core Objectives

| Objective | Description |
|-----------|-------------|
| **Daily Strength Returns** | Record and submit morning parade (MDD) counts for every post |
| **Personnel Management** | Maintain a central register of all active police personnel |
| **Hierarchical Accountability** | Enforce chain-of-command reporting from Post → Station → Division → Region → HQ |
| **Leave Administration** | Submit, review, and approve leave requests |
| **Audit Trail** | Full activity log of all system actions |
| **Communications** | System-wide messaging from command to subordinate units |

---

## 2. Technology Stack

```
┌─────────────────────────────────────────────────────┐
│               TECHNOLOGY STACK                      │
├──────────────────────┬──────────────────────────────┤
│  Runtime             │  PHP 8.2                     │
│  Web Server          │  PHP Built-in Dev Server     │
│  Database            │  SQLite 3 (via PDO)          │
│  Frontend            │  HTML5, CSS3, Vanilla JS     │
│  Styling             │  Custom CSS (no framework)   │
│  Icons               │  Inline SVG                  │
│  Auth                │  PHP sessions + bcrypt       │
│  Email               │  Pure-PHP SMTP (no Composer) │
│  SMS                 │  Africa's Talking / Twilio   │
│  Package Manager     │  None (zero dependencies)    │
│  Port                │  5000                        │
└──────────────────────┴──────────────────────────────┘
```

**Design principle:** Zero external PHP libraries (no Composer). All functionality is implemented in pure PHP 8.2.

---

## 3. System Architecture

```
                        ┌─────────────────────────────────────┐
                        │           Browser Client            │
                        │    HTML + CSS + Vanilla JS          │
                        └──────────────┬──────────────────────┘
                                       │ HTTP/HTTPS
                                       ▼
┌──────────────────────────────────────────────────────────────┐
│                  PHP Built-in Web Server                     │
│                    0.0.0.0:5000                              │
│                                                              │
│   public/router.php  ──►  routes to .php files              │
│                                                              │
│   ┌──────────────┐   ┌──────────────┐   ┌───────────────┐  │
│   │   public/    │   │  includes/   │   │   data/       │  │
│   │  (20 pages)  │◄──│  auth.php    │   │  mdd.sqlite   │  │
│   │              │   │  db.php      │◄──│               │  │
│   │              │   │  helpers.php │   │               │  │
│   │              │   │  header.php  │   └───────────────┘  │
│   │              │   │  footer.php  │                       │
│   │              │   │  mailer.php  │                       │
│   │              │   │  sms.php     │                       │
│   └──────────────┘   └──────────────┘                       │
└──────────────────────────────────────────────────────────────┘
                                       │
                    ┌──────────────────┴─────────────────┐
                    │                                    │
                    ▼                                    ▼
          ┌──────────────────┐              ┌──────────────────┐
          │   SMTP Server    │              │  SMS Gateway     │
          │  (Email alerts)  │              │ Africa's Talking │
          └──────────────────┘              └──────────────────┘
```

### Request Lifecycle

```
Browser Request
      │
      ▼
router.php
      │
      ├─ Static file? ──► Serve directly (CSS, images)
      │
      └─ PHP page? ──► require page.php
                              │
                              ├─ require auth.php ──► session check
                              │        │
                              │        └─ Not logged in? ──► redirect /login.php
                              │
                              ├─ require helpers.php
                              ├─ db() ──► PDO SQLite connection
                              │
                              ├─ POST handler (form submissions)
                              │
                              ├─ Data query (SELECT)
                              │
                              ├─ include header.php  (nav + topbar)
                              ├─ Page HTML output
                              └─ include footer.php
```

---

## 4. Geographic Hierarchy

The UPF organisational geography follows a strict 4-level hierarchy. Every employee and every user account is scoped to one level of this hierarchy.

```
                    ╔══════════════════╗
                    ║   HEADQUARTERS   ║  ← Super Admin (national scope)
                    ╚════════╤═════════╝
                             │
          ┌──────────────────┼──────────────────┐
          │                  │                  │
    ╔═════╧══════╗    ╔══════╧══════╗    ╔══════╧══════╗
    ║  REGION A  ║    ║  REGION B  ║    ║  REGION C  ║  ← Regional Commanders
    ╚═════╤══════╝    ╚═════════════╝    ╚═════════════╝
          │
    ┌─────┴──────┐
    │            │
╔═══╧════╗  ╔═══╧════╗
║ DIVIS. ║  ║ DIVIS. ║   ← Division Commanders
╚═══╤════╝  ╚════════╝
    │
 ┌──┴──┐
 │     │
╔╧╗   ╔╧╗
║S║   ║S║   ← Stations (Station Commanders)
╚╤╝   ╚═╝
 │
╔╧╗
║P║   ← Posts (Post Commanders / Officers)
╚═╝
```

### Hierarchy Levels

| Level | Table | Description | Managed by |
|-------|-------|-------------|------------|
| **Region** | `regions` | Widest geographic unit (e.g., Kampala Metropolitan) | Super Admin |
| **Division** | `divisions` | Sub-region (e.g., Kampala Central Division) | Super Admin |
| **Station** | `stations` | Police station (e.g., Central Police Station) | Super Admin |
| **Post** | `posts` | Smallest deployable unit (e.g., CPS Post 1) | Super Admin |

### Functional Hierarchy (Directorates)

In addition to geography, UPF is organised into functional directorates:

```
┌─────────────────────────────────────────────────────┐
│                   DIRECTORATE                       │
│  (e.g., Operations, CID, Special Branch, Traffic)  │
│                                                     │
│   ┌──────────────┐   ┌───────────────┐              │
│   │   UNIT A     │   │   UNIT B      │              │
│   │ (Flying Sqd) │   │ (K9 Unit)     │              │
│   └──────────────┘   └───────────────┘              │
└─────────────────────────────────────────────────────┘
```

**Default Directorates (15):** Operations, Criminal Investigations, Special Branch, Traffic, Fire Brigade, Marine, Administration, Finance, Human Resource, Training, Logistics, Media, Legal, ICT, Other.

**Default Operations Units (9):** General Duty, Flying Squad, Anti-Stock Theft, Anti-Terrorism, Border Security, K9 Unit, Rapid Response, VIP Protection, Community Policing.

---

## 5. User Roles & Access Control

### Role Hierarchy

```
                ┌────────────────────────┐
                │      SUPER ADMIN       │  Rank 6 — Full system access
                │  (National HQ)         │
                └──────────┬─────────────┘
                           │ can create ▼
                ┌──────────┴─────────────┐
                │  REGIONAL COMMANDER    │  Rank 5 — Region scope
                │  (Regional HQ)         │
                └──────────┬─────────────┘
                           │ can create ▼
                ┌──────────┴─────────────┐
                │  DIVISION COMMANDER    │  Rank 4 — Division scope
                └──────────┬─────────────┘
                           │ can create ▼
                ┌──────────┴─────────────┐
                │  STATION COMMANDER     │  Rank 3 — Station scope
                └──────────┬─────────────┘
                           │ can create ▼
                ┌──────────┴─────────────┐
                │   POST COMMANDER       │  Rank 2 — Post scope
                └──────────┬─────────────┘
                           │ can create ▼
                ┌──────────┴─────────────┐
                │   FIELD OFFICER        │  Rank 1 — Post scope (read-heavy)
                └────────────────────────┘
```

### Module Access Matrix

| Module | Officer (1) | Post Cmd (2) | Station Cmd (3) | Div Cmd (4) | Regional Cmd (5) | Super Admin (6) |
|--------|:-----------:|:------------:|:---------------:|:-----------:|:----------------:|:---------------:|
| Dashboard | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Personnel | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Daily Status | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Leave Requests | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Notifications | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Reports | — | ✓ | ✓ | ✓ | ✓ | ✓ |
| User Accounts | — | ✓ | ✓ | ✓ | ✓ | ✓ |
| History | — | — | ✓ | ✓ | ✓ | ✓ |
| Communications | — | — | ✓ | ✓ | ✓ | ✓ |
| Activity Log | — | — | — | ✓ | ✓ | ✓ |
| Settings | — | — | — | — | ✓ | ✓ |
| Structure (Org) | — | — | — | — | — | ✓ |

### Data Scope Rules

Each user can only see data within their geographic scope:

```
Super Admin       → sees ALL regions, divisions, stations, posts, employees
Regional Cmd      → sees only their region's data
Division Cmd      → sees only their division's data
Station Cmd       → sees only their station's data
Post Cmd/Officer  → sees only their post's data
```

**Rule:** A user can only create accounts for roles with a lower rank than their own, and only within their geographic scope.

---

## 6. Database Schema

The database file is stored at `data/mdd.sqlite`. Migration is handled automatically by `includes/db.php` using `PRAGMA user_version` (currently v8).

### Entity Relationship Diagram

```
┌──────────────┐       ┌──────────────┐       ┌──────────────┐
│   regions    │       │  divisions   │       │   stations   │
├──────────────┤       ├──────────────┤       ├──────────────┤
│ id (PK)      │◄──┐   │ id (PK)      │◄──┐   │ id (PK)      │
│ name UNIQUE  │   └───│ region_id FK │   └───│ division_id  │
│ code UNIQUE  │       │ name         │       │ name         │
│ location     │       │ code UNIQUE  │       │ code UNIQUE  │
└──────────────┘       └──────────────┘       └──────┬───────┘
                                                      │
                                              ┌───────▼───────┐
                                              │     posts     │
                                              ├───────────────┤
                                              │ id (PK)       │
                                              │ station_id FK │
                                              │ name          │
                                              │ code UNIQUE   │
                                              └───────┬───────┘
                                                      │
                       ┌──────────────────────────────┤
                       │                              │
              ┌────────▼──────┐              ┌────────▼──────┐
              │     users     │              │   employees   │
              ├───────────────┤              ├───────────────┤
              │ id (PK)       │              │ id (PK)       │
              │ username UNQ  │              │ service_no UQ │
              │ password_hash │              │ full_name     │
              │ full_name     │              │ gender M/F    │
              │ role          │              │ rank          │
              │ region_id FK  │              │ region_id FK  │
              │ division_id FK│              │ division_id FK│
              │ station_id FK │              │ station_id FK │
              │ post_id FK    │              │ post_id FK    │
              │ email         │              │ directorate   │
              │ phone         │              │ unit          │
              └───────┬───────┘              │ email         │
                      │                     │ phone         │
                      │                     │ photo_path    │
                      │                     │ active 0/1    │
                      │                     └───────┬───────┘
                      │                             │
         ┌────────────┴──────┐           ┌──────────┴────────┐
         │                   │           │                   │
┌────────▼──────┐   ┌────────▼──────┐   ┌▼──────────────┐  ┌▼──────────────┐
│  activity_log │   │ notifications │   │ daily_status  │  │leave_requests │
├───────────────┤   ├───────────────┤   ├───────────────┤  ├───────────────┤
│ id (PK)       │   │ id (PK)       │   │ id (PK)       │  │ id (PK)       │
│ user_id FK    │   │ title         │   │ employee_id FK│  │ employee_id FK│
│ user_name     │   │ message       │   │ date          │  │ start_date    │
│ action        │   │ kind          │   │ status        │  │ end_date      │
│ entity_type   │   │ audience      │   │ notes         │  │ reason        │
│ entity_id     │   │ created_by FK │   │ recorded_by FK│  │ status        │
│ entity_label  │   │ created_at    │   │ UNIQUE(emp,dt)│  │ submitted_by  │
│ details       │   └───────────────┘   └───────────────┘  │ reviewed_by   │
│ ip_address    │                                           └───────────────┘
│ created_at    │
└───────────────┘

┌────────────────┐       ┌────────────────┐       ┌────────────────┐
│  directorates  │       │     units      │       │    settings    │
├────────────────┤       ├────────────────┤       ├────────────────┤
│ id (PK)        │       │ id (PK)        │       │ key (PK)       │
│ name UNIQUE    │◄──────│ directorate_id │       │ value          │
│ code UNIQUE    │       │ name           │       └────────────────┘
│ description    │       │ code UNIQUE    │
│ active 0/1     │       │ description    │       ┌────────────────┐
└────────────────┘       │ active 0/1     │       │ communications │
                         └────────────────┘       ├────────────────┤
                                                  │ id (PK)        │
                                                  │ subject        │
                                                  │ body           │
                                                  │ channel        │
                                                  │ sent_by FK     │
                                                  │ recipient_scope│
                                                  │ sent_at        │
                                                  └────────────────┘
```

### Table Summary

| Table | Rows (est.) | Purpose |
|-------|-------------|---------|
| `regions` | 4–20 | Top-level geographic units |
| `divisions` | 10–50 | Sub-regional units |
| `stations` | 50–200 | Police stations |
| `posts` | 100–500 | Deployable duty posts |
| `employees` | 500–50,000 | Personnel register |
| `users` | 10–500 | System login accounts |
| `daily_status` | Millions | One row per employee per day |
| `leave_requests` | Thousands | Annual leave records |
| `reports` | Thousands | Generated strength returns |
| `notifications` | Hundreds | System announcements |
| `notification_reads` | Millions | Read-receipt tracking |
| `activity_log` | Millions | Full audit trail |
| `communications` | Hundreds | Broadcast messages |
| `directorates` | 15 (seeded) | Functional directorates |
| `units` | 9+ (seeded) | Sub-units within directorates |
| `settings` | ~20 | System configuration key-value |

---

## 7. Module Reference

---

### 7.1 Authentication

**File:** `public/login.php`, `public/logout.php`  
**Access:** Public

#### Login Flow

```
User visits any page
        │
        ▼
auth.php: require_login()
        │
        ├── Session has user_id? ──► Load user from DB ──► Continue
        │
        └── No session ──► Redirect to /login.php
                                    │
                                    ▼
                          POST username + password
                                    │
                                    ▼
                          SELECT user WHERE username=?
                          password_verify(input, hash)
                                    │
                          ┌─────────┴─────────┐
                          │                   │
                        Match             No match
                          │                   │
                    Set session          Flash error
                    Redirect /           Reload form
```

**Security measures:**
- Passwords stored as bcrypt hashes (`PASSWORD_DEFAULT`)
- Session ID regenerated on login
- No password is ever stored in plain text
- Failed logins display a generic message (no username enumeration)

---

### 7.2 Dashboard

**File:** `public/index.php`  
**Access:** All roles (min rank 1)  
**URL:** `/`

The dashboard provides a real-time strength summary scoped to the logged-in user's geographic level.

#### Dashboard Sections

```
┌────────────────────────────────────────────────────────┐
│  TODAY'S STRENGTH RETURN          Date: 13 Jul 2026    │
├────────────┬──────────────┬───────────────┬────────────┤
│  PRESENT   │     AWOL     │   ON LEAVE    │   SICK     │
│    142     │      3       │      12       │     5      │
├────────────┴──────────────┴───────────────┴────────────┤
│          STRENGTH BY UNIT (grouped by next level)      │
├─────────────────┬──────────────────────────────────────┤
│ Kampala Central │  Present: 45  AWOL: 1  Leave: 3 ...  │
│ Kampala North   │  Present: 38  AWOL: 0  Leave: 2 ...  │
└─────────────────┴──────────────────────────────────────┘
```

**Grouping logic:**
- Super Admin → grouped by **Region**
- Regional Commander → grouped by **Division**
- Division Commander → grouped by **Station**
- Station/Post Commander/Officer → grouped by **Post**

---

### 7.3 Personnel Management

**File:** `public/employees.php`, `public/export-personnel.php`  
**Access:** All roles (min rank 1)  
**URL:** `/employees.php`

Central register of all police personnel. Each record stores biography, rank, assignment, contact, and duty directorate.

#### Employee Record Fields

| Field | Type | Description |
|-------|------|-------------|
| `service_no` | TEXT UNIQUE | Force/file number (e.g., UPF-00123) |
| `full_name` | TEXT | Surname Firstname Othername |
| `gender` | CHAR | M or F |
| `rank` | TEXT | One of 22 UPF ranks (see §8) |
| `region_id` | FK | Geographic assignment |
| `division_id` | FK | Geographic assignment |
| `station_id` | FK | Geographic assignment |
| `post_id` | FK | Geographic assignment |
| `directorate` | TEXT | Functional directorate |
| `unit` | TEXT | Sub-unit within directorate |
| `email` | TEXT | Optional |
| `phone` | TEXT | Optional |
| `photo_path` | TEXT | Optional profile photo |
| `active` | 0/1 | Soft-delete flag |

#### Sorting
Personnel are always listed in **UPF seniority order** (IGP first, CIVILIAN last) using a SQL CASE expression.

#### Bulk Import
See [§7.14 Bulk Import](#714-bulk-import).

#### Export
A separate export page (`/export-personnel.php`) generates a printable/downloadable personnel list.

---

### 7.4 Daily Status (MDD)

**File:** `public/daily-status.php`, `public/status-details.php`  
**Access:** All roles (min rank 1)  
**URL:** `/daily-status.php`

The core MDD (Morning Duty Deployment) module. Commanders record the status of every person under their command for the current date.

#### Status Recording Flow

```
Commander opens Daily Status
           │
           ▼
  Selects date (default: today)
           │
           ▼
  System lists all employees in scope
           │
           ▼
  For each employee: select status
  ┌────────────┬────────────┬──────────┬────────────┐
  │  Present   │   AWOL     │  Leave   │   Sick     │
  ├────────────┼────────────┼──────────┼────────────┤
  │ Suspended  │ Discipl.   │ On Duty  │ On Course  │
  └────────────┴────────────┴──────────┴────────────┘
           │
           ▼
  Submit form → INSERT/UPDATE daily_status
           │
           ▼
  Strength summary updated on Dashboard
```

**Business rules:**
- One status record per employee per date (`UNIQUE(employee_id, date)`)
- Status can be updated on the same day by the recording commander
- Historical dates can be viewed but only edited by higher commanders
- `deserted` status is a permanent escalation (distinct from AWOL)

---

### 7.5 Reports

**File:** `public/reports.php`  
**Access:** Min rank 2 (Post Commander and above)  
**URL:** `/reports.php`

Commanders generate and archive daily strength returns (parade state reports). The report captures a JSON snapshot of the current day's status summary for their scope.

**Report stored fields:** post/station/division/region scope, date, generator user, generated timestamp, JSON summary, status (approved/pending), reviewer.

---

### 7.6 Leave Requests

**File:** `public/leave-requests.php`  
**Access:** All roles (min rank 1)  
**URL:** `/leave-requests.php`

#### Leave Workflow

```
  Officer/Commander
        │
        ▼
  Submit Leave Request
  (employee, start date, end date, reason)
        │
        ▼
  Status: PENDING
        │
        ▼
  Higher Commander reviews
        │
   ┌────┴────┐
   │         │
APPROVE    REJECT
   │         │
   ▼         ▼
Status=    Status=
approved   rejected
   │
   ▼
Employee status on affected dates
set to 'leave' automatically
```

**Leave statuses:** `pending` → `approved` / `rejected`

---

### 7.7 History

**File:** `public/history.php`  
**Access:** Min rank 3 (Station Commander and above)  
**URL:** `/history.php`

Displays historical daily status records for any past date within the user's scope. Allows comparison across dates and downloading historical parade states.

---

### 7.8 User Accounts

**File:** `public/users.php`  
**Access:** Min rank 2 (Post Commander and above)  
**URL:** `/users.php`

Manages system login accounts. Each user is bound to a geographic scope and a role.

#### Account Management Rules

```
Rule 1: A user can only manage accounts with a LOWER role rank than themselves.
Rule 2: A user can only manage accounts within their own geographic scope.
Rule 3: Super Admin can manage all accounts globally.
Rule 4: Accounts with no scope (NULL region/division/station/post) = HQ level.
```

#### User Creation Form Fields
- Username (must be unique)
- Full Name
- Password (minimum 4 characters)
- Role (limited to creatable roles based on actor's rank)
- Email, Phone
- Geographic scope (Region → Division → Station → Post)

---

### 7.9 Organisational Structure

**Files:** `public/hierarchy.php`, `public/structure-view.php`  
**Access:** Super Admin only (rank 6)  
**URL:** `/hierarchy.php`

Two-tab management page for the entire organisational structure.

#### Tab 1: Geographic Hierarchy

Manage the 4-level geography: Regions → Divisions → Stations → Posts.

```
Actions per level:
  ┌─────────────────────────────────────────────────────┐
  │  [+ Add]  Edit via modal dialog  Delete (with check)│
  │                                                     │
  │  Table columns:                                     │
  │  Name | Code | Parent | Sub-count | Personnel count │
  └─────────────────────────────────────────────────────┘
```

Personnel count shows how many active employees are assigned to each geographic unit.

#### Tab 2: Directorates & Units

Manage functional directorates and their sub-units.

```
  DIRECTORATES TABLE
  ┌─────────────────┬──────┬──────────────┬───────┬──────────┐
  │ Name            │ Code │ Description  │ Units │Personnel │
  ├─────────────────┼──────┼──────────────┼───────┼──────────┤
  │ Operations      │ OPS  │ ...          │   9   │  142     │
  │ CID             │ CID  │ ...          │   0   │   38     │
  └─────────────────┴──────┴──────────────┴───────┴──────────┘
  Actions: [👁 View] [✏ Edit] [🗑 Delete]

  UNITS TABLE
  ┌───────────────────┬─────────────┬──────┬──────────┐
  │ Directorate       │ Unit Name   │ Code │Personnel │
  ├───────────────────┼─────────────┼──────┼──────────┤
  │ Operations        │ Flying Squad│ FS   │   18     │
  │ Operations        │ K9 Unit     │ K9   │    6     │
  └───────────────────┴─────────────┴──────┴──────────┘
  Actions: [👁 View] [✏ Edit] [🗑 Delete]
```

#### Structure View (`/structure-view.php`)

Accessed via the **View** button on any directorate or unit. Shows:

- Summary statistics (total personnel, M/F breakdown)
- Sub-units (for directorates)
- Full personnel list sorted by UPF rank seniority
- **Create User Account** modal (commanders only)

```
  DIRECTORATE: OPERATIONS
  ┌──────────┬──────────┬──────────┬──────────┐
  │ Personnel│   Male   │  Female  │  Units   │
  │   142    │   120    │    22    │    9     │
  └──────────┴──────────┴──────────┴──────────┘

  UNITS WITHIN OPERATIONS
  Flying Squad (18 personnel)  [View]
  K9 Unit (6 personnel)        [View]
  ...

  PERSONNEL ATTACHED
  # | Rank  | Full Name          | Svc No   | Post
  1 | IGP   | SURNAME FIRSTNAME  | UPF-0001 | CPS Post 1
  2 | DIGP  | ...                | ...      | ...
```

---

### 7.10 Notifications

**File:** `public/notifications.php`  
**Access:** All roles (min rank 1)  
**URL:** `/notifications.php`

System-wide announcements published by commanders. Supports audience targeting (all users, specific role level). Read receipts are tracked per user.

**Notification types:** `info`, `warning`, `urgent`, `success`

---

### 7.11 Activity Log

**File:** `public/activity-log.php`  
**Access:** Min rank 4 (Division Commander and above)  
**URL:** `/activity-log.php`

A tamper-evident audit trail of all system actions. Every login, logout, record creation, edit, deletion, and bulk operation is recorded.

#### Logged Fields

| Field | Description |
|-------|-------------|
| `user_name` | Name of the actor |
| `action` | Human-readable action (e.g., "Add User") |
| `entity_type` | What was affected (employee, user, report…) |
| `entity_label` | Name/description of the affected record |
| `entity_id` | Database ID of the affected record |
| `details` | Free-text additional context |
| `ip_address` | Requestor IP |
| `created_at` | Timestamp (local time) |

**Super Admin only:** Purge all activity logs (with confirmation).

---

### 7.12 Communications

**File:** `public/communications.php`  
**Access:** Min rank 3 (Station Commander and above)  
**URL:** `/communications.php`

Broadcast messages to personnel via email and/or SMS. Powered by the system's built-in SMTP mailer and SMS gateway stubs.

**Channels:** Email, SMS, Both

**Configuration** (via Settings): SMTP host/port/credentials, SMS provider (Africa's Talking, Twilio, or Vonage), API keys.

---

### 7.13 Settings

**File:** `public/settings.php`  
**Access:** Min rank 5 (Regional Commander and above)  
**URL:** `/settings.php`

System configuration stored as key-value pairs in the `settings` table.

#### Configurable Settings

| Category | Setting |
|----------|---------|
| **Email** | SMTP host, port, username, password, from address, from name |
| **SMS** | Provider (Africa's Talking / Twilio / Vonage), API key, sender ID |
| **System** | Organisation name, timezone, date format |

---

### 7.14 Bulk Import

**File:** `public/bulk-import.php`  
**Access:** All roles (min rank 1)  
**URL:** `/bulk-import.php`

Import multiple personnel records from a CSV file.

#### CSV Format

```
service_no, full_name, rank, gender, directorate, unit, email, phone, post_id
UPF-00001, SURNAME FIRSTNAME, SGT, M, Operations, General Duty, officer@upf.go.ug, +256700000000, 1
UPF-00002, SURNAME FIRSTNAME B, CPL, F, CID, , , , 2
```

#### Import Process Flow

```
Upload CSV
    │
    ▼
First Pass: Parse & validate ALL rows
    │
    ├── Check required fields (service_no, full_name, rank)
    ├── Validate rank against 22 UPF ranks
    ├── Validate email format
    ├── Check post_id exists in database
    └── Check for duplicate service_no
    │
    ▼
Duplicate Handling (choose one):
    ├── SKIP   → leave existing record untouched, log as skipped
    ├── UPDATE → overwrite existing record with CSV data
    └── ABORT  → cancel entire import if any duplicate found
    │
    ▼
DRY RUN option: preview all results without writing to DB
    │
    ▼
Second Pass: Execute inserts/updates
    │
    ▼
Colour-coded results:
  ✓ Green  = imported
  ↻ Blue   = updated
  ⚠ Yellow = skipped / warning
  ✗ Red    = error
```

---

### 7.15 Data Export

**Files:** `public/export.php`, `public/export-personnel.php`  
**Access:** All roles  

Generates printable/downloadable reports in CSV or print-ready HTML format.

---

## 8. UPF Rank Order

Ranks are stored as text and sorted using a SQL CASE expression that preserves UPF seniority. Listed from most senior to most junior:

```
 1. IGP        — Inspector General of Police
 2. DIGP       — Deputy Inspector General of Police
 3. CJS        — Commissioner of Police (Joint Secretary)
 4. AIGP       — Assistant Inspector General of Police
 5. SCP        — Senior Commissioner of Police
 6. CP         — Commissioner of Police
 7. ACP        — Assistant Commissioner of Police
 8. SSP        — Senior Superintendent of Police
 9. SP         — Superintendent of Police
10. ASP        — Assistant Superintendent of Police
11. IP         — Inspector of Police
12. AIP        — Assistant Inspector of Police
13. HCM        — Head Constable (Major)
14. HC         — Head Constable
15. S/SGT      — Senior Sergeant
16. SGT        — Sergeant
17. CPL        — Corporal
18. L/CPL      — Lance Corporal
19. PC         — Police Constable
20. PPC        — Probationary Police Constable
21. SPC        — Special Police Constable
22. CIVILIAN   — Civilian Staff
```

---

## 9. Personnel Statuses

Nine operational statuses track the daily deployment state of each officer:

| Code | Label | Description |
|------|-------|-------------|
| `present` | Present | Officer is on parade and available for duty |
| `awol` | AWOL | Absent Without Official Leave |
| `leave` | On Leave | Approved annual/compassionate leave |
| `sick` | Sick | Medical absence |
| `suspended` | Suspended | Under administrative suspension |
| `disciplinary` | Disciplinary | Under disciplinary proceedings |
| `on_duty` | On Duty | On special assignment or external duty |
| `on_course` | On Course | Attending training or a course |
| `deserted` | Deserted | Permanent AWOL — fled post |

---

## 10. Security Model

### Authentication
- **Sessions:** PHP native sessions with `session_start()` on every request
- **Password hashing:** `password_hash()` / `password_verify()` using bcrypt (PHP `PASSWORD_DEFAULT`)
- **Session checks:** Every protected page calls `require_login()` or `require_role()` before any output

### Authorization Layers

```
Layer 1 — Authentication:  Is the user logged in?
Layer 2 — Role rank:       Is the user's rank high enough for this module?
Layer 3 — Data scope:      Can the user see this specific record?
Layer 4 — Action guard:    Can the user perform this action (e.g., only manage lower-ranked users)?
```

### SQL Injection Prevention
- All database queries use **PDO prepared statements** with bound parameters
- No raw SQL interpolation of user input anywhere in the codebase

### XSS Prevention
- All user-controlled output is passed through `e()` (`htmlspecialchars()` with `ENT_QUOTES`)
- No `innerHTML` injection in JavaScript

### CSRF
- Forms are protected by PHP session verification (same-origin POST)
- Destructive actions (delete, purge) require an explicit confirmation dialog

---

## 11. File & Directory Structure

```
workspace/
├── data/
│   └── mdd.sqlite                  ← SQLite database file
│
├── includes/
│   ├── auth.php                    ← Authentication, roles, scope helpers
│   ├── db.php                      ← PDO connection + all DB migrations (v1–v8)
│   ├── footer.php                  ← Closing HTML tags, JS
│   ├── header.php                  ← Navigation, topbar, session check
│   ├── helpers.php                 ← UPF_RANKS, statuses, log_activity(), flash()
│   ├── mailer.php                  ← Pure-PHP SMTP email sender
│   └── sms.php                     ← SMS gateway stubs
│
├── public/
│   ├── assets/
│   │   ├── logo.jpg                ← UPF crest
│   │   └── style.css               ← Main stylesheet
│   │
│   ├── router.php                  ← PHP built-in server request router
│   │
│   ├── login.php                   ← Login page
│   ├── logout.php                  ← Session destruction + redirect
│   ├── index.php                   ← Dashboard
│   ├── employees.php               ← Personnel register
│   ├── bulk-import.php             ← CSV bulk import
│   ├── export.php                  ← Data export
│   ├── export-personnel.php        ← Personnel list export
│   ├── daily-status.php            ← MDD daily status recorder
│   ├── status-details.php          ← Individual status detail view
│   ├── reports.php                 ← Strength return reports
│   ├── leave-requests.php          ← Leave management
│   ├── history.php                 ← Historical parade records
│   ├── users.php                   ← User account management
│   ├── hierarchy.php               ← Organisational structure (superadmin)
│   ├── structure-view.php          ← Directorate / unit detail view
│   ├── notifications.php           ← System announcements
│   ├── activity-log.php            ← Audit trail
│   ├── communications.php          ← Email / SMS broadcast
│   └── settings.php                ← System settings
│
└── MDD_SYSTEM_DOCUMENTATION.md    ← This document
```

---

## 12. Installation & Setup

### Prerequisites

| Requirement | Minimum Version |
|-------------|----------------|
| PHP | 8.2+ |
| SQLite | 3.x (bundled with PHP) |
| PDO SQLite extension | Enabled |
| PHP Sessions | Enabled |
| Write access | `data/` directory |

### Step 1 — Clone / Deploy

```bash
# Place codebase in your server directory
cd /var/www/mdd-manager
```

### Step 2 — Set Directory Permissions

```bash
# The data/ directory must be writable by the web server
chmod 755 data/
chmod 664 data/mdd.sqlite   # (if pre-existing)
```

### Step 3 — Start the Server

```bash
php -S 0.0.0.0:5000 -t public public/router.php
```

### Step 4 — First Run (Database Bootstrap)

On the first request, `includes/db.php` automatically:
1. Creates `data/mdd.sqlite`
2. Runs all 8 migration steps (v1–v8)
3. Seeds default directorates and units
4. Creates the **Super Admin** demo account (`admin` / `admin123`)

> **Security note:** Change the Super Admin password immediately after the first login.

### Step 5 — Configure System Settings

Log in as Super Admin → **Settings** → configure:
- SMTP credentials for email communications
- SMS provider and API key
- Organisation name

### Step 6 — Build the Geographic Structure

Log in as Super Admin → **Structure** → **Geographic Hierarchy**:
1. Add Regions
2. Add Divisions (under regions)
3. Add Stations (under divisions)
4. Add Posts (under stations)

### Step 7 — Import Personnel

Use **Bulk Import** with a CSV file, or add personnel individually via the **Personnel** module.

### Step 8 — Create User Accounts

**Users** → **Add User** → assign roles and geographic scope.

---

## 13. Demo Accounts

The following demo accounts are seeded on first run:

| Username | Password | Role | Scope |
|----------|----------|------|-------|
| `admin` | `admin123` | Super Admin | National (all) |
| `rcmd_kla` | `rcmd123` | Regional Commander | Kampala Region |
| `dcmd_kcd` | `dcmd123` | Division Commander | Kampala Central Division |
| `scmd_cps` | `scmd123` | Station Commander | Central Police Station |
| `pcmd_cps1` | `pcmd123` | Post Commander | CPS Post 1 |
| `offr_cps1` | `offr123` | Field Officer | CPS Post 1 |

> **Important:** These accounts and passwords are for demonstration only. All demo passwords must be changed before production deployment.

---

## 14. Glossary

| Term | Definition |
|------|-----------|
| **MDD** | Morning Duty Deployment — the daily parade state / strength return |
| **Strength Return** | A summary report of how many officers are present, AWOL, on leave, etc. |
| **Parade State** | The roll-call record of all personnel at a given post |
| **AWOL** | Absent Without Official Leave |
| **Deserted** | An officer who has permanently fled their duty post |
| **Scope** | The geographic or functional boundary a user account can see and manage |
| **Post** | The smallest deployable unit of police personnel |
| **Directorate** | A functional division of the UPF (e.g., Operations, CID) |
| **Unit** | A sub-section within a directorate (e.g., Flying Squad within Operations) |
| **Service No** | A unique alphanumeric identifier assigned to every police officer |
| **PDO** | PHP Data Objects — PHP's database abstraction layer |
| **Migration** | An incremental database schema update tracked by version number |
| **Bcrypt** | The password hashing algorithm used to store user credentials |
| **SMTP** | Simple Mail Transfer Protocol — used for sending system emails |
| **Soft Delete** | Marking a record as `active=0` rather than physically deleting it |

---

*End of Document*

---
**Uganda Police Force — MDD Manager System v2.0**  
*Protect & Serve*
