# Customer and staff journey simulation — 1 November 2026

A sanity check of the ERP against realistic lives rather than single features. Dummy families, teachers and staff were taken through months of activity on a fresh copy of the local database. Every step went through the real pages and forms, signed in as the person who would do it. "Time passing" was simulated by moving specific lessons and demos into the past.

Each step is labelled:

- **OK**: the system supports it.
- **Rule**: correctly refused by a business rule, with a clear message.
- **Gap**: something a real tuition business needs that the system can't do, or only through a workaround.
- **Bug**: wrong behaviour.

**First run: 91 steps, 66 OK, 3 rules, 22 gaps, 0 bugs.** Everything the system offered worked correctly, including money and timezone arithmetic. The gaps were missing capabilities, listed by priority below.

**After building the six high-priority gaps (same day): 93 steps, 77 OK, 3 rules, 13 gaps, 0 bugs.** Gaps 1–6 are resolved; the medium and lower gaps remain open.

### Resolved high-priority gaps

| # | What was built | Who | Where |
|---|---|---|---|
| 1 | **Pause classes** (date window) cancels every scheduled lesson in the window across all subjects, without charge; later lessons stay booked. **Stop classes** (last class day) cancels everything after it, ends each assignment and weekly agreement, and marks the student stopped once no classes remain. One audited step each. | Academics, manager | People, Timetable, workspace |
| 2 | **No-show:** teachers press "Student didn't attend?" with what happened; staff use "Record a student no-show". The lesson becomes `no_show`, with no charge and no teacher pay. Demo feedback has a "Learner did not attend (no-show)" outcome. | Teacher, academics, manager | Teacher portal, Timetable, workspace, Teacher matching |
| 3 | **Refund:** records money already paid back from a credit balance (`refund` ledger entry), never more than the current credit. It can be reversed if recorded by mistake. It moves no money. | Finance, manager | Money |
| 4 | **Demo handoff:** enquiries with a successful demo waiting for a weekly class appear in **Ready to allocate** for Academics. Academics can open and allocate exactly those enquiries (others stay private to their owner). | Academics | Teacher matching |
| 5 | **Add a weekly slot:** availability, propose, confirm and end-agreement forms are on the Timetable. Matching's "assignment already covers these dates" message points there. | Academics | Timetable |
| 6 | **Ended classes with no attendance:** a queue grouped by teacher, oldest first, with links to record minutes or a no-show. | Academics, manager, read-only | Overview, Attendance |

Regression tests: `tests/Feature/ErpJourneyGapsTest.php`.

See also: [the guide](APP_GUIDE.md) for the current app and its screens. This simulation predates the switch to the React app; its findings are about business rules, which the new app reuses unchanged.

## Dummy cast

| Who | Role in the simulation |
|---|---|
| **Aarav Menon** (mother Priya) | Kochi, Grade 8 CBSE Maths. Full lifecycle from enquiry to stopping with credit left |
| **Rohan Das** | Books a demo, doesn't attend, is closed as lost, comes back months later |
| **Zara and Ayaan Khan** | Siblings sharing one parent phone; join without a demo |
| **Meera Iyer** | Gulf Online, Dubai timezone, taught by an India-based teacher |
| **Nisha Varghese** | New teacher, recruited, onboarded and linked during the simulation |
| Staff | One account each: team manager, academics, finance, sales, read-only, site admin |

## Journey results

| Journey | OK | Rule | Gap |
|---|---|---|---|
| S5 HR recruits and onboards Nisha | 8 | | 1 |
| C1 Menons: enquiry → demo → join → weekly classes → advance fee | 10 | | 3 |
| S7 Nisha's teaching day | 7 | | 4 |
| C1 Menons: parent review, dispute, correction, charges, statement | 9 | 1 | 2 |
| C2 Rohan: demo no-show → lost → returns | 4 | | 1 |
| C3 Khan siblings | 6 | | 1 |
| C4 Meera: Dubai student, India teacher | 5 | | 2 |
| C5 Menons: exam pause, reschedule, stop with credit | 1 | 2 | 4 |
| S1 Sales morning | 4 | | |
| S2 Academics daily | 2 | | 1 |
| S3 Finance month-end | 3 | | 2 |
| S4 Manager oversight | 3 | | |
| S6 Admin: a staff member leaves | 2 | | 1 |
| S8 Read-only auditor | 2 | | |

### What held up (sanity check)

