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
        Schema::create('leave_quota_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('adjusted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('leave_type', 50);
            $table->year('year');
            $table->integer('old_quota')->default(0);
            $table->integer('new_quota')->default(0);
            $table->integer('difference')->default(0); // new_quota - old_quota
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['company_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_quota_adjustments');
    }
};
