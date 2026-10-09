<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cek & proses auto-checkout karyawan yang lupa checkout setiap 5 menit.
// Juga mengirim reminder push notification sebelum batas waktu.
Schedule::command('attendance:auto-checkout')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Kategori I: Pengingat presensi cerdas (jam masuk 15m sblm mulai, jam pulang, & cutoff 15m sblm batas alpha).
// Dijalankan setiap 10 menit untuk mengevaluasi window pengingat presensi karyawan mobile (WFH / Dinas Luar).
Schedule::command('attendance:send-reminders')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Auto-decline karyawan yang belum memilih cuti bersama saat hari H tiba.
// Berjalan SETIAP JAM (bukan sekali di jam 00:07), menandai pending → declined
// untuk tanggal cuti yang sudah lewat. Frekuensi jam juga yang membuatnya aman
// lintas zona: cabang WITA/WIT paling banter tertunda 1-2 jam, tidak terlewat.
Schedule::command('attendance:auto-decline-collective-leave')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Auto-reject semua jenis permohonan izin/cuti yang belum diproses HRD saat melewati H+1.
Schedule::command('attendance:auto-reject-expired-leaves')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Kirim notifikasi FCM & dering cuti bersama saat memasuki H-7 s/d Hari H (berbarengan).
// Berjalan secara berkala agar notifikasi langsung terkirim tepat saat hari H-7 tiba.
Schedule::command('attendance:notify-collective-leaves')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Reset otomatis saldo cuti tahunan per kantor (anniversary leave_reset_date).
// MULTI-ZONA: berjalan setiap JAM, bukan harian jam 00:03. Hari lokal cabang WIT
// mulai 2 jam lebih awal dari jam dinding server (00:00 WIT = 22:00 WIB H-1), jadi
// jadwal harian WIB membuat reset cabang timur terlambat 2 jam. Command sendiri
// yang memutuskan kantor mana yang anniversary-nya jatuh tempo menurut TANGGAL
// LOKAL kantor itu; anti-dobel dijaga oleh last_leave_reset_on (sudah idempoten &
// catch-up sejak awal), jadi frekuensi jam tidak menyebabkan reset ganda.
Schedule::command('attendance:reset-leave-balances')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Sync status WFH dan Radius karyawan berdasarkan jadwal shift hari tersebut.
// Hal ini memungkinkan HRD untuk melakukan override (on/off) di tengah hari jika diperlukan.
// MULTI-ZONA: berjalan setiap JAM, bukan harian jam 00:01 — alasan sama seperti di
// atas. Command mengelompokkan karyawan per zona cabang dan hanya menyinkronkan
// zona yang tanggal lokalnya baru berganti; penanda cache per (zona, tanggal lokal)
// menjaga agar override manual HRD di tengah hari tidak tertimpa tick jam berikutnya.
Schedule::command('attendance:sync-shift-wfh')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Rotasi & arsip otomatis audit log lama ke file kompresi .json.gz agar database tetap cepat & ringan.
// Berjalan setiap tanggal 1 awal bulan jam 02:30 dini hari.
// Log rutin/info diarsipkan setelah 90 hari, log warning & critical diarsipkan setelah 365 hari.
Schedule::command('logs:archive-and-prune --days-info=90 --days-critical=365')
    ->monthlyOn(1, '02:30')
    ->withoutOverlapping()
    ->runInBackground();

