# Research Student Portal — Website Page Guide

This document explains every page and tab of the **Research Student Portal**, an online class management system for teachers, students, and school administrators. The system allows teachers to manage classes, assignments, grades, and attendance, while students can join classes, submit assignments, and view their grades and attendance records.

**System type:** Web application (PHP + SQLite database)
**Users:** Teachers, Students, and Administrators
**Access:** Web browser via a local or school server

---

## I. Authentication Pages

### 1. Login Page (`login.php`)
- **Purpose:** Allows registered users (teachers, students, administrators) to sign in to the portal.
- **Who uses it:** All users.
- **Key features:**
  - Email and password login.
  - Role detection — the system automatically shows the correct dashboard after login (teacher, student, or admin).
  - Disabled accounts cannot sign in; accounts awaiting approval get a clear message.
  - "Create an account" link to the registration page.
  - Security: failed login attempts are limited per IP **and** per email (5 attempts within 15 minutes), session tokens protect the login form, and the session is renewed after a successful sign-in.
- **Research relevance:** Demonstrates secure authentication — the entry point that separates the user roles.

### 2. Register Page (`register.php`)
- **Purpose:** Allows new students to create an account.
- **Who uses it:** New students (e.g., newly enrolled students without an account).
- **Key features:**
  - Full name, email, student number, and password (**minimum 8 characters**).
  - Duplicate email **and** duplicate student number checking.
  - In **approval mode** (set by the admin), new accounts are created as "pending" and can only sign in after an administrator enables them.
- **Research relevance:** Shows self-registration with hashed passwords and an optional approval workflow.

### 3. Logout (`logout.php`)
- POST-only with CSRF validation — prevents logout CSRF attacks. Always destroys the session and cookie.

---

## II. Teacher Pages

### 4. Dashboard — "My Classes" (`dashboard.php`)
- **Purpose:** Home page of the teacher after logging in.
- **Who uses it:** Teachers.
- **Key features:**
  - Summary badges: number of active classes and total enrolled students.
  - Cards for every active class showing subject, section, school year, student count, and the grading policy in use (e.g., "DO 015 s.2026 - KS4 SHS Core").
  - Quick action buttons on each card: **Open**, **Grades**, **Attendance**, **Settings**.
  - "+ New Class" button to create a class.
  - Archived classes section with a **Restore** button.
- **Research relevance:** Shows the teacher's overview of all classes — the main navigation hub of the portal.

### 5. Create Class Page
- **Purpose:** Lets a teacher create a new class.
- **Who uses it:** Teachers.
- **Key features:**
  - Inputs for subject, section, school year, description, and the **academic year** (which supplies the term calendar).
  - **Grading Policy** selector — grouped by track, with each template's **weights shown inline** (`… • 20/50/30`) and a hover tooltip carrying the full name and description. Choosing a template pre-creates its components as grade categories with the official weights.

    Senior High has **two tracks only** (DepEd Order No. 017, s. 2026 — no strands, electives in clusters):

    | Group | Template | WW / PT / EX |
    |---|---|---|
    | **Academic** | Core & Academic Electives | 20 / 50 / 30 |
    | **Academic** | Research Electives & Design Innovation | 40 / 60 / — |
    | **Academic** | Arts, Sports, Health & Wellness | 20 / 60 / 20 |
    | **Academic** | Field Experience / Arts Apprenticeship | 15 / 70 / 15 *(Term Exam only)* |
    | **TechPro** | TechPro Electives | 15 / 65 / 20 |
    | **Both Tracks** | Work Immersion | 20 / 80 / — |
    | JHS | KS2/KS3 Core Subjects | 20 / 50 / 30 |
    | JHS | KS2/KS3 MAPEH / EPP-TLE | 20 / 60 / 20 |
    | Other | Legacy DO 8 s.2015 (historical classes) | 25 / 50 / 25 |
    | Other | Standard (custom categories) | teacher-defined |
  - Automatically generates a unique **join code** that students use to enroll.
- **Research relevance:** Demonstrates class creation and the join-code enrollment model.

### 6. Class Detail Page (Teacher View) — 3 Tabs
- **6a. Stream:** Announcements timeline with title, content, attachments, and date; "+ Post" modal; students are notified automatically.
- **6b. Assignments:** List with title, due date, submission + late counts; badges (**file required**, **late closed**); "+ Create" modal with a per-assignment submission policy (file required toggle, allowed file types, max size 0.5–25 MB, allow-late + optional cutoff date); "View" opens the Submissions page.
- **6c. Students:** Roster table (name, ID, section), name search, remove-student button.

