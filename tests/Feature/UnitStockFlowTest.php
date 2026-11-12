<?php

use App\Enums\Role as RoleEnum;
use App\Http\Requests\Web\Purchase\StorePurchaseRequest;
use App\Models\Brand;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Color;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\Type;
use App\Models\Unit;
use App\Models\Wilaya;
use App\Services\InventoryTransferService;
use App\Services\PurchaseService;
use App\Services\RestockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach ([RoleEnum::ADMIN, RoleEnum::MANAGER, RoleEnum::EMPLOYEE] as $role) {
        \Spatie\Permission\Models\Role::findOrCreate($role->value, 'web');
    }

    $wilaya = Wilaya::create(['name' => 'Alger']);

    $this->branch = Branch::create([
        'wilaya_id' => $wilaya->id,
        'name'      => 'Main',
        'code'      => 'M',
    ]);

    $this->otherBranch = Branch::create([
        'wilaya_id' => $wilaya->id,
        'name'      => 'Second',
        'code'      => 'S',
    ]);

    $this->inventory = Inventory::create([
        'branch_id' => $this->branch->id,
        'name'      => 'Main inventory',
    ]);

    $this->sourceInventory = Inventory::create([
        'branch_id' => $this->otherBranch->id,
        'name'      => 'Source inventory',
    ]);

    $category = Category::create(['name' => 'Category']);
    $type     = Type::create(['category_id' => $category->id, 'name' => 'Type']);
    $color    = Color::create(['name' => 'Red', 'code' => '#f00']);
    $brand    = Brand::create(['name' => 'Brand']);

    $this->product = Product::create([
        'type_id'  => $type->id,
        'color_id' => $color->id,
        'brand_id' => $brand->id,
        'name'     => 'Product',
    ]);

    $this->supplier = Supplier::create([
        'wilaya_id' => $wilaya->id,
        'name'      => 'Supplier',
        'phone'     => '0000000000',
        'address'   => 'Address',
    ]);

    $this->piece = Unit::query()->where('is_default', true)->firstOrFail();
    $this->box   = Unit::create(['name' => 'Box', 'symbol' => 'box']);
});

it('requires a unit on every purchase item', function () {
    $request = StorePurchaseRequest::create('/v1/purchases', 'POST', [
        'branch_id'   => $this->branch->id,
        'supplier_id' => $this->supplier->id,
        'items'       => [[
            'product_id' => $this->product->id,
            'quantity'   => 1,
            'price'      => 100,
        ]],
    ]);

    $validator = Validator::make($request->all(), $request->rules());

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->keys())->toContain('items.0.unit_id');
});

it('rejects the same product twice with the same unit', function () {
    $request = StorePurchaseRequest::create('/v1/purchases', 'POST', [
        'branch_id'   => $this->branch->id,
        'supplier_id' => $this->supplier->id,
        'items'       => [
            ['product_id' => $this->product->id, 'unit_id' => $this->piece->id, 'quantity' => 1, 'price' => 100],
            ['product_id' => $this->product->id, 'unit_id' => $this->piece->id, 'quantity' => 2, 'price' => 100],
        ],
    ]);

    $validator = Validator::make($request->all(), $request->rules());

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->first('items'))->toBe(__('purchases.duplicate_product_unit'));
});

it('books a repeated product into separate stocks per unit on receive', function () {
    $purchase = app(PurchaseService::class)->create([
        'supplier_id' => $this->supplier->id,
        'branch_id'   => $this->branch->id,
        'items'       => [
            ['product_id' => $this->product->id, 'unit_id' => $this->piece->id, 'quantity' => 5, 'price' => 100],
            ['product_id' => $this->product->id, 'unit_id' => $this->box->id, 'quantity' => 3, 'price' => 200],
        ],
    ]);

    [$pieceItem, $boxItem] = $purchase->items()->orderBy('id')->get()->all();

    app(PurchaseService::class)->receive($purchase, [
        'inventory_id' => $this->inventory->id,
        'items'        => [
            ['purchase_item_id' => $pieceItem->id, 'selling_prices' => [150]],
            ['purchase_item_id' => $boxItem->id, 'selling_prices' => [300]],
        ],
    ]);

    $pieceStock = Stock::where('inventory_id', $this->inventory->id)
        ->where('product_id', $this->product->id)
        ->where('unit_id', $this->piece->id)
        ->firstOrFail();

    $boxStock = Stock::where('inventory_id', $this->inventory->id)
        ->where('product_id', $this->product->id)
        ->where('unit_id', $this->box->id)
        ->firstOrFail();

    expect((int) $pieceStock->batches()->sum('current_quantity'))->toBe(5)
        ->and((int) $boxStock->batches()->sum('current_quantity'))->toBe(3)
        ->and((float) $pieceStock->sellingPrice?->amount)->toBe(150.0)
        ->and((float) $boxStock->sellingPrice?->amount)->toBe(300.0);
});

