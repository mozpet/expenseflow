<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('division_id')->nullable()->after('department')->constrained('divisions')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->after('division_id')->constrained('positions')->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->after('position_id')->constrained('users')->nullOnDelete();

            $table->index(['company_id', 'division_id']);
            $table->index(['company_id', 'manager_id']);
        });

        // Seed initial divisions from existing non-null department strings
        try {
            $deptRows = DB::table('users')
                ->whereNotNull('department')
                ->where('department', '!=', '')
                ->select('company_id', 'department')
                ->distinct()
                ->get();

            $divisionMap = [];
            foreach ($deptRows as $row) {
                $trimmed = trim((string) $row->department);
                if (empty($trimmed)) {
                    continue;
                }

                $key = $row->company_id . '_' . strtolower($trimmed);
                if (! isset($divisionMap[$key])) {
                    $divId = DB::table('divisions')->insertGetId([
                        'company_id'  => $row->company_id,
                        'name'        => $trimmed,
                        'code'        => strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $trimmed), 0, 4)) ?: null,
                        'is_active'   => true,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                    $divisionMap[$key] = $divId;
                }
            }

            // Backfill division_id on users table
            foreach ($divisionMap as $key => $divId) {
                [$comp, $dept] = explode('_', $key, 2);
                DB::table('users')
                    ->where('company_id', $comp)
                    ->whereRaw('LOWER(TRIM(department)) = ?', [$dept])
                    ->update(['division_id' => $divId]);
            }
        } catch (\Throwable $e) {
            // Ignore if error occurs during initial migration seeder
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['division_id']);
            $table->dropForeign(['position_id']);
            $table->dropForeign(['manager_id']);
            $table->dropIndex(['company_id', 'division_id']);
            $table->dropIndex(['company_id', 'manager_id']);
            $table->dropColumn(['division_id', 'position_id', 'manager_id']);
        });
    }
};