- **Admissions:** duplicate detection catches returning families and siblings by phone, and lets staff keep siblings as separate students. Lost enquiries can be reopened.
- **Matching:** it finds free, qualified teachers and hides anyone with an overlapping lesson. Demo → feedback → weekly class converts the lead and generated 21 Saturday lessons through March.
- **Time zones:** a 7 pm Dubai class is stored as 15:00 UTC (8:30 pm India).
- **Teacher portal:** teachers see only their own classes. The parent's phone number is hidden from them. Late submissions are flagged.
- **Parent review:** share → dispute → manager correction → re-share → acceptance → approval. A disputed class can't be approved.
- **Money:** a 40-minute class at ₹450/hour was charged exactly ₹300. The profile balance is correct (₹3,600 paid − ₹750 charged = ₹2,850 credit). Part-paid requests show the outstanding ₹1,000.
- **Staff:** suspending a leaver revokes their sessions while keeping their enquiries visible to the manager. Read-only staff can browse Money and export without seeing any action forms.

## Gaps, by priority

### High: daily friction or money (all resolved, see above)

| # | Gap | Found in | Today's workaround | Suggested direction |
|---|---|---|---|---|
| 1 | **Pausing or stopping a student means cancelling every future lesson one by one.** Pause, stop and end-agreement all refuse while future lessons exist; Aarav had 16–18. | C5 | One cancel form per lesson, then end each agreement and assignment, then change status | "Pause from–to" and "stop from date" actions that cancel the generated lessons in one audited step |
| 2 | **A no-show can't be recorded**, for a class or a demo. Attendance needs at least 1 minute; nothing ever sets `no_show`; demo feedback has no no-show option. The lesson stays "scheduled" with no attendance forever. | S7, C2 | Leave it, or cancel it (which loses the fact that the student was absent) | A no-show outcome for teacher attendance and demo feedback, plus a policy on whether it's charged or paid |
| 3 | **Refunds aren't supported.** Aarav left ₹3,850 in credit; only reversing a mistaken receipt exists. | C5 | Refund outside the system, with no record in the ledger | An audited "refund paid" entry that reduces a credit balance (records only; moves no money) |
| 4 | **The demo → class handoff needs a manager.** Academics, who own allocation, get a 404 on sales-owned enquiries. Sales can't allocate and has no "request allocation" action. | C1 | Sales messages a manager outside the system | Let Academics open enquiries that have successful demos, or add a "ready to allocate" handoff queue |
| 5 | **Twice-weekly classes are manager-only.** Matching refuses a second slot for the same subject ("an assignment already covers these dates"). The propose/confirm forms that can add one live only in the manager workspace. | C1 | The manager proposes, confirms and generates a second agreement in the workspace (verified: 20 Tuesday lessons) | Add "add another weekly slot" to Timetable / the student, for Academics |
| 6 | **No "attendance not submitted" list.** 9 ended classes had no attendance and nothing surfaced them. | S2 | Staff compare the timetable with the queue by eye | A queue of ended lessons with no attendance, per teacher |

### Medium: workarounds exist but cost time or accuracy

| # | Gap | Found in | Workaround |
|---|---|---|---|
| 7 | Teachers can't correct their own submission (Nisha entered 50 instead of 55) | S7 | Ask staff to use "correct attendance" |
| 8 | Teachers can't see what they've earned or been paid | S7 | Staff tell them |
| 9 | Teachers can't report leave or unavailability | S7 | They message staff, who record the exception |
| 10 | There's no substitute teacher for one lesson; reschedule changes only the time | C5 | A separate assignment with the substitute plus an extra class |
| 11 | Finance can't open reconciliation totals (403); only managers and read-only can | S3 | Ask a manager, or work from the ledger export |
| 12 | A one-off reschedule needs a **recurring** availability window for the new day | C5 | Add a weekly window just to move one class |
| 13 | New staff onboarding has no **Teacher** option, so teacher logins are created as a staff role and then switched (briefly giving that staff access) | S5 | Onboard as read-only, then grant Teacher and link straight away |
| 14 | **Teacher pools are separate per team.** An India teacher can't be added to Gulf Online, a second teacher record is needed, and one login can't link to both | C4 | A duplicate teacher record and a second login |
| 15 | When a staff member leaves, their enquiries can only be transferred one at a time | S6 | Repeat a transfer per enquiry |

### Lower or by design

| # | Gap | Notes |
|---|---|---|
| 16 | No family or guardian account; one transfer for two siblings must be split into two receipts by hand | C3 |
| 17 | Invoices exist only as an export dataset; there's no invoice screen | S3 |
| 18 | No parent or student portal; statements are printed or shared manually | By design today |
| 19 | The system never sends messages (statements, reminders, receipts) | By design: "No message or money transfer was sent" |

## Site administrator journeys

A second pass followed the superadmin through 49 steps: opening a new branch, the full account lifecycle, passwords and two-factor, daily oversight, archiving a team, migration and the Sheets bridge, and the older admin tools. It ran with database sessions, as production does (`src/index.ts`).

**Result: 49 steps, 34 OK, 9 rules, 6 gaps; 3 bugs found and fixed.**

