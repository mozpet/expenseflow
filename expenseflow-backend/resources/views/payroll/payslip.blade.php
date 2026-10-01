@php
    /** Formatter Rupiah untuk PDF (dompdf tidak punya Intl). */
    $rp = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Slip Gaji {{ $payslip->employee_name }} — {{ $payslip->payroll?->period_label }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 11px; color: #1f2937; margin: 0; }
        .wrap { padding: 24px 28px; }
        .header { border-bottom: 2px solid #4f46e5; padding-bottom: 10px; margin-bottom: 14px; }
        .company { font-size: 16px; font-weight: bold; color: #4f46e5; }
        .company-addr { font-size: 10px; color: #6b7280; margin-top: 2px; }
        .doc-title { float: right; text-align: right; }
        .doc-title .t { font-size: 14px; font-weight: bold; }
        .doc-title .p { font-size: 11px; color: #6b7280; }
        .clear { clear: both; }

        table { width: 100%; border-collapse: collapse; }
        .info td { padding: 2px 0; vertical-align: top; font-size: 10.5px; }
        .info .label { color: #6b7280; width: 130px; }
        .info .sep { width: 10px; }

        .cols { margin-top: 14px; }
        .cols td { vertical-align: top; width: 50%; }
        .box { border: 1px solid #e5e7eb; border-radius: 6px; }
        .box h4 { margin: 0; padding: 7px 10px; font-size: 11px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; }
        .box .earn { color: #047857; }
        .box .ded { color: #b91c1c; }
        .lines { width: 100%; }
        .lines td { padding: 5px 10px; font-size: 10.5px; border-bottom: 1px solid #f3f4f6; }
        .lines td.amt { text-align: right; white-space: nowrap; }
        .subtotal td { font-weight: bold; border-top: 1px solid #e5e7eb; background: #fafafa; }
        .muted { color: #9ca3af; font-size: 9.5px; }

        .net { margin-top: 16px; background: #4f46e5; color: #fff; border-radius: 6px; padding: 12px 16px; }
        .net .lbl { font-size: 11px; }
        .net .val { font-size: 20px; font-weight: bold; float: right; }

        .summary { margin-top: 14px; }
        .summary td { padding: 4px 8px; font-size: 10px; border: 1px solid #e5e7eb; text-align: center; }
        .summary .k { color: #6b7280; }

        .bpjs-info { margin-top: 14px; border: 1px solid #e5e7eb; border-radius: 6px; }
        .bpjs-info h4 { margin: 0; padding: 7px 10px; font-size: 11px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; color: #2563eb; }
        .bpjs-info .note { padding: 6px 10px 0; font-size: 9.5px; color: #6b7280; }
        .bpjs-info table { width: 100%; }
        .bpjs-info td { padding: 5px 10px; font-size: 10.5px; border-bottom: 1px solid #f3f4f6; }
        .bpjs-info td.amt { text-align: right; white-space: nowrap; }
        .bpjs-info .subtotal td { font-weight: bold; border-top: 1px solid #e5e7eb; border-bottom: none; background: #fafafa; }

        .foot { margin-top: 20px; font-size: 9px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 8px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="header">
        <div class="doc-title">
            <div class="t">SLIP GAJI</div>
            <div class="p">{{ $payslip->payroll?->period_label ?? ($payslip->period_month . '/' . $payslip->period_year) }}</div>
        </div>
        <div class="company">{{ $company?->name ?? 'Perusahaan' }}</div>
        @if ($company?->address)
            <div class="company-addr">{{ $company->address }}</div>
        @endif
        <div class="clear"></div>
    </div>

    <table class="info">
        <tr>
            <td class="label">Nama Karyawan</td><td class="sep">:</td><td><strong>{{ $payslip->employee_name }}</strong></td>
            <td class="label">Jabatan</td><td class="sep">:</td><td>{{ $payslip->position_name ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">NIK / Kode</td><td class="sep">:</td><td>{{ $payslip->employee_code ?? '-' }}</td>
            <td class="label">Departemen</td><td class="sep">:</td><td>{{ $payslip->department_name ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">Status PTKP</td><td class="sep">:</td><td>{{ $payslip->ptkp_status ?? '-' }}</td>
            <td class="label">NPWP</td><td class="sep">:</td><td>{{ $payslip->npwp_masked ?: 'Tidak ada' }}</td>
        </tr>
        <tr>
            <td class="label">Bank</td><td class="sep">:</td><td>{{ $payslip->bank_name ?? '-' }}</td>
            <td class="label">No. Rekening</td><td class="sep">:</td><td>{{ $payslip->bank_account_no ?? '-' }}</td>
        </tr>
    </table>

    <table class="cols">
        <tr>
            <td style="padding-right: 8px;">
                <div class="box">
                    <h4 class="earn">PENDAPATAN</h4>
                    <table class="lines">
                        @forelse ($earnings as $item)
                            <tr>
                                <td>{{ $item->label }}</td>
                                <td class="amt">{{ $rp($item->amount) }}</td>
                            </tr>
                        @empty
                            <tr><td class="muted" colspan="2">Tidak ada</td></tr>
                        @endforelse
                        <tr class="subtotal">
                            <td>Total Pendapatan (Bruto)</td>
                            <td class="amt">{{ $rp($payslip->gross) }}</td>
                        </tr>
                    </table>
                </div>
            </td>
            <td style="padding-left: 8px;">
                <div class="box">
                    <h4 class="ded">POTONGAN</h4>
                    <table class="lines">
                        @forelse ($deductions as $item)
                            <tr>
                                <td>
                                    {{ $item->label }}
                                    @if ($item->is_statutory)<span class="muted"> (wajib)</span>@endif
                                </td>
                                <td class="amt">{{ $rp($item->amount) }}</td>
                            </tr>
                        @empty
                            <tr><td class="muted" colspan="2">Tidak ada</td></tr>
                        @endforelse
                        <tr class="subtotal">
                            <td>Total Potongan</td>
                            <td class="amt">{{ $rp($payslip->total_deduction) }}</td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <div class="net">
        <span class="lbl">GAJI BERSIH (Take Home Pay)</span>
        <span class="val">{{ $rp($payslip->net) }}</span>
        <div class="clear"></div>
    </div>

    @php $bpjsEmployer = $bpjsEmployer ?? ['components' => [], 'total' => 0]; @endphp
    @if (! empty($bpjsEmployer['total']))
        {{-- Informasi: iuran BPJS yang ditanggung PERUSAHAAN. Bukan potongan gaji;
             tidak memengaruhi gaji bersih di atas. --}}
        <div class="bpjs-info">
            <h4>Iuran BPJS Ditanggung Perusahaan</h4>
            <div class="note">Informasi tambahan — <strong>bukan pengurang</strong> gaji bersih Anda.</div>
            <table>
                @foreach ($bpjsEmployer['components'] as $comp)
                    <tr>
                        <td>{{ $comp['label'] }}</td>
                        <td class="amt">{{ $rp($comp['amount']) }}</td>
                    </tr>
                @endforeach
                <tr class="subtotal">
                    <td>Total Iuran Ditanggung Perusahaan</td>
                    <td class="amt">{{ $rp($bpjsEmployer['total']) }}</td>
                </tr>
            </table>
        </div>
    @endif

    <table class="summary">
        <tr>
            <td class="k">Hari Kerja</td>
            <td class="k">Hadir</td>
            <td class="k">Absen</td>
            <td class="k">Jam Lembur</td>
            <td class="k">Dasar Pajak (PKP)</td>
            <td class="k">PPh21</td>
        </tr>
        <tr>
            <td>{{ $payslip->working_days ?? '-' }}</td>
            <td>{{ $payslip->present_days ?? '-' }}</td>
            <td>{{ $payslip->absent_days ?? '-' }}</td>
            <td>{{ rtrim(rtrim(number_format((float) $payslip->overtime_hours, 2, ',', '.'), '0'), ',') }}</td>
            <td>{{ $rp($payslip->taxable_income) }}</td>
            <td>{{ $rp($payslip->pph21) }}</td>
        </tr>
    </table>

    <div class="foot">
        Dokumen ini dicetak otomatis oleh sistem penggajian pada {{ now()->timezone('Asia/Jakarta')->format('d-m-Y H:i') }} WIB
        dan sah tanpa tanda tangan basah. PPh21 dihitung dengan metode TER 2024 (PMK 168/2023).
        Simpan slip ini sebagai bukti penerimaan gaji.
    </div>
</div>
</body>
</html>