it('defaults a restock item unit and keeps an explicit unit', function () {
    $restock = app(RestockService::class)->create([
        'branch_id' => $this->branch->id,
        'items'     => [
            ['product_id' => $this->product->id, 'requested_quantity' => 4],
            ['product_id' => $this->product->id, 'unit_id' => $this->box->id, 'requested_quantity' => 2],
        ],
    ]);

    $items = $restock->items()->orderBy('id')->get();

    expect($items[0]->unit_id)->toBe($this->piece->id)
        ->and($items[1]->unit_id)->toBe($this->box->id);
});

it('fulfills a restock with a different unit and updates the matching line on receive', function () {
    $restock = app(RestockService::class)->create([
        'branch_id' => $this->branch->id,
        'items'     => [[
            'product_id'         => $this->product->id,
            'unit_id'            => $this->piece->id,
            'requested_quantity' => 2,
        ]],
    ]);

    $line = $restock->items()->firstOrFail();

    $fulfilled = app(RestockService::class)->fulfill($restock, [
        'type'        => 'purchase',
        'supplier_id' => $this->supplier->id,
        'items'       => [[
            'restock_item_id' => $line->id,
            'price'           => 100,
            'unit_id'         => $this->box->id,
        ]],
    ]);

    $purchase = $fulfilled->fulfilledWith;

    expect($purchase)->toBeInstanceOf(Purchase::class);

    $purchaseItem = $purchase->items()->firstOrFail();

    expect($purchaseItem->unit_id)->toBe($this->box->id)
        ->and($purchaseItem->restock_item_id)->toBe($line->id);

    app(PurchaseService::class)->receive($purchase, [
        'inventory_id' => $this->inventory->id,
    ]);

    expect($line->fresh()->fulfilled_quantity)->toBe(2);

    $stock = Stock::where('inventory_id', $this->inventory->id)
        ->where('product_id', $this->product->id)
        ->where('unit_id', $this->box->id)
        ->firstOrFail();

    expect((int) $stock->batches()->sum('current_quantity'))->toBe(2);
});

it('fulfills a restock via transfer from stock in the chosen unit', function () {
    $sourceStock = Stock::create([
        'inventory_id' => $this->sourceInventory->id,
        'product_id'   => $this->product->id,
        'unit_id'      => $this->box->id,
    ]);

    $sourceStock->batches()->create([
        'purchase_price'   => 100,
        'initial_quantity' => 10,
        'current_quantity' => 10,
    ]);

    $restock = app(RestockService::class)->create([
        'branch_id' => $this->branch->id,
        'items'     => [[
            'product_id'         => $this->product->id,
            'unit_id'            => $this->piece->id,
            'requested_quantity' => 3,
        ]],
    ]);

    $line = $restock->items()->firstOrFail();

    $fulfilled = app(RestockService::class)->fulfill($restock, [
        'type'              => 'transfer',
        'from_inventory_id' => $this->sourceInventory->id,
        'items'             => [[
            'restock_item_id' => $line->id,
            'unit_id'         => $this->box->id,
        ]],
    ]);

    $transfer = $fulfilled->fulfilledWith;
    $transferItem = $transfer->items()->firstOrFail();

    expect($transferItem->stock_id)->toBe($sourceStock->id)
        ->and($transferItem->restock_item_id)->toBe($line->id);

    app(InventoryTransferService::class)->dispatch($transfer);
    app(InventoryTransferService::class)->receive($transfer);

    $destinationStock = Stock::where('inventory_id', $this->inventory->id)
        ->where('product_id', $this->product->id)
        ->where('unit_id', $this->box->id)
        ->firstOrFail();

    expect((int) $destinationStock->batches()->sum('current_quantity'))->toBe(3)
        ->and($line->fresh()->fulfilled_quantity)->toBe(3);
});

it('preserves the stock unit on a direct inventory transfer', function () {
    $sourceStock = Stock::create([
        'inventory_id' => $this->sourceInventory->id,
        'product_id'   => $this->product->id,
        'unit_id'      => $this->box->id,
    ]);

    $sourceStock->batches()->create([
        'purchase_price'   => 100,
        'initial_quantity' => 5,
        'current_quantity' => 5,
    ]);

    $service = app(InventoryTransferService::class);

    $transfer = $service->create([
        'from_inventory_id' => $this->sourceInventory->id,
        'to_inventory_id'   => $this->inventory->id,
        'items'             => [[
            'stock_id' => $sourceStock->id,
            'quantity' => 2,
        ]],
    ]);

    $service->dispatch($transfer);
    $service->receive($transfer);

    $destinationStock = Stock::where('inventory_id', $this->inventory->id)
        ->where('product_id', $this->product->id)
        ->where('unit_id', $this->box->id)
        ->firstOrFail();

    expect((int) $destinationStock->batches()->sum('current_quantity'))->toBe(2);
});
