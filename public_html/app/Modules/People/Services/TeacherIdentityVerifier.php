<?php

namespace App\Modules\People\Services;

use App\Models\Master;
use App\Modules\Erp\Services\ParentPortal;
use App\Modules\IdentityAccess\Services\CredentialAttempts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;

/**
 * Compatibility boundary for the existing teacher DOB credential.
 * A DOB is not a strong password. Replace this gate with account-based identity
 * before expanding access; never return or log the stored credential.
 *
 * Because a date of birth is guessable, failed attempts are limited per teacher and per
 * visitor address, and the counters are kept in the database so restarts do not reset them.
 * Teachers type their name; the public pages never list teacher names.
 */
class TeacherIdentityVerifier
{
    public const TEACHER_ATTEMPTS = 5;        // failed attempts per teacher…
    public const TEACHER_DECAY = 900;         // …per 15 minutes
    public const TEACHER_DAILY_ATTEMPTS = 15; // and per teacher per day, so slow guessing stays slow
    public const IP_ATTEMPTS = 20;            // failed attempts per visitor address per 15 minutes

    /** One normal form for typed names, shared by the verifier, lockout keys and the route limiter. */
    public static function normalizeName($name): string
    {
        if (!is_string($name)) return '';
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /** The stored spelling of a typed teacher name, or null when it matches no teacher or several spellings. */
    public function resolveName($typed): ?string
    {
        if (!is_string($typed) || trim($typed) === '') return null;
        $normal = self::normalizeName($typed);
        $names = Master::query()->select('teacher_name')->distinct()->pluck('teacher_name')
            ->filter(fn ($name) => is_string($name) && self::normalizeName($name) === $normal)->values();
        if ($names->contains($typed)) return $typed;

        return $names->count() === 1 ? $names->first() : null;
    }

    public function verifyTeacher(array $input): Master
    {
        $input = $this->normalizeCredential($input);
        Validator::make($input, [
            'teacher_name' => ['required', 'string', 'max:255'],
            'teacher_code' => ['sometimes', 'required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ])->validate();

        $attempts = app(CredentialAttempts::class);
        $ip = ParentPortal::clientIp(request()->ip());
        $ipKey = $ip === null ? null : 'teacher-dob-ip:'.$ip;
        // Check every lockout before verifying, and write nothing while locked.
        if ($ipKey !== null && $attempts->tooMany($ipKey, self::IP_ATTEMPTS)) $this->locked(false);
        $name = $this->resolveName($input['teacher_name']);
        // Only names of real teachers get counters, so made-up names cannot grow the table.
        $teacherKey = $name === null ? null : 'teacher-dob:'.self::normalizeName($name);
        if ($teacherKey !== null) {
            if ($attempts->tooMany($teacherKey.':day', self::TEACHER_DAILY_ATTEMPTS)) $this->locked(true);
            if ($attempts->tooMany($teacherKey, self::TEACHER_ATTEMPTS)) $this->locked(false);
        }
        $teacher = $name === null ? null : $this->match(['teacher_name' => $name] + $input);
        if (!$teacher) {
            if ($ipKey !== null) $attempts->hit($ipKey, self::TEACHER_DECAY);
            if ($teacherKey !== null) {
                $attempts->hit($teacherKey, self::TEACHER_DECAY);
                $attempts->hit($teacherKey.':day', 86400);
            }
            $this->deny();
        }
        // Clear only the short window: a daily sign-in must not reset the daily budget.
        if ($teacherKey !== null) $attempts->clear($teacherKey);

        return $teacher;
    }

    private function match(array $input): ?Master
    {
        $query = Master::where('teacher_name', $input['teacher_name']);
        if (isset($input['teacher_code'])) {
            $query->where('teacher_code', $input['teacher_code']);
        }
        $candidates = $query->get()->filter(function (Master $master) use ($input) {
            return $master->teacher_name === $input['teacher_name']
                && is_string($master->teacher_code) && trim($master->teacher_code) !== ''
                && (!isset($input['teacher_code']) || $master->teacher_code === $input['teacher_code']);
        });

        // A name shared by different teachers is not enough to select an identity.
        if ($candidates->pluck('teacher_code')->uniqueStrict()->count() !== 1
            || $candidates->pluck('teacher_dob')->uniqueStrict()->count() !== 1) {
            return null;
        }
        $teacher = $candidates->first(function (Master $master) use ($input) {
            return $this->matchesCredential($master->teacher_dob, $input['password']);
        });

        return $teacher ? $teacher->makeHidden('teacher_dob') : null;
    }

    public function verifyPair(array $input): Master
    {
        $input = $this->normalizeCredential($input);
        Validator::make($input, [
            'teacher_name' => ['required', 'string', 'max:255'],
            'teacher_code' => ['required', 'string', 'max:255'],
            'student_name' => ['required', 'string', 'max:255'],
            'student_code' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ])->validate();

        // Every assignment for this teacher must agree on the credential.
        // Later lookups use the stored spelling, not the typed one.
        $input['teacher_name'] = $this->verifyTeacher($input)->teacher_name;
        $candidates = Master::where('teacher_name', $input['teacher_name'])
            ->where('teacher_code', $input['teacher_code'])
            ->where('student_name', $input['student_name'])
            ->where('student_code', $input['student_code'])
            ->get()->filter(function (Master $master) use ($input) {
                return $master->teacher_name === $input['teacher_name']
                    && $master->teacher_code === $input['teacher_code']
                    && $master->student_name === $input['student_name']
                    && $master->student_code === $input['student_code']
                    && $this->matchesCredential($master->teacher_dob, $input['password']);
            });
        $assignmentDetails = $candidates->map(function (Master $master) {
            return $master->only(['teacher_name', 'teacher_code', 'student_name', 'student_code',
                'teacher_dob', 'hour_payment', 'profit_per_hour', 'payment_per_hour', 'team']);
        });
        if ($candidates->isEmpty() || $assignmentDetails->uniqueStrict()->count() !== 1) {
            $this->deny();
        }

        return $candidates->first()->makeHidden('teacher_dob');
    }

    private function normalizeCredential(array $input): array
    {
        // Do not let an explicitly empty password fall back to another value.
        if (!array_key_exists('password', $input)) {
            $input['password'] = $input['dob'] ?? $input['teacher_dob'] ?? null;
        }
        return $input;
    }

    private function matchesCredential($stored, string $provided): bool
    {
        return is_string($stored) && trim($stored) !== ''
            && trim($provided) !== '' && hash_equals($stored, $provided);
    }

    private function locked(bool $daily): void
    {
        abort(429, $daily
            ? 'Too many attempts for this teacher today. Please try again tomorrow, or contact the centre.'
            : 'Too many attempts. Please wait 15 minutes, or contact the centre.');
    }

    private function deny(): void
    {
        throw new AuthorizationException('Unable to verify the supplied identity.');
    }
}
