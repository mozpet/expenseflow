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
        // 1. Tambah data rekening bank ke tabel users
        Schema::table('users', function (Blueprint $table) {
            $table->string('bank_name', 50)->nullable()->after('employment_type');
            $table->string('bank_account_no', 50)->nullable()->after('bank_name');
            $table->string('bank_account_holder', 150)->nullable()->after('bank_account_no');
        });

        // 2. Tambah kolom pencairan & fraud check ke tabel receipts
        Schema::table('receipts', function (Blueprint $table) {
            $table->decimal('approved_amount', 15, 2)->nullable()->after('claimed_amount');
            $table->timestamp('paid_at')->nullable()->after('submitted_at');
            $table->foreignId('paid_by')->nullable()->after('paid_at')->constrained('users')->nullOnDelete();
            $table->string('payment_method', 50)->nullable()->default('bank_transfer')->after('paid_by');
            $table->string('payment_ref_no', 100)->nullable()->after('payment_method');
            $table->string('payment_proof_path', 255)->nullable()->after('payment_ref_no');
            $table->boolean('is_potential_duplicate')->default(false)->after('variance_pct');
            $table->foreignId('duplicate_reference_id')->nullable()->after('is_potential_duplicate')->constrained('receipts')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropForeign(['paid_by']);
            $table->dropForeign(['duplicate_reference_id']);
            $table->dropColumn([
                'approved_amount',
                'paid_at',
                'paid_by',
                'payment_method',
                'payment_ref_no',
                'payment_proof_path',
                'is_potential_duplicate',
                'duplicate_reference_id',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'bank_name',
                'bank_account_no',
                'bank_account_holder',
            ]);
        });
    }
};
