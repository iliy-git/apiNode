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
        PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY
    ]);

    if ($uri === '/email') {
        $email = $_GET['email'] ?? null;

        if (!$email) {
            http_response_code(400);
            exit(json_encode(["error" => "Email parameter is required"]));
        }

        $stmt = $db->prepare("SELECT up, down, total, expiry_time, enable FROM client_traffics WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($stats) {
            echo json_encode([
                "email"       => $email,
                "up"          => (int)$stats['up'],
                "down"        => (int)$stats['down'],
                "total"       => (int)$stats['total'],
                "expiry_time" => (int)$stats['expiry_time'],
                "is_active"   => (bool)$stats['enable']
            ]);
        } else {
            http_response_code(404);
            echo json_encode(["error" => "User not found"]);
        }
        exit;
    }

    if ($uri === '/' || $uri === '') {
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

            foreach ($settings['clients'] as $client) {
                if ($client['email'] !== $row['email']) continue;

                $params = http_build_query([
                    'type' => $stream['network'] ?? 'tcp',
                    'security' => $stream['security'] ?? 'reality',
                    'pbk' => $reality['settings']['publicKey'] ?? '',
                    'fp' => $reality['settings']['fingerprint'] ?? 'chrome',
                    'sni' => $reality['serverNames'][0] ?? '',
                    'sid' => $reality['shortIds'][0] ?? '',
                    'spx' => $reality['settings']['spiderX'] ?? '/',
                    'flow' => $client['flow'] ?? ''
                ]);

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
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
