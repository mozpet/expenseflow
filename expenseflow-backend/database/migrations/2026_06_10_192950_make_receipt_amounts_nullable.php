<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->decimal('total_amount', 15, 2)->nullable()->change();
            $table->date('receipt_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->decimal('total_amount', 15, 2)->nullable(false)->change();
            $table->date('receipt_date')->nullable(false)->change();
        });
    }
};
