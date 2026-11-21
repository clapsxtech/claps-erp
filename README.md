# logs.clapslearn.com

Laravel web application repository for logs.clapslearn.com.

## ERP user guide

See [every feature and page, with the latest verification results](public_html/docs/FEATURES_AND_PAGES.md).
The [customer and staff journey simulation](public_html/docs/JOURNEY_SIMULATION.md) lists gaps found by running realistic families and staff through the system.

Start with the [ERP user guide and journeys for each role](public_html/docs/ERP_USER_GUIDE.md) for login, daily workflows, permissions, handoffs and Sheets transition behavior. The [complete route reference](public_html/docs/ERP_ROUTE_REFERENCE.md) lists browser pages, form actions, exports and all registered Laravel routes.

## New app (React + Astryx)

Staff screens are being rebuilt at `/app` with the [Astryx](https://astryx.atmeta.com/) design system. The classic screens stay live until cutover. See [the app guide](public_html/resources/app/README.md) for how screens, permissions and tests fit together.

## Architecture and cutover review

See the [Sheets-to-application ER diagrams and production-readiness review](public_html/docs/architecture-review/README.md) for verified workbook dependencies, schema comparisons, migration join contracts, current release gaps, and validation results (26 October 2026).

The [temporary Sheets bridge](public_html/docs/sheets-transition.md) adds validated, read-only snapshots and a From Sheets workspace, with a separate source-authority switch. It is disabled by default; follow its activation and reconciliation steps before use.

## Project Structure

The project files reside inside the `public_html` directory:
- `public_html/` — Core Laravel application codebase

## Setup

1. Navigate to the `public_html` directory:
   ```bash
   cd public_html
   ```
2. Install dependencies:
   ```bash
   composer install
   npm install
   ```
3. Configure environment:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
4. Run migrations:
   ```bash
   php artisan migrate
   ```
