# ClapsLearn ERP: guide

Everything runs in one React + Astryx app. Sign in at `/login`. Staff land in the staff app at `/app`, and
linked teachers land in their portal at `/teacher`. Families use `/parent/login`.

## Getting around (staff)

- **Sidebar**, grouped by job. You only see what your role may open; the server enforces the same rules.
  - **Overview:** Home, My tasks
  - **People:** Students, Teachers, Admissions, Follow-ups, Teacher hiring
  - **Classes:** Timetable, Attendance, Teacher matching, Results
  - **Money:** Payments & dues, Accounts & tax
  - **Reports:** Sales report, From Sheets (when the Sheets bridge is on), Trends, Exports & checks
  - **Admin:** Team & access, Teams & accounts, Operations & audit, Migration center, Account security,
    Records & administration
- **New** (top bar) starts a lead, student, payment, payment request, task or teacher from any screen.
- **Search** (⌘K or Ctrl+K, or `/`) jumps to any screen, starts any new record, or finds a student or teacher
  by name or code.
- **Phones:** a bottom bar holds the four places your role uses most. **More** opens the same search, so
  everything is at most two taps away.
- **Team switcher** (top left): staff who belong to several teams switch here. The app remembers the last
  team you used.

## Screens

| Area | What you do there |
|---|---|
| Home | A greeting and "Needs you today": what is waiting for you, in plain sentences, each opening its list. Then quick actions for your role, figures that open the list behind them, today's lessons and your tasks |
| Students | Search, filter and sort; balances (money and sales roles); add a student; open the profile (managers and read-only) |
| Teachers | Levels, subjects, classes; earnings for money roles; add, classify, add eligibility |
| Admissions | Lead pipeline by stage; one-tap follow-up; demos, reminders, transfers; register as a student; teacher directory with Send profile |
| Follow-ups | Who to call today; log a follow-up in one tap |
| Teacher hiring | Applications and candidate review (managers) |
| Timetable | Week view; lesson actions (reschedule, cancel, no-show, extra class, unavailability, switch teacher, pause or stop); weekly slots (add availability, propose, confirm, generate lessons, end) |
| Attendance | Approve teachers' attendance (in bulk too); bill classes to families ("Ready to bill"); missing attendance; possible duplicates |
| Teacher matching | Find a teacher for a learner and slot; book a demo; allocate weekly classes |
| Results | Assessment results and subject eligibility; CSV export |
| Payments & dues | Collection queue; record payments; ledger with totals; payment requests; teacher pay; refunds and reversals (with confirmation) |
| Accounts & tax | Trial balance, journals, ledger, P&L and balance sheet, invoices, tax, chart of accounts |
| Tuition | Parent queries, statements and statement sharing, private notes (managers) |
| Records & administration | Read-only historical records from the Sheets era, with CSV export (administrators) |

Lists follow the same conventions everywhere:
- **Layout:** search and filters sit above the list. Click a column header to sort. Rows are paged, with the count shown.
- **Money:** amounts are right-aligned in ₹ with Indian digit grouping, and dues show in red.
- **Phones:** lists become cards.
- **Sharing views:** filters and sorting live in the URL, so a link or the back button reopens the same view.

## Printing

Receipts, invoices, payment requests and parent statements open as A4 print pages under `/print/...`. Use
**Print** on the page or your browser's print command.

## Teachers

- **Portal:** `/teacher` shows today's lessons first with one-tap attendance, then the timetable, students,
  results and reports. It is built for phones.
- **Who gets in:** only accounts explicitly linked to an active teacher record in one team.
- **Public forms:** teachers without a login can still use `/attendance`, `/resultform`, `/performance` and
  `/timetable`. They identify with their name and date of birth.

## Families

`/parent/login` takes a student ID and date of birth. Families see an overview, classes, results and fees, can
print their documents, and can raise a query about a class. Repeated wrong attempts lock the student ID for 15
minutes.

## Account security

`/account/security` (also under Admin in the staff app) turns two-factor sign-in on or off. Recovery codes are
shown once, when you turn it on.
