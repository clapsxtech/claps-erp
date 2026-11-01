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

See also: [features and pages](FEATURES_AND_PAGES.md) (feature catalogue, page crawl, click-path audit, functional test) and the [user guide](ERP_USER_GUIDE.md).

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

## Rules confirmed (correct refusals)

- Pausing a student with future lessons, and ending an agreement with lessons beyond its end date, are refused with clear instructions. The rules are sound; the missing bulk action is gap 1.
- A disputed class can't be approved until it's resolved.

## Method notes

- The harness and scenarios (`sim.py`, built on the functional-test harness) ran against a scratch copy of `database/database.sqlite`. The real database wasn't touched.
- Demo and lesson dates were moved into the past to simulate time passing. Everything else went through the real forms, with the same permissions, CSRF tokens and validation as a browser.
- An India-to-Dubai class and one-week-ahead scheduling were covered. Daylight-saving zones (for example a UK student) weren't.
