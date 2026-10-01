<?php

namespace App\Services\Payroll\Gl;

use App\Models\AttendanceSetting;
use App\Models\Division;
use App\Models\Payroll;
use App\Models\PayrollGlAccount;
use App\Models\PayslipItem;
use Illuminate\Support\Facades\DB;

/**
 * Penyusun Jurnal Akuntansi / General Ledger (GL) untuk satu payroll run — Fase 4 (lanjutan).
 *
 * Bersifat MURNI-BACA (read-only): membangun baris jurnal debet/kredit dari data slip
 * yang sudah immutable pasca-approve. TIDAK menyimpan apa pun (dokumen GL bersifat
 * on-demand, tanpa tabel — sesuai keputusan produk). Tidak ada eval / raw SQL.
 *
 * Pemetaan pos akun bersifat editable per-perusahaan: kode & nama akun diambil dari
 * tabel `payroll_gl_accounts` bila ada, jika tidak memakai default `config/payroll_gl.php`.
 *
 * ── Bukti jurnal SELALU seimbang ──────────────────────────────────────────────
 *   Σ(item pendapatan)  = total_gross      (per slip: gross = Σ pendapatan)
 *   Σ(item potongan)    = total_deduction  (per slip: total_deduction = Σ potongan)
 *   total_net           = total_gross − total_deduction
 * Debit  = Σ pendapatan + BPJS perusahaan          = total_gross + total_bpjs_company
 * Credit = Σ potongan + BPJS perusahaan + gaji net = total_deduction + total_bpjs_company + total_net
 *        = total_deduction + total_bpjs_company + (total_gross − total_deduction)
 *        = total_gross + total_bpjs_company
 * ⇒ Debit == Credit (diuji di PayrollPhase4TaxGlTraceTest).
 */
class PayrollGlComposer
{
    /**
     * Pemetaan efektif pos jurnal → kode/nama akun untuk sebuah perusahaan.
     * Menggabungkan default config dengan override tabel (override menang).
     *
     * @return array<string, array{key:string, label:string, side:string, account_code:string, account_name:string, default_code:string, default_name:string, is_overridden:bool}>
     */
    public function resolveAccounts(int $companyId): array
    {
        /** @var array<string, array<string, string>> $buckets */
        $buckets = (array) config('payroll_gl.buckets', []);

        $overrides = PayrollGlAccount::where('company_id', $companyId)->get()->keyBy('key');

        $result = [];
        foreach ($buckets as $key => $def) {
            $ov = $overrides->get($key);
            $result[$key] = [
                'key'           => $key,
                'label'         => $def['label'],
                'side'          => $def['side'],
                'account_code'  => $ov->account_code ?? $def['default_code'],
                'account_name'  => $ov->account_name ?? $def['default_name'],
                'default_code'  => $def['default_code'],
                'default_name'  => $def['default_name'],
                'is_overridden' => (bool) $ov,
            ];
        }

        return $result;
    }

    /**
     * Susun jurnal seimbang untuk sebuah payroll run.
     *
     * @return array{lines: array<int, array{key:string, account_code:string, account_name:string, description:string, debit:float, credit:float}>, total_debit: float, total_credit: float, balanced: bool}
     */
    public function compose(Payroll $payroll): array
    {
        $accounts = $this->resolveAccounts((int) $payroll->company_id);

        // Agregasi seluruh baris slip se-run per (type, source). Query baca berparameter
        // (join sederhana), lalu dijumlahkan di PHP — konsisten dgn gaya PayrollCalculator.
        $rows = DB::table('payslip_items as pi')
            ->join('payslips as p', 'p.id', '=', 'pi.payslip_id')
            ->where('p.payroll_id', $payroll->id)
            ->get(['pi.type', 'pi.source', 'pi.amount']);

        $bucketTotals = [];
        $add = function (string $bucket, float $amount) use (&$bucketTotals): void {
            $bucketTotals[$bucket] = ($bucketTotals[$bucket] ?? 0.0) + $amount;
        };

        foreach ($rows as $r) {
            $amount = (float) $r->amount;
            if ($amount == 0.0) {
                continue;
            }
            if ($r->type === PayslipItem::TYPE_EARNING) {
                $add($this->earningBucket((string) $r->source), $amount);
            } else {
                $add($this->deductionBucket((string) $r->source), $amount);
            }
        }

        // Iuran BPJS perusahaan: beban (debit) + utang setor (kredit) sekaligus.
        $bpjsCompany = (float) $payroll->total_bpjs_company;
        if ($bpjsCompany > 0) {
            $add('expense_bpjs_company', $bpjsCompany);
            $add('payable_bpjs_company', $bpjsCompany);
        }

        // Gaji bersih yang dibayarkan (Kas/Bank) — kredit.
        $add('payable_net', (float) $payroll->total_net);

        // Bangun baris jurnal mengikuti urutan pos di config; buang baris nol.
        $lines = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($accounts as $key => $acc) {
            $amount = round($bucketTotals[$key] ?? 0.0, 2);
            if ($amount == 0.0) {
                continue;
            }
            $isDebit = $acc['side'] === 'debit';
            $lines[] = [
                'key'          => $key,
                'account_key'  => $key,
                'account_code' => $acc['account_code'],
                'account_name' => $acc['account_name'],
                'description'  => $acc['label'],
                'debit'        => $isDebit ? $amount : 0.0,
                'credit'       => $isDebit ? 0.0 : $amount,
            ];
            if ($isDebit) {
                $totalDebit += $amount;
            } else {
                $totalCredit += $amount;
            }
        }

        $totalDebit = round($totalDebit, 2);
        $totalCredit = round($totalCredit, 2);

        return [
            'lines'        => $lines,
            'total_debit'  => $totalDebit,
            'total_credit' => $totalCredit,
            'balanced'     => abs($totalDebit - $totalCredit) < 0.01,
        ];
    }

