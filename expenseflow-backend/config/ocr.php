<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OCR Driver
    |--------------------------------------------------------------------------
    |
    | Pilihan driver OCR yang digunakan oleh OcrService:
    |   - tesseract     : Tesseract OCR (gratis, development)
    |   - google_vision : Google Cloud Vision API (production)
    |
    | Set melalui .env: OCR_DRIVER=tesseract
    |
    */

    'driver' => env('OCR_DRIVER', 'tesseract'),

];