### 7. Submissions Page
- **Purpose:** View and grade student assignment submissions — visible only to the class teacher.
- **Who uses it:** Teachers.
- **Key features:**
  - Table of submissions with student, submission timestamp, **Late** badge, **original filename** (served through the authenticated `download.php`), score, and feedback.
  - **Download all (.zip)** — one click downloads every submitted file (named by student), teacher-only.
  - **Not yet submitted** roster — enrolled students who still owe a file when file submission is required.
  - Inline grading — enter score and feedback per student, then click **Grade**.
  - Students receive a notification once their work is graded (late submissions are flagged in the notification).
- **Research relevance:** Demonstrates the assessment feedback loop plus submission monitoring and bulk collection.

### 8. Grades Page — term-based gradebook
- **Purpose:** The grade book for the class — the most data-intensive page.
- **Who uses it:** Teachers.
- **Key features:**
  - **Term tabs** (Term 1 / Term 2 / Term 3 per the school calendar); each term has its own categories, items, and scores. The active term is chosen in Settings.
  - **Policy banner** showing the grading policy, component weights, exam split (ST1/ST2/TE), and transmutation mode in effect.
  - **Publish / Unpublish** buttons per term — students only see term grades once published.
  - **Grade categories** with editable weights (e.g., Written or Oral Works 20%, Performance Tasks 50%, Examinations 30% per DO 015 s.2026).
  - **Activities (items)** under each category with max score; exam items can be tagged **ST1 / ST2 / TE** so the exam component is combined with the policy's internal split.
  - **Excel-style score grid** — students down the left, activities across the top. The grid is the **first thing on the page**; the category/weight editor sits below it in a collapsed *Activities & weights* panel so entering scores never requires scrolling past setup.
    - **Live recalculation** — typing a score immediately updates that student's category PS, **IG, TG and descriptor** plus the class-average row, with **no page reload**. The recomputation is done by `grade_engine.php` on the server (`grades_api.php`), so the browser never re-implements the grading rules.
    - **Autosave** — the grid saves itself when you press <kbd>Enter</kbd>/<kbd>Tab</kbd>, click away, or pause typing (1.2 s). The status line shows *All scores saved* / *N unsaved* / *N over max* / what the last bulk action did. A **Save All Scores** button remains as a full-grid fallback.
    - **Paste from Excel** — copy a column (or a block) of scores in Excel, click the first cell, and press <kbd>Ctrl</kbd>+<kbd>V</kbd>: it fills down/across exactly like a spreadsheet. Values outside the activity maximum are **skipped and reported**, never written.
    - **Copy out** — select cells and press <kbd>Ctrl</kbd>+<kbd>C</kbd> to put tab-separated values on the clipboard, which pastes straight into Excel.
    - **Range selection** — <kbd>Shift</kbd>+arrows (or <kbd>Ctrl</kbd>+<kbd>A</kbd> for everything) selects a block, highlighted in the grid.
    - **Fill down** — <kbd>Ctrl</kbd>+<kbd>D</kbd> copies the first cell of the selection into the rest (e.g. give the whole class the same score).
    - **Clear** — <kbd>Delete</kbd> empties the selection; an empty score stays *excluded* from the computation rather than counting as zero.
    - **Undo** — <kbd>Ctrl</kbd>+<kbd>Z</kbd> (or the **Undo** button) reverses the last bulk action.
    - **Keyboard navigation** — <kbd>Enter</kbd>/<kbd>&darr;</kbd> down, <kbd>Tab</kbd>/<kbd>&rarr;</kbd> right (both wrap to the next student at the end of a row), <kbd>Shift</kbd>+<kbd>Enter</kbd> up, <kbd>Ctrl</kbd>+<kbd>Home</kbd>/<kbd>End</kbd>, and <kbd>Esc</kbd> to drop a selection or revert a cell to its last saved value.
    - **Cell cursor** — the focused cell is outlined and its row and column headers highlight, so you always know where you are typing.
    - **Collapsible categories** — click a category banner to hide/show that group's columns; the state is kept in the URL, plus *Expand all* / *Collapse all*. On a wide gradebook this is how you narrow the grid until the scores and the grades both fit on screen.
    - **Column letters (A, B, C …)** and row numbers, and the student name column stays frozen on the left while scrolling.
    - **Class Average row** — the mean score per activity, the mean PS per category, and the mean IG / TG, kept correct as scores are typed.
    - **Add an activity in place** — the **+** button on each category banner opens that category's add-activity dialog without leaving the grid.
    - Missing scores are **excluded** from computation (no zero penalty) — except on historical "Legacy" classes which keep the exact pre-2026 behavior.
  - **Export .xlsx / Import .xlsx** (DO 015 ECR-style layout: item columns, PS, WS, IG, TG, descriptor) via PhpSpreadsheet.
