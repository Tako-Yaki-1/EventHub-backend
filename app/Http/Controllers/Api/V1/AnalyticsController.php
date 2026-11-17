<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnalyticsRequest;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;

class AnalyticsController extends Controller
{
    public function summary(AnalyticsService $analytics): JsonResponse
    {
        return response()->json($analytics->summary());
    }

    public function registrationsOverTime(AnalyticsRequest $request, AnalyticsService $analytics): JsonResponse
    {
        $days = (int) ($request->validated('days') ?? 30);

        return response()->json([
            'days' => $days,
            'data' => $analytics->registrationsOverTime($days),
        ]);
    }

    public function eventsPerformance(AnalyticsService $analytics): JsonResponse
    {
        return response()->json(['data' => $analytics->eventsPerformance()]);
    }

    public function topEvents(AnalyticsRequest $request, AnalyticsService $analytics): JsonResponse
    {
        $limit = (int) ($request->validated('limit') ?? 5);

        return response()->json(['data' => $analytics->topEvents($limit)]);
    }

    public function byYearSection(AnalyticsService $analytics): JsonResponse
    {
        return response()->json(['data' => $analytics->byYearSection()]);
    }

    public function insights(AnalyticsService $analytics): JsonResponse
    {
        return response()->json(['data' => $analytics->insights()]);
    }
}
