# Research Student Portal

A class-management system for teachers, students and school administrators, built around the **DepEd K to 12 grading system** — specifically *DepEd Order No. 015, s. 2026* (grading) and *No. 017, s. 2026* (Strengthened Senior High School Curriculum).

PHP 8.3 · SQLite · Bootstrap 5 · vanilla JavaScript.

---

## ⚠️ Security warning — read before deploying

The database is **auto-seeded on first request** with demo accounts that all share the
password **`password123`**:

| Email | Role |
|---|---|
| `admin@school.com` | Teacher **+ full admin panel** |
| `teacher@school.com` | Teacher |
| `john.smith@school.com` | Student |
| `maria.garcia@school.com` | Student |
| `alex.johnson@school.com` | Student |

That is fine locally. **It is not fine on a public URL.** Immediately after deploying, run:

```bash
php scripts/reset_demo_passwords.php
```

It replaces every demo password with a strong random one and prints them **once**. Then
change each password from *Profile → Change Password*.

Two further notes:

- This is a **research/coursework project, not a production SIS.** It has no rate limiting
  on downloads, no password reset flow, and no data-retention controls. Under the Data
  Privacy Act (RA 10173), do not load **real** student data into a public deployment.
- The seeded data is entirely synthetic.

---

## Requirements

| Requirement | Why |
|---|---|
| **PHP 8.3+** | App runtime |
| `pdo_sqlite` | Database (`grade_engine.php`) |
| `fileinfo` | MIME sniffing on uploads (`config.php`) |
| `zip`, `mbstring`, `xml`, `gd` | PhpSpreadsheet, for the .xlsx Export/Import |
| Composer | To install `vendor/` |

## Setup

```bash
composer install          # installs PhpSpreadsheet into vendor/
./start.sh                # Linux, macOS, Termux (Android)
start.bat                 # Windows  (also serves the LAN, see below)
```

Open <http://localhost:8000>. There is no install step — `db.php` creates the schema, seeds
the demo data and applies the grading-policy migration on first load.

### On a phone or tablet (Android)

Termux ships PHP with `pdo_sqlite`, `sqlite3`, `mbstring`, `zip` and `xml`, so the portal
runs on the device itself with no server and no hosting.

1. Install **Termux from F-Droid** — the Play Store build is deprecated and won't work.
2. In Termux: `pkg install php` then `termux-setup-storage`.
3. Copy the project **into Termux's own directory**, not into Downloads:
   ```bash
   mkdir -p ~/www && cd ~/www
   # then unzip/copy the project here
   ```
   ⚠️ This matters: Android's shared storage (`/sdcard`, `Downloads`) does **not** support
   the file locking SQLite needs, so a database kept there will fail to open.
4. `cd ~/www/StudentPortal && ./start.sh`
5. Run `termux-wake-lock` first, or Android will suspend Termux when you switch to the
   browser. Keep the Termux session alive in the background.
6. Open **<http://localhost:8000>** in the tablet's browser.

iOS/iPadOS has no PHP runtime available, so an iPad can't run this directly — it needs a
host or a remote-terminal workaround.

### Reaching it from another device on the same WiFi

`start.bat` binds to `0.0.0.0` (Windows Firewall may prompt to allow it). Then on any
phone or tablet on that WiFi, open `http://<the-PC's-IP>:8000` — `start.bat` prints the
address. The PC must stay awake.

## Tests

Four self-contained suites. Each restores any data it touches, so they are safe to re-run.

```bash
php tests/engine_test.php        # 61  transmutation table, IG/TG, descriptors
php tests/submission_test.php    # 13  assignment submission policy, upload limits
php tests/gradebook_test.php     # 49  live gradebook write path + validation
php tests/policies_test.php      # 27  DO 015 policy catalogue, tracks, weights
```

## Configuration (optional)

Every path has a working default, so these are only needed for deployment.

| Variable | Default | Purpose |
|---|---|---|
| `DB_PATH` | `<app>/school_portal.db` | SQLite database location |
| `UPLOAD_DIR` | `<app>/uploads` | Where uploaded files are written |
| `APP_URL` | *(empty)* | Absolute base URL, if needed |

