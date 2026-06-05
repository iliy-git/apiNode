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
        PDO::ATTR_TIMEOUT => 5
    ]);

    if ($uri === '/email/extend' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $email = $input['email'] ?? null;
        $days = (int)($input['days'] ?? 30);

        $stmtCurrent = $db->prepare("SELECT MAX(expiry_time) as last_expiry FROM client_traffics WHERE email = ?");
        $stmtCurrent->execute([$email]);
        $res = $stmtCurrent->fetch(PDO::FETCH_ASSOC);

        $currentExpiry = (int)$res['last_expiry'];
        $nowMs = (int)(microtime(true) * 1000);

        $baseTime = ($currentExpiry > $nowMs) ? $currentExpiry : $nowMs;
        $newExpiry = $baseTime + ($days * 86400 * 1000);

        if (!$email) {
            http_response_code(400);
            exit(json_encode(["error" => "Email is required"]));
        }

        $stmtTraffic = $db->prepare("UPDATE client_traffics SET expiry_time = ?, enable = 1 WHERE email = ?");
        $stmtTraffic->execute([$newExpiry, $email]);

        try {
            $stmtClients = $db->prepare("UPDATE clients SET expiry_time = ?, enable = 1 WHERE email = ?");
            $stmtClients->execute([$newExpiry, $email]);
        } catch (PDOException $e) {}

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

    if ($uri === '/email' || $uri === '/' || $uri === '') {
        $isSingleEmail = ($uri === '/email');
        
        if ($isSingleEmail) {
            $email = $_GET['email'] ?? null;
            if (!$email) {
                http_response_code(400);
                exit(json_encode(["error" => "Email parameter is required"]));
            }
        }
        
        $host = explode(':', $_SERVER['HTTP_HOST'] ?? '127.0.0.1')[0];
        
        // Получаем все инбаунды с нужными протоколами
        $query = "
            SELECT id, port, remark, protocol, settings, stream_settings
            FROM inbounds
            WHERE protocol IN ('vless', 'hysteria2', 'hysteria')
        ";
        
        $results = [];
        $stmt = $db->query($query);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings = json_decode($row['settings'], true) ?? [];
            $stream = json_decode($row['stream_settings'], true) ?? [];
            
            if (!isset($settings['clients']) || !is_array($settings['clients'])) {
                continue;
            }
            
            // Для каждого клиента в инбаунде
            foreach ($settings['clients'] as $client) {
                $clientEmail = $client['email'] ?? '';
                
                // Пропускаем если нет email
                if (empty($clientEmail)) {
                    continue;
                }
                
                // Фильтр по email если нужно
                if ($isSingleEmail && $clientEmail !== $email) {
                    continue;
                }
                
                // Пытаемся получить трафик из client_traffics
                $trafficStmt = $db->prepare("
                    SELECT up, down, total, expiry_time, enable 
                    FROM client_traffics 
                    WHERE email = ? AND inbound_id = ?
                    LIMIT 1
                ");
                $trafficStmt->execute([$clientEmail, $row['id']]);
                $traffic = $trafficStmt->fetch(PDO::FETCH_ASSOC);
                
                // Если нет в client_traffics - создаём запись по умолчанию
                if (!$traffic) {
                    $traffic = [
                        'up' => 0,
                        'down' => 0,
                        'total' => $client['totalGB'] ?? 0,
                        'expiry_time' => $client['expiryTime'] ?? 0,
                        'enable' => isset($client['enable']) ? ($client['enable'] ? 1 : 0) : 1
                    ];
                }
                
                // Генерация ссылки
                $generatedLink = null;
                $protocol = strtolower($row['protocol']);
                $remarkStr = !empty($row['remark']) ? $row['remark']."-".$clientEmail : $clientEmail;
                
                if ($protocol === 'vless') {
                    $reality = $stream['realitySettings'] ?? [];
                    $networkType = $stream['network'] ?? 'tcp';
                    
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
                    
                    // Убираем пустые параметры
                    $paramsArray = array_filter($paramsArray, function($value) {
                        return $value !== '' && $value !== null;
                    });
                    
                    $params = http_build_query($paramsArray);
                    $generatedLink = "vless://{$client['id']}@{$host}:{$row['port']}?" . $params . "#" . urlencode($remarkStr);
                    
                } elseif ($protocol === 'hysteria2' || $protocol === 'hysteria') {
                    $password = $client['auth'] ?? $client['password'] ?? $client['id'] ?? '';
                    $hyParams = [];
                    $tls = $stream['tlsSettings'] ?? [];
                    
                    if (!empty($tls['alpn']) && is_array($tls['alpn'])) {
                        $hyParams['alpn'] = implode(',', $tls['alpn']);
                    }
                    
                    if (!empty($stream['finalmask'])) {
                        $hyParams['fm'] = json_encode($stream['finalmask']);
                        if (isset($stream['finalmask']['udp'][0]['type'])) {
                            $hyParams['obfs'] = $stream['finalmask']['udp'][0]['type'];
                            $obfsPass = $stream['finalmask']['udp'][0]['settings']['password'] ?? '';
                            if ($obfsPass) {
                                $hyParams['obfs-password'] = $obfsPass;
                            }
                        }
                    }
                    
                    $fp = $tls['settings']['fingerprint'] ?? $tls['fingerprint'] ?? 'chrome';
                    if ($fp) $hyParams['fp'] = $fp;
                    
                    $hyParams['security'] = $stream['security'] ?? 'tls';
                    
                    $sni = $tls['serverName'] ?? $settings['serverName'] ?? '';
                    if ($sni) $hyParams['sni'] = $sni;
                    
                    $paramsStr = http_build_query($hyParams);
                    $generatedLink = "hysteria2://{$password}@{$host}:{$row['port']}" . ($paramsStr ? "?{$paramsStr}" : "") . "#" . urlencode($remarkStr);
                }
                
                $statData = [
                    'email' => $clientEmail,
                    'link' => $generatedLink,
                    'stats' => [
                        'up' => (int)$traffic['up'],
                        'down' => (int)$traffic['down'],
                        'total' => (int)$traffic['total'],
                        'expiry' => (int)$traffic['expiry_time']
                    ]
                ];
                
                if ($isSingleEmail) {
                    echo json_encode([
                        "email" => $clientEmail,
                        "up" => (int)$traffic['up'],
                        "down" => (int)$traffic['down'],
                        "total" => (int)$traffic['total'],
                        "expiry_time" => (int)$traffic['expiry_time'],
                        "is_active" => (bool)$traffic['enable'],
                        "link" => $generatedLink
                    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    exit;
                } else {
                    $results[] = $statData;
                }
            }
        }
        
        if (!$isSingleEmail) {
            echo json_encode($results, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            // Если single email не найден
            http_response_code(404);
            echo json_encode(["error" => "User not found"]);
            exit;
        }
    }
    
    http_response_code(404);
    echo json_encode(["error" => "Route not found"]);
    
} catch (Exception $e) {
    error_log("CRITICAL ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "error" => "Internal Server Error",
        "details" => $e->getMessage()
    ]);
}
