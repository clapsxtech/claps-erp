# ClapsLearn ERP — features and pages

Every feature and every page in the application, with the result of the **1 November 2026** verification pass. For role journeys read the [user guide](ERP_USER_GUIDE.md); for every route, action value and query parameter read the [route reference](ERP_ROUTE_REFERENCE.md).

## Verification summary (1 November 2026)

| Check | Result |
|---|---|
| PHP feature/unit suite (`php artisan test`) | **491 passed**, 4,156 assertions (1 failure found and fixed; regression tests added for every fix) |
| End-to-end functional journeys through the real forms, one account per role (see below) | **125 of 125 steps pass** on a fresh database copy |
| Node asset and deployment suite (`npm test`) | **59 passed** |
| Signed-in crawl of every browser GET page and export, as site administrator, local SQLite copy | **100 of 100 reachable pages return 200**, with no PHP errors in the page body |
| Browser console check (built-in browser) on every ERP, admin and public page | No JavaScript errors after the fix below |
| Click-path audit: one signed-in account per role, following every link the UI shows (see below) | **706 pages, 0 broken links, 0 missing anchors, 0 forms without a route** |
| Expected non-200 responses | `/erp/teacher*` → 403 for an account with no linked teacher identity; `/erp/from-sheets` → 404 while `ERP_SHEETS_TRANSITION_ENABLED=false` |

Form submissions (POST actions) were verified by the automated suites, not by hand: they cover every workspace, business, tuition, teacher, admissions, allocation, recruitment, money and administration action, including role checks and rollback on failure.

### Fixed during this pass

1. **Admissions — matched demos could be marked attended before they ended.** The "wait until the demo has ended" guard in `Admissions::updateDemo` read the server's raw clock (`gmdate`) instead of the application clock, so it disagreed with the rest of the scheduling code. It now uses `now('UTC')`. This was the failing test `ErpTeacherAllocationTest::test_matched_demo_cannot_be_rescheduled_or_converted_through_legacy_status_form`.
2. **Historical tools — 25 dead routes returned server errors.** `workreport`, `marklistreport`, `duereport` and `timetable` were registered as full resources although only `index` exists, and `workhour` registered a `show` page that doesn't exist. Visiting e.g. `/admin/duereport/create` gave a 500. The resources are now restricted to the methods that exist (163 → 138 application routes). The route reference, CSV and JSON inventories were updated.
3. **Work-hour records — table script crashed on load.** The `/admin/workhour` table has 18 columns but its totals footer covered 17, which threw a DataTables error on every load. The missing footer cell was added.
4. **Timetable — Academics couldn't change existing classes.** The reschedule, cancel and attended-minutes links pointed at the manager-only workspace (403 for Academics). The Timetable page now renders those forms itself, plus extra class, lesson generation and teacher leave. They're shared with the workspace through `erp/action-forms.blade.php`, and saving returns to Timetable.
5. **Money — Finance had no charge-import queue.** Finance could import student charges but couldn't see the queue. Money & receipts now shows *Student consumption awaiting import*. The teacher-approval queue stays off that page.
6. **Duplicate reviews — link went to the workspace.** "Review teacher reply" now opens the duplicate-review form on the same Overview/Attendance page, and is shown only to roles that can resolve it.
7. **Work-hour records — no way to add a record.** The **Add** button had been commented out, so the add form was unreachable. It's restored, and a duplicate script include that bound every modal click twice was removed.
8. **Records & administration (`/tools`)** listed every team page to every role, so Finance, Sales and Academics hit 403s. It now lists only the pages each role can open.
9. **Teacher setup page (`/teacher`)** showed User accounts / Team access buttons to staff who aren't administrators (403). They now appear only for administrators; other staff are told to ask one.
10. **Roles pages** linked their "HOME" breadcrumb to `/admin`, which doesn't exist (404). It now goes to Records & administration.
11. **Academics tabs** jumped to "Record assessment" / "Subject eligibility" forms that read-only users don't see. Those tabs now appear only when the form is available, and otherwise go to the eligibility table.
12. **Role-limited CSV exports were unreachable.** Academics, Finance and Sales may export some datasets, but the links lived only on Migration & exports. People, Timetable, Attendance, Money and Follow-ups now show "Download CSV" links filtered by role.
13. **Teachers had no Account security link**, so they couldn't set up two-factor sign-in from their workspace. It's now in the teacher sidebar.

