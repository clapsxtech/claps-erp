<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Settings;
use App\Modules\IdentityAccess\Services\SiteAccessPolicy;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Read-only JSON and CSV for "Records & administration" (/app/records), replacing the classic
 * /admin screens without their "Sync from Sheets" and edit actions. Superadmin only: the route
 * group uses the classic 'admin' middleware and every action asserts it again.
 * Sets, columns, filters and sorts come from RecordsDatasets; nothing is passed through unlisted.
 */
class RecordsApiController extends Controller
{
    public function overview(Request $request)
    {
        (new SiteAccessPolicy())->assertAdministrator($request->user());
        $sets = [];
        foreach (RecordsDatasets::all() as $slug => $set) {
            $count = isset($set['query'])
                ? DB::query()->fromSub(($set['query'])($request, []), 'r')->count()
                : DB::table('settings')->count();
            $sets[] = ['slug' => $slug, 'group' => $set['group'], 'title' => $set['title'], 'description' => $set['description'], 'count' => $count, 'classicUrl' => $set['classic']];
        }
        $groups = collect(RecordsDatasets::GROUPS)->map(fn ($g, $key) => ['key' => $key] + $g)->values();
        return response()->json(['groups' => $groups, 'sets' => $sets])->header('Cache-Control', 'no-store, private');
    }

    public function settings(Request $request)
    {
        (new SiteAccessPolicy())->assertAdministrator($request->user());
        // The classic screen 404s on an empty table (firstOrFail); here that is an empty state.
        $s = Settings::query()->orderBy('id')->first(['id', 'name', 'address', 'phone', 'support_mail', 'admin_mail', 'career_mail', 'facebook', 'instagram', 'youtube', 'linkedin', 'twitter', 'map', 'updated_at']);
        $settings = $s ? [
            'name' => $s->name, 'address' => $s->address, 'phone' => $s->phone,
            'supportMail' => $s->support_mail, 'adminMail' => $s->admin_mail, 'careerMail' => $s->career_mail,
            'facebook' => $s->facebook, 'instagram' => $s->instagram, 'youtube' => $s->youtube, 'linkedin' => $s->linkedin, 'twitter' => $s->twitter,
            'map' => $s->map, 'updatedAt' => $s->updated_at ? (string) $s->updated_at : null,
        ] : null;
        return response()->json(['settings' => $settings])->header('Cache-Control', 'no-store, private');
    }

