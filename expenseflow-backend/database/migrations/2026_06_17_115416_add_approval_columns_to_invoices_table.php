<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('subtotal', 15, 2)->nullable()->after('total_amount');
            $table->unsignedTinyInteger('max_approval_level')->default(1)->after('status');
            $table->unsignedTinyInteger('current_approval_level')->default(0)->after('max_approval_level');
            $table->string('source')->default('manual')->after('current_approval_level');
            $table->string('category')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['subtotal', 'max_approval_level', 'current_approval_level', 'source', 'category']);
        });
    }
};
