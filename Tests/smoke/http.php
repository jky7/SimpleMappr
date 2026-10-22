<?php
declare(strict_types=1);
require __DIR__.'/runtime.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    echo json_encode(nativeRuntimeReport(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $error) {
    error_log($error->getMessage());
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Native MapScript check failed']).PHP_EOL;
}
