<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->string('ocr_status')->nullable()->after('ocr_raw_date')->comment('pending|processing|done|failed');
            $table->unsignedTinyInteger('ocr_attempts')->default(0)->after('ocr_status');
            $table->text('ocr_error')->nullable()->after('ocr_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn(['ocr_status', 'ocr_attempts', 'ocr_error']);
        });
    }
};
