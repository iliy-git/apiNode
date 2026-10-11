<?php

namespace App\Services;

use App\Database;
use PDO;

class InboundService
{
    public static function getAllInbounds(): array
    {
        $db = Database::getConnection();
        $stmt = $db->query("
            SELECT id, user_id, up, down, total, remark, enable, expiry_time, listen, port, protocol, settings, stream_settings, tag
            FROM inbounds
        ");

        $inbounds = $stmt->fetchAll();

        return array_map(function ($item) {
            return [
                "id"       => (int)$item['id'],
                "remark"   => $item['remark'],
                "port"     => (int)$item['port'],
                "protocol" => $item['protocol'],
                "enable"   => (bool)$item['enable'],
                "listen"   => $item['listen'],
                "tag"      => $item['tag'],
            ];
        }, $inbounds);
    }

    public static function getAllConfigs(string $host): array
    {
        $db = Database::getConnection();
        $query = "
            SELECT id, port, remark, protocol, settings, stream_settings
            FROM inbounds
            WHERE protocol IN ('vless', 'hysteria2', 'hysteria')
            AND settings IS NOT NULL
        ";
        $stmt = $db->query($query);
        $allConfigs = [];

        while ($row = $stmt->fetch()) {
            $settings = json_decode($row['settings'] ?? '{}', true) ?? [];
            $stream = json_decode($row['stream_settings'] ?? '{}', true) ?? [];

            if (!isset($settings['clients']) || !is_array($settings['clients'])) {
                continue;
            }

            foreach ($settings['clients'] as $client) {
                $clientEmail = $client['email'] ?? 'Without-email';
                $generatedLink = LinkGenerator::generate($row, $client, $stream, $host);

                if ($generatedLink) {
                    $allConfigs[] = [
                        'email' => $clientEmail,
                        'link'  => $generatedLink,
                    ];
                }
            }
        }

        return $allConfigs;
    }
}
