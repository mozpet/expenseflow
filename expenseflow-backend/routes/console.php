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