```bash
DB_PATH=/var/lib/studentportal/school_portal.db \
UPLOAD_DIR=/var/lib/studentportal/uploads \
php -S localhost:8000
```

**Back up the database.** It is the only place your data lives:

```bash
sqlite3 school_portal.db ".backup '/backups/school_portal-$(date +%F).db'"
```

---

## Grading system

Grades are computed from three components — **Written or Oral Works (WW)**, **Product or
Performance Tasks (PT)** and **Examinations (EX)** — with the exam itself split
ST1 30% + ST2 30% + Term Exam 40%.

| Policy | WW / PT / EX |
|---|---|
| SHS Academic: Core & Academic Electives | 20 / 50 / 30 |
| SHS Academic: Research Electives & Design Innovation | 40 / 60 / — |
| SHS Academic: Arts, Sports, Health & Wellness | 20 / 60 / 20 |
| SHS Academic: Field Experience / Arts Apprenticeship | 15 / 70 / 15 *(Term Exam only)* |
| SHS TechPro: TechPro Electives | 15 / 65 / 20 |
| SHS Work Immersion (both tracks) | 20 / 80 / — |
| KS2/KS3 Core Subjects | 20 / 50 / 30 |
| KS2/KS3 MAPEH / EPP-TLE | 20 / 60 / 20 |
| Legacy DO 8 s.2015 (historical classes) | 25 / 50 / 25 |

Senior High School has **two tracks only** under DO 017, s. 2026 — **Academic** and
**TechPro** — with no strands; electives sit in clusters and learners may cross-track.

**Term Grade** = the Initial Grade looked up in the adjusted transmutation table
(SY 2026–2027). From **SY 2027–2028** grading is zero-based with no transmutation.

**Descriptors** (DO 015, s. 2026): Advancing 90–100 · Benchmarking 80–89 · Connecting
75–79 · Developing 65–74 · Emerging 0–64. Passing is 75. The repealed DO 8 s. 2015 scale is
retained only on the Legacy policy, so historical classes still read as recorded.

---

## Features

**Teacher** — Excel-style gradebook where typing a score instantly recomputes PS/IG/TG and
the class average with no page reload (autosaves in the background); paste a column
straight from Excel; copy a range out; range selection with Shift+arrows, Ctrl+D fill down,
Delete to clear, Ctrl+Z undo; collapsible category groups; frozen student column; live Class
Average row. Plus classes with join codes, announcements, assignments with per-assignment
submission policy, submissions grading, attendance, and settings.

**Student** — grade report with per-component PS/WS breakdown, a target-grade calculator for
the upcoming term, attendance, notifications, and assignment submission with a receipt.

**Admin** — users and roles, academic years and terms, grading policies (weights, exam
split, transmutation table, descriptors), school calendar, announcements, audit log, settings.

**Security** — prepared statements, bcrypt password hashing, CSRF tokens on every POST
(including an `X-CSRF-Token` header on the JSON gradebook endpoint), per-IP and per-email
login throttling, session regeneration, POST-only logout, upload extension allowlist with
MIME sniffing, and access-controlled file serving (a submission is readable only by its
owner, the class teacher, and admins).

---

## Documentation

- **`PAGE_GUIDE.md`** — every page and feature, page by page
- **`REPORT.md`** — architecture, what was built, and verification results
- **`METHODOLOGY.docx`** — methodology chapter (not in version control; a Word lock file is
  ignored via `~$*`)

## Project layout

```
├── config.php            session + security helpers, upload_dir(), app_base_url()
├── db.php                schema migrations, seed, migrate_grading_policies()
├── grade_engine.php      all grade computation + the gradebook write path
├── grades_api.php        JSON endpoint behind the live gradebook
├── dashboard.php         teacher/student router
├── admin.php             admin panel router
├── download.php          access-controlled file download
├── includes/             actions.php, teacher.php, student.php, admin.php
├── scripts/              reset_demo_passwords.php
├── tests/                engine, submission, gradebook, policies
├── style.css  script.js  theme.js
└── school_portal.db      created on first run (git-ignored)
```