- **Research relevance:** Core of the research — configurable DepEd DO 015 s.2026 computation with terms, publishing workflow, a spreadsheet-grade data-entry experience, and Excel interchange.

### 9. Attendance Page
- **Purpose:** Record and manage daily class attendance.
- **Who uses it:** Teachers.
- **Key features:**
  - Date picker — attendance is recorded per day.
  - Per-student status: **Present**, **Late**, **Excused**, or **Absent** (radio buttons).
  - **Save Attendance** stores records and notifies students.
- **Research relevance:** Demonstrates attendance monitoring that feeds the student dashboard.

### 10. Settings Page
- **Purpose:** Manage class configuration.
- **Who uses it:** Teachers.
- **Key features:**
  - **Class Information**: edit subject, section, school year, description.
  - **Join Code**: view the current code, generate a new one, or disable it.
  - **Grading Policy**: switch policies (or "Custom / no policy") — changes computation for all terms of the class. The same track-grouped picker is used here, pre-set to the class's current policy.
  - **Active Term**: choose which term the gradebook opens on.
  - **Danger Zone**: Archive or permanently Delete the class.
- **Research relevance:** Shows class lifecycle management and policy switching.

### 11. Profile Page (Teacher)
- Profile photo, office hours, contact email; **Change Password** (current password required, minimum 8 characters).

---

## III. Student Pages

### 12. Dashboard (Student View)
- **Purpose:** Student's home page after logging in.
- **Who uses it:** Students.
- **Key features:**
  - Statistic cards: enrolled classes, upcoming tasks, attendance rate, and **overall average** across published term grades.
  - **Needs attention** alert listing classes with published term grades below 75.
  - "My Classes" cards, upcoming assignments list, and latest announcement highlight.
- **Research relevance:** Personalized overview of the student's academic status.

### 13. Classes Page (Student)
- Cards of enrolled classes; **"+ Join Class"** modal for the teacher's join code.

### 14. Class Detail Page (Student View) — 2 Tabs
- **14a. Stream:** Announcements timeline; **My Grades Summary** (published terms only, with per-component PS badges and IG/TG); **My Attendance** breakdown including Excused.
- **14b. Assignments:** Assignment list with status badges (**Pending / Submitted / Submitted (Late) / Graded**), due date, attachment, and per-assignment requirements (file required, accepted types, max size, late policy). **Submit/Resubmit** modal shows the accepted file types; the button is hidden once the cutoff passes. Each card notes "Your submission is visible only to you and your teacher," and submitted work shows a receipt-style timestamp plus teacher feedback.

### 15. Grades Page (Student)
- **Purpose:** The student's complete, transparent grade report.
- **Who uses it:** Students.
- **Key features:**
  - Per class: expandable **term accordions** (published terms only) with a component table — score/total, PS (%), WS — then Initial Grade (IG), Term Grade (TG), and descriptor.
  - **Target grade calculator**: pick a target TG for the upcoming term and see the average PS needed on the remaining components (and whether the target is reachable).
- **Research relevance:** Students can verify their computed grades transparently and plan their performance.

### 16. Attendance Page (Student)
- Chronological list (latest 50 records): date, subject, section, status badge (incl. Excused).

### 17. Notifications Page (Student)
- Central inbox for alerts (new assignments, announcements, attendance, graded work); unread count badge in the navbar; all marked read on open.

### 18. Profile Page (Student)
- Profile photo, phone number; **Change Password**.

---

## IV. Admin Panel (`admin.php`)

- **Purpose:** Central administration of the whole portal.
- **Who uses it:** Users marked as admin (e.g., `admin@school.com`).
- **Pages:**
  - **Overview:** system stats (active teachers/students/classes/policies), upcoming events, latest audit activity, pending accounts alert.
  - **Users:** filter by role; make/revoke admin, enable/disable accounts, reset passwords.
  - **Years & Terms:** add academic years, set the active year, add terms with dates and weights, open/close terms.
  - **Grading Policies:** ten templates ship with the system, grouped by track (see §5). An admin can create/activate/delete policies; edit component names, weights, and the exam split (ST1/ST2/TE); edit the transmutation table row-by-row; and edit descriptor bands (English/Filipino). This is where DO 015 s.2026 rules are maintained. The templates are declared once in `db.php` (`grading_policy_catalog()`) and installed idempotently, so a fresh install and an existing database always agree.
  - **School Calendar:** add/delete holidays, exams, term dates, deadlines (seeded with the DO 009 s.2026 key dates).
  - **Announcements:** post school-wide announcements for everyone, students only, or teachers only.
  - **Audit Log:** full activity trail (who did what, when, from which IP).
  - **Settings:** school name and registration mode (open vs. approval).
