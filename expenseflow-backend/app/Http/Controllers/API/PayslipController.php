<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\Role;
use App\Services\Payroll\Bpjs\EmployerBpjsBreakdown;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Slip gaji — sisi dashboard (Finance/HRD) & sisi karyawan (self-service).
 *
 * Karyawan hanya boleh melihat slip MILIKNYA SENDIRI dan hanya untuk batch
 * yang sudah final (approved/paid). Dashboard butuh izin modul Payroll 'read'.
 */
class PayslipController extends Controller
{
    /** Status batch yang boleh dilihat karyawan (sudah final). */
    private const EMPLOYEE_VISIBLE = ['approved', 'paid'];

    private function deny(): JsonResponse
    {
        return response()->json(['message' => 'Akses ditolak.'], 403);
    }

    // ─────────────────────────── Dashboard (Finance/HRD) ───────────────────────────

    /** GET /dashboard/payroll/payslips?payroll_id=&user_id= */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }

        $payslips = Payslip::with('payroll:id,period_label,status')
            ->where('company_id', $user->company_id)
            ->when($request->filled('payroll_id'), fn ($q) => $q->where('payroll_id', (int) $request->query('payroll_id')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', (int) $request->query('user_id')))
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->orderBy('employee_name')
            ->get();

        return response()->json(['data' => $payslips]);
    }

    /** GET /dashboard/payroll/payslips/{payslip} */
    public function show(Request $request, Payslip $payslip): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }
        if ((int) $payslip->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Slip tidak ditemukan.'], 404);
        }

        return response()->json(['data' => $this->detail($payslip)]);
    }

    /** GET /dashboard/payroll/payslips/{payslip}/pdf */
    public function pdf(Request $request, Payslip $payslip): Response
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            abort(403, 'Akses ditolak.');
        }
        if ((int) $payslip->company_id !== (int) $user->company_id) {
            abort(404, 'Slip tidak ditemukan.');
        }

        return $this->streamPdf($payslip);
    }

    // ─────────────────────────── Karyawan (self-service) ───────────────────────────

    /** GET /employee/payslips */
    public function myPayslips(Request $request): JsonResponse
    {
        $user = $request->user();

        $payslips = Payslip::with('payroll:id,period_label,status')
            ->where('user_id', $user->id)
            ->whereHas('payroll', fn ($q) => $q->whereIn('status', self::EMPLOYEE_VISIBLE))
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->get(['id', 'payroll_id', 'period_month', 'period_year', 'gross', 'total_deduction', 'pph21', 'net', 'status']);

        return response()->json(['data' => $payslips]);
    }

    /** GET /employee/payslips/{payslip} */
    public function showMine(Request $request, Payslip $payslip): JsonResponse
    {
        if (! $this->ownedAndFinal($request, $payslip)) {
            return response()->json(['message' => 'Slip tidak ditemukan.'], 404);
        }

        return response()->json(['data' => $this->detail($payslip)]);
    }

    /** GET /employee/payslips/{payslip}/pdf */
    public function pdfMine(Request $request, Payslip $payslip): Response
    {
        if (! $this->ownedAndFinal($request, $payslip)) {
            abort(404, 'Slip tidak ditemukan.');
        }

        return $this->streamPdf($payslip);
    }

    // ─────────────────────────── Helper ───────────────────────────

    /** Slip milik user yang login DAN batch-nya sudah final. */
    private function ownedAndFinal(Request $request, Payslip $payslip): bool
    {
        $user = $request->user();
        if ((int) $payslip->user_id !== (int) $user->id) {
            return false;
        }
        $payslip->loadMissing('payroll:id,status');

        return $payslip->payroll && in_array($payslip->payroll->status, self::EMPLOYEE_VISIBLE, true);
    }

    /** Susun payload detail slip + rincian earning/deduction + jejak perhitungan. */
    private function detail(Payslip $payslip): array
    {
        $payslip->load(['items', 'calculationSteps', 'payroll:id,period_label,period_month,period_year,status']);

        return [
            'payslip'    => $payslip->makeHidden(['items', 'calculationSteps']),
            'period'     => $payslip->payroll?->period_label,
            'earnings'   => $payslip->items->where('type', PayslipItem::TYPE_EARNING)->values(),
            'deductions' => $payslip->items->where('type', PayslipItem::TYPE_DEDUCTION)->values(),
            // Jejak perhitungan (transparansi): GROSS → lembur → absen → BPJS → PPh21 → NETT.
            'calculation_steps' => $payslip->calculationSteps->map(fn ($s) => [
                'step_code'      => $s->step_code,
                'step_sequence'  => $s->step_sequence,
                'input_payload'  => $s->input_payload,
                'raw_result'     => $s->raw_result,
                'rounding_diff'  => $s->rounding_diff,
                'final_result'   => $s->final_result,
                'rule_reference' => $s->rule_reference,
            ])->values(),
        ];
    }

    /** Render & stream PDF slip gaji dari Blade. */
    private function streamPdf(Payslip $payslip): Response
    {
        $payslip->load(['items', 'calculationSteps', 'payroll:id,period_label,period_month,period_year']);
        $company = Company::find($payslip->company_id);

        // Rincian iuran BPJS yang DITANGGUNG PERUSAHAAN — informasi tambahan pada
        // slip, diturunkan dari jejak perhitungan (tanpa hitung ulang). BUKAN
        // pengurang gaji bersih; blok disembunyikan bila slip tanpa BPJS.
        $bpjsEmployer = EmployerBpjsBreakdown::fromSteps($payslip->calculationSteps);

        $pdf = Pdf::loadView('payroll.payslip', [
            'payslip'      => $payslip,
            'company'      => $company,
            'earnings'     => $payslip->items->where('type', PayslipItem::TYPE_EARNING)->values(),
            'deductions'   => $payslip->items->where('type', PayslipItem::TYPE_DEDUCTION)->values(),
            'bpjsEmployer' => $bpjsEmployer,
        ])->setPaper('a4');

        $filename = sprintf(
            'slip-gaji-%s-%04d%02d.pdf',
            $payslip->employee_code ?: $payslip->user_id,
            $payslip->period_year,
            $payslip->period_month
        );

        // Stream inline agar bisa dibuka langsung di viewer (mobile/web).
        return $pdf->download($filename);
    }
}
