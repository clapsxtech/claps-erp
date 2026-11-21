<?php

namespace App\Modules\Erp\Services;

use App\Models\User;
use App\Modules\IdentityAccess\Services\SiteAccessPolicy;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Stateless, expiring sign-in tokens for the sales app. Nothing is stored, so
 * reads never write to D1. A token stops working when it expires, when the
 * password changes, when two-factor is turned on or off (both rotate
 * remember_token), or when the account is disabled.
 */
class SalesApiTokens
{
    public const TTL_SECONDS = 12 * 3600;

    public function issue(User $user): array
    {
        $expires = now()->addSeconds(self::TTL_SECONDS)->timestamp;
        $token = Crypt::encryptString(json_encode(['uid' => (int) $user->id, 'exp' => $expires, 'fp' => $this->fingerprint($user)]));

        return ['token' => $token, 'expires_at' => $expires];
    }

    public function resolve(string $token): ?User
    {
        if ($token === '' || strlen($token) > 4096) return null;
        try {
            $claims = json_decode(Crypt::decryptString($token), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }
        if (!is_array($claims) || !is_int($claims['uid'] ?? null) || !is_int($claims['exp'] ?? null) || !is_string($claims['fp'] ?? null)) return null;
        if ($claims['exp'] < now()->timestamp) return null;
        $user = User::find($claims['uid']);
        if (!$user || !hash_equals($this->fingerprint($user), $claims['fp'])) return null;
        if (!(new SiteAccessPolicy())->isSiteMember($user)) return null;

        return $user;
    }

    private function fingerprint(User $user): string
    {
        return hash_hmac('sha256', (string) $user->password.'|'.(string) $user->remember_token, (string) config('app.key'));
    }
}
