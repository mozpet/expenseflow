<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Skema XSD e-Bupot 21/26
    |--------------------------------------------------------------------------
    |
    | `official` menunjuk berkas XSD RESMI terbitan DJP. Berkas tersebut TIDAK
    | didistribusikan bersama aplikasi (bukan milik kami untuk disebarkan);
    | administrator menempatkannya sendiri pada path ini.
    |
    | - Bila berkas ADA  → ekspor divalidasi terhadap skema DJP; bila lolos,
    |   root ditandai `resmi="true"`. Bila GAGAL, ekspor ditolak (422) beserta
    |   daftar galat — berkas cacat tidak pernah sampai ke pengguna.
    | - Bila berkas TIDAK ADA → ekspor divalidasi terhadap skema internal
    |   (`fallback`) dan tetap ditandai `resmi="false"`.
    |
    | Dengan begitu status "tervalidasi skema" selalu benar apa adanya: XML yang
    | keluar SELALU lolos sebuah XSD, dan label `resmi` hanya naik saat skema
    | resmi DJP-lah yang meloloskannya.
    |
    */

    'schema' => [
        'official' => storage_path('app/private/pajak/ebupot/xsd/ebupot.xsd'),
        'fallback' => resource_path('schemas/ebupot/ebupot-21-internal-v2.xsd'),
        // Label skema yang ditulis pada atribut `skema` root saat memakai fallback.
        'fallback_label' => 'internal-v2',
        'official_label' => 'djp-resmi',
    ],

];
