<?php

namespace App\Services\Ai;

use App\Models\Setting;

class AiSettings
{
    public const DEFAULTS = [
        'ai.enabled' => true,
        'ai.assistant_enabled' => true,
        'ai.summary_enabled' => true,
        'ai.daily_token_limit' => 20000,
        'ai.question_max_length' => 500,
    ];

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values ??= array_replace(
            self::DEFAULTS,
            Setting::query()
                ->whereIn('key', array_keys(self::DEFAULTS))
                ->get()
                ->mapWithKeys(fn (Setting $setting) => [$setting->key => $setting->value])
                ->all(),
        );
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public function enabledFor(string $feature): bool
    {
        // The connection test only needs the master switch; the feature switches must not block it.
        if ($feature === 'ai_test') {
            return (bool) $this->get('ai.enabled');
        }

        $flag = $feature === 'assistant' ? 'ai.assistant_enabled' : 'ai.summary_enabled';

        return (bool) $this->get('ai.enabled') && (bool) $this->get($flag);
    }

    public function dailyTokenLimit(): int
    {
        return max(1, (int) $this->get('ai.daily_token_limit'));
    }

    public function questionMaxLength(): int
    {
        return max(1, (int) $this->get('ai.question_max_length'));
    }

    /**
     * @param  array<string, mixed>  $values  Full setting keys to write.
     * @return array<string, array{old: mixed, new: mixed}> Only the fields that actually changed.
     */
    public function update(array $values): array
    {
        $changes = [];

        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                continue;
            }

            $old = $this->get($key);

            if ($old === $value) {
                continue;
            }

            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
            $changes[$key] = ['old' => $old, 'new' => $value];
        }

        $this->values = null;

        return $changes;
    }
}
