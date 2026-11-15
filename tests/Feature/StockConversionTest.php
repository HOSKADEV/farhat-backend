<?php

use App\Enums\InventoryMovementType;
use App\Enums\Role as RoleEnum;
use App\Http\Requests\Web\Product\BulkUpdateUnitConversionsRequest;
use App\Http\Requests\Web\Stock\ConvertStockRequest;
use App\Models\Brand;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Color;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockConversion;
use App\Models\Type;
use App\Models\Unit;
use App\Models\Wilaya;
use App\Services\ProductService;
use App\Services\StockConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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

    $this->inventory = Inventory::create([
        'branch_id' => $this->branch->id,
        'name'      => 'Main inventory',
    ]);

    $category = Category::create(['name' => 'Category']);
    $type     = Type::create(['category_id' => $category->id, 'name' => 'Type']);
    $color    = Color::create(['name' => 'Red', 'code' => '#f00']);
    $brand    = Brand::create(['name' => 'Brand']);

    $this->piece = Unit::query()->where('is_default', true)->firstOrFail();
    $this->box   = Unit::create(['name' => 'Box', 'symbol' => 'box']);
    $this->gram  = Unit::create(['name' => 'Gram', 'symbol' => 'g', 'allows_decimals' => true]);
    $this->kilo  = Unit::create(['name' => 'Kilogram', 'symbol' => 'kg', 'allows_decimals' => true]);

    $this->product = Product::create([
        'type_id'      => $type->id,
        'color_id'     => $color->id,
        'brand_id'     => $brand->id,
        'name'         => 'Product',
        'base_unit_id' => $this->piece->id,
    ]);

    $this->product->unitConversions()->createMany([
        ['unit_id' => $this->piece->id, 'factor' => 1],
        ['unit_id' => $this->box->id, 'factor' => 12],
    ]);

    $this->weight = Product::create([
        'type_id'      => $type->id,
        'color_id'     => $color->id,
        'brand_id'     => $brand->id,
        'name'         => 'Weight product',
        'base_unit_id' => $this->kilo->id,
    ]);

    $this->weight->unitConversions()->createMany([
        ['unit_id' => $this->kilo->id, 'factor' => 1],
        ['unit_id' => $this->gram->id, 'factor' => 0.001],
    ]);
});

function stockWithBatch(Product $product, Inventory $inventory, Unit $unit, array $batches): Stock
{
    $stock = Stock::create([
        'inventory_id' => $inventory->id,
        'product_id'   => $product->id,
        'unit_id'      => $unit->id,
    ]);

    foreach ($batches as [$quantity, $price]) {
        $stock->batches()->create([
            'purchase_price'   => $price,
            'initial_quantity' => $quantity,
            'current_quantity' => $quantity,
        ]);
    }

    return $stock;
}

it('converts stock between units using the product factors', function () {
    $sourceStock = stockWithBatch($this->product, $this->inventory, $this->box, [[2, 1200]]);

    $conversion = app(StockConversionService::class)->convert([
        'inventory_id'  => $this->inventory->id,
        'product_id'    => $this->product->id,
        'from_unit_id'  => $this->box->id,
        'to_unit_id'    => $this->piece->id,
        'from_quantity' => 1,
        'note'          => 'Repacked',
    ]);

    expect((float) $conversion->from_quantity)->toBe(1.0)
        ->and((float) $conversion->to_quantity)->toBe(12.0)
        ->and((float) $conversion->unit_cost)->toBe(100.0)
        ->and((float) $conversion->total_cost)->toBe(1200.0)
        ->and((float) $conversion->from_factor)->toBe(12.0)
        ->and((float) $conversion->to_factor)->toBe(1.0);

    expect(round((float) $sourceStock->batches()->sum('current_quantity'), 2))->toBe(1.0);

    $destinationStock = Stock::where('inventory_id', $this->inventory->id)
        ->where('product_id', $this->product->id)
        ->where('unit_id', $this->piece->id)
        ->firstOrFail();

    $destinationBatch = $destinationStock->batches()->firstOrFail();

    expect(round((float) $destinationStock->batches()->sum('current_quantity'), 2))->toBe(12.0)
        ->and((float) $destinationBatch->purchase_price)->toBe(100.0)
        ->and($destinationBatch->source_type)->toBe(StockConversion::class)
        ->and($destinationBatch->source_id)->toBe($conversion->id);
});