14. **Teacher matching — no way forward after a successful demo.** After "Continue" feedback the page said regular allocation was possible, but offered no link. The weekly-class form only appears inside a fresh search. Each successful demo now has an **Allocate weekly class with {teacher}** button. It opens the search pre-filled with the same teacher, subject, grade, syllabus, medium and time, starting the next week.
15. **Two-factor setup — 103-character secret to type by hand.** There's no QR code, so staff type the secret into their authenticator app. It's now a standard 160-bit, 32-character secret (`TwoFactorController`).
16. **Parent review deadline — had to be typed as UTC with seconds.** Both deadline fields (the class-review form and the monthly statement) were plain text boxes expecting `YYYY-MM-DD HH:MM:SS` in UTC. They're now date-time pickers in the team's own timezone, converted to UTC on save. Typed UTC values still work.

## Functional test (1 November 2026)

A Python harness drove the real app over HTTP on a fresh copy of the local database, signed in as each role. For every step it opened the page, read the form the page actually renders (hidden fields, dropdowns and search pickers included), filled it in, and submitted it like a browser. It then checked the redirect, the on-screen message and the resulting database rows. Negative steps confirm that invalid or unauthorised submissions are refused and leave the data unchanged.

| Journey | Steps | What was exercised |
|---|---|---|
| Admissions | 8/8 | Sales creates an enquiry, logs a follow-up, adds and completes a reminder, marks it hot, adds handoff notes, transfers it; the manager accepts the transfer |
| Teacher matching | 8/8 | Academics verifies a teacher and records availability. Search finds them (and correctly hides them when they have an overlapping lesson). Demo booked with confirmations, feedback recorded, weekly class allocated, and the lead is converted to a student with 8 dated lessons |
| Scheduling | 12/12 | Student and teacher availability; Academics reschedules, cancels and adds an extra class from Timetable; teacher leave (refused on a class day, accepted otherwise); regenerating lessons doesn't duplicate them |
| Teacher portal | 6/6 | Teacher submits attendance for their own ended lesson, can't submit twice or for another teacher's lesson, records a result, and a score above the maximum is refused |
| Attendance & charges | 10/10 | Approval blocked until the parent reviews; acceptance blocked until shared; share with a deadline; acceptance; approval; Finance imports the charge from Money exactly once (a replay doesn't double-charge); the manager corrects minutes, which resets approval and reverses the accrual |
| Money | 14/14 | Receipt (a duplicate reference isn't reposted; three-decimal amounts refused), payment request with print view, allocation (over-allocation refused), cancel the balance, correct and reverse receipts, receipt page, teacher settlement (overpayment refused) and its reversal |
| Follow-ups & tasks | 7/7 | Assigned task with checklist; a task can't be completed before its checklist; complete item and task from My tasks; student follow-up; read-only can't complete |
| People & academics | 11/11 | Register a student (duplicate code refused) and a teacher; eligibility; assignment; a grade change that would break eligibility is refused; pause (a paused student can't get new teaching); assessment, academic export, student profile |
| Administration | 12/12 | Onboard staff into a team; change role; teacher link (refused without teacher membership, then works) and unlink; remove membership; create, rename and archive a team; revoke sessions (a wrong admin password is refused) |
| Private tuition | 6/6 | Class note, parent share, HR candidate and interview, parent statement and monthly sharing |
| Teacher recruitment | 7/7 | Application, contact, stages, registration, approval; the approved teacher appears in Academics' directory |
| Two-factor sign-in | 4/4 | Setup shows a 32-character secret; enable with an authenticator code; password alone stops at the challenge; a fresh code completes sign-in (a reused code is refused) |
| Public teacher forms | 6/6 | Date-of-birth verification (a wrong date is refused for guests), class-hour submission (a resubmission doesn't duplicate), result submission, work-hour export |
| Role restrictions | 6/6 | Direct submissions outside a role (read-only/sales/teacher payments, finance marks, academics salary) return 403 and change nothing |
| Exports | 8/8 | Ledger, leads, payment-request, task, lesson, mark and student CSVs contain the new records; Finance can't export leads |

Most first-run failures were the test data breaking real business rules, and the app explained each one clearly. Examples: a lesson must fit both the student's and the teacher's availability, teacher leave can't overlap scheduled lessons, a teacher can't be paid beyond the recorded payable, and scores are whole numbers. Those rules are now asserted as negative steps. The functional run itself found items 14–16 above.

## Click-path audit (1 November 2026)

For each role, a test account signed in on a copy of the local database. A crawler then started from that account's landing page and followed every same-origin link it was shown. It checked the response for every link, that every `#anchor` exists on its target page, and that every form posts to a registered route with the right method. Forms were never submitted (POST behaviour is covered by the test suites). Up to 8 query-string variants per page were followed (pagination, filters, teacher sections).

| Role | Lands on | Pages reached | Distinct form targets | Problems |
|---|---|---|---|---|
| Site administrator | `/` | 262 | 139 | 0 |
| Team manager | `/` | 191 | 55 | 0 |
| Academics | `/` | 29 | 20 | 0 |
| Finance | `/` | 46 | 12 | 0 |
| Sales / follow-ups | `/` | 20 | 12 | 0 |
| Read-only | `/` | 157 | 28 | 0 |
| Teacher | `/erp/teacher` | 12 (2 are redirects to the dashboard) | 5 | 0 |

The first run found 23 problems in 4 groups (items 8–11 above). The gaps in items 12–13 came from comparing what each role could reach with what it's allowed. Every role now reaches every team page and export its permissions allow, and nothing else.

## Platform features

| Feature | What it does | Where |
|---|---|---|
| Sign-in portals | One login form with staff and superadmin entry pages; no public registration | `/login`, `/staff/login`, `/superadmin/login`, `/backend` |
| Password recovery | Reset request, email link and reset form (needs mail configured to deliver) | `/password/reset`, `/password/reset/{token}`, `/password/confirm` |
| Two-factor authentication | TOTP setup, enable/disable, recovery codes and sign-in challenge | `/account/security`, `/two-factor` |
| Business units (teams) | Multi-team ERP: each unit has its own students, teachers, money and timezone | `/` |
| Team roles and permissions | `team_manager`, `academics`, `finance`, `sales_followups`, `read_only`, plus site administrator and teacher access; enforced per page, action and export | Team access page, administration |
| Audit trail | Every operational change is recorded with actor and payload | Operations & audit |
| Health probes | Liveness and database readiness for monitoring | `/health`, `/ready` |
| Maintenance mode | Administrators switch the site into or out of maintenance | Admin settings |
| Sheets transition bridge | Optional read-only, validated copies of Google Sheets, with a separate switch that blocks ERP writes while Sheets are authoritative | `/erp/from-sheets` (disabled by default) |
| Data migration | Preview and execute imports from the legacy sheets with cutoff and opening-due handling | `/erp/migration-center` (import disabled by default) |
| CSV exports | 20 team datasets, filtered by role | `/erp/units/{unit}/exports/{dataset}` |
| Cloudflare deployment | Worker/D1/container deployment and static asset staging | See [deployment](deployment.md), [CLOUDFLARE.md](../CLOUDFLARE.md) |

## Business features by area

### Admissions & CRM
Lead capture and editing with ownership, demo scheduling and outcome tracking, contact attempts (WhatsApp/call/email — recorded, never sent), reminders, ownership transfers, conversion to a student with an optional real initial payment, and a reporting view (`?report=1`).

### Teacher matching (allocation)
Search for teachers by subject, grade, syllabus, medium and timezone-aware availability; verify qualifications and availability windows; book timed demos with teacher/parent confirmation; record demo feedback (continue, try another teacher, awaiting feedback, or no-show); hand a successful demo over to a regular recurring class with rates. Successful demos waiting for a weekly class appear in **Ready to allocate** for Academics.

### Teacher recruitment
Candidate pipeline with stages, contact history, registration, approval and identity resolution. The registered/approved directory is shared with academics, sales and read-only staff; decisions stay with managers.

### People and student profiles
Student and teacher lists with search and dues filter, operational student updates, and a full student profile (details, assignments, agreements and lessons, attendance, balance with receipts and payment requests, follow-ups and tasks). Assessment results live on the Academics page, not the profile.

### Academics
Assessment results, subject coverage and a results export by date range.

### Scheduling and timetable
Teacher availability, proposed and confirmed recurring agreements (including a second weekly slot), generation of dated lessons, reschedule, cancel, extra class, teacher leave/exceptions, and ending agreements. **Pause classes** for a date window and **stop classes** after a last class day, each in one step. Timetable view filters by date, student, teacher and status.

### Attendance
Teacher-submitted attendance after the class ends, **no-shows** (no charge, no teacher pay), manager review/approval, corrections, duplicate-case resolution, partial-day exceptions, a queue of **ended classes with no attendance**, and the reviewed queue that feeds student charges.

### Money & receipts
Receipts for money actually received, payment requests with printable copies, receipt allocation, audited reversals and corrections, **refunds** already paid back from a credit balance (and their reversal), teacher salary settlements and reversals, balances and reconciliation totals. Recording never transfers money.

### Follow-ups and tasks
Team tasks with checklists, follow-up evidence by student with priority filters (new this month / last month / older), and a personal **My assigned tasks** page.

### Private tuition workspace
Manager-only class follow-up notes, per-lesson parent review and disputes, monthly parent statement batches with an objection deadline, and a private HR candidate/interview record.

### Teacher portal
Linked teachers see their own overview, timetable, students, attendance history, results and reports, and submit attendance and results for their own active classes.

### Administration
Create, update and archive teams; onboard staff; update accounts; grant/revoke team membership and link teacher accounts; operations and audit view; migration center.

### Parent portal
Families sign in at `/parent` with the student ID and date of birth (one sign-in per child). They see upcoming and past classes, results, the balance, fee requests, invoices, receipts and refunds, and monthly statements, and can flag a class record as wrong. A flagged class that isn't approved yet is held as disputed. Staff answer flags under **Class follow-up → Parent queries**.

Security:
- **Lockout:** 5 failed attempts lock that student ID for 15 minutes, and site-wide failures are capped. Lockouts aren't keyed on IP, because the deployment's proxy hides real addresses.
- **Messages:** every failure shows the same message, so it doesn't reveal which half was wrong.
- **Sessions:** a session expires after 30 idle minutes, and changing the date of birth signs the family out.
- **Archived teams and missing DOB:** families in archived teams, or without a date of birth on file, can't sign in.
- **Audit:** every sign-in and query is audited.
- **What stays hidden:** staff notes, teacher pay, contact details and parent-handling notes are never shown.

### Accounts & tax
Double-entry books per team, posted automatically from the operational ledger. Each charge, receipt, refund, teacher accrual, teacher payment, opening balance and reversal posts exactly one balanced journal, so the Student accounts control account always equals the students' balances. Also includes:
- **Manual journals and reversals:** with custom accounts, for rent, bank charges, GST payments and capital.
- **Reports:** trial balance, general ledger with running balances, profit and loss, and balance sheet.

**GST teams** (enabled under Teams & accounts with GSTIN, legal name, address and state):
- **Rates:** class rates include 18% GST.
- **Split:** each charge splits into taxable value plus CGST and SGST, or IGST when the student's billing state differs from the team's state.
- **Tax invoices:** issued monthly per student (SAC 999293, numbered `PREFIX/2026-27/0001` per financial year, dated the day they're issued). Cancelling one issues a credit note (`PREFIX-CN/…`).
- **GST summary:** compares tax booked with tax on documents issued, by place of supply.

Non-GST teams get plain invoices. Tax is applied when a charge is posted; turning GST on posts existing charges under the old setting first. Tax-exclusive pricing isn't supported, because it would make the books disagree with the balances staff see.

### Trends
Six months side by side per team (managers, read-only and admins):
- **Sales:** enquiries, demos, joins and conversion.
- **Students and classes:** new and active students, hours taught, cancellations, no-shows and the no-show rate.
- **Money:** billed, collected, refunds, teacher cost, gross margin, and dues owed at month end.

Headline figures are for the last complete month, with a column chart for each measure and a full table.

### Historical records and tools
The original sheet-backed tools kept for reference: users, roles, subjects, topics, teacher/student/assignment records synced from Sheets, assessment and work-hour records, monthly and teacher-hour reports, historical dues and timetable, attendance and assessment history reports, and work-hour CSV export.

## Page catalog

Status is from the 1 November 2026 crawl as site administrator. `{unit}` is a team ID; `B` = `/erp/units/{unit}/business`. Every team page was checked on both local teams.

### ERP workspace

| Page | Path | Who can open it | Status |
|---|---|---|---|
| Business units | `/` | Signed-in staff | ✅ 200 |
| Records & administration | `/tools` | Signed-in staff | ✅ 200 |
| Account security (2FA) | `/account/security` | Any signed-in account | ✅ 200 |
| My assigned tasks | `/erp/my-tasks` | Team members | ✅ 200 |
| Overview | `B/dashboard` (also `B`) | Admin, manager, read-only | ✅ 200 |
| Admissions & CRM | `B/admissions`, `?report=1` | Admin, manager, sales, read-only | ✅ 200 |
| Teacher matching | `B/allocation` | Admin, manager, academics, sales, read-only | ✅ 200 |
| Teacher recruitment | `B/teacher-recruitment` | Admin/manager manage; academics, sales, read-only browse | ✅ 200 |
| Students & teachers | `B/people` | Admin, manager, academics, read-only | ✅ 200 |
| Academics | `B/academics` | Admin, manager, academics, read-only | ✅ 200 |
| Timetable | `B/timetable` | Admin, manager, academics, read-only | ✅ 200 |
| Attendance | `B/attendance` | Admin, manager, academics, read-only | ✅ 200 |
| Money & receipts | `B/money` | Admin, manager, finance, read-only | ✅ 200 |
| Follow-ups | `B/followups` | Admin, manager, sales, read-only | ✅ 200 |
| Team access | `B/staff` | Site administrator | ✅ 200 |
| Migration & exports | `B/migration` | Admin, manager, read-only | ✅ 200 |
| Detailed operations workspace | `/erp/units/{unit}` | Admin, manager, read-only | ✅ 200 |
| Full student profile | `/erp/units/{unit}/students/{student}` | Admin, manager, read-only | ✅ 200 |
| Receipt | `/erp/units/{unit}/receipts/{entry}` | Admin, manager, finance, read-only | ✅ 200 |
| Payment request print | `/erp/units/{unit}/payment-requests/{id}` | Admin, manager, finance, read-only | ✅ 200 |
| Reconciliation totals | `/erp/units/{unit}/reconciliation` | Admin, manager, read-only | ✅ 200 |
| Class follow-up and HR | `/erp/units/{unit}/tuition` | Admin, team manager | ✅ 200 |
| Parent attendance statement | `/erp/units/{unit}/tuition/statement?student_id=…&month=YYYY-MM` | Admin, team manager | ✅ 200 |
| Form lookups (JSON) | `/erp/units/{unit}/lookup?type=…` | Per role | ✅ 200 |
| Academic results export (CSV) | `/erp/units/{unit}/academics/export` | Admin, manager, academics, read-only | ✅ 200 |
| Dataset exports (CSV) ×20 | `/erp/units/{unit}/exports/{dataset}` | Per role | ✅ all 20 return 200 |
| Teacher entry | `/teacher` | Linked teachers; others see setup guidance | ✅ 200 |
| Teacher dashboard (6 sections) | `/erp/teacher?section=…` | Linked teachers only | ✅ 403 for non-teacher, as designed; covered by `ErpTeacherHttpTest` |
| From Sheets | `/erp/from-sheets` | When the bridge is enabled | ✅ 404 while disabled, as designed |

### Parent portal, accounts and trends (added 2 November 2026)

| Page | Path | Who |
|---|---|---|
| Parent sign-in | `/parent/login` | Families (student ID + date of birth) |
| Parent portal | `/parent`, `/parent/classes`, `/parent/results`, `/parent/fees` | Signed-in family |
| Parent documents | `/parent/statement?month=…`, `/parent/documents/{receipt|request|invoice}/{id}` | Signed-in family, own child only |
| Accounts & tax | `B/accounts?view=overview|journals|ledger|reports|invoices|tax|chart` | Admin, manager, finance; read-only can view |
| Invoice / credit note print | `/erp/units/{unit}/invoices/{id}` | Same as Accounts & tax |
| Trends | `B/insights` | Admin, manager, read-only |

### Administration

| Page | Path | Status |
|---|---|---|
| Teams & accounts | `/erp/administration` | ✅ 200 |
| Operations & audit | `/erp/administration/operations` | ✅ 200 |
| Migration Center | `/erp/migration-center` | ✅ 200 |

### Historical administration tools (site administrator)

| Page | Path | Status |
|---|---|---|
| Records overview | `/legacy/dashboard` | ✅ 200 |
| Application settings | `/admin/settings` | ✅ 200 |
| Site roles (list, create) | `/admin/roles`, `/admin/roles/create` | ✅ 200 |
| User accounts (list, create, edit) | `/admin/users`, `/admin/users/create`, `/admin/users/{id}/edit` | ✅ 200 |
| Profile | `/admin/profile` | ✅ 200 |
| Subjects (list, create) | `/admin/subjects`, `/admin/subjects/create` | ✅ 200 |
| Topics (list, create) | `/admin/topics`, `/admin/topics/create` | ✅ 200 |
| Teacher records | `/admin/teachers` | ✅ 200 |
| Student records | `/admin/students` | ✅ 200 |
| Assignment records | `/admin/master` | ✅ 200 |
| Assessment records (list, create) | `/admin/marklist`, `/admin/marklist/create` | ✅ 200 |
| Work-hour records (with Add form) | `/admin/workhour` | ✅ 200 (table error fixed, Add restored) |
| Work-hour CSV export | `/admin/work-hours/export` | ✅ 200 |
| Monthly reports | `/admin/monthlyreport` | ✅ 200 |
| Teacher hour reports | `/admin/workreport` | ✅ 200 |
| Assessment reports | `/admin/marklistreport` | ✅ 200 |
| Historical dues | `/admin/duereport` | ✅ 200 |
| Historical timetable | `/admin/timetable` | ✅ 200 |
| Student timetable | `/admin/studentTimetable` | ✅ 200 |
| Student attendance report | `/student-attendance-report` | ✅ 200 |
| Attendance history | `/attendanceReport` | ✅ 200 |
| Assessment history | `/markreport` | ✅ 200 |

### Public teacher forms (teacher name + date-of-birth verification)

| Page | Path | Status |
|---|---|---|
| Record attendance | `/attendance` | ✅ 200 |
| Record results | `/resultform` | ✅ 200 |
| Teacher performance | `/performance` | ✅ 200 |
| My timetable | `/timetable`, `/timetableReport` | ✅ 200 |

### Sign-in and technical

| Page | Path | Status |
|---|---|---|
| Sign in (staff / superadmin / backend) | `/login`, `/staff/login`, `/superadmin/login`, `/backend` | ✅ 200 |
| Health / readiness | `/health`, `/ready` | ✅ 200 |
| Compatibility redirects | `/home` → `/`, `/legacy` → `/tools`, `/erp` → `/` | ✅ redirect |

## Known limits of this verification

- The click-path audit used one unit with one account per role. Multi-unit accounts and archived units are covered by the test suites.
- The local database has little historical sheet data, so several historical tables and CSV exports showed headers only.
- The Sheets bridge and migration import were checked in their default disabled state. Their enabled behavior is covered by tests and by [sheets-transition.md](sheets-transition.md) / [ERP_MIGRATION.md](ERP_MIGRATION.md).
- Production deployment and email delivery were not checked.
- A teacher who opens Account security sees the staff layout's navigation. Its links work (the business-units link sends them back to their dashboard), but it isn't the teacher layout.
