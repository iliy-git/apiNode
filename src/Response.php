<?php

namespace App;

class Response
{
    public static function json($data, int $status = 200, int $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, $flags);
        exit;
    }

    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        self::json(array_merge(["error" => $message], $extra), $status);
    }

    public static function notFound(string $message = "Route not found", array $extra = []): void
    {
        self::json(array_merge(["error" => $message], $extra), 404);
    }
}
