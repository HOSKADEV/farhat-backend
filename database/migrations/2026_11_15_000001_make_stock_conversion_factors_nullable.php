<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_conversions', function (Blueprint $table) {
            $table->decimal('from_factor', 15, 4)->nullable()->change();
            $table->decimal('to_factor', 15, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_conversions', function (Blueprint $table) {
            $table->decimal('from_factor', 15, 4)->nullable(false)->change();
            $table->decimal('to_factor', 15, 4)->nullable(false)->change();
        });
    }
};
