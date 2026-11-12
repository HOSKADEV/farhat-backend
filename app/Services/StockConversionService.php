<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockConversion;
use App\Models\Unit;
use Exception;
use Illuminate\Support\Facades\DB;

class StockConversionService
{
    public function __construct(private readonly InventoryService $inventoryService) {}

    /**
     * Manually move stock of one product from one unit to another within the
     * same inventory, using the product's configured conversion factors.
     */
    public function convert(array $data): StockConversion
    {
        return DB::transaction(function () use ($data) {
            $inventory = Inventory::findOrFail($data['inventory_id']);
            $product = Product::with('unitConversions')->findOrFail($data['product_id']);
            $fromUnit = Unit::findOrFail($data['from_unit_id']);
            $toUnit = Unit::findOrFail($data['to_unit_id']);

            if ($fromUnit->id === $toUnit->id) {
                throw new Exception(__('stock_conversions.same_unit'), 422);
            }

            $fromQuantity = round((float) $data['quantity'], 2);

            if (! $fromUnit->acceptsQuantity($fromQuantity)) {
                throw new Exception(__('stock_conversions.invalid_source_quantity', [
                    'unit' => $fromUnit->name,
                ]), 422);
            }

            $fromFactor = $this->factorFor($product, $fromUnit);
            $toFactor = $this->factorFor($product, $toUnit);

            $exactToQuantity = $fromQuantity * $fromFactor / $toFactor;

            if (! $toUnit->acceptsQuantity($exactToQuantity)) {
                throw new Exception(__('stock_conversions.invalid_destination_quantity', [
                    'unit' => $toUnit->name,
                ]), 422);
            }

            $toQuantity = round($exactToQuantity, 2);

            if ($toQuantity <= 0) {
                throw new Exception(__('stock_conversions.invalid_destination_quantity', [
                    'unit' => $toUnit->name,
                ]), 422);
            }

            $sourceStock = Stock::where('inventory_id', $inventory->id)
                ->where('product_id', $product->id)
                ->where('unit_id', $fromUnit->id)
                ->lockForUpdate()
                ->first();

            $batches = $sourceStock
                ? $sourceStock->batches()
                    ->where('current_quantity', '>', 0)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                : collect();

            $availableQuantity = round((float) $batches->sum('current_quantity'), 2);

            if (! $sourceStock || $availableQuantity + 1e-9 < $fromQuantity) {
                throw new Exception(__('stock_conversions.insufficient_stock', [
                    'available' => $availableQuantity,
                    'unit'      => $fromUnit->symbol,
                ]), 422);
            }

            [$allocations, $sourceValue] = $this->consumeBatches($batches, $fromQuantity);

            $destinationStock = Stock::firstOrCreate([
                'inventory_id' => $inventory->id,
                'product_id'   => $product->id,
                'unit_id'      => $toUnit->id,
            ]);

            $destinationOldQuantity = round(
                (float) $destinationStock->batches()->sum('current_quantity'),
                2,
            );

            [$unitCost, $totalCost] = $this->resolveCosts($data['unit_cost'] ?? null, $toQuantity, $sourceValue);

            $conversion = StockConversion::create([
                'inventory_id'  => $inventory->id,
                'product_id'    => $product->id,
                'from_stock_id' => $sourceStock->id,
                'to_stock_id'   => $destinationStock->id,
                'from_unit_id'  => $fromUnit->id,
                'to_unit_id'    => $toUnit->id,
                'from_factor'   => $fromFactor,
                'to_factor'     => $toFactor,
                'from_quantity' => $fromQuantity,
                'to_quantity'   => $toQuantity,
                'unit_cost'     => $unitCost,
                'total_cost'    => $totalCost,
                'note'          => $data['note'] ?? null,
            ]);

            $destinationBatch = $destinationStock->batches()->create([
                'source_id'        => $conversion->id,
                'source_type'      => StockConversion::class,
                'purchase_price'   => $unitCost,
                'initial_quantity' => $toQuantity,
                'current_quantity' => $toQuantity,
            ]);

            $this->inventoryService->manual(
                stockId: $sourceStock->id,
                inventoryId: $inventory->id,
                productId: $product->id,
                oldQuantity: $availableQuantity,
                quantity: -$fromQuantity,
                source: $conversion,
                allocations: $allocations,
            );

            $this->inventoryService->manual(
                stockId: $destinationStock->id,
                inventoryId: $inventory->id,
                productId: $product->id,
                oldQuantity: $destinationOldQuantity,
                quantity: $toQuantity,
                source: $conversion,
                allocations: [[
                    'batch_id'       => $destinationBatch->id,
                    'quantity'       => $toQuantity,
                    'purchase_price' => $unitCost,
                ]],
            );

            return $conversion->load([
                'inventory', 'product', 'fromStock.unit', 'toStock.unit',
                'fromUnit', 'toUnit', 'movements',
            ]);
        });
    }

    /**
     * Deduct the requested quantity from the oldest batches first.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: float}
     */
    private function consumeBatches(\Illuminate\Support\Collection $batches, float $quantity): array
    {
        $remaining = $quantity;
        $allocations = [];
        $value = 0.0;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $deduct = round(min($remaining, (float) $batch->current_quantity), 2);

            if ($deduct <= 0) {
                continue;
            }

            $batch->decrement('current_quantity', $deduct);

            $allocations[] = [
                'batch_id'       => $batch->id,
                'quantity'       => -$deduct,
                'purchase_price' => $batch->purchase_price,
            ];

            $value += $deduct * (float) $batch->purchase_price;
            $remaining = round($remaining - $deduct, 2);
        }

        return [$allocations, round($value, 2)];
    }

    /**
     * Calculate the destination unit cost, honouring an explicit override.
     *
     * @return array{0: float, 1: float}
     */
    private function resolveCosts(mixed $unitCostOverride, float $toQuantity, float $sourceValue): array
    {
        if ($unitCostOverride !== null && $unitCostOverride !== '') {
            $unitCost = round((float) $unitCostOverride, 2);

            return [$unitCost, round($unitCost * $toQuantity, 2)];
        }

        return [
            $toQuantity > 0 ? round($sourceValue / $toQuantity, 2) : 0.0,
            round($sourceValue, 2),
        ];
    }

    private function factorFor(Product $product, Unit $unit): float
    {
        $factor = $product->conversionFactorFor($unit->id);

        if ($factor === null || $factor <= 0) {
            throw new Exception(__('stock_conversions.unit_not_configured', [
                'product' => $product->name,
                'unit'    => $unit->name,
            ]), 422);
        }

        return $factor;
    }
}
