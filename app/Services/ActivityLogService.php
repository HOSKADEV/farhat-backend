<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class ActivityLogService
{
    public function list(Request $request): LengthAwarePaginator
    {
        $query = ActivityLog::query();

        return QueryBuilder::for($query, $request)
            ->allowedFilters(
                AllowedFilter::exact('log_name'),
                AllowedFilter::exact('event'),
                AllowedFilter::exact('subject_type'),
                AllowedFilter::exact('subject_id'),
                AllowedFilter::exact('causer_type'),
                AllowedFilter::exact('causer_id'),
                AllowedFilter::callback('created_at_from', function ($query, string $value) {
                    $query->whereDate('created_at', '>=', $value);
                }),
                AllowedFilter::callback('created_at_to', function ($query, string $value) {
                    $query->whereDate('created_at', '<=', $value);
                }),
            )
            ->allowedSorts(
                AllowedSort::field('log_name'),
                AllowedSort::field('event'),
                AllowedSort::field('created_at'),
            )
            ->defaultSort('-created_at')
            ->paginate($request->integer('per_page', 15))
            ->appends($request->query());
    }
}
