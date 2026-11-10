<?php

namespace App\Services;

use App\Config;
use App\Database;
use PDO;

class ClientService
{
    public static function getStats(string $email, string $host): ?array
    {
        $db = Database::getConnection();

        $query = "
            SELECT id, port, remark, protocol, settings, stream_settings
            FROM inbounds
            WHERE settings IS NOT NULL
        ";

        $stmt = $db->query($query);

        while ($row = $stmt->fetch()) {
            $settings = json_decode($row['settings'] ?? '{}', true) ?? [];
            $stream = json_decode($row['stream_settings'] ?? '{}', true) ?? [];

            if (!isset($settings['clients']) || !is_array($settings['clients'])) {
                continue;
            }

            foreach ($settings['clients'] as $client) {
                if (($client['email'] ?? '') !== $email) {
                    continue;
                }

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
                $traffic = $trafficStmt->fetch();

                if (!$traffic || ($traffic['up'] == 0 && $traffic['down'] == 0)) {
                    $clientStmt = $db->prepare("
                        SELECT expiry_time, enable, total_gb
                        FROM clients
                        WHERE email = ?
                        LIMIT 1
                    ");
                    $clientStmt->execute([$email]);
                    $clientData = $clientStmt->fetch();

                    $traffic = [
                        'up'          => 0,
                        'down'        => 0,
                        'total'       => $clientData['total_gb'] ?? $client['totalGB'] ?? 0,
                        'expiry_time' => $clientData['expiry_time'] ?? $client['expiryTime'] ?? 0,
                        'enable'      => $clientData ? (bool)$clientData['enable'] : ($client['enable'] ?? true),
                    ];
                }

                $totalUsed = (int)$traffic['up'] + (int)$traffic['down'];
                $totalLimit = (int)$traffic['total'];
                $expiryTime = (int)$traffic['expiry_time'];
                $nowMs = (int)(microtime(true) * 1000);

                $isExpired = ($expiryTime > 0 && $expiryTime < $nowMs);
                $isActive = ($traffic['enable'] && !$isExpired && ($totalLimit == 0 || $totalUsed < $totalLimit));

                $generatedLink = LinkGenerator::generate($row, $client, $stream, $host);

                return [
                    'email'       => $email,
                    'inbound_id'  => (int)$row['id'],
                    'link'        => $generatedLink,
                    'up'          => (int)$traffic['up'],
                    'down'        => (int)$traffic['down'],
                    'total'       => $totalLimit,
                    'expiry_time' => $expiryTime,
                    'is_active'   => $isActive,
                    'enable'      => (bool)$traffic['enable'],
                ];
            }
        }

        return null;
    }

    public static function extend(string $email, int $days): array
    {
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $result = self::processSingleExtend($db, $email, $days);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // Обновляем клиента в памяти ядра Xray (без перезагрузки Xray)
        self::ensureClientActiveInXray($email);

        return $result;
    }

    public static function extendBatch(array $emails, int $days): array
    {
        $db = Database::getConnection();
        $db->beginTransaction();

        $results = [];
        try {
            foreach ($emails as $email) {
                $email = trim((string)$email);
                if ($email !== '') {
                    $results[] = self::processSingleExtend($db, $email, $days);
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // Обновляем клиентов в памяти ядра Xray (без перезагрузки Xray)
        foreach ($emails as $email) {
            $email = trim((string)$email);
            if ($email !== '') {
                self::ensureClientActiveInXray($email);
            }
        }

        return [
            "status"  => "success",
            "count"   => count($results),
            "results" => $results,
        ];
    }

    private static function processSingleExtend(PDO $db, string $email, int $days): array
    {
        $stmtCurrent = $db->prepare("
            SELECT expiry_time FROM client_traffics
            WHERE email = ?
            ORDER BY expiry_time DESC LIMIT 1
        ");
        $stmtCurrent->execute([$email]);
        $res = $stmtCurrent->fetch();

        $currentExpiry = $res ? (int)$res['expiry_time'] : 0;
        $nowMs = (int)(microtime(true) * 1000);

        $baseTime = ($currentExpiry > $nowMs) ? $currentExpiry : $nowMs;
        $newExpiry = $baseTime + ($days * 86400 * 1000);

        $resetTrafficSql = ($days >= 30) ? ", up = 0, down = 0" : "";

        $stmtTraffic = $db->prepare("
            UPDATE client_traffics
            SET expiry_time = ?, enable = 1 {$resetTrafficSql}
            WHERE email = ?
        ");
        $stmtTraffic->execute([$newExpiry, $email]);

        $stmtClients = $db->prepare("
            UPDATE clients
            SET expiry_time = ?, enable = 1
            WHERE email = ?
        ");
        $stmtClients->execute([$newExpiry, $email]);

        $stmtInbounds = $db->query("SELECT id, settings FROM inbounds WHERE settings IS NOT NULL");
        $inbounds = $stmtInbounds->fetchAll();

        foreach ($inbounds as $inbound) {
            $settings = json_decode($inbound['settings'] ?? '{}', true);
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

        return [
            "status"          => "success",
            "message"         => "Extended by {$days} days",
            "email"           => $email,
            "new_expiry"      => $newExpiry,
            "new_expiry_date" => date('Y-m-d H:i:s', (int)($newExpiry / 1000)),
        ];
    }

    public static function addOrUpdate(int $inboundId, string $email, int $totalGB, int $days, string $host = ''): array
    {
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $result = self::processSingleAddOrUpdate($db, $inboundId, $email, $totalGB, $days, $host);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // Добавляем клиента в память Xray на лету (без перезагрузки Xray)
        $xrayPush = self::pushClientToXray($inboundId, $email);
        $result['xray_live_applied'] = $xrayPush;

        return $result;
    }

    public static function addOrUpdateBatch(array $items, string $host = ''): array
    {
        $db = Database::getConnection();
        $db->beginTransaction();

        $results = [];
        try {
            foreach ($items as $item) {
                $inboundId = (int)($item['inbound_id'] ?? 0);
                $email     = trim((string)($item['email'] ?? ''));
                $totalGB   = (int)($item['totalGB'] ?? 0);
                $days      = (int)($item['days'] ?? 30);

                if ($inboundId <= 0 || $email === '') {
                    continue;
                }

                $results[] = self::processSingleAddOrUpdate($db, $inboundId, $email, $totalGB, $days, $host);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // Применяем всех клиентов в память Xray на лету
        foreach ($results as &$res) {
            $res['xray_live_applied'] = self::pushClientToXray((int)$res['inbound_id'], $res['email']);
        }

        return [
            "status"  => "success",
            "count"   => count($results),
            "results" => $results,
        ];
    }

    private static function processSingleAddOrUpdate(PDO $db, int $inboundId, string $email, int $totalGB, int $days, string $host = ''): array
    {
        $stmtInbound = $db->prepare("SELECT id, tag, port, protocol, settings, stream_settings, remark FROM inbounds WHERE id = ?");
        $stmtInbound->execute([$inboundId]);
        $inbound = $stmtInbound->fetch();

        if (!$inbound) {
            throw new \RuntimeException("Inbound not found (ID: {$inboundId})", 404);
        }

        $settings = json_decode($inbound['settings'] ?? '{}', true) ?? [];
        if (!isset($settings['clients']) || !is_array($settings['clients'])) {
            $settings['clients'] = [];
        }

        $nowMs = (int)(microtime(true) * 1000);
        $expiryTime = $days > 0 ? ($nowMs + ($days * 86400 * 1000)) : 0;
        $totalBytes = $totalGB * 1073741824;

        $clientUuid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        $subId = bin2hex(random_bytes(8));
        $auth  = bin2hex(random_bytes(8));
        $flow  = '';

        $clientIndex = -1;
        foreach ($settings['clients'] as $idx => $c) {
            if (($c['email'] ?? '') === $email) {
                $clientIndex = $idx;
                break;
            }
        }

        if ($clientIndex !== -1) {
            $settings['clients'][$clientIndex]['expiryTime'] = $expiryTime;
            $settings['clients'][$clientIndex]['totalGB']    = $totalBytes;
            $settings['clients'][$clientIndex]['enable']     = true;
            $settings['clients'][$clientIndex]['updated_at'] = $nowMs;

            $clientUuid = $settings['clients'][$clientIndex]['id'] ?? $clientUuid;
            $subId      = $settings['clients'][$clientIndex]['subId'] ?? $subId;
            $auth       = $settings['clients'][$clientIndex]['auth'] ?? $auth;
            $flow       = $settings['clients'][$clientIndex]['flow'] ?? $flow;
        } else {
            // Если reality TCP, по умолчанию можно использовать xtls-rprx-vision
            $stream = json_decode($inbound['stream_settings'] ?? '{}', true) ?? [];
            if (($inbound['protocol'] ?? '') === 'vless' && ($stream['security'] ?? '') === 'reality' && ($stream['network'] ?? 'tcp') === 'tcp') {
                $flow = 'xtls-rprx-vision';
            }

            $settings['clients'][] = [
                "id"              => $clientUuid,
                "password"        => $clientUuid,
                "auth"            => $auth,
                "email"           => $email,
                "enable"          => true,
                "expiryTime"      => $expiryTime,
                "totalGB"         => $totalBytes,
                "subId"           => $subId,
                "flow"            => $flow,
                "created_at"      => $nowMs,
                "updated_at"      => $nowMs,
                "limitIp"         => 0,
                "security"        => "auto",
                "tgId"            => 0,
                "reset"           => 0,
                "resetDay"        => 0,
                "resetMax"        => 0,
                "trafficReset"    => "never",
                "trafficResetDay" => 1,
                "comment"         => "",
            ];
        }

        $updateInbound = $db->prepare("UPDATE inbounds SET settings = ? WHERE id = ?");
        $updateInbound->execute([json_encode($settings, JSON_UNESCAPED_UNICODE), $inboundId]);

        $stmtClients = $db->prepare("
            INSERT INTO clients (
                email, sub_id, uuid, password, auth, enable, expiry_time, total_gb, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?)
            ON CONFLICT(email) DO UPDATE SET
                enable = 1,
                expiry_time = excluded.expiry_time,
                total_gb = excluded.total_gb,
                updated_at = excluded.updated_at
        ");
        $stmtClients->execute([$email, $subId, $clientUuid, $clientUuid, $auth, $expiryTime, $totalBytes, $nowMs, $nowMs]);

        $stmtGetClientId = $db->prepare("SELECT id FROM clients WHERE email = ?");
        $stmtGetClientId->execute([$email]);
        $clientId = $stmtGetClientId->fetchColumn();

        if ($clientId) {
            $stmtClientInbounds = $db->prepare("
                INSERT INTO client_inbounds (client_id, inbound_id, created_at)
                VALUES (?, ?, ?)
                ON CONFLICT(client_id, inbound_id) DO NOTHING
            ");
            $stmtClientInbounds->execute([$clientId, $inboundId, $nowMs]);
        }

        $stmtTraffic = $db->prepare("
            INSERT INTO client_traffics (inbound_id, enable, email, up, down, expiry_time, total, reset)
            VALUES (?, 1, ?, 0, 0, ?, ?, 0)
            ON CONFLICT(email) DO UPDATE SET
                inbound_id = excluded.inbound_id,
                enable = 1,
                expiry_time = excluded.expiry_time,
                total = excluded.total
        ");
        $stmtTraffic->execute([$inboundId, $email, $expiryTime, $totalBytes]);

        $link = '';
        if ($host !== '') {
            $stream = json_decode($inbound['stream_settings'] ?? '{}', true) ?? [];
            $clientForLink = [
                'id'       => $clientUuid,
                'email'    => $email,
                'flow'     => $flow,
                'password' => $clientUuid,
                'auth'     => $auth,
            ];
            $link = LinkGenerator::generate($inbound, $clientForLink, $stream, $host) ?? '';
        }

        return [
            "status"      => "success",
            "message"     => "Client configured and activated in Xray (no restart)",
            "client_id"   => $clientId,
            "inbound_id"  => $inboundId,
            "email"       => $email,
            "uuid"        => $clientUuid,
            "link"        => $link,
            "expiry_date" => $expiryTime > 0 ? date('Y-m-d H:i:s', (int)($expiryTime / 1000)) : "never",
        ];
    }

    public static function delete(string $email, ?int $inboundId = null): array
    {
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $result = self::processSingleDelete($db, $email, $inboundId);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // Удаляем из памяти Xray на лету (без перезагрузки Xray)
        if (!empty($result['removed_inbound_tags'])) {
            foreach ($result['removed_inbound_tags'] as $tag) {
                XrayService::removeUser($tag, $email);
            }
        }

        return $result;
    }

    public static function deleteBatch(array $emails, ?int $inboundId = null): array
    {
        $db = Database::getConnection();
        $db->beginTransaction();

        $results = [];
        try {
            foreach ($emails as $email) {
                $email = trim((string)$email);
                if ($email !== '') {
                    $results[] = self::processSingleDelete($db, $email, $inboundId);
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // Удаляем из памяти Xray на лету (без перезагрузки Xray)
        foreach ($results as $res) {
            if (!empty($res['removed_inbound_tags'])) {
                foreach ($res['removed_inbound_tags'] as $tag) {
                    XrayService::removeUser($tag, $res['email']);
                }
            }
        }

        return [
            "status"  => "success",
            "count"   => count($results),
            "results" => $results,
        ];
    }

    private static function processSingleDelete(PDO $db, string $email, ?int $inboundId = null): array
    {
        $inboundsQuery = $inboundId && $inboundId > 0
            ? "SELECT id, tag, settings FROM inbounds WHERE id = ?"
            : "SELECT id, tag, settings FROM inbounds WHERE settings IS NOT NULL";

        $stmtInbounds = $db->prepare($inboundsQuery);
        $stmtInbounds->execute($inboundId && $inboundId > 0 ? [$inboundId] : []);
        $inbounds = $stmtInbounds->fetchAll();

        $removedFromInbounds = 0;
        $removedInboundTags = [];

        foreach ($inbounds as $inbound) {
            $settings = json_decode($inbound['settings'] ?? '{}', true);
            if (isset($settings['clients']) && is_array($settings['clients'])) {
                $countBefore = count($settings['clients']);
                $settings['clients'] = array_values(array_filter(
                    $settings['clients'],
                    fn($c) => ($c['email'] ?? '') !== $email
                ));
                if (count($settings['clients']) !== $countBefore) {
                    $stmtUpdate = $db->prepare("UPDATE inbounds SET settings = ? WHERE id = ?");
                    $stmtUpdate->execute([json_encode($settings, JSON_UNESCAPED_UNICODE), $inbound['id']]);
                    $removedFromInbounds++;
                    if (!empty($inbound['tag'])) {
                        $removedInboundTags[] = $inbound['tag'];
                    }
                }
            }
        }

        if ($inboundId && $inboundId > 0) {
            $stmtDelRel = $db->prepare("
                DELETE FROM client_inbounds 
                WHERE inbound_id = ? 
                AND client_id IN (SELECT id FROM clients WHERE email = ?)
            ");
            $stmtDelRel->execute([$inboundId, $email]);

            $stmtCheck = $db->prepare("
                SELECT COUNT(*) FROM client_inbounds 
                WHERE client_id IN (SELECT id FROM clients WHERE email = ?)
            ");
            $stmtCheck->execute([$email]);
            $remaining = (int)$stmtCheck->fetchColumn();

            if ($remaining === 0) {
                $stmtDelTraffic = $db->prepare("DELETE FROM client_traffics WHERE email = ?");
                $stmtDelTraffic->execute([$email]);

                $stmtDelClient = $db->prepare("DELETE FROM clients WHERE email = ?");
                $stmtDelClient->execute([$email]);
            }
        } else {
            $stmtDelRel = $db->prepare("
                DELETE FROM client_inbounds 
                WHERE client_id IN (SELECT id FROM clients WHERE email = ?)
            ");
            $stmtDelRel->execute([$email]);

            $stmtDelTraffic = $db->prepare("DELETE FROM client_traffics WHERE email = ?");
            $stmtDelTraffic->execute([$email]);

            $stmtDelClient = $db->prepare("DELETE FROM clients WHERE email = ?");
            $stmtDelClient->execute([$email]);
        }

        return [
            "status"                => "success",
            "message"               => "Client removed successfully (no restart)",
            "email"                 => $email,
            "inbound_id"            => $inboundId,
            "removed_from_inbounds" => $removedFromInbounds,
            "removed_inbound_tags"  => $removedInboundTags,
        ];
    }

    public static function getAllClientsStats(string $host): array
    {
        $db = Database::getConnection();

        $trafficStmt = $db->query("
            SELECT
                email,
                COALESCE(SUM(up), 0) as up,
                COALESCE(SUM(down), 0) as down,
                MAX(expiry_time) as expiry_time,
                MAX(enable) as enable,
                COALESCE(MAX(total), 0) as total
            FROM client_traffics
            GROUP BY email
        ");
        $traffics = [];
        while ($row = $trafficStmt->fetch()) {
            $traffics[$row['email']] = $row;
        }

        $inboundsStmt = $db->query("
            SELECT id, port, remark, protocol, settings, stream_settings
            FROM inbounds
            WHERE settings IS NOT NULL
        ");
        $inbounds = $inboundsStmt->fetchAll();

        $nowMs = (int)(microtime(true) * 1000);
        $result = [];

        foreach ($inbounds as $inbound) {
            $settings = json_decode($inbound['settings'] ?? '{}', true) ?? [];
            $stream = json_decode($inbound['stream_settings'] ?? '{}', true) ?? [];

            if (!isset($settings['clients']) || !is_array($settings['clients'])) {
                continue;
            }

            foreach ($settings['clients'] as $client) {
                $email = $client['email'] ?? '';
                if ($email === '') {
                    continue;
                }

                $traffic = $traffics[$email] ?? null;

                $up = $traffic ? (int)$traffic['up'] : 0;
                $down = $traffic ? (int)$traffic['down'] : 0;
                $totalUsed = $up + $down;
                $totalLimit = $traffic && $traffic['total'] > 0
                    ? (int)$traffic['total']
                    : (int)($client['totalGB'] ?? 0);
                $expiryTime = $traffic && $traffic['expiry_time'] > 0
                    ? (int)$traffic['expiry_time']
                    : (int)($client['expiryTime'] ?? 0);
                $enable = $traffic ? (bool)$traffic['enable'] : (bool)($client['enable'] ?? true);

                $isExpired = ($expiryTime > 0 && $expiryTime < $nowMs);
                $isActive = ($enable && !$isExpired && ($totalLimit == 0 || $totalUsed < $totalLimit));

                $generatedLink = LinkGenerator::generate($inbound, $client, $stream, $host);

                $result[] = [
                    'email'       => $email,
                    'inbound_id'  => (int)$inbound['id'],
                    'link'        => $generatedLink,
                    'up'          => $up,
                    'down'        => $down,
                    'total'       => $totalLimit,
                    'expiry_time' => $expiryTime,
                    'is_active'   => $isActive,
                    'enable'      => $enable,
                ];
            }
        }

        return $result;
    }

    /**
     * Отправить клиента в память ядра Xray на лету
     */
    public static function pushClientToXray(int $inboundId, string $email): bool
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT id, tag, port, protocol, settings, stream_settings FROM inbounds WHERE id = ?");
            $stmt->execute([$inboundId]);
            $inbound = $stmt->fetch();
            if (!$inbound) {
                return false;
            }

            $settings = json_decode($inbound['settings'] ?? '{}', true) ?? [];
            if (empty($settings['clients']) || !is_array($settings['clients'])) {
                return false;
            }

            $clientData = null;
            foreach ($settings['clients'] as $c) {
                if (($c['email'] ?? '') === $email) {
                    $clientData = $c;
                    break;
                }
            }

            if (!$clientData) {
                return false;
            }

            $res = XrayService::addUser($inbound, $clientData);
            return $res['success'] ?? false;
        } catch (\Throwable $e) {
            error_log("Failed to push client to Xray on the fly: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Убедиться, что клиент активен в памяти Xray (например, после продления)
     */
    public static function ensureClientActiveInXray(string $email): void
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT i.id, i.tag, i.port, i.protocol, i.settings, i.stream_settings
                FROM inbounds i
                JOIN client_inbounds ci ON ci.inbound_id = i.id
                JOIN clients c ON c.id = ci.client_id
                WHERE c.email = ?
            ");
            $stmt->execute([$email]);
            $inbounds = $stmt->fetchAll();

            foreach ($inbounds as $inbound) {
                $settings = json_decode($inbound['settings'] ?? '{}', true) ?? [];
                if (!empty($settings['clients'])) {
                    foreach ($settings['clients'] as $c) {
                        if (($c['email'] ?? '') === $email && !empty($c['enable'])) {
                            XrayService::addUser($inbound, $c);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("Failed to ensure client active in Xray: " . $e->getMessage());
        }
    }

    /**
     * Синхронизация всех активных клиентов из базы в память ядра Xray
     */
    public static function syncAllActiveClients(): array
    {
        $db = Database::getConnection();
        $inboundsStmt = $db->query("
            SELECT id, tag, port, protocol, settings, enable
            FROM inbounds
            WHERE enable = 1 AND settings IS NOT NULL
        ");
        $inbounds = $inboundsStmt->fetchAll();

        $nowMs = (int)(microtime(true) * 1000);
        $synced = 0;
        $errors = 0;

        foreach ($inbounds as $inbound) {
            $settings = json_decode($inbound['settings'] ?? '{}', true) ?? [];
            if (empty($settings['clients'])) {
                continue;
            }

            foreach ($settings['clients'] as $c) {
                $email = $c['email'] ?? '';
                $expiry = (int)($c['expiryTime'] ?? 0);
                $enable = !empty($c['enable']);

                if ($email === '' || !$enable || ($expiry > 0 && $expiry < $nowMs)) {
                    continue;
                }

                $res = XrayService::addUser($inbound, $c);
                if (!empty($res['success'])) {
                    $synced++;
                } else {
                    $errors++;
                }
            }
        }

        return [
            'status' => 'success',
            'synced' => $synced,
            'errors' => $errors,
        ];
    }
}
