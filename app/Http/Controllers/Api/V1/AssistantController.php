<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AskAssistantRequest;
use App\Services\Ai\AssistantService;
use Illuminate\Http\JsonResponse;

class AssistantController extends Controller
{
    public function ask(AskAssistantRequest $request, AssistantService $assistant): JsonResponse
    {
        $result = $assistant->ask($request->user(), (string) $request->validated('question'));

        if ($result['limited'] ?? false) {
            return response()->json(['message' => $result['message']], 429);
        }

        return response()->json($result);
    }
}
