<?php

namespace App\Services\Ai;

/**
 * Providers are asked for JSON in the prompt, not with a JSON mode. Markdown
 * fences and a short preamble are common, so accept both before giving up.
 */
final class AiJson
{
    /**
     * @return array<string, mixed>|null
     */
    public static function decode(string $text): ?array
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        $text = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text));

        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $text, $match) === 1) {
            $decoded = json_decode($match[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
