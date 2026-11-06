<?php

namespace App\Services;

use App\Models\Unit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class UnitService
{
    public function list(Request $request): LengthAwarePaginator
    {
        return QueryBuilder::for(Unit::class, $request)
            ->allowedFilters(
                AllowedFilter::partial('name'),
                AllowedFilter::partial('symbol'),
                AllowedFilter::exact('is_default'),
                AllowedFilter::callback('created_at_from', function ($query, string $value) {
                    $query->whereDate('created_at', '>=', $value);
                }),
                AllowedFilter::callback('created_at_to', function ($query, string $value) {
                    $query->whereDate('created_at', '<=', $value);
                }),
                AllowedFilter::callback('search', function ($query, string $value) {
                    $query->where(function ($q) use ($value) {
                        $q->where('name', 'like', "%{$value}%")
                          ->orWhere('symbol', 'like', "%{$value}%");
                    });
                }),
            )
            ->allowedSorts(
                AllowedSort::field('name'),
                AllowedSort::field('symbol'),
                AllowedSort::field('created_at'),
            )
            ->defaultSort('name')
            ->paginate($request->integer('per_page', 15))
            ->appends($request->query());
    }

    public function create(array $data): Unit
    {
        return Unit::create([
            'name'   => $data['name'],
            'symbol' => $data['symbol'],
        ]);
    }

    public function show(Unit $unit): Unit
    {
        return $unit;
    }

    public function update(Unit $unit, array $data): Unit
    {
        $unit->update([
            'name'   => $data['name'],
            'symbol' => $data['symbol'],
        ]);

        return $unit->refresh();
    }

    public function delete(Unit $unit): void
    {
        if ($unit->is_default) {
            throw new \RuntimeException(__('units.cannot_delete_default'), 422);
        }

        $unit->delete();
    }
}