    /**
     * Susun jurnal seimbang PER DIMENSI cost center (Item 4).
     *
     * Berbeda dari compose() yang memakai skalar payroll.total_* , metode ini MENURUNKAN
     * setiap nilai PER SEGMEN dari slip yang di-snapshot (payslip_items difilter ke slip
     * segmen; BPJS perusahaan & net dijumlah dari kolom slip masing-masing — hazard 3).
     * Karena setiap slip memenuhi `net = gross − potongan`, tiap segmen otomatis seimbang,
     * dan Σ seluruh segmen == jurnal flat compose() (item terpartisi habis antar segmen).
     *
     * Slip tanpa dimensi (division_id / attendance_setting_id NULL — mis. run lama sebelum
     * snapshot) dikumpulkan ke segmen sentinel "tanpa_divisi"/"tanpa_cabang".
     *
     * @param  'division'|'attendance_setting'  $dim
     * @return array{group_by:string, dimension:string, segments: array<int, array{key:string, segment_id:int|null, label:string, lines: array<int, array<string, mixed>>, total_debit:float, total_credit:float, balanced:bool}>, grand_total_debit:float, grand_total_credit:float, balanced:bool}
     */
    public function composeGrouped(Payroll $payroll, string $dim): array
    {
        $accounts = $this->resolveAccounts((int) $payroll->company_id);
        $dimColumn     = $dim === 'division' ? 'division_id' : 'attendance_setting_id';
        $sentinelKey   = $dim === 'division' ? 'tanpa_divisi' : 'tanpa_cabang';
        $sentinelLabel = $dim === 'division' ? 'Tanpa Divisi' : 'Tanpa Cabang';

        /** @var array<string, array{seg_id: int|null, buckets: array<string, float>, bpjs: float, net: float}> $segments */
        $segments = [];
        $ensure = function (?int $segId) use (&$segments, $sentinelKey): string {
            $key = $segId === null ? $sentinelKey : (string) $segId;
            if (! isset($segments[$key])) {
                $segments[$key] = ['seg_id' => $segId, 'buckets' => [], 'bpjs' => 0.0, 'net' => 0.0];
            }

            return $key;
        };

        // Slip se-run: akumulasi BPJS perusahaan & net PER SEGMEN dari kolom slip.
        $slips = DB::table('payslips')
            ->where('payroll_id', $payroll->id)
            ->get(['id', "{$dimColumn} as seg_id", 'bpjs_company_total', 'net']);

        foreach ($slips as $s) {
            $key = $ensure($s->seg_id === null ? null : (int) $s->seg_id);
            $segments[$key]['bpjs'] += (float) $s->bpjs_company_total;
            $segments[$key]['net']  += (float) $s->net;
        }

        // Baris item slip se-run + dimensi segmennya → bucket pendapatan/potongan per segmen.
        $rows = DB::table('payslip_items as pi')
            ->join('payslips as p', 'p.id', '=', 'pi.payslip_id')
            ->where('p.payroll_id', $payroll->id)
            ->get(['pi.type', 'pi.source', 'pi.amount', "p.{$dimColumn} as seg_id"]);

        foreach ($rows as $r) {
            $amount = (float) $r->amount;
            if ($amount == 0.0) {
                continue;
            }
            $key = $ensure($r->seg_id === null ? null : (int) $r->seg_id);
            $bucket = $r->type === PayslipItem::TYPE_EARNING
                ? $this->earningBucket((string) $r->source)
                : $this->deductionBucket((string) $r->source);
            $segments[$key]['buckets'][$bucket] = ($segments[$key]['buckets'][$bucket] ?? 0.0) + $amount;
        }

        // Turunkan BPJS perusahaan (beban + utang) & net per segmen.
        foreach ($segments as $key => $seg) {
            $bpjs = round($seg['bpjs'], 2);
            if ($bpjs > 0) {
                $segments[$key]['buckets']['expense_bpjs_company'] = ($seg['buckets']['expense_bpjs_company'] ?? 0.0) + $bpjs;
                $segments[$key]['buckets']['payable_bpjs_company'] = ($seg['buckets']['payable_bpjs_company'] ?? 0.0) + $bpjs;
            }
            $segments[$key]['buckets']['payable_net'] = ($seg['buckets']['payable_net'] ?? 0.0) + $seg['net'];
        }

        // Label tampilan segmen.
        $segIds = [];
        foreach ($segments as $seg) {
            if ($seg['seg_id'] !== null) {
                $segIds[] = $seg['seg_id'];
            }
        }
        $labels = $this->segmentLabels($dim, $segIds);

        // Bangun keluaran per segmen + grand total.
        $out = [];
        $grandDebit = 0.0;
        $grandCredit = 0.0;
        foreach ($segments as $key => $seg) {
            $built = $this->buildJournalLines($accounts, $seg['buckets']);
            $out[] = [
                'key'          => $key,
                'segment_id'   => $seg['seg_id'],
                'label'        => $seg['seg_id'] === null ? $sentinelLabel : ($labels[$seg['seg_id']] ?? $key),
                'lines'        => $built['lines'],
                'total_debit'  => $built['total_debit'],
                'total_credit' => $built['total_credit'],
                'balanced'     => abs($built['total_debit'] - $built['total_credit']) < 0.01,
            ];
            $grandDebit += $built['total_debit'];
            $grandCredit += $built['total_credit'];
        }

        // Urutkan: segmen ber-label A→Z; sentinel (tanpa dimensi) di paling akhir.
        usort($out, function (array $a, array $b) use ($sentinelKey): int {
            if ($a['key'] === $sentinelKey) {
                return 1;
            }
            if ($b['key'] === $sentinelKey) {
                return -1;
            }

            return strcmp((string) $a['label'], (string) $b['label']);
        });

        $grandDebit = round($grandDebit, 2);
        $grandCredit = round($grandCredit, 2);

        return [
            'group_by'           => $dim === 'division' ? 'division' : 'branch',
            'dimension'          => $dim,
            'segments'           => $out,
            'grand_total_debit'  => $grandDebit,
            'grand_total_credit' => $grandCredit,
            'balanced'           => abs($grandDebit - $grandCredit) < 0.01,
        ];
    }

