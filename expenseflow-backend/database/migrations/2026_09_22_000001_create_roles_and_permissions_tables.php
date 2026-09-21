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
        // 1. Tabel roles (Built-in + Custom Roles)
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->enum('platform', ['mobile_only', 'web_only', 'both'])->default('both');
            $table->enum('branch_scope', ['all', 'specific', 'self'])->default('all');
            $table->boolean('is_builtin')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'slug']);
        });

        // 2. Tabel role_permissions (Matriks Hak Akses per Modul)
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('module', 50);
            $table->enum('access_level', ['none', 'read', 'manage'])->default('none');
            $table->timestamps();

            $table->unique(['role_id', 'module']);
        });

        // 3. Tabel role_branches (Cabang Khusus jika branch_scope = specific)
        Schema::create('role_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('attendance_setting_id')->constrained('attendance_settings')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['role_id', 'attendance_setting_id']);
        });

        // 4. Tambah role_id ke tabel users (relasi 1 akun = 1 role)
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('password')->constrained('roles')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn('role_id');
        });

        Schema::dropIfExists('role_branches');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }
};
