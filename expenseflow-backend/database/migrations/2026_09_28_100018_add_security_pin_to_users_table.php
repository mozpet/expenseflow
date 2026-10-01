<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step-up PIN keamanan untuk aksi payroll kritikal (Fase 3, spec §14).
     *
     * Soft rollout: PIN diwajibkan HANYA jika sudah diatur. `security_pin` disimpan
     * ter-hash (cast 'hashed' di model User) dan TIDAK mass-assignable.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('security_pin', 255)->nullable()->after('password');
            $table->timestamp('security_pin_set_at')->nullable()->after('security_pin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['security_pin', 'security_pin_set_at']);
        });
    }
};
