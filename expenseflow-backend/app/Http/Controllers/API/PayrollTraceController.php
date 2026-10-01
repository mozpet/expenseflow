<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Calculation-Trace payslip (Fase 4 lanjutan).
 *
 * MURNI-BACA: memaparkan jejak langkah perhitungan (`payslip_calculation_steps`)
 * yang sudah disimpan PayrollCalculator saat run dihitung, beserta rincian item
 * pendapatan/potongan. Untuk transparansi & audit "bagaimana angka slip diperoleh".
 * Tidak ada tabel/mutasi baru.
 *
 * Prinsip keamanan: izin `read`; scoping company (lintas-company → 404 defense-in-depth,
 * 403 oleh CompanyMiddleware). Uang `decimal:2`. NPWP tidak pernah dipaparkan di sini
 * (slip hanya menyimpan `npwp_masked`).
 */
class PayrollTraceController extends Controller
{
    /** GET /dashboard/payroll/payslips/{payslip}/calculation-trace — jejak perhitungan satu slip. */
    public function show(Request $request, Payslip $payslip): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki wewenang atas jejak perhitungan payroll.'], 403);
        }
        if ((int) $payslip->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Slip gaji tidak ditemukan.'], 404);
        }

        $payslip->load(['calculationSteps', 'items']);

        $steps = $payslip->calculationSteps->map(fn ($s) => [
            'step_code'       => $s->step_code,
            'step_sequence'   => $s->step_sequence,
            'formula_version' => $s->formula_version,
            'input_payload'   => $s->input_payload,   // array (cast) — konteks input langkah.
            'raw_result'      => $s->raw_result,       // hasil sebelum pembulatan.
            'rounding_diff'   => $s->rounding_diff,     // selisih pembulatan.
            'final_result'    => $s->final_result,      // hasil akhir langkah.
            'rule_reference'  => $s->rule_reference,    // acuan aturan (mis. pasal / versi tarif).
        ])->values();

        $mapItem = fn ($i) => [
            'label'        => $i->label,
            'code'         => $i->code,
            'amount'       => $i->amount,
            'is_taxable'   => $i->is_taxable,
            'is_statutory' => $i->is_statutory,
            'source'       => $i->source,
            'notes'        => $i->notes,
        ];

        $earnings = $payslip->items
            ->where('type', PayslipItem::TYPE_EARNING)
            ->map($mapItem)->values();
        $deductions = $payslip->items
            ->where('type', PayslipItem::TYPE_DEDUCTION)
            ->map($mapItem)->values();

        return response()->json([
            'data' => [
                'payslip' => [
                    'id'              => $payslip->id,
                    'payroll_id'      => $payslip->payroll_id,
                    'employee_name'   => $payslip->employee_name,
                    'employee_code'   => $payslip->employee_code,
                    'period_month'    => $payslip->period_month,
                    'period_year'     => $payslip->period_year,
                    'ptkp_status'     => $payslip->ptkp_status,
                    'gross'           => $payslip->gross,
                    'taxable_income'  => $payslip->taxable_income,
                    'pph21'           => $payslip->pph21,
                    'total_deduction' => $payslip->total_deduction,
                    'net'             => $payslip->net,
                    'status'          => $payslip->status,
                ],
                'steps'      => $steps,
                'earnings'   => $earnings,
                'deductions' => $deductions,
            ],
        ]);
    }
}
