<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'result',
        'ip_address',
        'user_agent',
        'metadata',
        'hash',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The log is append-only: any edit or deletion is a logic error, not a feature.
     */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit log entries are append-only.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Stable representation used by the hash chain. Never include values that can change
     * (like an updated_at) or that are derived from the row id.
     *
     * @param  array<string, mixed>  $row
     */
    public static function canonicalPayload(array $row): string
    {
        $createdAt = $row['created_at'] ?? null;

        if ($createdAt instanceof \DateTimeInterface) {
            $createdAt = $createdAt->format('Y-m-d H:i:s');
        }

        return json_encode([
            'user_id' => $row['user_id'] ?? null,
            'action' => $row['action'] ?? null,
            'entity_type' => $row['entity_type'] ?? null,
            'entity_id' => $row['entity_id'] ?? null,
            'result' => $row['result'] ?? null,
            'ip_address' => $row['ip_address'] ?? null,
            'user_agent' => $row['user_agent'] ?? null,
            'metadata' => $row['metadata'] ?? null,
            'created_at' => $createdAt,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function canonical(): string
    {
        return self::canonicalPayload([
            'user_id' => $this->user_id,
            'action' => $this->action,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'result' => $this->result,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'metadata' => $this->metadata,
            'created_at' => $this->getRawOriginal('created_at'),
        ]);
    }
}
