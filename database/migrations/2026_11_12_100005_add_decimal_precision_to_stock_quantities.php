<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->decimal('initial_quantity', 15, 2)->change();
            $table->decimal('current_quantity', 15, 2)->change();
        });

        Schema::table('batch_allocations', function (Blueprint $table) {
            $table->decimal('quantity', 15, 2)->change();
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->decimal('old_quantity', 15, 2)->nullable()->change();
            $table->decimal('new_quantity', 15, 2)->nullable()->change();
            $table->decimal('quantity', 15, 2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->unsignedInteger('initial_quantity')->change();
            $table->unsignedInteger('current_quantity')->change();
        });

        Schema::table('batch_allocations', function (Blueprint $table) {
            $table->integer('quantity')->change();
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->integer('old_quantity')->nullable()->change();
            $table->integer('new_quantity')->nullable()->change();
            $table->integer('quantity')->change();
        });
    }
};
