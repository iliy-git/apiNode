<?php

namespace App;

use App\Services\ClientService;
use App\Services\InboundService;
use Throwable;

class Router
{
    public static function dispatch(): void
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uri = rtrim($uri, '/');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $host = explode(':', $_SERVER['HTTP_HOST'] ?? '127.0.0.1')[0];

        // 1. Healthcheck: GET /ping
        if ($uri === '/ping' && $method === 'GET') {
            Response::json([
                "status"      => "ok",
                "message"     => "Connection established and authenticated",
                "server_time" => date('Y-m-d H:i:s'),
            ]);
        }

        // 2. Список инбаундов: GET /inbounds
        if ($uri === '/inbounds' && $method === 'GET') {
            $inbounds = InboundService::getAllInbounds();
            Response::json([
                "status" => "success",
                "count"  => count($inbounds),
                "data"   => $inbounds,
            ], 200, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        // 3. Выгрузка всех конфигов: GET / или корень
        if (($uri === '' || $uri === '/') && $method === 'GET' && !isset($_GET['email'])) {
            $configs = InboundService::getAllConfigs($host);
            Response::json($configs);
        }

        // 4. Статистика по конкретному клиенту: GET /email?email=... (или GET /?email=...)
        if (($uri === '/email' || $uri === '' || $uri === '/') && $method === 'GET' && isset($_GET['email'])) {
            $email = trim((string)$_GET['email']);
            if ($email === '') {
                Response::error("Email is required", 400);
            }

            $stats = ClientService::getStats($email, $host);
            if (!$stats) {
                Response::notFound("User not found", ["email" => $email]);
            }

            Response::json($stats);
        }

        // 5. Продление подписки клиента: POST /email/extend
        if ($uri === '/email/extend' && $method === 'POST') {
            $input = self::getJsonInput();
            $email = trim((string)($input['email'] ?? ''));
            $days  = (int)($input['days'] ?? 30);

            if ($email === '') {
                Response::error("Email is required", 400);
            }

            $result = ClientService::extend($email, $days);
            Response::json($result);
        }

        // 6. Добавление / обновление клиента: POST /client/add
        if ($uri === '/client/add' && $method === 'POST') {
            $input     = self::getJsonInput();
            $inboundId = (int)($input['inbound_id'] ?? 0);
            $email     = trim((string)($input['email'] ?? ''));
            $totalGB   = (int)($input['totalGB'] ?? 0);
            $days      = (int)($input['days'] ?? 30);

            if ($inboundId <= 0 || $email === '') {
                Response::error("inbound_id and email are required", 400);
            }

            $result = ClientService::addOrUpdate($inboundId, $email, $totalGB, $days);
            Response::json($result);
        }

        // 7. Удаление клиента: POST /client/delete
        if ($uri === '/client/delete' && $method === 'POST') {
            $input     = self::getJsonInput();
            $email     = trim((string)($input['email'] ?? ''));
            $inboundId = isset($input['inbound_id']) && (int)$input['inbound_id'] > 0
                ? (int)$input['inbound_id']
                : null;

            if ($email === '') {
                Response::error("email is required", 400);
            }

            $result = ClientService::delete($email, $inboundId);
            Response::json($result);
        }

        // 404 Route Not Found
        Response::notFound("Route not found", ["uri" => $_SERVER['REQUEST_URI'] ?? '']);
    }

    private static function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        if (!$raw) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
