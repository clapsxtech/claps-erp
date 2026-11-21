# Route reference

Generated from `php artisan route:list --json`. Regenerate it after adding routes.

Every page URL serves the React host page; data and writes go through the JSON routes. Unit routes pass through `TeamAuthorization`, which applies the role matrix in `TeamPermissions`; app JSON routes declare their check with `->defaults('erp_permission', [kind, value])`.

## Staff app pages

| Method | URI | Name | Middleware |
|---|---|---|---|
| GET | `/app/{path?}` | app.shell | Authenticate |
| GET | `/print/{path?}` | print.host | Authenticate |

## Staff app data (JSON)

| Method | URI | Name | Middleware |
|---|---|---|---|
| GET | `/app-api/account/two-factor` | app.api.account.two-factor | Authenticate |
| GET | `/app-api/units/{unit}/academics` | app.api.classes.academics | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/academics/eligibility` | app.api.classes.academics.eligibility | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/accounts/chart` | app.api.accounts.chart | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/accounts/invoices` | app.api.accounts.invoices | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/accounts/journals` | app.api.accounts.journals | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/accounts/ledger` | app.api.accounts.ledger | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/accounts/overview` | app.api.accounts.overview | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/accounts/reports` | app.api.accounts.reports | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/accounts/tax` | app.api.accounts.tax | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/admin/team` | app.api.admin.team | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/admissions` | app.api.people.admissions | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/admissions/demos` | app.api.people.admissions.demos | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/admissions/reminders` | app.api.people.admissions.reminders | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/admissions/transfers` | app.api.people.admissions.transfers | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/admissions/{lead}` | app.api.people.admissions.lead | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/attendance` | app.api.classes.attendance | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/attendance/queues/{queue}` | app.api.classes.attendance.queue | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/attendance/reviews` | app.api.classes.attendance.reviews | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/followups` | app.api.people.followups | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/matching` | app.api.classes.matching | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/matching/prepare/{teacher}` | app.api.classes.matching.prepare | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/matching/teachers` | app.api.classes.matching.teachers | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/money/dues` | app.api.money.dues | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/money/ledger` | app.api.money.ledger | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/money/requests` | app.api.money.requests | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/money/students` | app.api.money.students | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/money/summary` | app.api.money.summary | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/money/teachers` | app.api.money.teachers | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/overview/exports` | app.api.overview.exports | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/overview/exports/history` | app.api.overview.history | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/overview/home` | app.api.overview.home | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/overview/insights` | app.api.overview.insights | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/overview/sales-report` | app.api.overview.sales-report | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/overview/tasks` | app.api.overview.tasks | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/print/invoice/{id}` | app.api.print.invoice | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/print/receipt/{entry}` | app.api.print.receipt | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/print/request/{id}` | app.api.print.request | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/print/statement` | app.api.print.statement | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/recruitment` | app.api.people.recruitment | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/recruitment/{candidate}` | app.api.people.recruitment.candidate | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/students` | app.api.students | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/students/{student}` | app.api.student | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/teacher-directory` | app.api.people.teacher-directory | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/teachers` | app.api.people.teachers | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/teachers/{teacher}` | app.api.people.teacher | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/timetable` | app.api.classes.timetable | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/timetable/availability` | app.api.classes.timetable.availability | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/timetable/plans` | app.api.classes.timetable.plans | Authenticate, TeamAuthorization |
| GET | `/app-api/units/{unit}/tuition` | app.api.money.tuition | Authenticate, TeamAuthorization |

## Staff app actions (JSON writes)

| Method | URI | Name | Middleware |
|---|---|---|---|
| POST | `/erp/administration/accounts/{id}` | erp.accounts.update | Authenticate, RequireAdministrator, ThrottleRequests:20,1,admin-accounts |
| POST | `/erp/administration/onboard` | erp.onboard | Authenticate, RequireAdministrator |
| POST | `/erp/administration/teams` | erp.teams.create | Authenticate, RequireAdministrator |
| POST | `/erp/administration/teams/{id}` | erp.teams.update | Authenticate, RequireAdministrator |
| POST | `/erp/administration/teams/{id}/archive` | erp.teams.archive | Authenticate, RequireAdministrator |
| GET | `/erp/from-sheets` | erp.sheets | Authenticate |
| POST | `/erp/migration-center/execute` | erp.migration.execute | Authenticate, RequireAdministrator, ThrottleRequests:5,1,migration-execute |
| POST | `/erp/migration-center/preview` | erp.migration.preview | Authenticate, RequireAdministrator, ThrottleRequests:10,1,migration-preview |
| POST | `/erp/my-tasks/{task}/complete` | erp.tasks.complete | Authenticate, TeamAuthorization |
| POST | `/erp/my-tasks/{task}/items/{item}/complete` | erp.tasks.item.complete | Authenticate, TeamAuthorization |
| POST | `/erp/teacher/attendance` | erp.teacher.submit | Authenticate, TeamAuthorization |
| POST | `/erp/teacher/results` | erp.teacher.result | Authenticate, TeamAuthorization |
| GET | `/erp/units/{unit}/academics/export` | erp.academics.export | Authenticate, TeamAuthorization |
| POST | `/erp/units/{unit}/business-actions/{action}` | erp.business.mutate | Authenticate, TeamAuthorization |
| GET | `/erp/units/{unit}/exports/{dataset}` | erp.export | Authenticate, TeamAuthorization |
| GET | `/erp/units/{unit}/lookup` | erp.lookup | Authenticate, TeamAuthorization |
| GET | `/erp/units/{unit}/reconciliation` | erp.reconciliation | Authenticate, TeamAuthorization |
| POST | `/erp/units/{unit}/tuition/{operation}` | erp.tuition.mutate | Authenticate |
| POST | `/erp/units/{unit}/{action}` | erp.mutate | Authenticate, TeamAuthorization |

## Records (JSON, administrators)

| Method | URI | Name | Middleware |
|---|---|---|---|
| GET | `/app-api/records` | app.api.records.overview | Authenticate, RequireAdministrator |
| GET | `/app-api/records/settings` | app.api.records.settings | Authenticate, RequireAdministrator |
| GET | `/app-api/records/{set}` | app.api.records.index | Authenticate, RequireAdministrator |
| GET | `/app-api/records/{set}/export` | app.api.records.export | Authenticate, RequireAdministrator |

## Administration (JSON, administrators)

| Method | URI | Name | Middleware |
|---|---|---|---|
| GET | `/app-api/admin/accounts` | app.api.admin.accounts | Authenticate, RequireAdministrator |
| GET | `/app-api/admin/migration` | app.api.admin.migration | Authenticate, RequireAdministrator |
| GET | `/app-api/admin/operations` | app.api.admin.operations | Authenticate, RequireAdministrator |
| GET | `/app-api/admin/teams` | app.api.admin.teams | Authenticate, RequireAdministrator |

## Teacher and family portals

| Method | URI | Name | Middleware |
|---|---|---|---|
| GET | `/parent-api/documents/{type}/{id}` | parent.api.document |  |
| GET | `/parent-api/sections/{section}` | parent.api.section |  |
| GET | `/parent-api/statement` | parent.api.statement |  |
| POST | `/parent/disputes` | parent.dispute |  |
| GET | `/parent/documents/{type}/{id}` | parent.document |  |
| POST | `/parent/login` | parent.login.submit |  |
| GET | `/parent/login` | parent.login |  |
| POST | `/parent/logout` | parent.logout |  |
| GET | `/parent/statement` | parent.statement |  |
| GET | `/parent/{section?}` | parent.home |  |
| GET | `/teacher` | teacher.portal | Authenticate |
| GET | `/teacher-api/workspace` | teacher.api.workspace | Authenticate |
| GET | `/teacher/{section}` | teacher.portal.section | Authenticate |

## Public teacher forms

| Method | URI | Name | Middleware |
|---|---|---|---|
| GET | `/attendance` | attendance |  |
| POST | `/get-teacher-dob` | get-teacher-dob | ThrottleRequests:teacher-credentials |
| GET | `/performance` | performenceform |  |
| POST | `/performnce-upload` | performnce-upload | ThrottleRequests:teacher-credentials |
| GET | `/resultform` | resultform |  |
| POST | `/studentinfom` | studentinfom | ThrottleRequests:teacher-credentials |
| POST | `/studentinforesult` | studentinforesult | ThrottleRequests:teacher-credentials |
| POST | `/submit-application` | submit-application | ThrottleRequests:teacher-credentials |
| GET | `/timetable` | timetable |  |
| GET | `/timetableReport` | timetableReport |  |
| POST | `/upload-result` | upload-result | ThrottleRequests:teacher-credentials |
| POST | `/upload-workhour` | upload-workhour | ThrottleRequests:teacher-credentials |

## Sign-in and account

| Method | URI | Name | Middleware |
|---|---|---|---|
| GET | `/` | home | Authenticate |
| GET | `/account/security` | account.security | Authenticate |
| POST | `/account/two-factor/disable` | two-factor.disable | Authenticate, ThrottleRequests:6,1,two-factor-settings |
| POST | `/account/two-factor/enable` | two-factor.enable | Authenticate, ThrottleRequests:6,1,two-factor-settings |
| POST | `/account/two-factor/setup` | two-factor.setup | Authenticate, ThrottleRequests:6,1,two-factor-settings |
| GET | `/backend` |  | RedirectIfAuthenticated |
| POST | `/login` |  | RedirectIfAuthenticated |
| GET | `/login` | login | RedirectIfAuthenticated |
| POST | `/logout` | logout |  |
| POST | `/password/confirm` |  | Authenticate |
| GET | `/password/confirm` | password.confirm | Authenticate |
| POST | `/password/email` | password.email | RedirectIfAuthenticated |
| POST | `/password/reset` | password.update | RedirectIfAuthenticated |
| GET | `/password/reset` | password.request |  |
| GET | `/password/reset/{token}` | password.reset |  |
| GET | `/staff/login` | staff.login | RedirectIfAuthenticated |
| GET | `/superadmin/login` | superadmin.login | RedirectIfAuthenticated |
| POST | `/two-factor` | two-factor.verify | RedirectIfAuthenticated, ThrottleRequests:6,1,two-factor-challenge |
| GET | `/two-factor` | two-factor.challenge | RedirectIfAuthenticated |

## Sales app API (Worker gateway only)

| Method | URI | Name | Middleware |
|---|---|---|---|
| POST | `/api/sales/login` | api.sales.login | api, SalesGatewayOnly |
| GET | `/api/sales/me` | api.sales.me | api, SalesGatewayOnly, SalesApiAuth, ThrottleRequests:sales-api |
| POST | `/api/sales/units/{unit}/actions/{action}` | api.sales.act | api, SalesGatewayOnly, SalesApiAuth, ThrottleRequests:sales-api |
| GET | `/api/sales/units/{unit}/demos` | api.sales.demos | api, SalesGatewayOnly, SalesApiAuth, ThrottleRequests:sales-api |
| GET | `/api/sales/units/{unit}/leads` | api.sales.leads | api, SalesGatewayOnly, SalesApiAuth, ThrottleRequests:sales-api |
| GET | `/api/sales/units/{unit}/leads/{lead}` | api.sales.lead | api, SalesGatewayOnly, SalesApiAuth, ThrottleRequests:sales-api |
| GET | `/api/sales/units/{unit}/members` | api.sales.members | api, SalesGatewayOnly, SalesApiAuth, ThrottleRequests:sales-api |
| GET | `/api/sales/units/{unit}/overview` | api.sales.overview | api, SalesGatewayOnly, SalesApiAuth, ThrottleRequests:sales-api |
| GET | `/api/sales/units/{unit}/reminders` | api.sales.reminders | api, SalesGatewayOnly, SalesApiAuth, ThrottleRequests:sales-api |
| GET | `/api/sales/units/{unit}/teachers` | api.sales.teachers | api, SalesGatewayOnly, SalesApiAuth, ThrottleRequests:sales-api |
| GET | `/api/sales/units/{unit}/transfers` | api.sales.transfers | api, SalesGatewayOnly, SalesApiAuth, ThrottleRequests:sales-api |
| GET | `/api/user` |  | api, Authenticate:api |

## Health

| Method | URI | Name | Middleware |
|---|---|---|---|
| GET | `/health` |  |  |
| GET | `/ready` |  |  |
| GET | `/sanctum/csrf-cookie` | sanctum.csrf-cookie |  |
