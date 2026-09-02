<?php

require_once __DIR__ . '/../expenseflow-backend/vendor/autoload.php';
$app = require_once __DIR__ . '/../expenseflow-backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;

echo "Testing API Hari Libur Indonesia...\n";

$apis = [
    'api-hari-libur' => 'https://api-hari-libur.vercel.app/api?year=2026',
    'dayoffapi' => 'https://dayoffapi.vercel.app/api?year=2026',
    'radyakaze' => 'https://raw.githubusercontent.com/radyakaze/api-hari-libur/main/data/2026.json',
];

foreach ($apis as $name => $url) {
    try {
        echo "\n[$name] GET $url\n";
        $res = Http::timeout(8)->get($url);
        echo "Status: " . $res->status() . "\n";
        if ($res->successful()) {
            $data = $res->json();
            echo "Total items: " . (is_array($data) ? count($data) : 'not array') . "\n";
            echo "Sample items:\n" . json_encode(array_slice($data, 0, 3), JSON_PRETTY_PRINT) . "\n";
        } else {
            echo "Failed body: " . substr($res->body(), 0, 200) . "\n";
        }
    } catch (\Throwable $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
