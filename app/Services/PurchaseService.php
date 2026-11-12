<?php

namespace App\Services;

use App\Enums\PriceType;
use App\Enums\PurchaseStatus;
use App\Enums\NotificationType;
use App\Models\Inventory;
use App\Models\Price;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Restock;
use App\Models\Stock;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class PurchaseService
{

    use \App\Traits\NotifiesOnAction;

    public function __construct(private readonly InventoryService $inventoryService)
    {
    }
    public function list(Request $request): LengthAwarePaginator
    {
        $query = Purchase::query();

        $query->byUserBranches();

        return QueryBuilder::for($query, $request)
            ->with(['supplier', 'branch', 'items.product', 'items.unit', 'items.returnItems.purchaseReturn', 'inventory', 'returns.items.purchaseItem', 'payments', 'refunds'])
            ->allowedFilters(
                AllowedFilter::partial('reference'),
                AllowedFilter::exact('supplier_id'),
                AllowedFilter::exact('branch_id'),
                AllowedFilter::exact('inventory_id'),
                AllowedFilter::exact('status'),
                AllowedFilter::callback('payment_status', function ($query, string $value) {
                    $query->where(function ($q) use ($value) {
                        match ($value) {
                            'unpaid' => $q->where('paid_amount', '<=', 0),
                            'partially_paid' => $q->where('paid_amount', '>', 0)
                                ->whereColumn('paid_amount', '<', 'net_amount'),
                            'paid' => $q->whereColumn('paid_amount', '>=', 'net_amount'),
                            default => null,
                        };
                    });
                }),
                AllowedFilter::callback('created_at_from', function ($query, string $value) {
                    $query->whereDate('created_at', '>=', $value);
                }),
                AllowedFilter::callback('created_at_to', function ($query, string $value) {
                    $query->whereDate('created_at', '<=', $value);
                }),
                AllowedFilter::callback('search', function ($query, string $value) {
                    $query->where(function ($q) use ($value) {
                        $q->where('reference', 'like', "%{$value}%");
                    });
                }),
            )
            ->allowedSorts(
                AllowedSort::field('total_amount'),
                AllowedSort::field('created_at'),
            )
            ->defaultSort('-created_at')
            ->paginate($request->integer('per_page', 15))
            ->appends($request->query());
    }

    public function create(array $data): Purchase
    {
        return DB::transaction(function () use ($data) {
            $items = $data['items'];

            $totalAmount = collect($items)->sum(fn($item) => $item['quantity'] * $item['price']);

            $purchase = Purchase::create([
                'supplier_id' => $data['supplier_id'],
                'branch_id' => $data['branch_id'],
                'status' => PurchaseStatus::PENDING,
                'total_amount' => $totalAmount,
                'note' => $data['note'] ?? null,
            ]);

            $purchase->items()->createMany(
                collect($items)->map(fn($item) => [
                    'product_id' => $item['product_id'],
                    'unit_id' => $item['unit_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                ])->all()
            );

            $purchase->recalculateAmounts();

            $this->notifyCreated($purchase, NotificationType::PURCHASE_CREATED);

            return $purchase->loadMissing(['supplier', 'items.product', 'items.unit', 'returns.items.purchaseItem']);
        });
    }

    public function show(Purchase $purchase): Purchase
    {
        return $purchase->loadMissing(['supplier', 'items.product', 'items.unit', 'payments', 'refunds', 'inventory', 'returns.items.purchaseItem']);
    }

    public function update(Purchase $purchase, array $data): Purchase
    {
        if ($purchase->status !== PurchaseStatus::PENDING) {
            throw new Exception(__('purchases.not_pending'), 422);
        }

        return DB::transaction(function () use ($purchase, $data) {
            $purchase->update(array_filter([
                'supplier_id' => $data['supplier_id'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'note' => $data['note'] ?? null,
            ], fn($v) => $v !== null));

            if (array_key_exists('items', $data)) {
                $items = $data['items'];

                $totalAmount = collect($items)->sum(fn($item) => $item['quantity'] * $item['price']);

                // Keep restock lineage for lines that survive the edit.
                $restockLinks = $purchase->items()
                    ->whereNotNull('restock_item_id')
                    ->get()
                    ->keyBy(fn(PurchaseItem $item) => $item->product_id . ':' . $item->unit_id);

                $purchase->items()->delete();
                $purchase->items()->createMany(
                    collect($items)->map(fn($item) => [
                        'product_id' => $item['product_id'],
                        'unit_id' => $item['unit_id'],
                        'restock_item_id' => $restockLinks->get($item['product_id'] . ':' . $item['unit_id'])?->restock_item_id,
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                    ])->all()
                );

                $purchase->update(['total_amount' => $totalAmount]);

                $purchase->recalculateAmounts();
            }

            return $purchase->refresh()->loadMissing(['supplier', 'items.product', 'items.unit', 'payments', 'returns.items.purchaseItem']);
        });
    }

    public function delete(Purchase $purchase): void
    {
        if ($purchase->status !== PurchaseStatus::PENDING) {
            throw new Exception(__('purchases.not_pending'), 422);
        }

        $purchase->delete();
    }

    public function cancel(Purchase $purchase): Purchase
    {
        if ($purchase->status !== PurchaseStatus::PENDING) {
            throw new Exception(__('purchases.not_pending'), 422);
        }

        $purchase->update(['status' => PurchaseStatus::CANCELED]);

        $this->notifyAction($purchase, NotificationType::PURCHASE_CANCELLED, $purchase->branch_id);

        return $purchase->refresh()->loadMissing(['supplier', 'items.product', 'items.unit', 'payments', 'returns.items.purchaseItem']);
    }

    public function receive(Purchase $purchase, array $data): Purchase
    {
        if ($purchase->status !== PurchaseStatus::PENDING) {
            throw new Exception(__('purchases.not_pending'), 422);
        }

        return DB::transaction(function () use ($purchase, $data) {
            $inventoryId = $this->resolveInventoryId($purchase, $data['inventory_id'] ?? null);

            $purchase->update([
                'status' => PurchaseStatus::COMPLETED,
                'inventory_id' => $inventoryId,
            ]);

            // Index optional per-item pricing overrides by purchase item, falling back to product
            $pricingByItem = collect($data['items'] ?? [])->keyBy(
                fn (array $line) => ! empty($line['purchase_item_id'])
                    ? 'id:' . $line['purchase_item_id']
                    : 'product:' . $line['product_id'],
            );

            // Validate prices before processing
            $this->validatePrices($purchase, $inventoryId, $pricingByItem);

            // If this purchase was created from a restock, load the restock to update fulfilled quantities
            $restock = Restock::where('fulfilled_with_id', $purchase->id)
                ->where('fulfilled_with_type', Purchase::class)
                ->first();

            foreach ($purchase->items as $item) {
                $pricing = $this->pricingFor($pricingByItem, $item);

                $stock = Stock::firstOrCreate([
                    'inventory_id' => $inventoryId,
                    'product_id' => $item->product_id,
                    'unit_id' => $item->unit_id,
                ]);

                $oldQuantity = $stock->batches()->sum('current_quantity');

                $batch = $stock->batches()->create([
                    'source_id' => $item->id,
                    'source_type' => PurchaseItem::class,
                    'purchase_price' => $item->price,
                    'initial_quantity' => $item->quantity,
                    'current_quantity' => $item->quantity,
                ]);

                $prices = collect();
                foreach ($pricing['selling_prices'] ?? [] as $amount) {
                    $prices->push(new Price(['type' => PriceType::SELLING, 'amount' => $amount]));
                }
                foreach ($pricing['installment_prices'] ?? [] as $amount) {
                    $prices->push(new Price(['type' => PriceType::INSTALLMENT, 'amount' => $amount]));
                }

                if ($prices->isNotEmpty()) {
                    $stock->prices()->saveMany($prices);
                }

                $this->inventoryService->receive(
                    stockId: $stock->id,
                    inventoryId: $inventoryId,
                    productId: $item->product_id,
                    oldQuantity: $oldQuantity,
                    quantity: $item->quantity,
                    source: $purchase,
                    allocations: [
                        [
                            'batch_id'       => $batch->id,
                            'quantity'       => $item->quantity,
                            'purchase_price' => $item->price,
                        ],
                    ],
                );

                // Update restock fulfilled_quantity if linked
                if ($restock) {
                    $restockItem = $this->resolveRestockItem($restock, $item);

                    if ($restockItem) {
                        $restockItem->increment('fulfilled_quantity', $item->quantity);
                    }
                }
            }

            $this->notifyAction($purchase, NotificationType::PURCHASE_RECEIVED, $purchase->branch_id);

            return $purchase->refresh()->loadMissing(['supplier', 'items.product', 'items.unit', 'payments', 'returns.items.purchaseItem']);
        });
    }

    private function validatePrices(
        Purchase $purchase,
        int $inventoryId,
        \Illuminate\Support\Collection $pricingByItem,
    ): void
    {
        if ($pricingByItem->isEmpty()) {
            return;
        }

        $pricedItems = $purchase->items
            ->filter(fn (PurchaseItem $item) => $this->pricingFor($pricingByItem, $item) !== []);

        if ($pricedItems->isEmpty()) {
            return;
        }

        // Fetch max old-batch purchase prices in a single query, keyed by product and unit
        $maxOldBatchPrices = Stock::where('inventory_id', $inventoryId)
            ->whereIn('product_id', $pricedItems->pluck('product_id')->unique())
            ->whereIn('unit_id', $pricedItems->pluck('unit_id')->unique())
            ->join('batches', 'stocks.id', '=', 'batches.stock_id')
            ->where('batches.current_quantity', '>', 0)
            ->groupBy('stocks.product_id', 'stocks.unit_id')
            ->selectRaw('stocks.product_id, stocks.unit_id, MAX(batches.purchase_price) as max_price')
            ->get()
            ->keyBy(fn ($row) => $row->product_id . ':' . $row->unit_id);

        foreach ($purchase->items as $item) {
            $pricing = $this->pricingFor($pricingByItem, $item);

            if ($pricing === []) {
                continue;
            }

            $maxOldBatchPrice = (float) ($maxOldBatchPrices->get($item->product_id . ':' . $item->unit_id)?->max_price ?? 0);

            foreach ($pricing['selling_prices'] ?? [] as $amount) {
                $amount = (float) $amount;

                if ($amount <= $item->price) {
                    throw new Exception(__('purchases.selling_price_below_purchase', [
                        'price' => $item->price,
                    ]), 422);
                }
                if ($maxOldBatchPrice > 0 && $amount <= $maxOldBatchPrice) {
                    throw new Exception(__('purchases.selling_price_below_old_batches', [
                        'price' => $maxOldBatchPrice,
                    ]), 422);
                }
            }

            foreach ($pricing['installment_prices'] ?? [] as $amount) {
                $amount = (float) $amount;

                if ($amount <= $item->price) {
                    throw new Exception(__('purchases.installment_price_below_purchase', [
                        'price' => $item->price,
                    ]), 422);
                }
                if ($maxOldBatchPrice > 0 && $amount <= $maxOldBatchPrice) {
                    throw new Exception(__('purchases.installment_price_below_old_batches', [
                        'price' => $maxOldBatchPrice,
                    ]), 422);
                }
            }
        }
    }

    /**
     * Resolve a receive pricing override for a purchase line, preferring an
     * explicit purchase_item_id and falling back to the legacy product_id key.
     */
    private function pricingFor(\Illuminate\Support\Collection $pricingByItem, PurchaseItem $item): array
    {
        return $pricingByItem->get('id:' . $item->id)
            ?? $pricingByItem->get('product:' . $item->product_id)
            ?? [];
    }

    /**
     * Find the restock line a received purchase item fulfills, tolerating
     * purchases created before restock_item_id was recorded.
     */
    private function resolveRestockItem(Restock $restock, PurchaseItem $item): ?\App\Models\RestockItem
    {
        if ($item->restock_item_id) {
            return $restock->items()->whereKey($item->restock_item_id)->first();
        }

        return $restock->items()
            ->where('product_id', $item->product_id)
            ->where('unit_id', $item->unit_id)
            ->first()
            ?? $restock->items()->where('product_id', $item->product_id)->first();
    }

    private function resolveInventoryId(Purchase $purchase, ?int $inventoryId): int
    {
        if ($inventoryId) {
            $inventory = Inventory::findOrFail($inventoryId);

            if ($purchase->branch_id && $inventory->branch_id !== $purchase->branch_id) {
                throw new Exception(__('purchases.inventory_branch_mismatch'), 422);
            }

            return $inventoryId;
        }

        $branchInventories = Inventory::where('branch_id', $purchase->branch_id)->get();

        if ($branchInventories->isEmpty()) {
            throw new Exception(__('purchases.no_branch_inventories'), 422);
        }

        if ($branchInventories->count() > 1) {
            throw new Exception(__('purchases.multiple_branch_inventories'), 422);
        }

        return $branchInventories->first()->id;
    }

}
