@php
    // Formatter Rupiah lokal — dompdf tanpa ext-intl (mirror payslip.blade.php).
    $rp = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $npwp = $row['npwp'] ?? null; // NPWP PENUH — berkas ini hanya di-stream via izin manage + audit.
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Draf 1721-A1 — {{ $row['employee_name'] }} — {{ $tax_year }}</title>
    <style>
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 11px; color: #1a1a1a; margin: 0; }
        .draft-banner {
            border: 1px solid #b45309; background: #fef3c7; color: #7c2d12;
            padding: 6px 10px; margin-bottom: 12px; font-size: 10px; font-weight: bold;
            text-align: center; border-radius: 4px;
        }
        .header { text-align: center; margin-bottom: 10px; }
        .header .form-code { font-size: 13px; font-weight: bold; }
        .header .form-title { font-size: 11px; }
        .header .company { font-size: 12px; font-weight: bold; margin-top: 6px; }
        table { width: 100%; border-collapse: collapse; }
        .identity td { padding: 2px 4px; vertical-align: top; }
        .identity .label { width: 26%; color: #444; }
        .identity .sep { width: 2%; }
        .section-title {
            background: #e5e7eb; padding: 4px 8px; font-weight: bold;
            margin: 12px 0 0; font-size: 11px;
        }
        table.calc { margin-top: 0; }
        table.calc th, table.calc td { border: 1px solid #cbd5e1; padding: 4px 8px; }
        table.calc th { background: #f1f5f9; text-align: left; }
        table.calc td.no { width: 6%; text-align: center; }
        table.calc td.amount { width: 28%; text-align: right; }
        table.calc tr.total td { font-weight: bold; background: #f8fafc; }
        table.calc tr.grand td { font-weight: bold; background: #eef2ff; }
        .note { margin-top: 10px; font-size: 9px; color: #555; line-height: 1.5; }
        .sign { margin-top: 24px; width: 100%; }
        .sign td { width: 50%; vertical-align: top; font-size: 10px; }
        .selisih-lebih { color: #b91c1c; }
        .selisih-kurang { color: #047857; }
    </style>
</head>
<body>
    <div class="draft-banner">
        DRAF — BUKAN Bukti Potong pajak resmi &amp; BUKAN berkas e-Bupot XML DJP.
        Dokumen internal, wajib diverifikasi sebelum dilaporkan.
    </div>

    <div class="header">
        <div class="form-code">FORMULIR 1721-A1</div>
        <div class="form-title">Bukti Pemotongan PPh Pasal 21 — Pegawai Tetap (Rekap Setahun {{ $tax_year }})</div>
        <div class="company">{{ $company->name ?? '-' }}</div>
    </div>

    <div class="section-title">A. Identitas Pemotong (Pemberi Kerja)</div>
    <table class="identity">
        <tr>
            <td class="label">Nama Pemberi Kerja</td><td class="sep">:</td>
            <td>{{ $company->name ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">NPWP Pemberi Kerja</td><td class="sep">:</td>
            <td>{{ $company->npwp ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">Alamat</td><td class="sep">:</td>
            <td>{{ $company->address ?? '-' }}</td>
        </tr>
    </table>

    <div class="section-title">B. Identitas Penerima Penghasilan (Pegawai)</div>
    <table class="identity">
        <tr>
            <td class="label">Nama Pegawai</td><td class="sep">:</td>
            <td>{{ $row['employee_name'] }}</td>
        </tr>
        <tr>
            <td class="label">NPWP</td><td class="sep">:</td>
            <td>{{ $npwp ?: '(tidak ber-NPWP)' }}</td>
        </tr>
        <tr>
            <td class="label">Kode / NIK Pegawai</td><td class="sep">:</td>
            <td>{{ $row['employee_code'] ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">Jabatan</td><td class="sep">:</td>
            <td>{{ $row['position_name'] ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">Status PTKP</td><td class="sep">:</td>
            <td>{{ $row['ptkp_status'] }}</td>
        </tr>
        <tr>
            <td class="label">Masa Perolehan</td><td class="sep">:</td>
            <td>{{ $row['months_count'] }} bulan pada tahun {{ $tax_year }}</td>
        </tr>
    </table>

    <div class="section-title">C. Rincian Penghasilan &amp; Perhitungan PPh Pasal 21 (Setahun)</div>
    <table class="calc">
        <thead>
            <tr>
                <th class="no">No.</th>
                <th>Uraian</th>
                <th style="text-align:right;">Jumlah (Rp)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="no">1</td>
                <td>Penghasilan Bruto (teratur + tidak teratur + tunjangan kena pajak)</td>
                <td class="amount">{{ $rp($row['bruto']) }}</td>
            </tr>
            <tr>
                <td class="no">2</td>
                <td>Pengurang — Biaya Jabatan (5%, maks Rp 6.000.000/tahun)</td>
                <td class="amount">{{ $rp($row['biaya_jabatan']) }}</td>
            </tr>
            <tr>
                <td class="no">3</td>
                <td>Pengurang — Iuran Pensiun (JHT 2% + JP 1% ditanggung pegawai)</td>
                <td class="amount">{{ $rp($row['iuran_pensiun']) }}</td>
            </tr>
            <tr class="total">
                <td class="no">4</td>
                <td>Penghasilan Neto (1 − 2 − 3)</td>
                <td class="amount">{{ $rp($row['neto']) }}</td>
            </tr>
            <tr>
                <td class="no">5</td>
                <td>Penghasilan Tidak Kena Pajak (PTKP) — {{ $row['ptkp_status'] }}</td>
                <td class="amount">{{ $rp($row['ptkp']) }}</td>
            </tr>
            <tr class="total">
                <td class="no">6</td>
                <td>Penghasilan Kena Pajak / PKP (4 − 5, dibulatkan ke bawah ribuan)</td>
                <td class="amount">{{ $rp($row['pkp']) }}</td>
            </tr>
            <tr>
                <td class="no">7</td>
                <td>
                    PPh Pasal 21 Terutang Setahun (tarif Pasal 17 UU HPP)
                    @unless($row['has_npwp'])
                        <br><small>+ sanksi 20% lebih tinggi karena tidak ber-NPWP</small>
                    @endunless
                </td>
                <td class="amount">{{ $rp($row['pph21_terutang']) }}</td>
            </tr>
            <tr>
                <td class="no">8</td>
                <td>PPh Pasal 21 Telah Dipotong (akumulasi masa {{ $tax_year }})</td>
                <td class="amount">{{ $rp($row['pph21_dipotong']) }}</td>
            </tr>
            <tr class="grand">
                <td class="no">9</td>
                <td>
                    @if ($row['selisih'] > 0)
                        PPh 21 Kurang Dipotong (7 − 8)
                    @elseif ($row['selisih'] < 0)
                        PPh 21 Lebih Dipotong (7 − 8)
                    @else
                        PPh 21 Nihil (7 − 8)
                    @endif
                </td>
                <td class="amount {{ $row['selisih'] > 0 ? 'selisih-lebih' : ($row['selisih'] < 0 ? 'selisih-kurang' : '') }}">
                    {{ $rp(abs($row['selisih'])) }}
                </td>
            </tr>
        </tbody>
    </table>

    <div class="note">
        <strong>Catatan:</strong> Angka dirangkai on-demand dari rekapitulasi masa pajak
        (<em>employee_tax_period_totals</em>) dan potongan iuran pada slip gaji yang telah disetujui.
        Perhitungan mengikuti aturan biaya jabatan, PTKP, pembulatan PKP, dan tarif Pasal 17 UU HPP.
        Dokumen ini adalah <strong>draf internal</strong> — bukan Bukti Potong resmi DJP dan bukan berkas e-Bupot XML.
        Verifikasi seluruh nilai sebelum pelaporan pajak.
    </div>

    <table class="sign">
        <tr>
            <td></td>
            <td>
                {{ $company->name ?? '' }}<br>
                Dicetak: {{ now()->format('d-m-Y H:i') }}<br><br><br><br>
                (____________________)<br>
                Pemotong / Pemberi Kerja
            </td>
        </tr>
    </table>
</body>
</html>
