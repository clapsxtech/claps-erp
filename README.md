# logs.clapslearn.com

The ClapsLearn ERP. It has a Laravel backend and a single React + [Astryx](https://astryx.atmeta.com/) front end.

## Using it

- **Staff:** sign in at `/login`; you land in the app at `/app`.
- **Teachers:** linked teachers land in their portal at `/teacher`.
- **Families:** use `/parent/login`.
- **Teachers without a login:** the public forms are `/attendance`, `/resultform`, `/performance` and `/timetable`.

[The guide](public_html/docs/APP_GUIDE.md) covers navigation, every screen and the portals. The
[route reference](public_html/docs/ROUTES.md) lists every registered route.
The [customer and staff journey simulation](public_html/docs/JOURNEY_SIMULATION.md) lists gaps found by running
realistic families and staff through the system.

## Developing

- [The app README](public_html/resources/app/README.md): how screens, JSON routes, permissions and tests fit together.
- [Front end](public_html/docs/FRONTEND.md): build, theme, asset delivery and the host page.
- `public_html/.claude/CLAUDE.md`: Astryx conventions for AI coding agents.

## Architecture and cutover review

See the [Sheets-to-application ER diagrams and production-readiness review](public_html/docs/architecture-review/README.md) for verified workbook dependencies, schema comparisons, migration join contracts, current release gaps, and validation results (26 October 2026).

The [temporary Sheets bridge](public_html/docs/sheets-transition.md) adds validated, read-only snapshots and a From Sheets screen, with a separate source-authority switch. It is disabled by default; follow its activation and reconciliation steps before use.

## Setup

All project files are in `public_html/`.

1. Go to the app folder:
   ```bash
   cd public_html
   ```
2. Install dependencies (PHP 8.2+ with BCMath, Node 22):
   ```bash
   composer install
   npm ci
   ```
3. Create `.env` with your database and app settings (see `config/*.php` for the variables), then:
   ```bash
   php artisan key:generate
   ```
4. Run migrations and build the app:
   ```bash
   php artisan migrate
   npm run production
   ```
5. Run the checks:
   ```bash
   php vendor/bin/phpunit
   npm test
   ```
