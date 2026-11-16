<?php
namespace App\Modules\Erp\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Monthly invoices from posted class charges. GST teams issue tax invoices (SAC 999293, rates include 18% GST);
 * other teams, and charges booked before GST registration, go on plain invoices. Numbers run per financial year (April–March). Cancelling issues a credit note.
 */
class Invoicing
{
    public static function financialYear(string $date): string
    {
        $d = CarbonImmutable::createFromFormat('Y-m-d', $date);
        $start = $d->month >= 4 ? $d->year : $d->year - 1;
        return $start.'-'.substr((string) ($start + 1), -2);
    }

    private function nextNumber(int $unit, object $team, string $type, string $issueDate): string
    {
        $prefix = strtoupper($team->invoice_prefix ?: $team->code).($type === 'credit_note' ? '-CN' : '');
        $series = $prefix.'/'.self::financialYear($issueDate).'/';
        $last = DB::table('erp_invoices')->where('unit_id', $unit)->where('invoice_number', 'like', $series.'%')->lockForUpdate()->pluck('invoice_number')
            ->map(fn ($n) => (int) substr($n, strlen($series)))->max() ?? 0;
        return $series.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Invoice every student's not-yet-invoiced class charges posted in the month. Running it again only picks up new charges. */
    public function runMonth(int $unit, int $actor, array $p): object
    {
        $v = validator($p, ['month' => 'required|date_format:Y-m'])->validate();
        $team = DB::table('erp_units')->find($unit);
        $today = CarbonImmutable::now($team->timezone)->toDateString();
        if ($v['month'].'-01' > $today) throw ValidationException::withMessages(['month' => 'Choose this month or an earlier one.']);
        $books = new Books();
        $books->sync($unit);
        $zone = config('app.timezone');
        $start = CarbonImmutable::createFromFormat('!Y-m', $v['month'], $zone); $end = $start->addMonth();
        $issueDate = $today; // Issued when run; the billing period records the month it covers (no back-dating).
        $created = 0; $lines = 0;
        $charges = DB::table('erp_ledger as l')->where('l.unit_id', $unit)->where('l.kind', 'lesson_charge')->whereNull('l.invoice_id')->whereNotNull('l.student_id')
            ->where('l.occurred_at', '>=', $start->format('Y-m-d H:i:s'))->where('l.occurred_at', '<', $end->format('Y-m-d H:i:s'))
            ->whereNotExists(function ($q) { $q->selectRaw('1')->from('erp_ledger as r')->whereColumn('r.reverses_id', 'l.id'); })
            ->orderBy('l.student_id')->orderBy('l.occurred_at')->get();
        // Each charge is invoiced with the tax it was booked with, so invoices and the books always agree.
        $splits = $books->postedSplits($unit, $charges->pluck('id')->all());
        $splitFor = fn ($row) => $splits[(int) $row->id] ?? Books::taxSplit($team, abs((int) $row->amount_paise), DB::table('erp_student_profiles')->where('student_id', $row->student_id)->value('billing_state'));
        $taxed = fn ($row) => ($s = $splitFor($row))['cgst'] + $s['sgst'] + $s['igst'] !== 0;
        if (($team->tax_mode === 'gst' || $charges->contains($taxed)) && (!$team->tax_id || !$team->state || !$team->legal_name)) throw ValidationException::withMessages(['month' => 'Add the GSTIN, legal name and state in Teams & accounts before issuing tax invoices.']);
        // Charges booked with GST go on a tax invoice; charges booked without it (e.g. before registration) go on a plain invoice.
        $groups = $charges->groupBy(fn ($row) => $row->student_id.'|'.($taxed($row) ? 'tax_invoice' : 'invoice'));
        foreach ($groups as $groupKey => $rows) {
            [$studentId, $type] = explode('|', $groupKey);
            DB::transaction(function () use ($unit, $actor, $team, $studentId, $type, $rows, $issueDate, $v, $splitFor, &$created, &$lines) {
                $ids = $rows->pluck('id')->all();
                if (DB::table('erp_ledger')->whereIn('id', $ids)->whereNotNull('invoice_id')->lockForUpdate()->exists()) return;
                $student = DB::table('erp_students')->find($studentId);
                $state = DB::table('erp_student_profiles')->where('student_id', $studentId)->value('billing_state');
                $totals = ['taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0, 'gross' => 0]; $prepared = []; $interstate = false;
                foreach ($rows as $row) {
                    $gross = abs((int) $row->amount_paise); $split = $splitFor($row);
                    foreach (['taxable', 'cgst', 'sgst', 'igst'] as $k) $totals[$k] += $split[$k];
                    $totals['gross'] += $gross; $interstate = $interstate || $split['interstate'];
                    $prepared[] = ['ledger_id' => $row->id, 'description' => $this->describe($row, $student), 'quantity' => 1, 'unit_rate_paise' => $split['taxable'], 'amount_paise' => $gross, 'taxable_paise' => $split['taxable'], 'tax_paise' => $gross - $split['taxable'], 'sac' => $type === 'tax_invoice' ? Books::SAC_TUITION : null];
                }
                $placeOfSupply = $type === 'tax_invoice' && !$interstate ? $team->state : ($state ?: $team->state);
                $id = DB::table('erp_invoices')->insertGetId(['unit_id' => $unit, 'student_id' => $studentId, 'invoice_number' => $this->nextNumber($unit, $team, $type, $issueDate), 'status' => 'issued', 'issue_date' => $issueDate, 'due_date' => null, 'currency' => 'INR',
                    'subtotal_paise' => $totals['taxable'], 'discount_paise' => 0, 'tax_paise' => $totals['cgst'] + $totals['sgst'] + $totals['igst'], 'total_paise' => $totals['gross'],
                    'billing_name' => $student->name, 'billing_contact' => null, 'notes' => null, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
                    'document_type' => $type, 'billing_period' => $v['month'], 'place_of_supply' => $placeOfSupply, 'cgst_paise' => $totals['cgst'], 'sgst_paise' => $totals['sgst'], 'igst_paise' => $totals['igst'], 'supplier_tax_id' => $type === 'tax_invoice' ? $team->tax_id : null]);
                foreach ($prepared as $line) DB::table('erp_invoice_lines')->insert($line + ['unit_id' => $unit, 'invoice_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('erp_ledger')->whereIn('id', $ids)->update(['invoice_id' => $id]);
                Audit::record($unit, $actor, 'invoice.issued', 'invoice', $id, ['charges' => count($ids), 'total_paise' => $totals['gross']]);
                $created++; $lines += count($ids);
            });
        }
        return (object) ['invoices' => $created, 'charges' => $lines, 'issue_date' => $issueDate];
    }

    /** Cancel an invoice by issuing a credit note. Its charges can be invoiced again (for example after a correction). */
    public function cancel(int $unit, int $actor, array $p): object
    {
        $v = validator($p, ['invoice_id' => 'required|integer|min:1', 'reason' => 'required|string|max:500'])->validate();
        $team = DB::table('erp_units')->find($unit);
        return DB::transaction(function () use ($unit, $actor, $team, $v) {
            $invoice = DB::table('erp_invoices')->where('unit_id', $unit)->where('id', $v['invoice_id'])->lockForUpdate()->first();
            if (!$invoice || $invoice->document_type === 'credit_note' || $invoice->status !== 'issued') throw ValidationException::withMessages(['invoice_id' => 'Only an issued invoice can be cancelled.']);
            $today = CarbonImmutable::now($team->timezone)->toDateString();
            $note = (array) $invoice; unset($note['id']);
            $creditId = DB::table('erp_invoices')->insertGetId(array_merge($note, ['invoice_number' => $this->nextNumber($unit, $team, 'credit_note', $today), 'issue_date' => $today, 'document_type' => 'credit_note', 'credits_invoice_id' => $invoice->id, 'status' => 'issued', 'notes' => $v['reason'], 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]));
            foreach (DB::table('erp_invoice_lines')->where('invoice_id', $invoice->id)->get() as $line) { $copy = (array) $line; unset($copy['id']); DB::table('erp_invoice_lines')->insert(array_merge($copy, ['invoice_id' => $creditId, 'created_at' => now(), 'updated_at' => now()])); }
            DB::table('erp_invoices')->where('id', $invoice->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            DB::table('erp_ledger')->where('invoice_id', $invoice->id)->update(['invoice_id' => null]);
            Audit::record($unit, $actor, 'invoice.cancelled', 'invoice', $invoice->id, ['credit_note_id' => $creditId, 'reason' => $v['reason']]);
            return DB::table('erp_invoices')->find($creditId);
        });
    }

    /**
     * Called when a class charge is reversed. If it was already invoiced, that invoice is cancelled with a credit note
     * so the family is not left holding a bill for a reversed class; its other charges are invoiced again on the next run.
     */
    public function creditReversedCharge(int $unit, int $actor, object $charge, string $reason): ?object
    {
        if ($charge->kind !== 'lesson_charge' || !$charge->invoice_id) return null;
        $invoice = DB::table('erp_invoices')->where('unit_id', $unit)->where('id', $charge->invoice_id)->first();
        if (!$invoice || $invoice->status !== 'issued' || $invoice->document_type === 'credit_note') return null;
        // Families can open credit notes, so the staff reason stays in the audit log (ledger.reversed), not on the document.
        return $this->cancel($unit, $actor, ['invoice_id' => $invoice->id, 'reason' => 'A class on this invoice was corrected or removed. The other classes will be re-invoiced.']);
    }

    private function describe(object $row, object $student): string
    {
        $class = DB::table('erp_attendance as a')->join('erp_lessons as l', 'l.id', '=', 'a.lesson_id')->join('erp_subjects as s', 's.id', '=', 'l.subject_id')
            ->where('a.id', $row->attendance_id)->first(['l.starts_at', 'a.actual_starts_at', 'a.actual_minutes', 's.name as subject']);
        if (!$class) return 'Tuition';
        $at = CarbonImmutable::parse($class->actual_starts_at ?? $class->starts_at, 'UTC')->setTimezone($student->timezone);
        return $class->subject.' class, '.$at->format('j M Y').' ('.$class->actual_minutes.' min)';
    }

    /** Indian-system amount in words, e.g. "Rupees Four Thousand Five Hundred and Paise Fifty Only". */
    public static function words(int $paise): string
    {
        $paise = abs($paise); $rest = $paise % 100;
        return 'Rupees '.(self::integerWords(intdiv($paise, 100)) ?: 'Zero').($rest ? ' and Paise '.self::integerWords($rest) : '').' Only';
    }

    private static function integerWords(int $n): string
    {
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $two = fn ($x) => $x < 20 ? $ones[$x] : trim($tens[intdiv($x, 10)].' '.$ones[$x % 10]);
        $parts = [];
        if ($n >= 10000000) { $parts[] = self::integerWords(intdiv($n, 10000000)).' Crore'; $n %= 10000000; }
        foreach ([[100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']] as [$size, $name]) if ($n >= $size) { $parts[] = $two(intdiv($n, $size)).' '.$name; $n %= $size; }
        if ($n) $parts[] = $two($n);
        return implode(' ', $parts);
    }
}
