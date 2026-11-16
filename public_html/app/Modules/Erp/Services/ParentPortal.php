<?php
namespace App\Modules\Erp\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Read-only family view of one student, signed in with the student ID and date of birth.
 * Everything here is scoped to that student; staff notes, teacher pay and contact details never leave it.
 */
class ParentPortal
{
    public const IDLE_MINUTES = 30;
    private const CODE_ATTEMPTS = 5;        // failed attempts per student ID…
    private const CODE_DECAY = 900;         // …per 15 minutes
    private const CODE_DAILY_ATTEMPTS = 10; // and per student ID per day, so slow guessing stays slow
    private const IP_ATTEMPTS = 20;         // failed attempts per public client IP per 15 minutes (only when real client IPs are visible)
    private const SITE_ATTEMPTS = 200;      // failed attempts across all IDs per minute: a distributed guessing attack

    /**
     * The client address, only when it is a real public one. Behind the Cloudflare container no proxy is trusted,
     * so every request shows the same internal address; limiting on that would lock out every family at once.
     */
    public static function clientIp(?string $ip): ?string
    {
        // Only trust the address when a proxy is explicitly configured (TRUSTED_PROXIES), and never a private, reserved or shared (100.64/10) one.
        if (trim((string) config('proxy.trusted_proxies', '')) === '' || !$ip) return null;
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
        if (\Symfony\Component\HttpFoundation\IpUtils::checkIp($ip, '100.64.0.0/10')) return null;
        return $ip;
    }

    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', $code));
    }

    /** Returns the student for a matching ID and date of birth. Failures share one message and count towards lockouts. */
    public function authenticate(string $code, string $dob, ?string $ip = null): object
    {
        $code = self::normalizeCode($code);
        $codeKey = 'parent-login:'.hash('sha256', $code);
        $ipKey = 'parent-login-ip:'.hash('sha256', (string) $ip);
        $dayKey = 'parent-login-day:'.hash('sha256', $code);
        // During a site-wide attack, IDs that have already had a wrong guess are frozen, while
        // families whose ID has not been tried can still sign in (no lockout for everyone).
        $underAttack = RateLimiter::tooManyAttempts('parent-login:site', self::SITE_ATTEMPTS);
        if (RateLimiter::tooManyAttempts($dayKey, self::CODE_DAILY_ATTEMPTS)) {
            throw ValidationException::withMessages(['student_code' => 'Too many attempts for this student ID today. Please try again tomorrow, or contact the centre.']);
        }
        if (RateLimiter::tooManyAttempts($codeKey, self::CODE_ATTEMPTS) || ($ip !== null && RateLimiter::tooManyAttempts($ipKey, self::IP_ATTEMPTS))
            || ($underAttack && RateLimiter::attempts($codeKey) > 0)) {
            throw ValidationException::withMessages(['student_code' => 'Too many attempts. Please wait 15 minutes, or contact the centre.']);
        }
        $student = $code === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) ? null : DB::table('erp_students as s')
            ->join('erp_student_profiles as p', 'p.student_id', '=', 's.id')
            ->join('erp_units as u', 'u.id', '=', 's.unit_id')
            ->whereRaw('UPPER(s.code) = ?', [$code])->where('p.date_of_birth', $dob)->whereNull('u.archived_at')
            ->select('s.*')->first();
        if (!$student) {
            RateLimiter::hit($codeKey, self::CODE_DECAY);
            RateLimiter::hit($dayKey, 86400);
            if ($ip !== null) RateLimiter::hit($ipKey, self::CODE_DECAY);
            RateLimiter::hit('parent-login:site', 60);
            throw ValidationException::withMessages(['student_code' => 'The student ID and date of birth do not match our records.']);
        }
        RateLimiter::clear($codeKey); RateLimiter::clear($dayKey);
        Audit::record((int) $student->unit_id, null, 'parent.signed_in', 'student', (int) $student->id, []);
        return $student;
    }

    /** Ties a session to the date of birth used, so changing it signs the family out. */
    public static function fingerprint(int $studentId): string
    {
        return hash('sha256', $studentId.'|'.(string) DB::table('erp_student_profiles')->where('student_id', $studentId)->value('date_of_birth'));
    }

    public function overview(object $student): array
    {
        $now = now('UTC')->format('Y-m-d H:i:s');
        return [
            'upcoming' => $this->lessons($student)->where('l.starts_at', '>=', $now)->where('l.status', 'scheduled')->orderBy('l.starts_at')->limit(5)->get(),
            'balance' => (int) DB::table('erp_ledger')->where('unit_id', $student->unit_id)->where('student_id', $student->id)->sum('amount_paise'),
            'results' => $this->results($student)->limit(3)->get(),
            'open_requests' => DB::table('erp_payment_requests')->where('unit_id', $student->unit_id)->where('student_id', $student->id)->where('status', 'open')->count(),
        ];
    }

    public function classes(object $student): array
    {
        $tz = $student->timezone;
        $from = CarbonImmutable::now($tz)->subDays(60)->startOfDay()->utc()->format('Y-m-d H:i:s');
        $to = CarbonImmutable::now($tz)->addDays(60)->endOfDay()->utc()->format('Y-m-d H:i:s');
        $rows = $this->lessons($student)->whereBetween('l.starts_at', [$from, $to])
            ->leftJoin('erp_attendance as a', function ($j) { $j->on('a.lesson_id', '=', 'l.id')->where('a.status', '!=', 'rejected'); })
            ->leftJoin('erp_parent_requests as pr', function ($j) { $j->on('pr.attendance_id', '=', 'a.id')->where('pr.status', 'open'); })
            ->addSelect('a.id as attendance_id', 'a.actual_minutes', 'a.parent_review_status', 'pr.id as open_request_id')
            ->orderByDesc('l.starts_at')->get();
        return ['upcoming' => $rows->filter(fn ($r) => $r->status === 'scheduled' && $r->starts_at >= now('UTC')->format('Y-m-d H:i:s'))->sortBy('starts_at')->values(),
            'past' => $rows->reject(fn ($r) => $r->status === 'scheduled' && $r->starts_at >= now('UTC')->format('Y-m-d H:i:s'))->values()];
    }

    public function results(object $student)
    {
        return DB::table('erp_marks as m')->join('erp_enrollments as e', function ($j) { $j->on('e.id', '=', 'm.enrollment_id')->on('e.unit_id', '=', 'm.unit_id'); })
            ->join('erp_subjects as subject', 'subject.id', '=', 'e.subject_id')
            ->where('e.unit_id', $student->unit_id)->where('e.student_id', $student->id)
            ->orderByDesc('m.assessed_on')->orderByDesc('m.id')
            ->select('m.id', 'm.assessment', 'm.assessed_on', 'm.score', 'm.maximum', 'm.notes', 'subject.name as subject_name');
    }

    public function fees(object $student): array
    {
        $money = new MoneyOperations();
        $requests = DB::table('erp_payment_requests')->where('unit_id', $student->unit_id)->where('student_id', $student->id)->orderByDesc('requested_on')->orderByDesc('id')->limit(24)->get()
            ->map(fn ($r) => (object) ((array) $r + $money->requestSummary((int) $student->unit_id, (int) $r->id)));
        $documents = DB::table('erp_ledger')->where('unit_id', $student->unit_id)->where('student_id', $student->id)->whereIn('kind', ['payment', 'refund'])->orderByDesc('occurred_at')->orderByDesc('id')->limit(50)->get();
        $reversed = DB::table('erp_ledger')->whereIn('reverses_id', $documents->pluck('id'))->pluck('reverses_id')->all();
        $invoices = DB::table('erp_invoices')->where('unit_id', $student->unit_id)->where('student_id', $student->id)->orderByDesc('issue_date')->orderByDesc('id')->limit(24)->get();
        $months = DB::table('erp_lessons as l')->join('erp_attendance as a', 'a.lesson_id', '=', 'l.id')->where('l.unit_id', $student->unit_id)->where('l.student_id', $student->id)->pluck('l.starts_at')
            ->merge(DB::table('erp_ledger')->where('unit_id', $student->unit_id)->where('student_id', $student->id)->pluck('occurred_at'))
            ->map(fn ($t) => CarbonImmutable::parse($t, 'UTC')->setTimezone($student->timezone)->format('Y-m'))->unique()->sortDesc()->take(12)->values();
        return ['balance' => (int) DB::table('erp_ledger')->where('unit_id', $student->unit_id)->where('student_id', $student->id)->sum('amount_paise'),
            'requests' => $requests, 'documents' => $documents, 'reversed' => $reversed, 'invoices' => $invoices, 'months' => $months];
    }

    /** One month of reported teaching plus the account summary; shared by staff and parents so both see the same numbers. */
    public static function statement(int $unit, object $student, string $month): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, $student->timezone)->startOfMonth();
        $rows = DB::table('erp_attendance as a')->join('erp_lessons as l', 'l.id', '=', 'a.lesson_id')->join('erp_subjects as s', 's.id', '=', 'l.subject_id')->join('erp_teachers as t', 't.id', '=', 'l.teacher_id')
            ->where('a.unit_id', $unit)->where('l.unit_id', $unit)->where('l.student_id', $student->id)->where('a.status', '!=', 'rejected')
            ->whereRaw('COALESCE(a.actual_starts_at,l.starts_at) >= ?', [$start->utc()->format('Y-m-d H:i:s')])->whereRaw('COALESCE(a.actual_starts_at,l.starts_at) < ?', [$start->addMonth()->utc()->format('Y-m-d H:i:s')])
            ->orderByRaw('COALESCE(a.actual_starts_at,l.starts_at)')->get(['a.*', 'l.starts_at', 'l.kind', 'l.student_rate_paise', 's.name as subject_name', 't.name as teacher_name']);
        // Ledger times are recorded in the installation timezone.
        $ledgerZone = config('app.timezone'); $from = $start->setTimezone($ledgerZone)->format('Y-m-d H:i:s'); $to = $start->addMonth()->setTimezone($ledgerZone)->format('Y-m-d H:i:s');
        $ledger = DB::table('erp_ledger')->where('unit_id', $unit)->where('student_id', $student->id);
        $kinds = (clone $ledger)->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)->groupBy('kind')->selectRaw('kind, SUM(amount_paise) AS total')->pluck('total', 'kind')->map(fn ($v) => (int) $v);
        $opening = (int) (clone $ledger)->where('occurred_at', '<', $from)->sum('amount_paise');
        return ['rows' => $rows, 'account' => ['opening' => $opening, 'kinds' => $kinds, 'closing' => $opening + (int) $kinds->sum()]];
    }

    /** A parent flags a class record. Unapproved classes are held for review; staff resolve every request. */
    public function dispute(object $student, int $attendanceId, string $note): object
    {
        $note = trim($note);
        if (mb_strlen($note) < 10) throw ValidationException::withMessages(['note' => 'Please describe what is wrong (at least 10 characters).']);
        if (mb_strlen($note) > 1000) throw ValidationException::withMessages(['note' => 'Please keep it under 1,000 characters.']);
        $key = 'parent-dispute:'.$student->id;
        if (RateLimiter::tooManyAttempts($key, 10)) throw ValidationException::withMessages(['note' => 'Too many requests today. Please contact the centre.']);
        return DB::transaction(function () use ($student, $attendanceId, $note, $key) {
            $attendance = DB::table('erp_attendance as a')->join('erp_lessons as l', 'l.id', '=', 'a.lesson_id')
                ->where('a.id', $attendanceId)->where('a.unit_id', $student->unit_id)->where('l.student_id', $student->id)->where('a.status', '!=', 'rejected')
                ->lockForUpdate()->select('a.*')->first();
            if (!$attendance) throw ValidationException::withMessages(['attendance_id' => 'That class is not on this student\'s record.']);
            if (DB::table('erp_parent_requests')->where('attendance_id', $attendance->id)->where('status', 'open')->exists()) throw ValidationException::withMessages(['attendance_id' => 'This class already has an open query. The centre will reply.']);
            RateLimiter::hit($key, 86400);
            $id = DB::table('erp_parent_requests')->insertGetId(['unit_id' => $student->unit_id, 'student_id' => $student->id, 'attendance_id' => $attendance->id, 'kind' => 'dispute', 'note' => $note, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
            // Hold a class that is still awaiting approval; approved classes are corrected by staff.
            if ($attendance->status === 'submitted') DB::table('erp_attendance')->where('id', $attendance->id)->update(['parent_review_status' => 'disputed', 'parent_review_note' => mb_substr('Parent portal: '.$note, 0, 2000), 'updated_at' => now()]);
            Audit::record((int) $student->unit_id, null, 'parent.disputed_class', 'attendance', (int) $attendance->id, ['request_id' => $id]);
            $request = DB::table('erp_parent_requests')->find($id);
            $request->held = $attendance->status === 'submitted'; // false: already approved (and charged); staff correct it if needed
            return $request;
        });
    }

    private function lessons(object $student)
    {
        return DB::table('erp_lessons as l')->join('erp_teachers as t', 't.id', '=', 'l.teacher_id')->join('erp_subjects as subject', 'subject.id', '=', 'l.subject_id')
            ->where('l.unit_id', $student->unit_id)->where('l.student_id', $student->id)->whereIn('l.status', ['scheduled', 'completed', 'cancelled', 'no_show'])
            ->select('l.id', 'l.starts_at', 'l.ends_at', 'l.status', 'l.kind', 't.name as teacher_name', 'subject.name as subject_name');
    }
}
