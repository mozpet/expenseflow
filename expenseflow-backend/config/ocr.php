<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OCR Driver
    |--------------------------------------------------------------------------
    |
    | Driver OCR yang digunakan oleh OcrService untuk scan struk:
    |   - gemini        : Google Gemini 1.5 Flash Vision API (100% Gratis, Cerdas)
    |   - google_vision : Google Cloud Vision API (Production dengan Billing)
    |
    */

    'driver' => env('OCR_DRIVER', 'gemini'),

];