    /**
     * Bangun baris jurnal dari total per-pos (buang baris nol) mengikuti urutan config.
     * Dipakai composeGrouped(); compose() memakai logika setara inline agar byte-identik.
     *
     * @param  array<string, array<string, mixed>>  $accounts
     * @param  array<string, float>  $bucketTotals
     * @return array{lines: array<int, array<string, mixed>>, total_debit: float, total_credit: float}
     */
    private function buildJournalLines(array $accounts, array $bucketTotals): array
    {
        $lines = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($accounts as $key => $acc) {
            $amount = round($bucketTotals[$key] ?? 0.0, 2);
            if ($amount == 0.0) {
                continue;
            }
            $isDebit = $acc['side'] === 'debit';
            $lines[] = [
                'key'          => $key,
                'account_key'  => $key,
                'account_code' => $acc['account_code'],
                'account_name' => $acc['account_name'],
                'description'  => $acc['label'],
                'debit'        => $isDebit ? $amount : 0.0,
                'credit'       => $isDebit ? 0.0 : $amount,
            ];
            if ($isDebit) {
                $totalDebit += $amount;
            } else {
                $totalCredit += $amount;
            }
        }

        return [
            'lines'        => $lines,
            'total_debit'  => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
        ];
    }

    /**
     * Peta id-segmen → label tampilan untuk dimensi terpilih (dengan fallback bila
     * master sudah terhapus). Divisi: "Nama (KODE)"; cabang: office_name.
     *
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private function segmentLabels(string $dim, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $labels = [];
        if ($dim === 'division') {
            foreach (Division::whereIn('id', $ids)->get(['id', 'name', 'code']) as $d) {
                $labels[(int) $d->id] = $d->code ? "{$d->name} ({$d->code})" : (string) $d->name;
            }
            foreach ($ids as $id) {
                $labels[$id] ??= "Divisi #{$id}";
            }
        } else {
            foreach (AttendanceSetting::whereIn('id', $ids)->get(['id', 'office_name']) as $a) {
                $labels[(int) $a->id] = $a->office_name ? (string) $a->office_name : "Cabang #{$a->id}";
            }
            foreach ($ids as $id) {
                $labels[$id] ??= "Cabang #{$id}";
            }
        }

        return $labels;
    }

    /**
     * Susun ulang jurnal menjadi baris CSV siap-impor (header + baris + total).
     *
     * @param array{lines: array<int, array<string, mixed>>, total_debit: float, total_credit: float} $journal
     */
    public function toCsv(Payroll $payroll, array $journal): string
    {
        $period = sprintf('%04d-%02d', (int) $payroll->period_year, (int) $payroll->period_month);

        $rows = [];
        $rows[] = ['Periode', 'Kode Akun', 'Nama Akun', 'Keterangan', 'Debit', 'Kredit'];
        foreach ($journal['lines'] as $line) {
            $rows[] = [
                $period,
                $line['account_code'],
                $line['account_name'],
                $line['description'],
                number_format((float) $line['debit'], 2, '.', ''),
                number_format((float) $line['credit'], 2, '.', ''),
            ];
        }
        $rows[] = [
            $period, '', '', 'TOTAL',
            number_format((float) $journal['total_debit'], 2, '.', ''),
            number_format((float) $journal['total_credit'], 2, '.', ''),
        ];

        $out = '';
        foreach ($rows as $row) {
            $out .= implode(',', array_map([$this, 'csvCell'], $row)) . "\r\n";
        }

        return $out;
    }

