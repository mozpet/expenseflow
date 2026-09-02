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
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('category')->nullable()->after('action');
            $table->string('severity')->default('info')->after('category'); // info, warning, critical
            $table->json('old_values')->nullable()->after('entity_id');
            $table->json('new_values')->nullable()->after('old_values');
            $table->string('ip_address', 45)->nullable()->after('new_values');
            $table->text('user_agent')->nullable()->after('ip_address');

            $table->index('category');
            $table->index('severity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropIndex(['severity']);
            $table->dropColumn([
                'category',
                'severity',
                'old_values',
                'new_values',
                'ip_address',
                'user_agent',
            ]);
        });
    }
};
