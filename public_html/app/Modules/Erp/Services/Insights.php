<?php
namespace App\Modules\Erp\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Month-by-month management figures for one team. Reversals count against the month of the entry they undo. */
class Insights
{
    public function monthly(int $unit, int $months = 6): array
    {
        $team = DB::table('erp_units')->find($unit);
        $zone = $team->timezone; $ledgerZone = config('app.timezone');
        $first = CarbonImmutable::now($zone)->startOfMonth()->subMonths($months - 1);
        $rows = [];
        for ($i = 0; $i < $months; $i++) {
            $start = $first->addMonths($i); $end = $start->addMonth();
            $d0 = $start->toDateString(); $d1 = $end->toDateString();
            $u0 = $start->utc()->format('Y-m-d H:i:s'); $u1 = $end->utc()->format('Y-m-d H:i:s');
            $l0 = $start->setTimezone($ledgerZone)->format('Y-m-d H:i:s'); $l1 = $end->setTimezone($ledgerZone)->format('Y-m-d H:i:s');
            $enquiries = DB::table('erp_leads')->where('unit_id', $unit)->where('enquiry_date', '>=', $d0)->where('enquiry_date', '<', $d1)->count();
            $joined = DB::table('erp_leads')->where('unit_id', $unit)->where('status', 'JOINED')->where('joined_on', '>=', $d0)->where('joined_on', '<', $d1)->count();
            $lessons = DB::table('erp_lessons')->where('unit_id', $unit)->where('starts_at', '>=', $u0)->where('starts_at', '<', $u1);
            $attended = DB::table('erp_attendance as a')->join('erp_lessons as l', 'l.id', '=', 'a.lesson_id')->where('a.unit_id', $unit)->where('a.status', '!=', 'rejected')->where('l.starts_at', '>=', $u0)->where('l.starts_at', '<', $u1);
            $classes = (clone $attended)->count(); $noShows = (clone $lessons)->where('status', 'no_show')->count();
            $money = DB::table('erp_ledger as e')->leftJoin('erp_ledger as o', 'o.id', '=', 'e.reverses_id')->where('e.unit_id', $unit)->where('e.occurred_at', '>=', $l0)->where('e.occurred_at', '<', $l1)
                ->selectRaw("COALESCE(o.kind, e.kind) AS kind, SUM(e.amount_paise) AS total")->groupByRaw('COALESCE(o.kind, e.kind)')->pluck('total', 'kind')->map(fn ($v) => (int) $v);
            $billed = -($money['lesson_charge'] ?? 0); $teacherCost = $money['teacher_accrual'] ?? 0;
            $dues = (int) DB::query()->fromSub(DB::table('erp_ledger')->where('unit_id', $unit)->whereNotNull('student_id')->where('occurred_at', '<', $l1)->groupBy('student_id')->selectRaw('SUM(amount_paise) AS balance'), 'b')->where('balance', '<', 0)->sum('balance');
            $rows[] = (object) [
                'month' => $start->format('Y-m'), 'label' => $start->format('M Y'), 'short' => $start->format('M'),
                'enquiries' => $enquiries,
                'demos' => DB::table('erp_demos')->where('unit_id', $unit)->whereNotIn('status', ['CANCELLED'])->where('scheduled_on', '>=', $d0)->where('scheduled_on', '<', $d1)->count(),
                'joined' => $joined, 'conversion' => $enquiries ? round(100 * $joined / $enquiries, 1) : null,
                'new_students' => DB::table('erp_students')->where('unit_id', $unit)->where('registered_on', '>=', $d0)->where('registered_on', '<', $d1)->count(),
                'active_students' => (clone $attended)->distinct()->count('l.student_id'),
                'hours' => round((int) (clone $attended)->sum('a.actual_minutes') / 60, 1),
                'cancelled' => (clone $lessons)->where('status', 'cancelled')->count(), 'no_shows' => $noShows,
                'no_show_rate' => ($classes + $noShows) ? round(100 * $noShows / ($classes + $noShows), 1) : null,
                'billed' => $billed, 'collected' => $money['payment'] ?? 0, 'refunds' => -($money['refund'] ?? 0),
                'teacher_cost' => $teacherCost, 'margin' => $billed - $teacherCost, 'dues' => -$dues,
            ];
        }
        return $rows;
    }
}
