<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite index untuk mempercepat query dashboard.
 *
 * Pola query dashboard selalu: WHERE company_id + status/date ORDER BY created_at.
 * Sebelumnya hanya ada index kolom tunggal (status, date, receipt_date) sehingga
 * MySQL memakai satu index lalu filesort. Composite index menutup seluruh pola
 * filter + sorting dalam satu index.
 */
return new class extends Migration
{
    public function up(): void
    {
        // dashboardReceipts() & inbox(): where company_id + status, latest() (created_at)
        Schema::table('receipts', function (Blueprint $table) {
            $table->index(['company_id', 'status', 'created_at'], 'receipts_company_status_created_idx');
        });

        // today() & reportAttendance(): where company_id + range date
        Schema::table('attendances', function (Blueprint $table) {
            $table->index(['company_id', 'date'], 'attendances_company_date_idx');
        });

        // today() & buildFullRows(): where company_id + status + range start/end_date
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->index(['company_id', 'status'], 'leave_requests_company_status_idx');
            $table->index(['start_date', 'end_date'], 'leave_requests_dates_idx');
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropIndex('receipts_company_status_created_idx');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex('attendances_company_date_idx');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropIndex('leave_requests_company_status_idx');
            $table->dropIndex('leave_requests_dates_idx');
        });
    }
};
