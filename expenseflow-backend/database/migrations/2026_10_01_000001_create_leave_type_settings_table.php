<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leave_type_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_setting_id')->constrained('attendance_settings')->cascadeOnDelete();
            $table->string('leave_type', 40);
            $table->boolean('is_enabled')->default(true);
            $table->integer('quota_days')->default(0);
            $table->boolean('requires_document')->default(false);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['attendance_setting_id', 'leave_type']);
        });

        // Seed default leave type settings untuk seluruh attendance_settings yang sudah ada
        $catalog = config('leave_types.catalog', []);
        if (!empty($catalog)) {
            $offices = DB::table('attendance_settings')->pluck('id');
            $rows = [];
            $now = now();
            foreach ($offices as $officeId) {
                foreach ($catalog as $typeKey => $meta) {
                    $rows[] = [
                        'attendance_setting_id' => $officeId,
                        'leave_type'           => $typeKey,
                        'is_enabled'           => (bool) ($meta['default_enabled'] ?? true),
                        'quota_days'           => (int) ($meta['default_quota_days'] ?? 0),
                        'requires_document'    => (bool) ($meta['requires_document'] ?? false),
                        'notes'                => $meta['description'] ?? null,
                        'created_at'           => $now,
                        'updated_at'           => $now,
                    ];
                }
            }

            if (!empty($rows)) {
                foreach (array_chunk($rows, 100) as $chunk) {
                    DB::table('leave_type_settings')->insert($chunk);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_type_settings');
    }
};
