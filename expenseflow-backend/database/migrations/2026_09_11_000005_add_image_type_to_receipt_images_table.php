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
        Schema::table('receipt_images', function (Blueprint $table) {
            $table->string('image_type', 30)->default('primary')->after('mime_type');
            $table->index(['receipt_id', 'image_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('receipt_images', function (Blueprint $table) {
            $table->dropIndex(['receipt_id', 'image_type']);
            $table->dropColumn('image_type');
        });
    }
};
