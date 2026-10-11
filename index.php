<?php

use App\Auth;
use App\Response;
use App\Router;

header('Content-Type: application/json; charset=utf-8');

// Автозагрузка классов App\
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// Проверка авторизации через API-ключ
if (!Auth::check()) {
    Response::error("Access denied.", 403);
}

// Запуск роутера
try {
    Router::dispatch();
} catch (Throwable $e) {
    error_log("API Error: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? (int)$e->getCode() : 500;
    Response::error("Internal Server Error", $code, [
        "details" => $e->getMessage(),
    ]);
}
