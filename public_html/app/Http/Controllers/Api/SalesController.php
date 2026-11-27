<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Erp\Services\{Access, Admissions, SalesApiTokens, SalesCrmReader, SalesPipeline, TeamPermissions};
use App\Modules\IdentityAccess\Services\{SiteAccessPolicy, TwoFactor};
use App\Modules\IdentityAccess\Services\CredentialAttempts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * JSON API for the sales app at sales.clapslearn.in. Reads and writes go through
 * the same services and permission matrix as the ERP's Sales CRM screen, so both
 * apps work on one set of records with one set of rules.
 */
class SalesController extends Controller
{
    private const LOGIN_ATTEMPTS = 8;          // failed sign-ins per account per 5 minutes
    private const LOGIN_DAILY_ATTEMPTS = 30;   // and per account per day
    private const LOGIN_SITE_ATTEMPTS = 100;   // across all accounts per 15 minutes (password spraying)
    private const LOGIN_SITE_KEY = 'sales-login:site';

    /** Sales app action name => Admissions method. Permission names match the ERP's mutation names. */
    private const ACTIONS = [
        'create-lead' => 'createLead', 'update-lead' => 'updateLead', 'schedule-demo' => 'scheduleDemo',
        'update-demo' => 'updateDemo', 'followup' => 'recordFollowup', 'reminder' => 'createReminder',
        'complete-reminder' => 'completeReminder', 'transfer' => 'requestTransfer',
        'resolve-transfer' => 'resolveTransfer', 'convert' => 'convertLead',
    ];

