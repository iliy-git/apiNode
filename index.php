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
$uri = rtrim($uri, '/'); // Приводим к единому виду (корень станет пустым "")

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

    // === 1. ENDPOINT: ПОДТВЕРЖДЕНИЕ И ПРОДЛЕНИЕ ПОДПИСКИ ===
    if ($uri === '/email/extend' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $email = $input['email'] ?? null;
        $days = (int)($input['days'] ?? 30);
        
        if (!$email) {
            http_response_code(400);
            exit(json_encode(["error" => "Email is required"]));
        }
        
        // Получаем текущий expiry_time
        $stmtCurrent = $db->prepare("
            SELECT expiry_time FROM client_traffics 
            WHERE email = ? 
            ORDER BY expiry_time DESC LIMIT 1
        ");
        $stmtCurrent->execute([$email]);
        $res = $stmtCurrent->fetch(PDO::FETCH_ASSOC);
        
        $currentExpiry = $res ? (int)$res['expiry_time'] : 0;
        $nowMs = (int)(microtime(true) * 1000);
        
        $baseTime = ($currentExpiry > $nowMs) ? $currentExpiry : $nowMs;
        $newExpiry = $baseTime + ($days * 86400 * 1000);
        
        // Обновляем во всех таблицах
        $stmtTraffic = $db->prepare("
            UPDATE client_traffics 
            SET expiry_time = ?, enable = 1 
            WHERE email = ?
        ");
        $stmtTraffic->execute([$newExpiry, $email]);
        
        $stmtClients = $db->prepare("
            UPDATE clients 
            SET expiry_time = ?, enable = 1 
            WHERE email = ?
        ");
        $stmtClients->execute([$newExpiry, $email]);
        
        // Обновляем в settings инбаундов
        $stmtInbounds = $db->query("SELECT id, settings FROM inbounds WHERE protocol IN ('vless', 'hysteria2', 'hysteria')");
        $inbounds = $stmtInbounds->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($inbounds as $inbound) {
            $settings = json_decode($inbound['settings'], true);
            if (isset($settings['clients']) && is_array($settings['clients'])) {
                $changed = false;
                foreach ($settings['clients'] as &$client) {
                    if (($client['email'] ?? '') === $email) {
                        $client['expiryTime'] = $newExpiry;
                        $client['enable'] = true;
                        $changed = true;
                    }
                }
                if ($changed) {
                    $updateInbound = $db->prepare("UPDATE inbounds SET settings = ? WHERE id = ?");
                    $updateInbound->execute([json_encode($settings, JSON_UNESCAPED_UNICODE), $inbound['id']]);
                }
            }
        }
        
        echo json_encode([
            "status" => "success", 
            "message" => "Extended by {$days} days",
            "email" => $email,
            "new_expiry" => $newExpiry,
            "new_expiry_date" => date('Y-m-d H:i:s', $newExpiry / 1000)
        ]);
        exit;
    }
    
    // === 2. ENDPOINT: ВЫГРУЗКА ВСЕХ КОНФИГОВ (Корень /) ===
    if (($uri === '' || $uri === '/') && $_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['email'])) {
        $host = explode(':', $_SERVER['HTTP_HOST'] ?? '127.0.0.1')[0];

        $query = "
            SELECT id, port, remark, protocol, settings, stream_settings
            FROM inbounds
            WHERE protocol IN ('vless', 'hysteria2', 'hysteria')
            AND settings IS NOT NULL
        ";
        $stmt = $db->query($query);
        $allConfigs = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings = json_decode($row['settings'], true) ?? [];
            $stream = json_decode($row['stream_settings'], true) ?? [];

            if (!isset($settings['clients']) || !is_array($settings['clients'])) {
                continue;
            }

            foreach ($settings['clients'] as $client) {
                $clientEmail = $client['email'] ?? 'Without-email';
                $generatedLink = generateLink($row, $client, $stream, $host);

                if ($generatedLink) {
                    $allConfigs[] = [
                        'email' => $clientEmail,
                        'link'  => $generatedLink
                    ];
                }
            }
        }

        echo json_encode($allConfigs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // === 3. ENDPOINT: СТАТИСТИКА ОДНОГО КЛИЕНТА (По email) ===
    if (($uri === '/email' || $uri === '' || $uri === '/') && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['email'])) {
        $email = $_GET['email'];
        $host = explode(':', $_SERVER['HTTP_HOST'] ?? '127.0.0.1')[0];
        
        $query = "
            SELECT id, port, remark, protocol, settings, stream_settings
            FROM inbounds
            WHERE protocol IN ('vless', 'hysteria2', 'hysteria')
            AND settings IS NOT NULL
        ";
        
        $stmt = $db->query($query);
        $found = false;
        $result = null;
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings = json_decode($row['settings'], true) ?? [];
            $stream = json_decode($row['stream_settings'], true) ?? [];
            
            if (!isset($settings['clients']) || !is_array($settings['clients'])) {
                continue;
            }
            
            foreach ($settings['clients'] as $client) {
                $clientEmail = $client['email'] ?? '';
                
                if ($clientEmail === $email) {
                    $found = true;
                    
                    // Получаем трафик из client_traffics
                    $trafficStmt = $db->prepare("
                        SELECT 
                            COALESCE(SUM(up), 0) as up,
                            COALESCE(SUM(down), 0) as down,
                            MAX(expiry_time) as expiry_time,
                            MAX(enable) as enable,
                            COALESCE(MAX(total), 0) as total
                        FROM client_traffics 
                        WHERE email = ?
                    ");
                    $trafficStmt->execute([$email]);
                    $traffic = $trafficStmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Если нет в client_traffics - берем из clients
                    if (!$traffic || ($traffic['up'] == 0 && $traffic['down'] == 0)) {
                        $clientStmt = $db->prepare("
                            SELECT expiry_time, enable, totalGB as total
                            FROM clients 
                            WHERE email = ?
                            LIMIT 1
                        ");
                        $clientStmt->execute([$email]);
                        $clientData = $clientStmt->fetch(PDO::FETCH_ASSOC);
                        
                        $traffic = [
                            'up' => 0,
                            'down' => 0,
                            'expiry_time' => $client['expiryTime'] ?? ($clientData['expiry_time'] ?? 0),
                            'enable' => $client['enable'] ?? ($clientData['enable'] ?? 1),
                            'total' => $client['totalGB'] ?? ($clientData['total'] ?? 0)
                        ];
                    }
                    
                    $generatedLink = generateLink($row, $client, $stream, $host);
                    
                    $totalUsed = (int)$traffic['up'] + (int)$traffic['down'];
                    $totalLimit = (int)$traffic['total'];
                    $expiryTime = (int)$traffic['expiry_time'];
                    $nowMs = (int)(microtime(true) * 1000);
                    $isExpired = ($expiryTime > 0 && $expiryTime < $nowMs);
                    $isActive = ($traffic['enable'] == 1 && !$isExpired && ($totalLimit == 0 || $totalUsed < $totalLimit));
                    
                    $result = [
                        'email' => $email,
                        'link' => $generatedLink,
                        'up' => (int)$traffic['up'],
                        'down' => (int)$traffic['down'],
                        'total' => $totalLimit,
                        'expiry_time' => $expiryTime,
                        'is_active' => $isActive,
                        'enable' => (bool)$traffic['enable']
                    ];
                    
                    break 2;
                }
            }
        }
        
        if (!$found || !$result) {
            http_response_code(404);
            echo json_encode(["error" => "User not found", "email" => $email]);
        } else {
            echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
    
    http_response_code(404);
    echo json_encode(["error" => "Route not found", "uri" => $_SERVER['REQUEST_URI']]);
    
} catch (Exception $e) {
    error_log("CRITICAL ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "error" => "Internal Server Error",
        "details" => $e->getMessage()
    ]);
}

// Функция для генерации ссылок
function generateLink($inbound, $client, $stream, $host) {
    $protocol = strtolower($inbound['protocol']);
    
    // Склейка имени инбаунда и email для точного совпадения ремарки
    $remarkStr = !empty($inbound['remark']) ? ($inbound['remark'] . "-" . $client['email']) : $client['email'];
    
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
        
        // Для gRPC
        if ($networkType === 'grpc' && !empty($stream['grpcSettings'])) {
            $grpcSettings = $stream['grpcSettings'];
            $paramsArray['serviceName'] = $grpcSettings['serviceName'] ?? '';
            
            // Вытаскиваем mode (multi/single)
            if (!empty($grpcSettings['mode'])) {
                $paramsArray['mode'] = $grpcSettings['mode'];
            }
            
            if (!empty($grpcSettings['authority'])) {
                $paramsArray['authority'] = $grpcSettings['authority'];
            } elseif (!empty($reality['serverNames'][0])) {
                $paramsArray['authority'] = $reality['serverNames'][0];
            }
            if ($stream['security'] === 'reality') {
                $paramsArray['encryption'] = 'none';
            }
        }
        
        // Для WebSocket
        if ($networkType === 'ws' && !empty($stream['wsSettings'])) {
            $wsSettings = $stream['wsSettings'];
            $paramsArray['path'] = $wsSettings['path'] ?? '/';
            if (!empty($wsSettings['host'])) {
                $paramsArray['host'] = $wsSettings['host'];
            }
        }
        
        // Убираем пустые параметры
        $paramsArray = array_filter($paramsArray, function($value) {
            return $value !== '' && $value !== null;
        });
        
        ksort($paramsArray); // Сортируем параметры для красоты
        
        $params = http_build_query($paramsArray);
        return "vless://{$client['id']}@{$host}:{$inbound['port']}?" . $params . "#" . urlencode($remarkStr);
        
    } elseif ($protocol === 'hysteria2' || $protocol === 'hysteria') {
        $password = $client['auth'] ?? $client['password'] ?? $client['id'] ?? '';
        $hyParams = [];
        
        if ($protocol === 'hysteria' && isset($stream['hysteriaSettings']['version'])) {
            $hyParams['version'] = $stream['hysteriaSettings']['version'];
        }
        
        $tls = $stream['tlsSettings'] ?? [];
        
        if (!empty($tls['alpn']) && is_array($tls['alpn'])) {
            $hyParams['alpn'] = implode(',', $tls['alpn']);
        }
        
        if (!empty($stream['finalmask'])) {
            if (isset($stream['finalmask']['udp'][0]['type'])) {
                $hyParams['obfs'] = $stream['finalmask']['udp'][0]['type'];
                $obfsPass = $stream['finalmask']['udp'][0]['settings']['password'] ?? '';
                if ($obfsPass) {
                    $hyParams['obfs-password'] = $obfsPass;
                }
            }
        }
        
        $fp = $tls['settings']['fingerprint'] ?? $tls['fingerprint'] ?? 'chrome';
        if ($fp) $hyParams['fingerprint'] = $fp;
        
        $hyParams['security'] = $stream['security'] ?? 'tls';
        
        $sni = $tls['serverName'] ?? $tls['settings']['serverName'] ?? '';
        if ($sni) $hyParams['sni'] = $sni;
        
        ksort($hyParams);
        
        $paramsStr = http_build_query($hyParams);
        return "hysteria2://{$password}@{$host}:{$inbound['port']}" . ($paramsStr ? "?{$paramsStr}" : "") . "#" . urlencode($remarkStr);
    }
    
    return null;
}
?>
