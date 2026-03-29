<?php
$keyFile = __DIR__ . '/.key';

$action = $argv[1] ?? 'show';

switch ($action) {
    case 'gen':
        $newKey = bin2hex(random_bytes(16));
        file_put_contents($keyFile, $newKey);
        echo "✅ New API Key generated: $newKey\n";
        break;

    case 'show':
        if (file_exists($keyFile)) {
            echo "🔑 Current API Key: " . trim(file_get_contents($keyFile)) . "\n";
        } else {
            echo "❌ Key not found. Use 'php key.php gen' to create one.\n";
        }
        break;

    default:
        echo "Usage:\n  php key.php show - display current key\n  php key.php gen  - generate new key\n";
}