    public function index(Request $request, string $set)
    {
        [$def, $query, $v, $filters] = $this->prepare($request, $set);
        $totals = [];
        foreach ($def['totals'] ?? [] as $key => [$label, $expr, $type]) {
            $totals[] = ['key' => $key, 'label' => $label, 'type' => $type, 'value' => null, 'expr' => $expr];
        }
        if ($totals) {
            $row = (clone $query)->selectRaw(implode(', ', array_map(fn ($t) => $t['expr'].' as '.Str::snake($t['key']), $totals)))->first();
            $totals = array_map(function ($t) use ($row) {
                $value = $row->{Str::snake($t['key'])} ?? null;
                unset($t['expr']);
                $t['value'] = $value === null ? null : $this->figure($value);
                return $t;
            }, $totals);
        }
        [$sortKey, $dir] = $this->applySort($def, $query, $v);
        $perPage = (int) ($v['per_page'] ?? 25);
        $page = $query->paginate($perPage, ['r.*'], 'page', (int) ($v['page'] ?? 1));
        $rows = collect($page->items());
        if (isset($def['decorate'])) $rows = ($def['decorate'])($rows);
        $offset = ($page->currentPage() - 1) * $perPage;
        $data = $rows->values()->map(fn ($row, $i) => ['rowKey' => (string) ($offset + $i + 1)] + $this->row($def, $row));

        return response()->json([
            'set' => [
                'slug' => $set, 'title' => $def['title'], 'description' => $def['description'], 'group' => $def['group'],
                'columns' => collect($def['columns'])->map(fn ($c, $key) => ['key' => $key, 'label' => $c[0], 'type' => $c[1], 'sortable' => (bool) $c[2]])->values(),
                'filters' => collect($def['filters'] ?? [])->map(fn ($f, $key) => [
                    'key' => $key, 'label' => $f['label'], 'type' => $f['type'],
                    'options' => $this->options($f),
                ])->values(),
                'card' => $def['card'],
            ],
            'filters' => (object) $filters,
            'totals' => $totals,
            'data' => $data,
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'sort' => $sortKey, 'dir' => $dir],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function export(Request $request, string $set)
    {
        [$def, $query, $v] = $this->prepare($request, $set);
        $this->applySort($def, $query, $v);
        $columns = $def['columns'];
        $file = 'records-'.$set.'-'.now()->format('Y-m-d').'.csv';
        return response()->streamDownload(function () use ($def, $query, $columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_map(fn ($c) => $c[0], array_values($columns)));
            $query->select('r.*')->chunk(500, function (Collection $rows) use ($def, $out) {
                if (isset($def['decorate'])) $rows = ($def['decorate'])($rows);
                foreach ($rows as $row) {
                    fputcsv($out, array_map(fn ($value) => $this->cell($value), array_values($this->row($def, $row))));
                }
            });
            fclose($out);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    /** Validates the query, then builds the filtered (unsorted) outer query over the set. */
    private function prepare(Request $request, string $set): array
    {
        (new SiteAccessPolicy())->assertAdministrator($request->user());
        $def = RecordsDatasets::get($set);
        abort_if(!$def || !isset($def['columns']), 404);
        $sortable = array_keys(array_filter($def['columns'], fn ($c) => $c[2]));
        $rules = [
            'q' => 'nullable|string|max:100',
            'sort' => 'nullable|in:'.implode(',', $sortable),
            'dir' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|in:25,50,100',
        ];
        foreach ($def['filters'] ?? [] as $key => $f) {
            $rules[$key] = match ($f['type']) {
                'from', 'to' => 'nullable|date_format:Y-m-d',
                'enum' => 'nullable|in:'.implode(',', array_map('strval', array_keys($f['options']))),
                default => 'nullable|string|max:255',
            };
        }
        $v = $request->validate($rules);
        $filters = [];
        foreach (array_keys($def['filters'] ?? []) as $key) {
            if (isset($v[$key]) && $v[$key] !== '') $filters[$key] = (string) $v[$key];
        }
        if (isset($filters['from'], $filters['to']) && $filters['from'] > $filters['to']) {
            abort(response()->json(['message' => 'The start date must be on or before the end date.', 'errors' => ['to' => ['The end date must be on or after the start date.']]], 422));
        }

        $query = DB::query()->fromSub(($def['query'])($request, $filters), 'r');
        foreach ($def['filters'] ?? [] as $key => $f) {
            if (!isset($filters[$key]) || !empty($f['scoped'])) continue;
            $column = 'r.'.$f['column'];
            match ($f['type']) {
                'from' => $query->whereDate($column, '>=', $filters[$key]),
                'to' => $query->whereDate($column, '<=', $filters[$key]),
                // SQLite never matches a computed integer against the text '0'.
                'enum' => $query->where($column, ctype_digit($filters[$key]) ? (int) $filters[$key] : $filters[$key]),
                default => $query->where($column, $filters[$key]),
            };
        }
        $search = trim((string) ($v['q'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $w) use ($def, $search) {
                foreach ($def['search'] as $column) $w->orWhere('r.'.$column, 'like', '%'.$search.'%');
            });
        }
        return [$def, $query, $v, $filters];
    }

    private function applySort(array $def, Builder $query, array $v): array
    {
        [$sortKey, $dir] = [$v['sort'] ?? $def['sort'][0], $v['dir'] ?? (isset($v['sort']) ? 'asc' : $def['sort'][1])];
        $type = $def['columns'][$sortKey][1];
        $expr = $def['sortExpr'][$sortKey] ?? 'r.'.Str::snake($sortKey);
        if (in_array($type, ['number', 'money'], true)) $expr = RecordsDatasets::num($expr);
        $query->orderByRaw($expr.' '.($dir === 'desc' ? 'DESC' : 'ASC'));
        if ($sortKey === 'day') $query->orderBy('r.time');
        $query->orderBy('r.id', $dir === 'desc' ? 'desc' : 'asc');
        foreach ($def['tiebreak'] ?? [] as $column) $query->orderBy($column);
        return [$sortKey, $dir];
    }

    private function options(array $filter): array
    {
        $options = $filter['options'] ?? null;
        if (!$options) return [];
        if ($options instanceof \Closure) return array_map(fn ($v) => ['value' => $v, 'label' => $v], $options());
        return collect($options)->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values()->all();
    }

    /** Only the listed columns, typed. Figures stay the stored text; links are http(s) only. */
    private function row(array $def, object $row): array
    {
        $out = [];
        foreach ($def['columns'] as $key => [, $type]) {
            $value = $row->{Str::snake($key)} ?? null;
            $out[$key] = match ($type) {
                'bool' => $value === null ? null : (bool) (int) $value,
                'list' => array_values((array) $value),
                'link' => RecordsDatasets::safeLink($value),
                'number', 'money' => $value === null || $value === '' ? null : $this->figure($value),
                default => $value === null ? null : (string) $value,
            };
        }
        return $out;
    }

    private function figure($value): string
    {
        if (is_float($value) || (is_string($value) && is_numeric($value) && str_contains($value, '.') && strlen($value) > 12)) {
            return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
        }
        return (string) $value;
    }

    /** Same spreadsheet-formula guard as WorkHourController::exportWorkHours. */
    private function cell($value): string
    {
        if (is_array($value)) $value = implode('; ', $value);
        if (is_bool($value)) $value = $value ? 'Yes' : 'No';
        $value = (string) $value;
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
