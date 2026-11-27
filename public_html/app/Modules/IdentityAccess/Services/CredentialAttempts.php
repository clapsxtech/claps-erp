<?php

namespace App\Modules\IdentityAccess\Services;

use Illuminate\Support\Facades\DB;

/**
 * Failed-credential counters that survive container restarts.
 *
 * The application cache lives on the container's disk, which is lost on every deploy or idle
 * restart, so lockouts kept there reset whenever the container restarts. These counters are
 * stored in the application database instead. On D1 every write is a remote commit, so only
 * failures write: a successful sign-in with nothing to clear makes reads only, and one failure
 * records all its counters in one transaction (one commit). Call it outside other database
 * transactions: a rollback would discard the recorded failure.
 */
class CredentialAttempts
{
    private const TABLE = 'erp_credential_attempts';

    public function tooMany(string $key, int $maxAttempts): bool
    {
        $row = DB::table(self::TABLE)->where('key', $this->key($key))->first(['attempts', 'expires_at']);

        return $row !== null && (int) $row->expires_at > time() && (int) $row->attempts >= $maxAttempts;
    }

    /** Failures recorded in the current window (0 once it has expired). */
    public function attempts(string $key): int
    {
        $row = DB::table(self::TABLE)->where('key', $this->key($key))->first(['attempts', 'expires_at']);

        return $row !== null && (int) $row->expires_at > time() ? (int) $row->attempts : 0;
    }

    /** Record one failure against several counters in a single commit. @param array<string,int> $decays key => window seconds */
    public function hitAll(array $decays): void
    {
        if ($decays === []) return;
        DB::transaction(function () use ($decays) {
            foreach ($decays as $key => $decaySeconds) $this->hit($key, $decaySeconds);
        });
    }

    public function hit(string $key, int $decaySeconds): void
    {
        $key = $this->key($key);
        $now = time();
        $row = DB::table(self::TABLE)->where('key', $key)->first(['attempts', 'expires_at']);
        if ($row === null) {
            DB::table(self::TABLE)->insertOrIgnore(['key' => $key, 'attempts' => 1, 'expires_at' => $now + $decaySeconds]);
        } elseif ((int) $row->expires_at <= $now) {
            DB::table(self::TABLE)->where('key', $key)->update(['attempts' => 1, 'expires_at' => $now + $decaySeconds]);
        } else {
            DB::table(self::TABLE)->where('key', $key)->increment('attempts');
        }
        // Occasionally remove expired counters so the table stays small.
        if (random_int(1, 50) === 1) {
            DB::table(self::TABLE)->where('expires_at', '<', $now)->delete();
        }
    }

    public function clear(string $key): void
    {
        // Read first: on D1 even a DELETE that matches nothing would cost a remote commit.
        $key = $this->key($key);
        if (DB::table(self::TABLE)->where('key', $key)->exists()) DB::table(self::TABLE)->where('key', $key)->delete();
    }

    private function key(string $key): string
    {
        // Never store names or addresses: keys are hashed with a fixed-length digest.
        return hash('sha256', $key);
    }
}
