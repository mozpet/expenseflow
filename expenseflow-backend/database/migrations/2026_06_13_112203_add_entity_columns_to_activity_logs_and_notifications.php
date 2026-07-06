<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // activity_logs: add entity_type & entity_id
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('entity_type')->nullable()->after('description');
            $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
        });

        // notifications: add entity_type & entity_id
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('entity_type')->nullable()->after('data');
            $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropColumn(['entity_type', 'entity_id']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn(['entity_type', 'entity_id']);
        });
    }
};
