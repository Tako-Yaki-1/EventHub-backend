<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata  IDs and field names only; never secrets or full personal data.
     */
    public function record(
        string $action,
        string $result = 'success',
        ?User $user = null,
        ?string $entityType = null,
        ?int $entityId = null,
        array $metadata = [],
        ?Request $request = null,
    ): AuditLog {
        $request ??= request();
        $now = now();

        $attributes = [
            'user_id' => $user?->getKey(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'result' => $result,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent() !== null
                ? mb_substr($request->userAgent(), 0, 255)
                : null,
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => $now,
        ];

        return DB::transaction(function () use ($attributes) {
            $previous = AuditLog::query()->orderByDesc('id')->lockForUpdate()->first();

            $attributes['hash'] = hash(
                'sha256',
                ($previous?->hash ?? '').AuditLog::canonicalPayload($attributes),
            );

            return AuditLog::create($attributes);
        });
    }

    public function maskEmail(string $email): string
    {
        $at = strpos($email, '@');

        if ($at === false) {
            return $this->maskIdentifier($email);
        }

        return substr($email, 0, 1).'***'.substr($email, $at);
    }

    public function maskIdentifier(string $identifier): string
    {
        return '***'.substr($identifier, -2);
    }

    /**
     * @return array{intact: bool, checked: int, broken_at: int|null}
     */
    public function verifyChain(): array
    {
        $previous = '';
        $checked = 0;
        $brokenAt = null;

        AuditLog::query()->orderBy('id')->chunk(500, function ($logs) use (&$previous, &$checked, &$brokenAt) {
            foreach ($logs as $log) {
                $expected = hash('sha256', $previous.$log->canonical());

                if (! hash_equals($expected, (string) $log->hash)) {
                    $brokenAt = $log->id;

                    return false;
                }

                $previous = $log->hash;
                $checked++;
            }

            return true;
        });

        return [
            'intact' => $brokenAt === null,
            'checked' => $checked,
            'broken_at' => $brokenAt,
        ];
    }
}
