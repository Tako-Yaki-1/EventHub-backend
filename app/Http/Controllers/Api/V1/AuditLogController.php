<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListAuditLogsRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(ListAuditLogsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when(isset($filters['action']), fn ($query) => $query
                ->where('action', 'like', $filters['action'].'%'))
            ->when(isset($filters['user_id']), fn ($query) => $query
                ->where('user_id', $filters['user_id']))
            ->when(isset($filters['result']), fn ($query) => $query
                ->where('result', $filters['result']))
            ->when(isset($filters['from']), fn ($query) => $query
                ->whereDate('created_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query
                ->whereDate('created_at', '<=', $filters['to']))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return AuditLogResource::collection($logs);
    }

    public function verify(Request $request, AuditLogger $logger): JsonResponse
    {
        $result = $logger->verifyChain();

        $logger->record(
            'admin.audit_verified',
            result: $result['intact'] ? 'success' : 'failed',
            user: $request->user(),
            metadata: [
                'checked' => $result['checked'],
                'intact' => $result['intact'],
                'broken_at' => $result['broken_at'],
            ],
        );

        return response()->json($result);
    }
}
