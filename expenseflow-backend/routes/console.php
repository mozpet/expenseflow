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

// Auto-decline karyawan yang belum memilih cuti bersama saat hari H tiba.
// Berjalan setiap jam 00:07 (ambil jam non-round untuk distribusi beban),
// menandai pending → declined untuk tanggal cuti yang sudah lewat.
Schedule::command('attendance:auto-decline-collective-leave')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