- **Research relevance:** Demonstrates role-based administration and configuration of the grading system without code changes.

---

## V. Shared Features & Technical Notes

### Navigation Bar
- Persistent top bar: role-specific links, unread notification badge for students, **Admin Panel** link for admins, and a CSRF-protected Logout button.

### Flash Messages
- Success/error alerts that auto-hide after a few seconds.

### Secure File Downloads (`download.php`)
- All uploads (announcements, assignments, submissions, profile photos) are served through `download.php`, which validates that the requester is the owner or a member/teacher of the related class — raw `uploads/` URLs are no longer public.
- **Submission privacy:** a submission file is readable only by the student who submitted it, the class teacher, and admins (audit). Other students always receive 403 — confirmed by tests.

### Assignment Submission System
- Per-assignment policy: file required, accepted file types, max size per file (0.5–25 MB), late allowed (marked **Late**) or a hard **cutoff date**.
- Enforcement is server-side: missing file, disallowed type, and oversize are rejected; late status and cutoff checks use the school timezone (`Asia/Manila`) aligned with the server clock.
- Teachers can download all submissions as a ZIP (named per student) and see a "Not yet submitted" roster.
- Original filenames are stored alongside randomized storage names for the teacher's view.

### Screenshot Tool
*Removed.* The floating Screenshot button, its `html2canvas` dependency and the auto-capture queue were taken out to keep the portal focused on the grading workflow; pages can still be captured with the browser's own print-to-PDF.

### Technical Stack (for the Methodology chapter)
- **Backend:** PHP 8.3 — modular architecture: `config.php` (session/security helpers), `db.php` (schema migrations + seeds), `grade_engine.php` (single source of truth for grade computation *and* for validating/writing gradebook cells), `grades_api.php` (JSON endpoint behind the live gradebook grid), `includes/` (page + action modules), `admin.php` (admin panel).
- **Database:** SQLite — relational tables for users, students, teachers, classes, memberships, assignments, submissions, grade categories/items/scores, attendance, announcements, notifications, plus new tables for academic years, terms, grading policies/components, transmutation rows, descriptors, term publishing, calendar events, school announcements, audit logs, and settings. A unique index on `grade_scores (grade_item_id, student_id)` guarantees one score per activity per student, so a duplicated row can never inflate a category percentage.
- **Grading system:** configurable per policy — component weights, exam internal split (ST1 30% / ST2 30% / TE 40%; Field Experience subjects use the Term Exam only), adjusted transmutation table (DO 015 s.2026, SY 2026–2027 only), zero-based mode with no transmutation (from SY 2027–2028), and legacy linear mode for historical classes. Policies follow **DepEd Order No. 015, s. 2026** (grading) and **No. 017, s. 2026** (Strengthened SHS Curriculum — two tracks, Academic and TechPro).
- **Descriptors:** DO 015, s. 2026 qualitative bands — **Advancing** 90–100 (Namumukod-tangi), **Benchmarking** 80–89 (Napamamalas), **Connecting** 75–79 (Natutungo), **Developing** 65–74 (Napauunlad), **Emerging** 0–64 (Nagsisimula). The repealed DO 8, s. 2015 scale (Outstanding / Very Satisfactory / Satisfactory / Fairly Satisfactory / Did Not Meet Expectations) is retained **only** on the Legacy policy so historical classes still read as they were recorded. Passing is 75.
- **Frontend:** HTML5, CSS (Bootstrap 5), vanilla JavaScript. The gradebook grid is a plain `<table>` driven by `data-ri` / `data-ci` cell coordinates, with no grid library. Clipboard work uses the native `copy`/`paste` events and a `DataTransfer`, so pasting from Excel needs no plugin.
- **Live gradebook:** the browser sends only the edited cells to `grades_api.php`; the endpoint validates them (activity must belong to the class and term, student must be enrolled, value must be within 0…max), writes them with prepared statements, and returns the recomputed PS/IG/TG/descriptor plus class averages as JSON, which the grid patches in place. Out-of-range values are **rejected and reported**, never silently dropped, and the grade math is never duplicated on the client.
- **Security measures:** prepared statements, password hashing, CSRF tokens on all POST actions (the JSON endpoint checks the `X-CSRF-Token` header), per-IP + per-email login throttling, session regeneration, POST-only logout, upload allowlist + MIME sniffing, authenticated file serving, teacher/student/admin access control, and audit logging. Autosaves are audited at most once per minute per class so the audit log stays readable.
- **Data interchange:** Microsoft Excel (.xlsx) export and import of grade books via PhpSpreadsheet.

---

*Prepared as documentation for the Research Student Portal — School Portal project.*