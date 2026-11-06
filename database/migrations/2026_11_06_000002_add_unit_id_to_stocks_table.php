<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('product_id')->constrained()->restrictOnDelete();
        });

        $defaultUnitId = DB::table('units')->where('is_default', true)->value('id')
            ?? DB::table('units')->orderBy('id')->value('id');

        if ($defaultUnitId !== null) {
            DB::table('stocks')->whereNull('unit_id')->update(['unit_id' => $defaultUnitId]);
        }

        Schema::table('stocks', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_id')->nullable(false)->change();
            $table->unique(['inventory_id', 'product_id', 'unit_id'], 'stocks_inventory_product_unit_unique');
        });
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropUnique('stocks_inventory_product_unit_unique');
            $table->dropConstrainedForeignId('unit_id');
        });
    }
};
