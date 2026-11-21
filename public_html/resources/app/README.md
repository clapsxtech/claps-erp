# ClapsLearn ERP app (React + Astryx)

The app lives at `/app`. One Blade page (`resources/views/app/shell.blade.php`, served by
`App\Http\Controllers\App\AppShellController`) hosts it and embeds the session: the user, their
teams, and per team the `pages`, `actions` and `exports` the role allows. Those come from
`TeamPermissions::capabilities()`, which calls `allows()`, so the client never decides access. It
only hides what the server would refuse anyway.

Astryx conventions are in `public_html/.claude/CLAUDE.md`. Run `npx astryx component <Name>` to read
a component's props before you use it; most prop names differ from other libraries.

## Adding a screen

1. **JSON read**: add a controller in `app/Http/Controllers/App/` and its routes in
   `routes/app/<module>.php`. URLs are `/app-api/units/{unit}/...`. Every route **must** declare its
   permission with `->defaults('erp_permission', [kind, value])`. The kinds are `page`, `detail`,
   `lookup` and `export`, the same vocabulary as `TeamPermissions::allows()`. A route without one is
   refused.
   - List fields explicitly, never `select *` passed through. Copy any render-time hiding from the
     classic Blade view, for example the balance rule in `StudentsApiController`.
   - Sort keys come from a whitelist map. Validate every query parameter.
   - Money is integer paise in JSON, and keys are camelCase.
2. **Writes** reuse the existing classic action routes; don't add new mutation code:
   - `POST /erp/units/{unit}/{action}` for actions in `WorkspaceController::mutate`
   - `POST /erp/units/{unit}/business-actions/{action}` for actions in `BusinessController::mutate`,
     which also dispatches `admission-*`, `allocation-*`, `teacher-candidate-*` and `books-*`
   - `POST /erp/units/{unit}/tuition/{operation}`

   Send JSON with `Accept: application/json` (`postJson` does this). Validation errors come back as
   422 with `errors`. Use `FormDrawer` for create and edit forms.
3. **Page**: put it in `resources/app/pages/<Area>/`, default-export the component, and register it
   in `resources/app/modules/<area>.tsx`. Use `Page` for the frame, `DataTable` for lists, `Metric`
   for KPI tiles (clickable tiles open the filtered list), `Status` for status words, and `Balance`
   or `Toned` for money.
4. **Show and hide** buttons with `unit.actions.includes('<action>')`, plus `unit.isArchived` and
   `session().sheetsAuthority`, which both mean read-only.
5. **Tests**: add a feature test per JSON controller in `tests/Feature/App*Test.php`. Cover role
   access with an allowed role, a refused role and another unit, the explicit field list, and the
   filters and sort.

## UX rules

- **Few taps.** Every list has search and the common filters inline. The most common action is the
  primary button top-right, and the "New" menu and ⌘K reach it from anywhere. Row names link to the
  detail page.
- **Tables** keep numbers and money right-aligned with tabular figures, sort on the value rather
  than the label, put state in the URL, count rows in the footer, export CSV of the filtered set,
  and show cards on phones.
- **One status word, one colour** (`components/Status.tsx`).
- **Writes are explicit.** A drawer has one primary button, and errors appear inline next to the
  field. Destructive or money-reversing actions confirm first.

## Checks

```sh
npx tsc -p resources/app/tsconfig.json
php vendor/bin/phpunit tests/Feature/App*
npm run production
```
