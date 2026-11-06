<?php

namespace App\Http\Controllers\Web;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Unit\StoreUnitRequest;
use App\Http\Requests\Web\Unit\UpdateUnitRequest;
use App\Http\Resources\Web\UnitResource;
use App\Models\Unit;
use App\Services\UnitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    public function __construct(private readonly UnitService $unitService) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->authorizePermission(Permission::VIEW_UNITS->value)) {
            return $response;
        }

        return $this->successResponse(UnitResource::collection($this->unitService->list($request)));
    }

    public function store(StoreUnitRequest $request): JsonResponse
    {
        if ($response = $this->authorizePermission(Permission::CREATE_UNITS->value)) {
            return $response;
        }

        try {
            $unit = $this->unitService->create($this->validateRequest($request));

            return $this->successResponse(new UnitResource($unit), statusCode: 201);
        } catch (\Exception $e) {
            return $this->errorResponse(message: $e->getMessage(), statusCode: $e->getCode() ?: 400);
        }
    }

    public function show(Unit $unit): JsonResponse
    {
        if ($response = $this->authorizePermission(Permission::VIEW_UNITS->value)) {
            return $response;
        }

        try {
            return $this->successResponse(new UnitResource($this->unitService->show($unit)));
        } catch (\Exception $e) {
            return $this->errorResponse(message: $e->getMessage(), statusCode: $e->getCode() ?: 400);
        }
    }

    public function update(UpdateUnitRequest $request, Unit $unit): JsonResponse
    {
        if ($response = $this->authorizePermission(Permission::UPDATE_UNITS->value)) {
            return $response;
        }

        try {
            $unit = $this->unitService->update($unit, $this->validateRequest($request));

            return $this->successResponse(new UnitResource($unit));
        } catch (\Exception $e) {
            return $this->errorResponse(message: $e->getMessage(), statusCode: $e->getCode() ?: 400);
        }
    }

    public function destroy(Unit $unit): JsonResponse
    {
        if ($response = $this->authorizePermission(Permission::DELETE_UNITS->value)) {
            return $response;
        }

        try {
            $this->unitService->delete($unit);

            return $this->successResponse(message: __('app.deleted'));
        } catch (\Exception $e) {
            return $this->errorResponse(message: $e->getMessage(), statusCode: $e->getCode() ?: 400);
        }
    }
}
