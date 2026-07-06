<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->string('sha256_hash', 64)->nullable()->after('receipt_number');
            $table->string('image_path')->nullable()->after('sha256_hash');
            $table->string('category')->nullable()->after('notes');
            $table->decimal('claimed_amount', 15, 2)->nullable()->after('category');
            $table->decimal('ocr_raw_amount', 15, 2)->nullable()->after('claimed_amount');
            $table->string('ocr_raw_merchant')->nullable()->after('ocr_raw_amount');
            $table->date('ocr_raw_date')->nullable()->after('ocr_raw_merchant');
            $table->boolean('variance_flag')->default(false)->after('ocr_raw_date');
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn([
                'sha256_hash', 'image_path', 'category',
                'claimed_amount', 'ocr_raw_amount', 'ocr_raw_merchant',
                'ocr_raw_date', 'variance_flag',
            ]);
        });
    }
};