| Journey | OK | Rule | Gap |
|---|---|---|---|
| A1 Open a new branch: create team, onboard a manager and a two-team finance person, per-team roles, add an existing sales person | 7 | 2 | |
| A2 Account lifecycle: reset password, suspend, reactivate, promote, demote | 10 | 3 | |
| A3 Passwords and two-factor | 2 | | 2 |
| A4 Daily oversight and audit | 4 | | 2 |
| A5 Archive and restore a team | 5 | 2 | |
| A6 Migration center and Sheets bridge | | 2 | 1 |
| A7 Older admin tools: roles, settings, users, maintenance mode | 6 | | 1 |

### Bugs found and fixed

| Bug | Effect | Fix |
|---|---|---|
| **Creating a site role without ticking any permission crashed (500).** The role row was still created. | Admins saw an error page for a valid action | `RoleController` treats "no permissions" as an empty list |
| **Unticking every permission on a role never removed them.** Update only synced permissions when at least one was ticked. | Permissions could not be fully revoked from a role | Always sync, so an empty selection clears them |
| **All numeric rate limits shared one counter per user.** After 6 account changes in a minute, the admin was blocked (429) from setting up their own 2FA and from the migration center, and vice versa. | Busy admin sessions hit unrelated "too many requests" errors | Each throttle has its own counter (`throttle:N,1,<name>` in `routes/erp.php` and `routes/web.php`) |

Regression tests: `tests/Feature/AdminJourneyTest.php`.

### What held up

- Branch staff see only their own team; Kochi pages and Teams & accounts return 403 for them. One person can hold different roles in different teams.
- Password reset enforces 12+ characters. The old password stops working and existing sessions are signed out. Suspension blocks sign-in and live sessions, and reactivation restores access.
- The installation owner can't be demoted, an admin can't suspend themselves, and at least one superadmin is always kept.
- The forgot-password email produces a working reset link (logged locally).
- Archived teams are closed to their staff and read-only even for admins, can still be exported, and can be restored.
- Maintenance mode shows the maintenance page to everyone, and the off-switch still works while the site is down.

### Admin gaps

| # | Gap | Workaround |
|---|---|---|
| 20 | No "change my password" on Account security | Use the emailed reset link, or ask another admin |
| 21 | An admin can't reset someone else's two-factor when they lose their phone | The user's recovery codes; otherwise they're locked out |
| 22 | The audit trail shows actor and team ID numbers, not names | Cross-reference Teams & accounts |
| 23 | The audit trail has no filters (person, team, action, date) or export; only the latest 30 events, paginated | Page through it |
| 24 | The Migration center doesn't say when import is switched off in this environment | Read `ERP_MIGRATION_IMPORT_ENABLED` / the docs |
| 25 | Maintenance mode has routes but no on-screen switch | A direct POST, or `php artisan down/up` |

Local note: this checkout's `.env` uses `SESSION_DRIVER=file`. With file sessions, "revoke sessions" and password resets can't sign out existing browser sessions, because they delete rows from the database session table. Production sets `database`. Suspension still takes effect immediately either way, because every request checks the account is active. The local site settings also hold placeholder emails ("Abc 673585"), which must be corrected before settings can be saved.

## Opening a new team

A third pass created "Calicut Centre" and took it from an empty team to its first paid class. First, every page, export and lookup of the **empty** team was crawled as each role: no errors (admin 49 pages, manager 48, read-only 47, academics 15, sales 15, finance 7). Then the go-live itself: **27 steps, 22 OK, 3 rules, 2 gaps; 1 bug found and fixed.**

- **Team record:** a duplicate or lower-case code is refused. Name, phone and timezone can be changed while the team is empty.
- **Teacher pool:** approving an applicant creates the teacher in the new team only; direct registration works too.
- **First customer:** enquiry → demo → Ready to allocate → weekly class (5 pm India stored as 11:30 UTC) → attendance → parent review → approval → ₹350 charge → payment.
- **Isolation:** the enquiry 404s through Kochi, and Kochi's ledger export doesn't include Calicut money.

| Finding | Type | Detail |
|---|---|---|
| Typing "maths" created a second subject next to "Maths" | **Bug, fixed** | Subject matching was case-sensitive, and subjects are shared by every team, so matching and reports would split. `People::eligibility` now matches ignoring case and extra spaces. Test: `AdminJourneyTest::test_a_new_team_reuses_shared_subjects_whatever_the_case` |
| 26. An empty team's Overview has no setup checklist | Gap | Added a go-live checklist to the user guide (since replaced by [the guide](APP_GUIDE.md)) (section 5) |
| 27. Existing records for a new team can only be bulk-imported in the operator JSON format, which is off by default | Gap | Otherwise records are entered one by one |

Existing duplicate subjects (if any were created before the fix) aren't merged automatically.

