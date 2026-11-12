<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class ProductService
{
    public function list(Request $request): LengthAwarePaginator
    {
        return QueryBuilder::for(Product::class, $request)
            ->with(['type', 'color', 'brand', 'baseUnit', 'unitConversions.unit'])
            ->allowedFilters(
                AllowedFilter::partial('name'),
                AllowedFilter::partial('barcode'),
                AllowedFilter::exact('type_id'),
                AllowedFilter::exact('color_id'),
                AllowedFilter::exact('brand_id'),
                AllowedFilter::callback('created_at_from', function ($query, string $value) {
                    $query->whereDate('created_at', '>=', $value);
                }),
                AllowedFilter::callback('created_at_to', function ($query, string $value) {
                    $query->whereDate('created_at', '<=', $value);
                }),
                AllowedFilter::callback('search', function ($query, string $value) {
                    $query->where(function ($q) use ($value) {
                        $q->where('name', 'like', "%{$value}%")
                          ->orWhere('barcode', 'like', "%{$value}%");
                    });
                }),
            )
            ->allowedSorts(
                AllowedSort::field('name'),
                AllowedSort::field('created_at'),
            )
            ->defaultSort('-created_at')
            ->paginate($request->integer('per_page', 15))
            ->appends($request->query());
    }

    public function create(array $data, Request $request): Product
    {
        $product = DB::transaction(function () use ($data) {
            $product = Product::create([
                'type_id'      => $data['type_id'] ?? null,
                'color_id'     => $data['color_id'] ?? null,
                'brand_id'     => $data['brand_id'] ?? null,
                'name'         => $data['name'],
                'base_unit_id' => $data['base_unit_id'] ?? null,
                'barcode'      => $data['barcode'] ?? null,
                'image'        => '',
                'min_quantity' => $data['min_quantity'] ?? null,
            ]);

            $this->syncUnitConversions($product, $data);

            return $product;
        });

        if ($request->hasFile('image')) {
            $media = $product->addMediaFromRequest('image')->toMediaCollection('image');
            $product->update(['image' => $media->getUrl()]);
        }

        return $product->load(['type', 'color', 'brand', 'baseUnit', 'unitConversions.unit']);
    }

    public function show(Product $product): Product
    {
        return $product->loadMissing(['type', 'color', 'brand', 'baseUnit', 'unitConversions.unit']);
    }

    public function update(Product $product, array $data, Request $request): Product
    {
        DB::transaction(function () use ($product, $data) {
            $product->update(array_intersect_key($data, array_flip([
                'type_id',
                'color_id',
                'brand_id',
                'name',
                'base_unit_id',
                'barcode',
                'min_quantity',
            ])));

            $this->syncUnitConversions($product, $data);
        });

        if ($request->hasFile('image')) {
            $product->clearMediaCollection('image');
            $product->addMediaFromRequest('image')->toMediaCollection('image');
        }

        return $product->refresh()->loadMissing(['type', 'color', 'brand', 'baseUnit', 'unitConversions.unit']);
    }

    public function delete(Product $product): void
    {
        $product->delete();
    }

    /**
     * Update unit conversion factors for several products in a single atomic
     * operation. Each entry replaces that product's existing factors.
     */
    public function bulkUpdateUnitConversions(array $items): \Illuminate\Support\Collection
    {
        return DB::transaction(function () use ($items) {
            $products = collect();

            foreach ($items as $item) {
                $product = Product::findOrFail($item['product_id']);

                $product->update(['base_unit_id' => $item['base_unit_id']]);

                $this->syncUnitConversions($product, $item);

                $products->push($product->refresh()->load([
                    'type', 'color', 'brand', 'baseUnit', 'unitConversions.unit',
                ]));
            }

            return $products;
        });
    }

    /**
     * Replace the product's unit factors when they are supplied. The base unit
     * always maps to itself with a factor of one.
     */
    private function syncUnitConversions(Product $product, array $data): void
    {
        $hasConversions = array_key_exists('unit_conversions', $data);

        if (! $hasConversions && ! array_key_exists('base_unit_id', $data)) {
            return;
        }

        $baseUnitId = $product->base_unit_id;

        if ($hasConversions && $baseUnitId === null) {
            throw new \RuntimeException(__('stock_conversions.base_unit_required'), 422);
        }

        if ($hasConversions) {
            $product->unitConversions()->delete();

            foreach ($data['unit_conversions'] as $conversion) {
                $product->unitConversions()->create([
                    'unit_id' => $conversion['unit_id'],
                    'factor'  => (int) $conversion['unit_id'] === (int) $baseUnitId ? 1 : $conversion['factor'],
                ]);
            }
        }

        if ($baseUnitId !== null) {
            $product->unitConversions()->updateOrCreate(
                ['unit_id' => $baseUnitId],
                ['factor'  => 1],
            );
        }
    }
}
