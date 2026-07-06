<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('bank_name')->nullable()->after('tax_id');
            $table->string('bank_account_no')->nullable()->after('bank_name');
            $table->string('bank_account_name')->nullable()->after('bank_account_no');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['bank_name', 'bank_account_no', 'bank_account_name']);
        });
    }
};