## Teacher, parent and sales in depth

A fourth pass followed three people further than the first run did: Nisha (teacher), Priya Menon (parent), and two counsellors, Asha and Ravi (sales). Parents have no login, so the parent journey covers the documents they receive and the requests staff must handle for them.

**Result: 44 steps, 32 OK, 6 rules, 6 gaps.** Two gaps were closed straight away, and the other five were built the same day (below). **After building them: 44 steps, 37 OK, 6 rules, 1 gap (no parent portal).**

**Teacher (14 steps):** signing in lands on the teacher workspace. Overview shows her next classes. The timetable reflects a reschedule (Thu 12 Nov 10:00), an extra class and a cancellation. A late start submitted as 17:15 her time was stored as 11:45 UTC with 75 minutes. Future classes, report ranges over 366 days and staff pages are refused. Account security is reachable, and unlinking her login stops portal access immediately.

**Sales (19 steps):** Today, Overdue and No-next-action filters each show the right enquiries. Today's reminder appears. Ravi can't see, search for or open Asha's enquiries. Asha hands Leo to Ravi, who accepts it himself, and Leo leaves her list. Lost with a reason; direct conversion with a ₹2,500 first payment; sales can't record later payments (finance only). The source report shows Instagram, Google and Referral outcomes. A new student appears in follow-ups.

**Parent (11 steps):** the fee request shows requested, paid, outstanding and due date; the receipt shows student, amount, reference and date. The monthly statement lists classes, minutes and charges. None of the documents show internal notes or teacher pay.

### Closed during this pass

| Gap | Fix |
|---|---|
| Refunds had no printable note (404) | The receipt page also prints a **Refund acknowledgement** ("Paid back to the family"). It's linked from the student profile and the Money ledger |
| Printed fee requests and receipts had no way to contact the centre | Both show the team's phone next to its name |

### Gaps from this pass (28–32 resolved)

| # | Gap | Who it affects | Workaround |
|---|---|---|---|
| 28 | The teacher's student list doesn't show the learning needs and parent concerns sales captured at handoff | Teacher | Staff pass them on outside the system |
| 29 | The monthly statement shows classes and charges but not payments received or the balance | Parent | Finance or a manager quotes the balance separately |
| 30 | Sales, usually the parent's contact, can't see a student's balance | Sales, parent | Ask finance or a manager |
| 31 | Changing the teacher for **one** subject still means cancelling that subject's future lessons one by one before ending the assignment (Stop classes covers all subjects) | Parent, academics | Cancel each lesson, end the assignment, assign the new teacher |
| 32 | The sales report groups outcomes by source only; there's no per-counsellor comparison | Manager, sales | Count from the leads export |
| 18 | No parent portal (known) | Parent | Printed documents |

### Resolved gaps 28–32

| # | What was built | Where |
|---|---|---|
| 28 | The teacher's student cards show **Learning needs**, **Parent's concerns** and **About the student** from the latest admissions handoff. Notes about the parent and contact details stay hidden | Teacher workspace → My students |
| 29 | The parent statement has an **Account summary**: balance at the start of the month, payments received, class charges posted, refunds, corrections, and the closing balance as credit or due | Class follow-up → parent statement |
| 30 | Sales see each student's **balance** (read-only) on the Follow-ups list. Teacher pay stays visible only to roles with Money | Follow-ups |
| 31 | **Change the teacher for one subject** from a date, in one step. It cancels that subject's later lessons, ends the assignment, creates the new one (same student rate unless changed), and rebooks the same weekly slots with the new teacher after checking eligibility and availability. Nothing changes if any check fails. In the simulation, 34 Tuesday and Saturday classes moved in one submission | People, Timetable, workspace (Academics and managers) |
| 32 | **Outcomes by counsellor** in the sales report: enquiries, leads with a demo, joined, lost and conversion per current owner, for the same dates as the source table. Sales staff still see only their own enquiries | Admissions → Reports |

Regression tests: `tests/Feature/ErpJourneyGapsTest.php` (teacher handoff notes, statement summary, sales balances, teacher switch including the all-or-nothing case, counsellor report).

## Rules confirmed (correct refusals)

- Pausing a student with future lessons, and ending an agreement with lessons beyond its end date, are refused with clear instructions. The rules are sound; the missing bulk action is gap 1.
- A disputed class can't be approved until it's resolved.

## Method notes

- The harness and scenarios (`sim.py`, built on the functional-test harness) ran against a scratch copy of `database/database.sqlite`. The real database wasn't touched.
- Demo and lesson dates were moved into the past to simulate time passing. Everything else went through the real forms, with the same permissions, CSRF tokens and validation as a browser.
- An India-to-Dubai class and one-week-ahead scheduling were covered. Daylight-saving zones (for example a UK student) weren't.
