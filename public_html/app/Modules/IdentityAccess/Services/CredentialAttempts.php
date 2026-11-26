<?php

namespace App\Modules\IdentityAccess\Services;

use Illuminate\Support\Facades\DB;

/**
 * Failed-credential counters that survive container restarts.
 *
 * The application cache lives on the container's disk, which is lost on every deploy or idle
 * restart, so lockouts kept there reset whenever the container restarts. These counters are
 * stored in the application database instead. Only failures are written, so a successful
 * sign-in costs one read. Call it outside database transactions: a rollback would discard
 * the recorded failure.
 */
class CredentialAttempts
{
    private const TABLE = 'erp_credential_attempts';

    public function tooMany(string $key, int $maxAttempts): bool
    {
        $row = DB::table(self::TABLE)->where('key', $this->key($key))->first(['attempts', 'expires_at']);

        return $row !== null && (int) $row->expires_at > time() && (int) $row->attempts >= $maxAttempts;
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
        DB::table(self::TABLE)->where('key', $this->key($key))->delete();
    }

    private function key(string $key): string
    {
        // Never store names or addresses: keys are hashed with a fixed-length digest.
        return hash('sha256', $key);
    }
}
