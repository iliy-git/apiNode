<?php

namespace App\Services;

use App\Config;
use App\Database;

class XrayService
{
    /**
     * Получить адрес Xray API сервера (например, 127.0.0.1:62789)
     */
    public static function getApiServer(): string
    {
        $env = Config::getXrayApiServer();
        if ($env !== '') {
            return $env;
        }

        try {
            $db = Database::getConnection();
            $stmt = $db->query("SELECT value FROM settings WHERE key = 'xrayTemplateConfig'");
            $raw = $stmt->fetchColumn();
            if ($raw) {
                $tpl = json_decode($raw, true);
                if (!empty($tpl['inbounds'])) {
                    foreach ($tpl['inbounds'] as $ib) {
                        if (($ib['tag'] ?? '') === 'api' && !empty($ib['port'])) {
                            return '127.0.0.1:' . (int)$ib['port'];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // fallback
        }

        return '127.0.0.1:62789';
    }

    /**
     * Получить путь к бинарнику xray
     */
    public static function getXrayBinary(): string
    {
        return Config::getXrayBinary();
    }

    /**
     * Добавление пользователя в память Xray без перезапуска (xray api adu)
     *
     * @param array $inbound Массив инбаунда (tag, port, protocol)
     * @param array $client  Данные клиента (id/uuid, email, flow, password, auth)
     * @return array Результат выполнения команды
     */
    public static function addUser(array $inbound, array $client): array
    {
        $tag      = trim((string)($inbound['tag'] ?? ''));
        $port     = (int)($inbound['port'] ?? 443);
        $protocol = strtolower(trim((string)($inbound['protocol'] ?? 'vless')));
        $email    = trim((string)($client['email'] ?? ''));
        $uuid     = trim((string)($client['id'] ?? $client['uuid'] ?? ''));
        $flow     = trim((string)($client['flow'] ?? ''));

        if ($tag === '' || $email === '' || $uuid === '') {
            return [
                'success' => false,
                'message' => 'Tag, email and uuid are required for Xray API addUser',
            ];
        }

        // Перед добавлением удаляем старую запись (если была), чтобы избежать ошибки "already exists" при обновлении
        self::removeUser($tag, $email);

        $clientConfig = [
            'email' => $email,
        ];

        if ($protocol === 'vless') {
            $clientConfig['id'] = $uuid;
            if ($flow !== '') {
                $clientConfig['flow'] = $flow;
            }
            $settings = [
                'decryption' => 'none',
                'clients'    => [$clientConfig],
            ];
        } elseif ($protocol === 'vmess') {
            $clientConfig['id']      = $uuid;
            $clientConfig['alterId'] = (int)($client['alterId'] ?? 0);
            $settings = [
                'clients' => [$clientConfig],
            ];
        } elseif ($protocol === 'trojan') {
            $clientConfig['password'] = $client['password'] ?? $uuid;
            $settings = [
                'clients' => [$clientConfig],
            ];
        } elseif ($protocol === 'hysteria' || $protocol === 'hysteria2') {
            $clientConfig['auth'] = $client['auth'] ?? $client['password'] ?? $uuid;
            $settings = [
                'version' => 2,
                'clients' => [$clientConfig],
            ];
        } else {
            $clientConfig['id'] = $uuid;
            $settings = [
                'clients' => [$clientConfig],
            ];
        }

        $payload = [
            'inbounds' => [
                [
                    'tag'      => $tag,
                    'port'     => $port,
                    'protocol' => $protocol,
                    'settings' => $settings,
                ]
            ]
        ];

        $tmpFile = tempnam(sys_get_temp_dir(), 'xray_adu_');
        file_put_contents($tmpFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $bin    = self::getXrayBinary();
        $server = self::getApiServer();
        $cmd    = escapeshellcmd($bin) . ' api adu --server=' . escapeshellarg($server) . ' ' . escapeshellarg($tmpFile) . ' 2>&1';

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);
        @unlink($tmpFile);

        $outputStr = trim(implode("\n", $output));
        $success = ($returnCode === 0) && (str_contains($outputStr, 'result: ok') || str_contains($outputStr, 'Added 1 user'));

        return [
            'success' => $success,
            'tag'     => $tag,
            'email'   => $email,
            'output'  => $outputStr,
            'code'    => $returnCode,
        ];
    }

    /**
     * Удаление пользователя из памяти Xray без перезапуска (xray api rmu)
     *
     * @param string $tag   Тег инбаунда
     * @param string $email Email клиента
     * @return array Результат выполнения команды
     */
    public static function removeUser(string $tag, string $email): array
    {
        $tag   = trim($tag);
        $email = trim($email);

        if ($tag === '' || $email === '') {
            return [
                'success' => false,
                'message' => 'Tag and email are required for Xray API removeUser',
            ];
        }

        $bin    = self::getXrayBinary();
        $server = self::getApiServer();
        $cmd    = escapeshellcmd($bin) . ' api rmu --server=' . escapeshellarg($server) . ' -tag=' . escapeshellarg($tag) . ' ' . escapeshellarg($email) . ' 2>&1';

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        $outputStr = trim(implode("\n", $output));
        $success = str_contains($outputStr, 'Removed 1 user') || str_contains($outputStr, 'not found') || ($returnCode === 0);

        return [
            'success' => $success,
            'tag'     => $tag,
            'email'   => $email,
            'output'  => $outputStr,
            'code'    => $returnCode,
        ];
    }

    /**
     * Проверка, есть ли пользователь в памяти ядра Xray (xray api inbounduser)
     */
    public static function isUserActive(string $tag, string $email): bool
    {
        $bin    = self::getXrayBinary();
        $server = self::getApiServer();
        $cmd    = escapeshellcmd($bin) . ' api inbounduser --server=' . escapeshellarg($server) . ' -tag=' . escapeshellarg($tag) . ' -email=' . escapeshellarg($email) . ' 2>&1';

        $output = [];
        exec($cmd, $output);
        $raw = implode("\n", $output);

        if (str_contains($raw, '"email": "' . $email . '"')) {
            return true;
        }

        return false;
    }
}
