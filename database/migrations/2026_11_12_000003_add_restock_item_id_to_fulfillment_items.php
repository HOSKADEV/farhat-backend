<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->foreignId('restock_item_id')->nullable()->after('unit_id')->constrained('restock_items')->nullOnDelete();
        });

        Schema::table('inventory_transfer_items', function (Blueprint $table) {
            $table->foreignId('restock_item_id')->nullable()->after('stock_id')->constrained('restock_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('restock_item_id');
        });

        Schema::table('inventory_transfer_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('restock_item_id');
        });
    }
};
