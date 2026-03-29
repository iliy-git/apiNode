<?php
header('Content-Type: application/json');

// --- Инициализация ---
$dbPath = '/etc/x-ui/x-ui.db';
$keyFile = __DIR__ . '/.key';
$savedKey = file_exists($keyFile) ? trim(file_get_contents($keyFile)) : null;

// --- 1. ПРОВЕРКА КЛЮЧА (ОБЯЗАТЕЛЬНО ДЛЯ ВСЕХ) ---
$userToken = $_SERVER['HTTP_X_API_KEY'] ?? null;

if (!$savedKey || $userToken !== $savedKey) {
    http_response_code(403);
    echo json_encode(["error" => "Access denied. Invalid or missing X-API-KEY header."]);
    exit;
}

// --- 2. ОПРЕДЕЛЕНИЕ РОУТА ---
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Роут: PING
if ($uri === '/ping') {
    echo json_encode([
        "status" => "ok",
        "message" => "Connection established and authenticated",
        "node" => "Asus-S14", // Опционально: имя ноды для удобства
        "server_time" => date('Y-m-d H:i:s')
    ]);
    exit;
}

// --- 3. ОСНОВНОЙ РОУТ (ВЫДАЧА КОНФИГОВ) ---
$host = explode(':', $_SERVER['HTTP_HOST'] ?? '127.0.0.1')[0];

try {
    $db = new PDO("sqlite:$dbPath", null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY
    ]);

    $stmt = $db->query("SELECT * FROM inbounds");
    $links = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['protocol'] !== 'vless') continue;
        
        $settings = json_decode($row['settings'], true);
        $stream = json_decode($row['stream_settings'], true);
        $reality = $stream['realitySettings'] ?? [];

        foreach ($settings['clients'] as $client) {
            $query = http_build_query([
                'type' => $stream['network'] ?? 'tcp',
                'security' => $stream['security'] ?? 'reality',
                'pbk' => $reality['settings']['publicKey'] ?? '',
                'fp' => $reality['settings']['fingerprint'] ?? 'chrome',
                'sni' => $reality['serverNames'][0] ?? '',
                'sid' => $reality['shortIds'][0] ?? '',
                'spx' => $reality['settings']['spiderX'] ?? '/',
                'flow' => $client['flow'] ?? ''
            ]);
            $remark = urlencode(($row['remark'] ?: 'VPN') . "-" . ($client['email'] ?? 'user'));
            $links[] = "vless://{$client['id']}@{$host}:{$row['port']}?{$query}#{$remark}";
        }
    }
    echo json_encode($links, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