it('records a paired manual movement for both units', function () {
    stockWithBatch($this->product, $this->inventory, $this->box, [[3, 600]]);

    $conversion = app(StockConversionService::class)->convert([
        'inventory_id'  => $this->inventory->id,
        'product_id'    => $this->product->id,
        'from_unit_id'  => $this->box->id,
        'to_unit_id'    => $this->piece->id,
        'from_quantity' => 2,
    ]);

    $movements = InventoryMovement::where('source_type', StockConversion::class)
        ->where('source_id', $conversion->id)
        ->orderBy('id')
        ->get();

    expect($movements)->toHaveCount(2)
        ->and($movements[0]->movement_type)->toBe(InventoryMovementType::MANUAL)
        ->and((float) $movements[0]->quantity)->toBe(-2.0)
        ->and((float) $movements[0]->old_quantity)->toBe(3.0)
        ->and((float) $movements[0]->new_quantity)->toBe(1.0)
        ->and((float) $movements[1]->quantity)->toBe(24.0)
        ->and((float) $movements[1]->old_quantity)->toBe(0.0)
        ->and((float) $movements[1]->new_quantity)->toBe(24.0);

    expect($movements[0]->stock_id)->not->toBe($movements[1]->stock_id);
});

it('consumes the oldest batches first and carries their cost', function () {
    stockWithBatch($this->product, $this->inventory, $this->box, [[1, 1000], [1, 1400]]);

    $conversion = app(StockConversionService::class)->convert([
        'inventory_id'  => $this->inventory->id,
        'product_id'    => $this->product->id,
        'from_unit_id'  => $this->box->id,
        'to_unit_id'    => $this->piece->id,
        'from_quantity' => 1,
    ]);

    expect((float) $conversion->total_cost)->toBe(1000.0)
        ->and((float) $conversion->unit_cost)->toBe(83.33);
});

it('honours an operator supplied destination unit cost', function () {
    stockWithBatch($this->product, $this->inventory, $this->box, [[1, 1200]]);

    $conversion = app(StockConversionService::class)->convert([
        'inventory_id'  => $this->inventory->id,
        'product_id'    => $this->product->id,
        'from_unit_id'  => $this->box->id,
        'to_unit_id'    => $this->piece->id,
        'from_quantity' => 1,
        'unit_cost'     => 150,
    ]);

    expect((float) $conversion->unit_cost)->toBe(150.0)
        ->and((float) $conversion->total_cost)->toBe(1800.0);

    $destinationStock = Stock::where('unit_id', $this->piece->id)
        ->where('product_id', $this->product->id)
        ->firstOrFail();

    expect((float) $destinationStock->batches()->firstOrFail()->purchase_price)->toBe(150.0);
});

it('bypasses conversion factors when an explicit destination quantity is given', function () {
    $pallet = Unit::create(['name' => 'Pallet', 'symbol' => 'plt']);
    stockWithBatch($this->product, $this->inventory, $this->box, [[2, 1200]]);

    $conversion = app(StockConversionService::class)->convert([
        'inventory_id'  => $this->inventory->id,
        'product_id'    => $this->product->id,
        'from_unit_id'  => $this->box->id,
        'to_unit_id'    => $pallet->id,
        'from_quantity' => 1,
        'to_quantity'   => 7,
    ]);

    expect((float) $conversion->from_quantity)->toBe(1.0)
        ->and((float) $conversion->to_quantity)->toBe(7.0)
        ->and((float) $conversion->from_factor)->toBe(12.0)
        ->and($conversion->to_factor)->toBeNull()
        ->and((float) $conversion->unit_cost)->toBe(171.43)
        ->and((float) $conversion->total_cost)->toBe(1200.0);

    $destinationStock = Stock::where('unit_id', $pallet->id)
        ->where('product_id', $this->product->id)
        ->firstOrFail();

    expect(round((float) $destinationStock->batches()->sum('current_quantity'), 2))->toBe(7.0);
});

it('prefers an explicit destination quantity over the configured factors', function () {
    stockWithBatch($this->product, $this->inventory, $this->box, [[1, 1200]]);

    $conversion = app(StockConversionService::class)->convert([
        'inventory_id'  => $this->inventory->id,
        'product_id'    => $this->product->id,
        'from_unit_id'  => $this->box->id,
        'to_unit_id'    => $this->piece->id,
        'from_quantity' => 1,
        'to_quantity'   => 10,
    ]);

    expect((float) $conversion->to_quantity)->toBe(10.0)
        ->and((float) $conversion->from_factor)->toBe(12.0)
        ->and((float) $conversion->to_factor)->toBe(1.0);

    $destinationStock = Stock::where('unit_id', $this->piece->id)
        ->where('product_id', $this->product->id)
        ->firstOrFail();

    expect(round((float) $destinationStock->batches()->sum('current_quantity'), 2))->toBe(10.0);
});

