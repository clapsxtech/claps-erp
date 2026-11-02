<?php
namespace App\Modules\Erp\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Double-entry books for a team, derived from the operational ledger (erp_ledger).
 * Every ledger row posts exactly one balanced journal; staff can add manual journals.
 * Student balances in the ledger and the "Student accounts" control account always agree.
 */
class Books
{
    public const GST_RATE_PERCENT = 18;
    public const SAC_TUITION = '999293';

    private const CHART = [
        'bank' => ['1000', 'Bank and cash', 'asset'],
        'students' => ['1100', 'Student accounts (owed to us / paid in advance)', 'asset'],
        'suspense' => ['1900', 'Suspense (to review)', 'asset'],
        'teacher_payable' => ['2100', 'Teacher fees payable', 'liability'],
        'cgst' => ['2200', 'Output CGST', 'liability'],
        'sgst' => ['2201', 'Output SGST', 'liability'],
        'igst' => ['2202', 'Output IGST', 'liability'],
        'opening' => ['3000', 'Opening balances', 'equity'],
        'tuition' => ['4000', 'Tuition income', 'income'],
        'teacher_cost' => ['5000', 'Teacher fees', 'expense'],
    ];
    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    public function ensureChart(int $unit): void
    {
        foreach (self::CHART as $key => [$code, $name, $type]) {
            if (DB::table('erp_accounts')->where('unit_id', $unit)->where('system_key', $key)->exists()) continue;
            $taken = DB::table('erp_accounts')->where('unit_id', $unit)->where('code', $code)->exists();
            DB::table('erp_accounts')->insert(['unit_id' => $unit, 'code' => $taken ? $code.'-SYS' : $code, 'name' => $name, 'type' => $type, 'system_key' => $key, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function account(int $unit, string $key): int
    {
        return (int) DB::table('erp_accounts')->where('unit_id', $unit)->where('system_key', $key)->value('id');
    }

    /** Tuition charge split for a GST team. Rates include GST; IGST applies when the student's billing state differs from the team's. */
    public static function taxSplit(object $unit, int $grossPaise, ?string $studentState): array
    {
        if (($unit->tax_mode ?? 'none') !== 'gst' || $grossPaise === 0) return ['taxable' => $grossPaise, 'cgst' => 0, 'sgst' => 0, 'igst' => 0, 'interstate' => false];
        $sign = $grossPaise < 0 ? -1 : 1; $gross = abs($grossPaise);
        $taxable = intdiv($gross * 100 + intdiv(100 + self::GST_RATE_PERCENT, 2), 100 + self::GST_RATE_PERCENT);
        $tax = $gross - $taxable;
        $norm = fn ($s) => mb_strtolower(trim((string) $s));
        $interstate = $norm($studentState) !== '' && $norm($unit->state) !== '' && $norm($studentState) !== $norm($unit->state);
        $cgst = $interstate ? 0 : intdiv($tax, 2);
        return ['taxable' => $sign * $taxable, 'cgst' => $sign * $cgst, 'sgst' => $sign * ($interstate ? 0 : $tax - $cgst), 'igst' => $sign * ($interstate ? $tax : 0), 'interstate' => $interstate];
    }

    /** Post journals for any ledger rows not yet in the books. Safe to call repeatedly. */
    public function sync(int $unit): int
    {
        $this->ensureChart($unit);
        $team = DB::table('erp_units')->find($unit);
        $posted = 0;
        $pending = DB::table('erp_ledger as l')->where('l.unit_id', $unit)
            ->whereNotExists(function ($q) { $q->selectRaw('1')->from('erp_journal_entries as j')->where('j.source_type', 'ledger')->whereColumn('j.source_id', 'l.id'); })
            ->orderBy('l.id')->limit(5000)->get();
        foreach ($pending as $row) {
            DB::transaction(function () use ($unit, $team, $row, &$posted) {
                if (DB::table('erp_journal_entries')->where('source_type', 'ledger')->where('source_id', $row->id)->lockForUpdate()->exists()) return;
                $lines = $this->linesFor($unit, $team, $row);
                $this->insertEntry($unit, substr($row->occurred_at, 0, 10), $row->reference, $this->memo($row), 'ledger', (int) $row->id, $lines, $row->created_by, $row->reverses_id ? $this->entryForLedger((int) $row->reverses_id) : null);
                $posted++;
            });
        }
        return $posted;
    }

    private function linesFor(int $unit, object $team, object $row): array
    {
        $a = (int) $row->amount_paise; $abs = abs($a);
        $student = $row->student_id ? (int) $row->student_id : null; $teacher = $row->teacher_id ? (int) $row->teacher_id : null;
        $line = fn ($key, $debit, $credit) => ['account_id' => $this->account($unit, $key), 'debit_paise' => $debit, 'credit_paise' => $credit, 'student_id' => $student, 'teacher_id' => $teacher];
        if ($row->reverses_id) {
            $original = $this->entryForLedger((int) $row->reverses_id);
            if (!$original) { $this->sync($unit); $original = $this->entryForLedger((int) $row->reverses_id); }
            if ($original) return DB::table('erp_journal_lines')->where('entry_id', $original)->get()->map(fn ($l) => ['account_id' => $l->account_id, 'debit_paise' => $l->credit_paise, 'credit_paise' => $l->debit_paise, 'student_id' => $l->student_id, 'teacher_id' => $l->teacher_id])->all();
        }
        $studentSide = $student !== null;
        switch ($row->kind) {
            case 'payment':
                return $a >= 0 ? [$line('bank', $abs, 0), $line('students', 0, $abs)] : [$line('students', $abs, 0), $line('bank', 0, $abs)];
            case 'refund':
                return [$line('students', $abs, 0), $line('bank', 0, $abs)];
            case 'lesson_charge':
                $split = self::taxSplit($team, $abs, $student ? DB::table('erp_student_profiles')->where('student_id', $student)->value('billing_state') : null);
                $lines = [$line('students', $abs, 0), $line('tuition', 0, $split['taxable'])];
                foreach (['cgst', 'sgst', 'igst'] as $tax) if ($split[$tax]) $lines[] = $line($tax, 0, $split[$tax]);
                return $a < 0 ? $lines : array_map(fn ($l) => ['debit_paise' => $l['credit_paise'], 'credit_paise' => $l['debit_paise']] + $l, $lines);
            case 'teacher_accrual':
                return $a >= 0 ? [$line('teacher_cost', $abs, 0), $line('teacher_payable', 0, $abs)] : [$line('teacher_payable', $abs, 0), $line('teacher_cost', 0, $abs)];
            case 'teacher_payment':
                return $a <= 0 ? [$line('teacher_payable', $abs, 0), $line('bank', 0, $abs)] : [$line('bank', $abs, 0), $line('teacher_payable', 0, $abs)];
            case 'opening_balance':
                if ($studentSide) return $a < 0 ? [$line('students', $abs, 0), $line('opening', 0, $abs)] : [$line('opening', $abs, 0), $line('students', 0, $abs)];
                return $a >= 0 ? [$line('opening', $abs, 0), $line('teacher_payable', 0, $abs)] : [$line('teacher_payable', $abs, 0), $line('opening', 0, $abs)];
        }
        // Unknown kinds land in suspense so the books still balance and someone reviews them.
        $control = $studentSide ? 'students' : 'teacher_payable';
        return $a >= 0 ? [$line('suspense', $abs, 0), $line($control, 0, $abs)] : [$line($control, $abs, 0), $line('suspense', 0, $abs)];
    }

    private function memo(object $row): string
    {
        return ['payment' => 'Payment received', 'refund' => 'Refund paid', 'lesson_charge' => 'Class charge', 'teacher_accrual' => 'Teacher fees earned', 'teacher_payment' => 'Teacher fees paid', 'opening_balance' => 'Opening balance', 'reversal' => 'Reversal'][$row->kind] ?? ucfirst(str_replace('_', ' ', $row->kind));
    }

    private function entryForLedger(int $ledgerId): ?int
    {
        $id = DB::table('erp_journal_entries')->where('source_type', 'ledger')->where('source_id', $ledgerId)->value('id');
        return $id ? (int) $id : null;
    }

    private function insertEntry(int $unit, string $date, string $reference, ?string $memo, string $sourceType, ?int $sourceId, array $lines, $actor, ?int $reverses = null): int
    {
        $lines = array_values(array_filter($lines, fn ($l) => $l['debit_paise'] || $l['credit_paise']));
        $debits = array_sum(array_column($lines, 'debit_paise')); $credits = array_sum(array_column($lines, 'credit_paise'));
        if ($debits !== $credits || !$lines) throw new \RuntimeException("Unbalanced journal for {$sourceType} {$sourceId}: {$debits} / {$credits}");
        $id = DB::table('erp_journal_entries')->insertGetId(['unit_id' => $unit, 'entry_date' => $date, 'reference' => mb_substr($reference, 0, 120), 'memo' => $memo, 'source_type' => $sourceType, 'source_id' => $sourceId, 'reverses_id' => $reverses, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($lines as $l) DB::table('erp_journal_lines')->insert($l + ['entry_id' => $id, 'unit_id' => $unit, 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    // ------------------------------------------------------------ staff actions

    public function createAccount(int $unit, int $actor, array $p): object
    {
        $this->ensureChart($unit);
        $v = validator($p, ['code' => 'required|string|max:20|regex:/^[0-9A-Za-z-]+$/', 'name' => 'required|string|max:120', 'type' => 'required|in:'.implode(',', self::TYPES)])->validate();
        if (DB::table('erp_accounts')->where('unit_id', $unit)->where('code', $v['code'])->exists()) throw ValidationException::withMessages(['code' => 'That account code is already used.']);
        $id = DB::table('erp_accounts')->insertGetId($v + ['unit_id' => $unit, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        Audit::record($unit, $actor, 'books.account_created', 'account', $id, $v);
        return DB::table('erp_accounts')->find($id);
    }

    /** A balanced manual journal, e.g. rent, bank charges, a GST payment or an owner's capital. */
    public function manualJournal(int $unit, int $actor, array $p): object
    {
        $this->ensureChart($unit);
        $v = validator($p, ['entry_date' => 'required|date_format:Y-m-d', 'memo' => 'required|string|max:500', 'lines' => 'required|array|min:2|max:10', 'lines.*.account_id' => 'nullable|integer', 'lines.*.debit_paise' => 'nullable|integer|min:0', 'lines.*.credit_paise' => 'nullable|integer|min:0'])->validate();
        if ($v['entry_date'] > CarbonImmutable::now(DB::table('erp_units')->where('id', $unit)->value('timezone'))->toDateString()) throw ValidationException::withMessages(['entry_date' => 'Journals cannot be dated in the future.']);
        $lines = [];
        foreach ($v['lines'] as $l) {
            $debit = (int) ($l['debit_paise'] ?? 0); $credit = (int) ($l['credit_paise'] ?? 0);
            if (!$debit && !$credit && empty($l['account_id'])) continue;
            if (empty($l['account_id']) || !DB::table('erp_accounts')->where('unit_id', $unit)->where('id', $l['account_id'])->where('active', true)->exists()) throw ValidationException::withMessages(['lines' => 'Choose an active account on every line with an amount.']);
            if (($debit > 0) === ($credit > 0)) throw ValidationException::withMessages(['lines' => 'Each line needs either a debit or a credit amount, not both.']);
            $lines[] = ['account_id' => (int) $l['account_id'], 'debit_paise' => $debit, 'credit_paise' => $credit, 'student_id' => null, 'teacher_id' => null];
        }
        if (count($lines) < 2) throw ValidationException::withMessages(['lines' => 'A journal needs at least two lines.']);
        $debits = array_sum(array_column($lines, 'debit_paise')); $credits = array_sum(array_column($lines, 'credit_paise'));
        if ($debits !== $credits) throw ValidationException::withMessages(['lines' => 'Debits ('.MoneyDisplay::inr($debits).') and credits ('.MoneyDisplay::inr($credits).') must be equal.']);
        return DB::transaction(function () use ($unit, $actor, $v, $lines) {
            $number = 'MJ-'.str_pad((string) (DB::table('erp_journal_entries')->where('unit_id', $unit)->where('source_type', 'manual')->lockForUpdate()->count() + 1), 5, '0', STR_PAD_LEFT);
            $id = $this->insertEntry($unit, $v['entry_date'], $number, $v['memo'], 'manual', null, $lines, $actor);
            Audit::record($unit, $actor, 'books.manual_journal', 'journal', $id, ['reference' => $number]);
            return DB::table('erp_journal_entries')->find($id);
        });
    }

    public function reverseManual(int $unit, int $actor, array $p): object
    {
        $v = validator($p, ['entry_id' => 'required|integer|min:1', 'reason' => 'required|string|max:500'])->validate();
        return DB::transaction(function () use ($unit, $actor, $v) {
            $entry = DB::table('erp_journal_entries')->where('unit_id', $unit)->where('id', $v['entry_id'])->lockForUpdate()->first();
            if (!$entry || $entry->source_type !== 'manual') throw ValidationException::withMessages(['entry_id' => 'Only manual journals can be reversed here; operational entries are corrected where they were recorded.']);
            if (DB::table('erp_journal_entries')->where('reverses_id', $entry->id)->exists()) throw ValidationException::withMessages(['entry_id' => 'This journal was already reversed.']);
            $lines = DB::table('erp_journal_lines')->where('entry_id', $entry->id)->get()->map(fn ($l) => ['account_id' => $l->account_id, 'debit_paise' => $l->credit_paise, 'credit_paise' => $l->debit_paise, 'student_id' => $l->student_id, 'teacher_id' => $l->teacher_id])->all();
            $today = CarbonImmutable::now(DB::table('erp_units')->where('id', $unit)->value('timezone'))->toDateString();
            $id = $this->insertEntry($unit, $today, $entry->reference.'-REV', 'Reversal: '.$v['reason'], 'manual', null, $lines, $actor, (int) $entry->id);
            Audit::record($unit, $actor, 'books.manual_journal_reversed', 'journal', $id, ['reverses' => $entry->id, 'reason' => $v['reason']]);
            return DB::table('erp_journal_entries')->find($id);
        });
    }

    // ------------------------------------------------------------ reports

    /** Account balances up to a date (inclusive), debit-positive. */
    public function balances(int $unit, ?string $from, string $to): Collection
    {
        $q = DB::table('erp_accounts as a')->leftJoin('erp_journal_lines as l', 'l.account_id', '=', 'a.id')->leftJoin('erp_journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->where('a.unit_id', $unit)->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.system_key')->orderBy('a.code')
            ->select('a.id', 'a.code', 'a.name', 'a.type', 'a.system_key');
        $range = $from ? 'e.entry_date >= ? AND e.entry_date <= ?' : 'e.entry_date <= ?';
        $bind = $from ? [$from, $to] : [$to];
        $q->selectRaw("COALESCE(SUM(CASE WHEN $range THEN l.debit_paise ELSE 0 END),0) AS debit", $bind)->selectRaw("COALESCE(SUM(CASE WHEN $range THEN l.credit_paise ELSE 0 END),0) AS credit", $bind);
        return $q->get()->map(function ($r) { $r->debit = (int) $r->debit; $r->credit = (int) $r->credit; $r->balance = $r->debit - $r->credit; return $r; });
    }

    public function trialBalance(int $unit, string $asOf): array
    {
        $rows = $this->balances($unit, null, $asOf)->filter(fn ($r) => $r->debit || $r->credit)->values();
        return ['rows' => $rows, 'debit' => $rows->sum(fn ($r) => max($r->balance, 0)), 'credit' => $rows->sum(fn ($r) => max(-$r->balance, 0))];
    }

    public function profitAndLoss(int $unit, string $from, string $to): array
    {
        $rows = $this->balances($unit, $from, $to);
        $income = $rows->where('type', 'income')->map(fn ($r) => tap($r, fn () => $r->amount = -$r->balance))->filter(fn ($r) => $r->amount)->values();
        $expense = $rows->where('type', 'expense')->map(fn ($r) => tap($r, fn () => $r->amount = $r->balance))->filter(fn ($r) => $r->amount)->values();
        return ['income' => $income, 'expense' => $expense, 'total_income' => $income->sum('amount'), 'total_expense' => $expense->sum('amount'), 'net' => $income->sum('amount') - $expense->sum('amount')];
    }

    public function balanceSheet(int $unit, string $asOf): array
    {
        $rows = $this->balances($unit, null, $asOf);
        $section = fn ($type, $sign) => $rows->where('type', $type)->map(fn ($r) => tap(clone $r, fn ($c) => $c->amount = $sign * $r->balance))->filter(fn ($r) => $r->amount)->values();
        $assets = $section('asset', 1); $liabilities = $section('liability', -1); $equity = $section('equity', -1);
        $earnings = -$rows->whereIn('type', ['income', 'expense'])->sum('balance');
        return ['assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity, 'earnings' => $earnings, 'total_assets' => $assets->sum('amount'), 'total_liabilities_equity' => $liabilities->sum('amount') + $equity->sum('amount') + $earnings];
    }

    public function generalLedger(int $unit, int $accountId, string $from, string $to): array
    {
        $account = DB::table('erp_accounts')->where('unit_id', $unit)->where('id', $accountId)->first();
        if (!$account) return ['account' => null, 'opening' => 0, 'lines' => collect()];
        $opening = (int) DB::table('erp_journal_lines as l')->join('erp_journal_entries as e', 'e.id', '=', 'l.entry_id')->where('l.account_id', $accountId)->where('e.entry_date', '<', $from)->selectRaw('COALESCE(SUM(l.debit_paise - l.credit_paise),0) AS b')->value('b');
        $running = $opening;
        $lines = DB::table('erp_journal_lines as l')->join('erp_journal_entries as e', 'e.id', '=', 'l.entry_id')->leftJoin('erp_students as s', 's.id', '=', 'l.student_id')->leftJoin('erp_teachers as t', 't.id', '=', 'l.teacher_id')
            ->where('l.account_id', $accountId)->whereBetween('e.entry_date', [$from, $to])->orderBy('e.entry_date')->orderBy('e.id')
            ->get(['e.id as entry_id', 'e.entry_date', 'e.reference', 'e.memo', 'l.debit_paise', 'l.credit_paise', 's.name as student_name', 't.name as teacher_name'])
            ->map(function ($l) use (&$running) { $running += $l->debit_paise - $l->credit_paise; $l->running = $running; return $l; });
        return ['account' => $account, 'opening' => $opening, 'lines' => $lines];
    }

    /** Output tax by month from the books and from issued tax documents (they should agree once a month is fully invoiced). */
    public function taxSummary(int $unit, string $month): array
    {
        $start = $month.'-01'; $end = CarbonImmutable::createFromFormat('Y-m-d', $start)->endOfMonth()->toDateString();
        $booked = $this->balances($unit, $start, $end)->whereIn('system_key', ['tuition', 'cgst', 'sgst', 'igst'])->keyBy('system_key')->map(fn ($r) => -$r->balance);
        $docs = DB::table('erp_invoices')->where('unit_id', $unit)->whereBetween('issue_date', [$start, $end])->get();
        $signed = fn ($i, $f) => ($i->document_type === 'credit_note' ? -1 : 1) * (int) $i->$f;
        $issued = ['taxable' => $docs->sum(fn ($i) => $signed($i, 'subtotal_paise')), 'cgst' => $docs->sum(fn ($i) => $signed($i, 'cgst_paise')), 'sgst' => $docs->sum(fn ($i) => $signed($i, 'sgst_paise')), 'igst' => $docs->sum(fn ($i) => $signed($i, 'igst_paise')), 'total' => $docs->sum(fn ($i) => $signed($i, 'total_paise'))];
        $byState = $docs->groupBy(fn ($i) => $i->place_of_supply ?: 'Not recorded')->map(fn ($g) => ['documents' => $g->count(), 'taxable' => $g->sum(fn ($i) => $signed($i, 'subtotal_paise')), 'tax' => $g->sum(fn ($i) => $signed($i, 'tax_paise'))])->sortKeys();
        return ['booked' => ['taxable' => (int) ($booked['tuition'] ?? 0), 'cgst' => (int) ($booked['cgst'] ?? 0), 'sgst' => (int) ($booked['sgst'] ?? 0), 'igst' => (int) ($booked['igst'] ?? 0)], 'issued' => $issued, 'by_state' => $byState, 'documents' => $docs->count(), 'credit_notes' => $docs->where('document_type', 'credit_note')->count()];
    }
}
