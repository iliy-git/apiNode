<?php
header('Content-Type: application/json');

$dbPath = '/etc/x-ui/x-ui.db';
$keyFile = __DIR__ . '/.key';
$savedKey = file_exists($keyFile) ? trim(file_get_contents($keyFile)) : null;

$userToken = $_SERVER['HTTP_X_API_KEY'] ?? null;

if (!$savedKey || $userToken !== $savedKey) {
    http_response_code(403);
    exit(json_encode(["error" => "Access denied."]));
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($uri === '/ping') {
    echo json_encode([
        "status" => "ok",
        "message" => "Connection established and authenticated",
        "server_time" => date('Y-m-d H:i:s')
    ]);
    exit;
}

try {
    $db = new PDO("sqlite:$dbPath", null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        // Добавляем таймаут ожидания, чтобы избежать ошибки "database is locked"
        PDO::ATTR_TIMEOUT => 5
    ]);

    if ($uri === '/email/extend' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $email = $input['email'] ?? null;
        $days = (int)($input['days'] ?? 30); // По умолчанию 30 дней
        
        // Получаем текущую дату окончания из БД, чтобы продлевать ОТ НЕЕ, а не от сегодня
        // Если у пользователя нет записей, берем текущее время
        $stmtCurrent = $db->prepare("SELECT MAX(expiry_time) as last_expiry FROM client_traffics WHERE email = ?");
        $stmtCurrent->execute([$email]);
        $res = $stmtCurrent->fetch(PDO::FETCH_ASSOC);
        
        $currentExpiry = (int)$res['last_expiry'];
        $nowMs = (int)(microtime(true) * 1000);
        
        // Если дата в прошлом или 0, продлеваем от сейчас. Если в будущем - прибавляем дни.
        $baseTime = ($currentExpiry > $nowMs) ? $currentExpiry : $nowMs;
        $newExpiry = $baseTime + ($days * 86400 * 1000);

        error_log("DEBUG [extend]: Request: {$email}, Days: {$days}, New Expiry: {$newExpiry}");

        if (!$email) {
            http_response_code(400);
            exit(json_encode(["error" => "Email is required"]));
        }

        // 1. Обновляем статистику
        error_log("DEBUG [extend]: Executing UPDATE client_traffics...");
        $stmtTraffic = $db->prepare("UPDATE client_traffics SET expiry_time = ?, enable = 1 WHERE email = ?");
        $stmtTraffic->execute([$newExpiry, $email]);
        
        // 1.5 Обновляем таблицу clients
        try {
            $stmtClients = $db->prepare("UPDATE clients SET expiry_time = ?, enable = 1 WHERE email = ?");
            $stmtClients->execute([$newExpiry, $email]);
        } catch (PDOException $e) {
            error_log("DEBUG [extend]: clients table not found.");
        }

        // 2. Обновляем JSON-настройки в inbounds
        error_log("DEBUG [extend]: Updating inbounds...");
        $stmtInbounds = $db->query("SELECT id, settings FROM inbounds");
        $inbounds = $stmtInbounds->fetchAll(PDO::FETCH_ASSOC);

        foreach ($inbounds as $inbound) {
            $settings = json_decode($inbound['settings'], true);
            if (isset($settings['clients']) && is_array($settings['clients'])) {
                $changed = false;
                foreach ($settings['clients'] as &$client) {
                    if (($client['email'] ?? '') === $email) {
                        $client['expiryTime'] = $newExpiry;
                        $changed = true;
                    }
                }
                if ($changed) {
                    $updateInbound = $db->prepare("UPDATE inbounds SET settings = ? WHERE id = ?");
                    $updateInbound->execute([json_encode($settings, JSON_UNESCAPED_UNICODE), $inbound['id']]);
                }
            }
        }

        echo json_encode(["status" => "success", "message" => "Extended by {$days} days", "new_expiry" => $newExpiry]);
        exit;
    }

    if ($uri === '/email') {
        // ... (твой рабочий код для GET /email остается без изменений) ...
        $email = $_GET['email'] ?? null;

        if (!$email) {
            http_response_code(400);
            exit(json_encode(["error" => "Email parameter is required"]));
        }

        $stmtTraffic = $db->prepare("SELECT up, down, total, expiry_time, enable FROM client_traffics WHERE email = ? LIMIT 1");
        $stmtTraffic->execute([$email]);
        $stats = $stmtTraffic->fetch(PDO::FETCH_ASSOC);

        if (!$stats) {
            http_response_code(404);
            exit(json_encode(["error" => "User not found in traffic stats"]));
        }

        $stmtInbounds = $db->prepare("SELECT port, remark, settings, stream_settings FROM inbounds WHERE protocol = 'vless'");
        $stmtInbounds->execute();
        $inbounds = $stmtInbounds->fetchAll(PDO::FETCH_ASSOC);

        $generatedLink = null;
        $host = explode(':', $_SERVER['HTTP_HOST'] ?? '127.0.0.1')[0];

        foreach ($inbounds as $row) {
            $settings = json_decode($row['settings'], true);

            if (!isset($settings['clients']) || !is_array($settings['clients'])) {
                continue;
            }

            foreach ($settings['clients'] as $client) {
                if (($client['email'] ?? '') === $email) {

                    $stream = json_decode($row['stream_settings'], true);
                    $reality = $stream['realitySettings'] ?? [];
                    $networkType = $stream['network'] ?? 'tcp';

                    $paramsArray = [
                        'type'     => $networkType,
                        'security' => $stream['security'] ?? 'reality',
                        'pbk'      => $reality['settings']['publicKey'] ?? '',
                        'fp'       => $reality['settings']['fingerprint'] ?? 'chrome',
                        'sni'      => $reality['serverNames'][0] ?? '',
                        'sid'      => $reality['shortIds'][0] ?? '',
                        'spx'      => $reality['settings']['spiderX'] ?? '/',
                        'flow'     => $client['flow'] ?? ''
                    ];

                    if ($networkType === 'grpc' && !empty($stream['grpcSettings'])) {
                        $paramsArray['serviceName'] = $stream['grpcSettings']['serviceName'] ?? '';
                        if (!empty($stream['grpcSettings']['mode'])) {
                            $paramsArray['mode'] = $stream['grpcSettings']['mode'];
                        }
                    }

                    $params = http_build_query($paramsArray);
                    $generatedLink = "vless://{$client['id']}@{$host}:{$row['port']}?{$params}#" . urlencode($row['remark'] . "-" . $email);
                    break 2;
                }
            }
        }

        echo json_encode([
            "email"       => $email,
            "up"          => (int)$stats['up'],
            "down"        => (int)$stats['down'],
            "total"       => (int)$stats['total'],
            "expiry_time" => (int)$stats['expiry_time'],
            "is_active"   => (bool)$stats['enable'],
            "link"        => $generatedLink
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        exit;
    }

    if ($uri === '/' || $uri === '') {
        // ... (твой рабочий код для GET / остается без изменений) ...
        $host = explode(':', $_SERVER['HTTP_HOST'] ?? '127.0.0.1')[0];
        $query = "
            SELECT i.port, i.remark, i.protocol, i.settings, i.stream_settings,
                   t.email, t.up, t.down, t.total, t.expiry_time
            FROM inbounds i
            JOIN client_traffics t ON i.id = t.inbound_id
            WHERE i.protocol = 'vless'
        ";

        $results = [];
        foreach ($db->query($query) as $row) {
            $settings = json_decode($row['settings'], true);
            $stream = json_decode($row['stream_settings'], true);
            $reality = $stream['realitySettings'] ?? [];
            $networkType = $stream['network'] ?? 'tcp';

            foreach ($settings['clients'] as $client) {
                if ($client['email'] !== $row['email']) continue;

                $paramsArray = [
                    'type' => $networkType,
                    'security' => $stream['security'] ?? 'reality',
                    'pbk' => $reality['settings']['publicKey'] ?? '',
                    'fp' => $reality['settings']['fingerprint'] ?? 'chrome',
                    'sni' => $reality['serverNames'][0] ?? '',
                    'sid' => $reality['shortIds'][0] ?? '',
                    'spx' => $reality['settings']['spiderX'] ?? '/',
                    'flow' => $client['flow'] ?? ''
                ];

                if ($networkType === 'grpc' && !empty($stream['grpcSettings'])) {
                    $paramsArray['serviceName'] = $stream['grpcSettings']['serviceName'] ?? '';
                    if (!empty($stream['grpcSettings']['mode'])) {
                        $paramsArray['mode'] = $stream['grpcSettings']['mode'];
                    }
                }

                $params = http_build_query($paramsArray);

                $results[] = [
                    'email' => $row['email'],
                    'link' => "vless://{$client['id']}@{$host}:{$row['port']}?{$params}#" . urlencode($row['remark']."-".$row['email']),
                    'stats' => [
                        'up' => (int)$row['up'],
                        'down' => (int)$row['down'],
                        'total' => (int)$row['total'],
                        'expiry' => (int)$row['expiry_time']
                    ]
                ];
            }
        }
        echo json_encode($results, JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(404);
    echo json_encode(["error" => "Route not found"]);

} catch (Exception $e) {
    // Пишем саму ошибку в лог докера
    error_log("CRITICAL ERROR: " . $e->getMessage());

    http_response_code(500);
    // Возвращаем текст ошибки в ответе, чтобы ты видел её на стороне Laravel
    echo json_encode([
        "error" => "Internal Server Error",
        "details" => $e->getMessage()
    ]);
}