    public function login(Request $request)
    {
        $v = $request->validate(['email' => 'required|string|max:255', 'password' => 'required|string|max:255', 'code' => 'nullable|string|max:64']);
        // Counters live in the database so restarts do not reset them. Sales requests reach us
        // through the Worker, so visitor addresses are unknown: limit per account, per day, and
        // across all accounts so one password cannot be sprayed over every login.
        $attempts = app(CredentialAttempts::class);
        $key = 'sales-login:'.hash('sha256', mb_strtolower(trim($v['email'])));
        if ($attempts->tooMany(self::LOGIN_SITE_KEY, self::LOGIN_SITE_ATTEMPTS)) {
            throw ValidationException::withMessages(['email' => 'Sign-in is paused for a few minutes after many failed attempts. Please try again shortly.']);
        }
        if ($attempts->tooMany($key.':day', self::LOGIN_DAILY_ATTEMPTS)) {
            throw ValidationException::withMessages(['email' => 'Too many sign-in attempts today. Try again tomorrow, or ask an administrator.']);
        }
        if ($attempts->tooMany($key, self::LOGIN_ATTEMPTS)) {
            throw ValidationException::withMessages(['email' => 'Too many sign-in attempts. Try again in 5 minutes.']);
        }
        $user = User::where('email', trim($v['email']))->first();
        $policy = new SiteAccessPolicy();
        $failed = function () use ($attempts, $key, $user) {
            $attempts->hit(self::LOGIN_SITE_KEY, 900);
            // Only real accounts get their own counters, so made-up emails cannot grow the table.
            if ($user) { $attempts->hit($key, 300); $attempts->hit($key.':day', 86400); }
        };
        if (!$user || !Hash::check($v['password'], (string) $user->password) || !$policy->isSiteMember($user)) {
            $failed();
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        if ($user->two_factor_secret) {
            if (empty($v['code'])) return response()->json(['two_factor_required' => true], 202);
            if (!(new TwoFactor())->consume((int) $user->id, $v['code'])) {
                $failed();
                throw ValidationException::withMessages(['code' => 'Invalid or expired code. Try a new code or a recovery code.']);
            }
        }
        if ($this->salesUnits($user) === []) {
            throw ValidationException::withMessages(['email' => 'This account does not have access to the Sales CRM.']);
        }
        // Clear only the short window: a daily sign-in must not reset the daily budget.
        $attempts->clear($key);

        return response()->json((new SalesApiTokens())->issue($user) + ['user' => $this->profile($user)]);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $this->profile($request->user())]);
    }

    public function overview(Request $request, int $unit)
    {
        return response()->json($this->reader($request, $unit)->overview());
    }

    public function leads(Request $request, int $unit)
    {
        $reader = $this->reader($request, $unit);
        $filters = $request->validate([
            'q' => 'nullable|string|max:100', 'status' => 'nullable|in:LEAD,JOINED,LOST',
            'stage' => 'nullable|in:'.implode(',', array_keys(SalesPipeline::STAGES)), 'hot' => 'nullable|boolean',
            'followup' => 'nullable|in:today,overdue,unscheduled', 'page' => 'nullable|integer|min:1',
        ]);

        return response()->json($reader->leads($filters));
    }

    public function lead(Request $request, int $unit, int $lead)
    {
        $detail = $this->reader($request, $unit)->lead($lead);
        abort_unless($detail, 404, 'Lead not found.');

        return response()->json($detail);
    }

    public function demos(Request $request, int $unit)
    {
        $reader = $this->reader($request, $unit);
        $v = $request->validate(['scope' => 'nullable|in:upcoming,all', 'page' => 'nullable|integer|min:1']);

        return response()->json($reader->demos($v['scope'] ?? 'upcoming', (int) ($v['page'] ?? 1)));
    }

    public function reminders(Request $request, int $unit)
    {
        $reader = $this->reader($request, $unit);
        $v = $request->validate(['due' => 'nullable|boolean', 'page' => 'nullable|integer|min:1']);

        return response()->json($reader->reminders((bool) ($v['due'] ?? false), (int) ($v['page'] ?? 1)));
    }

    public function transfers(Request $request, int $unit)
    {
        $reader = $this->reader($request, $unit);
        $v = $request->validate(['page' => 'nullable|integer|min:1']);

        return response()->json($reader->transfers((int) ($v['page'] ?? 1)));
    }

    public function teachers(Request $request, int $unit)
    {
        return response()->json($this->reader($request, $unit)->teachers($request->query()));
    }

    public function members(Request $request, int $unit)
    {
        return response()->json(['data' => $this->reader($request, $unit)->members()]);
    }

    public function act(Request $request, int $unit, string $action)
    {
        abort_unless(isset(self::ACTIONS[$action]), 404);
        $user = $request->user();
        (new Access())->unit($user, $unit, true);
        abort_unless((new TeamPermissions())->allows($user, $unit, 'action', 'admission-'.$action), 403, 'Your team role does not allow this operation.');
        $payload = $request->except(['_token']);
        $result = (new Admissions())->{self::ACTIONS[$action]}($unit, (int) $user->id, $payload);

        return response()->json(['result' => $result]);
    }

    private function reader(Request $request, int $unit): SalesCrmReader
    {
        $user = $request->user();
        (new Access())->unit($user, $unit, false);
        abort_unless((new TeamPermissions())->allows($user, $unit, 'page', 'admissions'), 403, 'Your team role does not allow the Sales CRM.');

        return new SalesCrmReader($unit, $user);
    }

    /** Units where this user may open the Sales CRM. */
    private function salesUnits(User $user): array
    {
        $permissions = new TeamPermissions();

        return (new Access())->units($user)->filter(fn ($unit) => $permissions->allows($user, (int) $unit->id, 'page', 'admissions'))
            ->map(fn ($unit) => [
                'id' => (int) $unit->id, 'code' => $unit->code, 'name' => $unit->name, 'timezone' => $unit->timezone,
                'role' => $permissions->role($user, (int) $unit->id),
                'actions' => array_values(array_filter(array_keys(self::ACTIONS), fn ($a) => $permissions->allows($user, (int) $unit->id, 'action', 'admission-'.$a))),
            ])->values()->all();
    }

    private function profile(User $user): array
    {
        return ['id' => (int) $user->id, 'name' => $user->name, 'email' => $user->email, 'units' => $this->salesUnits($user)];
    }
}
