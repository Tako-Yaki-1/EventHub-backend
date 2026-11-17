<?php

namespace App\Services;

use App\Models\User;

class TokenService
{
    public function issue(User $user, ?string $userAgent): string
    {
        return $user->createToken(
            $this->deviceName($userAgent),
            ['*'],
            now()->addMinutes((int) config('security.token_ttl_minutes', 480)),
        )->plainTextToken;
    }

    public function deviceName(?string $userAgent): string
    {
        $userAgent ??= '';

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/'), str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Unknown browser',
        };

        $platform = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Unknown OS',
        };

        return mb_substr("web ({$browser} on {$platform})", 0, 80);
    }
}
