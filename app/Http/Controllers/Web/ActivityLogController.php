<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Web\ActivityLogResource;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function __construct(private readonly ActivityLogService $activityLogService) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->authorizePermission(Permission::VIEW_LOGS->value)) {
            return $response;
        }

        return $this->successResponse(
            ActivityLogResource::collection($this->activityLogService->list($request)),
        );
    }
}