it('rejects an explicit destination quantity that breaks the unit precision', function () {
    stockWithBatch($this->product, $this->inventory, $this->piece, [[10, 100]]);

    expect(fn () => app(StockConversionService::class)->convert([
        'inventory_id'  => $this->inventory->id,
        'product_id'    => $this->product->id,
        'from_unit_id'  => $this->piece->id,
        'to_unit_id'    => $this->box->id,
        'from_quantity' => 1,
        'to_quantity'   => 1.5,
    ]))->toThrow(\Exception::class, __('stock_conversions.invalid_destination_quantity', ['unit' => 'Box']));
});

it('supports fractional quantities for units that allow decimals', function () {
    stockWithBatch($this->weight, $this->inventory, $this->kilo, [[1, 500]]);

    $conversion = app(StockConversionService::class)->convert([
        'inventory_id'  => $this->inventory->id,
        'product_id'    => $this->weight->id,
        'from_unit_id'  => $this->kilo->id,
        'to_unit_id'    => $this->gram->id,
        'from_quantity' => 0.25,
    ]);

    expect((float) $conversion->to_quantity)->toBe(250.0);

    $destinationStock = Stock::where('unit_id', $this->gram->id)
        ->where('product_id', $this->weight->id)
        ->firstOrFail();

    expect(round((float) $destinationStock->batches()->sum('current_quantity'), 2))->toBe(250.0);
});

it('rejects a fractional source quantity for a whole number unit', function () {
    stockWithBatch($this->product, $this->inventory, $this->box, [[2, 1200]]);

    expect(fn () => app(StockConversionService::class)->convert([
        'inventory_id'  => $this->inventory->id,
        'product_id'    => $this->product->id,
        'from_unit_id'  => $this->box->id,
        'to_unit_id'    => $this->piece->id,
        'from_quantity' => 0.5,
    ]))->toThrow(\Exception::class, __('stock_conversions.invalid_source_quantity', ['unit' => 'Box']));
});

it('rejects a conversion that would leave a fractional destination quantity', function () {
    stockWithBatch($this->product, $this->inventory, $this->piece, [[5, 100]]);

    expect(fn () => app(StockConversionService::class)->convert([
        'inventory_id'  => $this->inventory->id,
        'product_id'    => $this->product->id,
        'from_unit_id'  => $this->piece->id,
        'to_unit_id'    => $this->box->id,
        'from_quantity' => 1,
    ]))->toThrow(\Exception::class, __('stock_conversions.invalid_destination_quantity', ['unit' => 'Box']));
});

it('rejects conversions with insufficient stock and leaves stock untouched', function () {
    $sourceStock = stockWithBatch($this->product, $this->inventory, $this->box, [[1, 1200]]);

    try {
        app(StockConversionService::class)->convert([
            'inventory_id'  => $this->inventory->id,
            'product_id'    => $this->product->id,
            'from_unit_id'  => $this->box->id,
            'to_unit_id'    => $this->piece->id,
            'from_quantity' => 2,
        ]);
    } catch (\Exception $e) {
        expect($e->getCode())->toBe(422);
    }

    expect(round((float) $sourceStock->batches()->sum('current_quantity'), 2))->toBe(1.0)
        ->and(StockConversion::count())->toBe(0)
        ->and(InventoryMovement::where('source_type', StockConversion::class)->count())->toBe(0);
});

it('rejects a unit without a configured factor', function () {
    $pallet = Unit::create(['name' => 'Pallet', 'symbol' => 'plt']);
    stockWithBatch($this->product, $this->inventory, $this->box, [[1, 1200]]);

    expect(fn () => app(StockConversionService::class)->convert([
        'inventory_id'  => $this->inventory->id,
        'product_id'    => $this->product->id,
        'from_unit_id'  => $this->box->id,
        'to_unit_id'    => $pallet->id,
        'from_quantity' => 1,
    ]))->toThrow(\Exception::class, __('stock_conversions.unit_not_configured', [
        'product' => 'Product',
        'unit'    => 'Pallet',
    ]));
});

