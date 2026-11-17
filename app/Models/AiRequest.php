<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRequest extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'feature',
        'driver',
        'model',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'tokens_estimated',
        'latency_ms',
        'status',
        'error_code',
        'http_status',
        'error_message',
        'prompt_chars',
        'prompt_hash',
        'prompt',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'tokens_estimated' => 'boolean',
            'latency_ms' => 'integer',
            'http_status' => 'integer',
            'prompt_chars' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
