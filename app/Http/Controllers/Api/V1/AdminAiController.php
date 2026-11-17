<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAiSettingsRequest;
use App\Models\AiRequest;
use App\Models\User;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AnalyticsSummaryService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAiController extends Controller
{
    public function settings(AiSettings $settings): JsonResponse
    {
        return response()->json(['data' => $this->payload($settings)]);
    }

    public function updateSettings(
        UpdateAiSettingsRequest $request,
        AiSettings $settings,
        AuditLogger $audit,
    ): JsonResponse {
        $map = [
            'enabled' => 'ai.enabled',
            'assistant_enabled' => 'ai.assistant_enabled',
            'summary_enabled' => 'ai.summary_enabled',
            'daily_token_limit' => 'ai.daily_token_limit',
            'question_max_length' => 'ai.question_max_length',
        ];

        $values = collect($request->validated())
            ->mapWithKeys(fn ($value, $key) => [$map[$key] => $value])
            ->all();

        $changes = $settings->update($values);

        if ($changes !== []) {
            $audit->record('ai.settings_changed', user: $request->user(), metadata: [
                'changed' => $changes,
            ]);
        }

        return response()->json([
            'data' => $this->payload($settings),
            'changed' => array_keys($changes),
        ]);
    }

    public function usage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        $days = (int) ($validated['days'] ?? 30);
        $from = now()->subDays($days - 1)->startOfDay();

        $rows = fn () => AiRequest::query()->where('created_at', '>=', $from);

        $totals = $rows()
            ->selectRaw('count(*) as requests, coalesce(sum(total_tokens), 0) as tokens')
            ->first();

        $byDay = $rows()
            ->selectRaw('DATE(created_at) as day, count(*) as requests, coalesce(sum(total_tokens), 0) as tokens')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'day' => $row->day,
                'requests' => (int) $row->requests,
                'tokens' => (int) $row->tokens,
            ])
            ->values();

        $byFeature = $rows()
            ->selectRaw('feature, count(*) as requests, coalesce(sum(total_tokens), 0) as tokens')
            ->groupBy('feature')
            ->orderByDesc('tokens')
            ->get()
            ->map(fn ($row) => [
                'feature' => $row->feature,
                'requests' => (int) $row->requests,
                'tokens' => (int) $row->tokens,
            ])
            ->values();

        $byStatus = $rows()
            ->selectRaw('status, count(*) as requests')
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status,
                'requests' => (int) $row->requests,
            ])
            ->values();

        $topUsers = $rows()
            ->whereNotNull('user_id')
            ->selectRaw('user_id, count(*) as requests, coalesce(sum(total_tokens), 0) as tokens')
            ->groupBy('user_id')
            ->orderByDesc('tokens')
            ->limit(5)
            ->get();

        $names = User::query()->whereIn('id', $topUsers->pluck('user_id'))->pluck('name', 'id');

        return response()->json([
            'days' => $days,
            'totals' => [
                'requests' => (int) ($totals->requests ?? 0),
                'tokens' => (int) ($totals->tokens ?? 0),
            ],
            'by_day' => $byDay,
            'by_feature' => $byFeature,
            'by_status' => $byStatus,
            'top_users' => $topUsers
                ->map(fn ($row) => [
                    'user_id' => (int) $row->user_id,
                    'name' => $names[$row->user_id] ?? 'Deleted user',
                    'requests' => (int) $row->requests,
                    'tokens' => (int) $row->tokens,
                ])
                ->values(),
        ]);
    }

    public function summary(Request $request, AnalyticsSummaryService $summary): JsonResponse
    {
        $result = $summary->summarize($request->user());

        if ($result['limited'] ?? false) {
            return response()->json(['message' => $result['message']], 429);
        }

        return response()->json($result);
    }

    public function test(Request $request, AiGateway $ai): JsonResponse
    {
        $result = $ai->call(
            $request->user(),
            'ai_test',
            'Reply with the single word OK.',
            [['role' => 'user', 'content' => 'Reply with the single word OK.']],
            20,
        );

        return response()->json([
            'ok' => $result->successful(),
            'error_code' => $result->errorCode,
            'http_status' => $result->httpStatus,
            'latency_ms' => $result->latencyMs,
            'model' => (string) config('ai.model'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AiSettings $settings): array
    {
        return [
            'enabled' => (bool) $settings->get('ai.enabled'),
            'assistant_enabled' => (bool) $settings->get('ai.assistant_enabled'),
            'summary_enabled' => (bool) $settings->get('ai.summary_enabled'),
            'daily_token_limit' => $settings->dailyTokenLimit(),
            'question_max_length' => $settings->questionMaxLength(),
            'driver' => (string) config('ai.driver'),
            'model' => (string) config('ai.model'),
            'configured' => in_array(config('ai.driver'), ['anthropic', 'openai'], true)
                && filled(config('ai.api_key'))
                && filled(config('ai.model')),
            'last_error' => $this->lastError(),
        ];
    }

    /**
     * The most recent provider-side failure, for the admin status line.
     *
     * @return array{code: string, http_status: int|null, message: string|null, at: string|null}|null
     */
    private function lastError(): ?array
    {
        $row = AiRequest::query()
            ->whereIn('error_code', [
                'auth_failed', 'model_not_found', 'rate_limited_upstream', 'provider_error',
                'bad_request', 'timeout', 'invalid_response', 'empty_response',
            ])
            ->latest('id')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'code' => (string) $row->error_code,
            'http_status' => $row->http_status,
            'message' => $row->error_message,
            'at' => $row->created_at?->toIso8601String(),
        ];
    }
}