it('rejects converting a unit into itself', function () {
    stockWithBatch($this->product, $this->inventory, $this->box, [[1, 1200]]);

    expect(fn () => app(StockConversionService::class)->convert([
        'inventory_id'  => $this->inventory->id,
        'product_id'    => $this->product->id,
        'from_unit_id'  => $this->box->id,
        'to_unit_id'    => $this->box->id,
        'from_quantity' => 1,
    ]))->toThrow(\Exception::class, __('stock_conversions.same_unit'));
});

it('stores product factors and forces the base unit factor to one', function () {
    $product = app(ProductService::class)->create([
        'name'             => 'Configured product',
        'base_unit_id'     => $this->piece->id,
        'unit_conversions' => [
            ['unit_id' => $this->piece->id, 'factor' => 7],
            ['unit_id' => $this->box->id, 'factor' => 24],
        ],
    ], Request::create('/v1/products', 'POST'));

    $factors = $product->unitConversions()->pluck('factor', 'unit_id');

    expect((float) $factors[$this->piece->id])->toBe(1.0)
        ->and((float) $factors[$this->box->id])->toBe(24.0);
});

it('reports decimals support on units', function () {
    expect($this->gram->acceptsQuantity(0.25))->toBeTrue()
        ->and($this->gram->acceptsQuantity(0.123))->toBeFalse()
        ->and($this->box->acceptsQuantity(2.0))->toBeTrue()
        ->and($this->box->acceptsQuantity(1.5))->toBeFalse();
});

it('renders a product base unit and unit factors', function () {
    $payload = (new \App\Http\Resources\Web\ProductResource(
        $this->product->load(['baseUnit', 'unitConversions.unit']),
    ))->resolve();

    expect($payload['base_unit_id'])->toBe($this->piece->id)
        ->and($payload['base_unit']['name'])->toBe($this->piece->name)
        ->and($payload['unit_conversions'])->toHaveCount(2);

    $withoutBase = Product::create(['name' => 'No base unit']);

    $emptyPayload = (new \App\Http\Resources\Web\ProductResource(
        $withoutBase->load(['baseUnit', 'unitConversions']),
    ))->resolve();

    expect($emptyPayload['base_unit'])->toBeNull()
        ->and($emptyPayload['unit_conversions'])->toHaveCount(0);
});

it('updates unit conversions for multiple products at once', function () {
    $pallet = Unit::create(['name' => 'Pallet', 'symbol' => 'plt']);

    $products = app(ProductService::class)->bulkUpdateUnitConversions([
        [
            'product_id'       => $this->product->id,
            'base_unit_id'     => $this->piece->id,
            'unit_conversions' => [
                ['unit_id' => $this->piece->id, 'factor' => 1],
                ['unit_id' => $this->box->id, 'factor' => 20],
                ['unit_id' => $pallet->id, 'factor' => 500],
            ],
        ],
        [
            'product_id'       => $this->weight->id,
            'base_unit_id'     => $this->gram->id,
            'unit_conversions' => [
                ['unit_id' => $this->kilo->id, 'factor' => 1000],
            ],
        ],
    ]);

    expect($products)->toHaveCount(2);

    $productFactors = $this->product->fresh()->unitConversions()->pluck('factor', 'unit_id');
    $weightFactors  = $this->weight->fresh()->unitConversions()->pluck('factor', 'unit_id');

    expect((float) $productFactors[$this->box->id])->toBe(20.0)
        ->and((float) $productFactors[$pallet->id])->toBe(500.0)
        ->and((float) $weightFactors[$this->gram->id])->toBe(1.0)
        ->and((float) $weightFactors[$this->kilo->id])->toBe(1000.0)
        ->and($this->product->fresh()->base_unit_id)->toBe($this->piece->id)
        ->and($this->weight->fresh()->base_unit_id)->toBe($this->gram->id);
});

it('rolls back every product when a bulk conversion update fails', function () {
    try {
        app(ProductService::class)->bulkUpdateUnitConversions([
            [
                'product_id'       => $this->product->id,
                'base_unit_id'     => $this->piece->id,
                'unit_conversions' => [
                    ['unit_id' => $this->piece->id, 'factor' => 1],
                    ['unit_id' => $this->box->id, 'factor' => 30],
                ],
            ],
            [
                'product_id'       => 999999,
                'base_unit_id'     => $this->piece->id,
                'unit_conversions' => [
                    ['unit_id' => $this->piece->id, 'factor' => 1],
                ],
            ],
        ]);
    } catch (\Throwable $e) {
        expect($e)->not->toBeNull();
    }

    expect((float) $this->product->fresh()->unitConversions()
        ->where('unit_id', $this->box->id)
        ->value('factor'))->toBe(12.0);
});