    /**
     * CSV jurnal BERDIMENSI: kolom "Segmen" + subtotal tiap segmen + grand total.
     *
     * @param array{segments: array<int, array<string, mixed>>, grand_total_debit: float, grand_total_credit: float} $grouped
     */
    public function toCsvGrouped(Payroll $payroll, array $grouped): string
    {
        $period = sprintf('%04d-%02d', (int) $payroll->period_year, (int) $payroll->period_month);
        $money = fn ($v) => number_format((float) $v, 2, '.', '');

        $rows = [];
        $rows[] = ['Periode', 'Segmen', 'Kode Akun', 'Nama Akun', 'Keterangan', 'Debit', 'Kredit'];
        foreach ($grouped['segments'] as $seg) {
            foreach ($seg['lines'] as $line) {
                $rows[] = [
                    $period,
                    (string) $seg['label'],
                    (string) $line['account_code'],
                    (string) $line['account_name'],
                    (string) $line['description'],
                    $money($line['debit']),
                    $money($line['credit']),
                ];
            }
            $rows[] = [
                $period, (string) $seg['label'], '', '', 'SUBTOTAL',
                $money($seg['total_debit']), $money($seg['total_credit']),
            ];
        }
        $rows[] = [
            $period, 'SEMUA SEGMEN', '', '', 'GRAND TOTAL',
            $money($grouped['grand_total_debit']), $money($grouped['grand_total_credit']),
        ];

        $out = '';
        foreach ($rows as $row) {
            $out .= implode(',', array_map([$this, 'csvCell'], $row)) . "\r\n";
        }

        return $out;
    }

    /** Escape satu sel CSV (bungkus tanda kutip bila perlu). */
    private function csvCell(string $value): string
    {
        if (preg_match('/[",\r\n]/', $value)) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

    /** Pos DEBIT untuk sebuah sumber baris pendapatan. */
    private function earningBucket(string $source): string
    {
        return match ($source) {
            'overtime'   => 'expense_overtime',
            'receipt'    => 'expense_reimbursement',
            'adjustment' => 'expense_adjustment',
            'thr'        => 'expense_thr', // Tunjangan Hari Raya (Run THR terpisah).
            // Exit Settlement (Fase 6): seluruh hak pengakhiran → beban pesangon.
            'severance_up',
            'severance_upmk',
            'severance_uph',
            'severance_pkwt',
            'severance_separation' => 'expense_severance',
            default      => 'expense_salary', // basic, fixed (tunjangan tetap), dll.
        };
    }

    /** Pos KREDIT untuk sebuah sumber baris potongan. */
    private function deductionBucket(string $source): string
    {
        return match ($source) {
            'tax'   => 'payable_pph21',
            'bpjs'  => 'payable_bpjs_employee',
            'loan'  => 'receivable_loan',
            default => 'payable_other', // fixed(potongan), attendance, adjustment(potongan), dll.
        };
    }
}
