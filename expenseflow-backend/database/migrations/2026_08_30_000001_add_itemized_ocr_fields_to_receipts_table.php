<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->json('ocr_raw_items')->nullable()->after('ocr_raw_date');
            $table->decimal('ocr_raw_subtotal', 15, 2)->nullable()->after('ocr_raw_items');
            $table->decimal('ocr_raw_tax', 15, 2)->nullable()->after('ocr_raw_subtotal');
            $table->decimal('ocr_raw_discount', 15, 2)->nullable()->after('ocr_raw_tax');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn([
                'ocr_raw_items',
                'ocr_raw_subtotal',
                'ocr_raw_tax',
                'ocr_raw_discount',
            ]);
        });
    }
};