it('validates bulk unit conversion payloads', function () {
    $valid = BulkUpdateUnitConversionsRequest::create('/v1/products/unit-conversions', 'PUT', [
        'products' => [[
            'product_id'       => $this->product->id,
            'base_unit_id'     => $this->piece->id,
            'unit_conversions' => [
                ['unit_id' => $this->piece->id, 'factor' => 1],
                ['unit_id' => $this->box->id, 'factor' => 12],
            ],
        ]],
    ]);

    expect(Validator::make($valid->all(), $valid->rules())->passes())->toBeTrue();

    $invalid = BulkUpdateUnitConversionsRequest::create('/v1/products/unit-conversions', 'PUT', [
        'products' => [[
            'product_id'       => $this->product->id,
            'base_unit_id'     => $this->piece->id,
            'unit_conversions' => [
                ['unit_id' => $this->box->id, 'factor' => 12],
                ['unit_id' => $this->box->id, 'factor' => 24],
            ],
        ]],
    ]);

    $validator = Validator::make($invalid->all(), $invalid->rules());

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->keys())->toContain('products.0.unit_conversions.1.unit_id');
});

it('validates the convert stock payload', function () {
    $valid = ConvertStockRequest::create('/v1/stocks/convert', 'POST', [
        'inventory_id'  => $this->inventory->id,
        'product_id'    => $this->product->id,
        'from_unit_id'  => $this->box->id,
        'to_unit_id'    => $this->piece->id,
        'from_quantity' => 1,
        'to_quantity'   => 12,
    ]);

    expect(Validator::make($valid->all(), $valid->rules())->passes())->toBeTrue();

    $invalid = ConvertStockRequest::create('/v1/stocks/convert', 'POST', [
        'inventory_id'  => $this->inventory->id,
        'product_id'    => $this->product->id,
        'from_unit_id'  => $this->box->id,
        'to_unit_id'    => $this->piece->id,
        'from_quantity' => 0,
        'to_quantity'   => 0,
    ]);

    $validator = Validator::make($invalid->all(), $invalid->rules());

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->keys())->toContain('from_quantity', 'to_quantity');
});

it('renders the conversion with its product, units and movements', function () {
    stockWithBatch($this->product, $this->inventory, $this->box, [[2, 1200]]);

    $conversion = app(StockConversionService::class)->convert([
        'inventory_id'  => $this->inventory->id,
        'product_id'    => $this->product->id,
        'from_unit_id'  => $this->box->id,
        'to_unit_id'    => $this->piece->id,
        'from_quantity' => 1,
    ]);

    $payload = (new \App\Http\Resources\Web\StockConversionResource(
        $conversion->load(['inventory', 'product', 'fromStock.unit', 'toStock.unit', 'fromUnit', 'toUnit', 'movements']),
    ))->resolve();

    expect($payload['from_quantity'])->toBe(1.0)
        ->and($payload['to_quantity'])->toBe(12.0)
        ->and($payload['unit_cost'])->toBe(100.0)
        ->and($payload['from_unit']['symbol'])->toBe('box')
        ->and($payload['to_unit']['symbol'])->toBe($this->piece->symbol)
        ->and($payload['product']['name'])->toBe('Product')
        ->and($payload['movements'])->toHaveCount(2);
});

it('renders null factors for a conversion that bypassed them', function () {
    $pallet = Unit::create(['name' => 'Pallet', 'symbol' => 'plt']);
    stockWithBatch($this->product, $this->inventory, $this->box, [[1, 1200]]);

    $conversion = app(StockConversionService::class)->convert([
        'inventory_id'  => $this->inventory->id,
        'product_id'    => $this->product->id,
        'from_unit_id'  => $this->box->id,
        'to_unit_id'    => $pallet->id,
        'from_quantity' => 1,
        'to_quantity'   => 7,
    ]);

    $payload = (new \App\Http\Resources\Web\StockConversionResource(
        $conversion->load(['inventory', 'product', 'fromStock.unit', 'toStock.unit', 'fromUnit', 'toUnit', 'movements']),
    ))->resolve();

    expect((float) $payload['from_factor'])->toBe(12.0)
        ->and($payload['to_factor'])->toBeNull()
        ->and($payload['to_quantity'])->toBe(7.0);
});
