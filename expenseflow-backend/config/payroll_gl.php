<?php

/**
 * Definisi bucket (pos) Jurnal Akuntansi / General Ledger untuk ekspor payroll — Fase 4 (lanjutan).
 *
 * File ini mendefinisikan HIMPUNAN POS JURNAL KANONIK beserta kode & nama akun DEFAULT
 * (skema Chart of Accounts umum Indonesia). Default dipakai apa adanya bila sebuah
 * perusahaan BELUM mengatur pemetaannya sendiri, sehingga ekspor GL selalu jalan
 * "out-of-the-box". Perusahaan dapat menimpa (override) kode/nama tiap pos lewat tabel
 * `payroll_gl_accounts` (editable per-perusahaan) — lihat PayrollGlComposer::resolveAccounts().
 *
 * PENTING: daftar KEY di sini bersifat kanonik & tetap. PayrollGlComposer memetakan
 * setiap baris slip (payslip_items) ke salah satu key ini. Menamb/mengubah key berarti
 * mengubah kontrak composer & FE — jangan diubah tanpa menyesuaikan keduanya.
 *
 * Sisi (side):
 *   - debit  = beban/harta (pengeluaran perusahaan)
 *   - credit = utang/pengurang (kewajiban yang harus disetor / kas keluar)
 *
 * Bukti selalu seimbang (dibuktikan di composer & diuji):
 *   Debit  = Σ(pendapatan slip) + BPJS perusahaan  = total_gross + total_bpjs_company
 *   Credit = Σ(potongan slip) + BPJS perusahaan + gaji bersih
 *          = total_deduction + total_bpjs_company + total_net
 *   karena total_net = total_gross − total_deduction ⇒ Debit == Credit.
 */

return [
    // Urutan array = urutan tampil baris jurnal (debit dulu, lalu kredit).
    'buckets' => [
        // ─── Sisi DEBIT (beban perusahaan) ───────────────────────────────
        'expense_salary' => [
            'label'        => 'Beban Gaji Pokok & Tunjangan',
            'side'         => 'debit',
            'default_code' => '5100',
            'default_name' => 'Beban Gaji',
        ],
        'expense_overtime' => [
            'label'        => 'Beban Tunjangan Lembur',
            'side'         => 'debit',
            'default_code' => '5110',
            'default_name' => 'Beban Lembur',
        ],
        'expense_reimbursement' => [
            'label'        => 'Beban Reimbursement (Struk)',
            'side'         => 'debit',
            'default_code' => '5120',
            'default_name' => 'Beban Reimbursement',
        ],
        'expense_adjustment' => [
            'label'        => 'Beban Penyesuaian / Koreksi Gaji',
            'side'         => 'debit',
            'default_code' => '5130',
            'default_name' => 'Beban Penyesuaian Gaji',
        ],
        'expense_thr' => [
            'label'        => 'Beban Tunjangan Hari Raya (THR)',
            'side'         => 'debit',
            'default_code' => '5150',
            'default_name' => 'Beban THR',
        ],
        'expense_bpjs_company' => [
            'label'        => 'Beban Iuran BPJS (Perusahaan)',
            'side'         => 'debit',
            'default_code' => '5140',
            'default_name' => 'Beban BPJS Perusahaan',
        ],
        // Fase 6 — Exit Settlement. UP/UPMK/UPH, uang pisah, potongan aset, dan
        // Uang Kompensasi PKWT semuanya bermuara ke pos beban pesangon agar
        // biaya pengakhiran hubungan kerja terbaca terpisah dari beban gaji rutin.
        'expense_severance' => [
            'label'        => 'Beban Pesangon & Kompensasi Pengakhiran',
            'side'         => 'debit',
            'default_code' => '5160',
            'default_name' => 'Beban Pesangon',
        ],

        // ─── Sisi KREDIT (utang / pengurang / kas keluar) ─────────────────
        'payable_pph21' => [
            'label'        => 'Utang PPh 21 (disetor ke negara)',
            'side'         => 'credit',
            'default_code' => '2101',
            'default_name' => 'Utang PPh 21',
        ],
        'payable_bpjs_employee' => [
            'label'        => 'Utang BPJS (potongan karyawan)',
            'side'         => 'credit',
            'default_code' => '2102',
            'default_name' => 'Utang BPJS Karyawan',
        ],
        'payable_bpjs_company' => [
            'label'        => 'Utang BPJS (iuran perusahaan)',
            'side'         => 'credit',
            'default_code' => '2103',
            'default_name' => 'Utang BPJS Perusahaan',
        ],
        'receivable_loan' => [
            'label'        => 'Pelunasan Piutang Karyawan (kasbon)',
            'side'         => 'credit',
            'default_code' => '1201',
            'default_name' => 'Piutang Karyawan',
        ],
        'payable_other' => [
            'label'        => 'Potongan Lain / Titipan',
            'side'         => 'credit',
            'default_code' => '2109',
            'default_name' => 'Utang Potongan Lain',
        ],
        'payable_net' => [
            'label'        => 'Gaji Bersih Dibayar (Kas / Bank)',
            'side'         => 'credit',
            'default_code' => '1101',
            'default_name' => 'Kas / Bank',
        ],
    ],
];
