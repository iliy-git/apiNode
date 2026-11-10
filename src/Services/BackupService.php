<?php

namespace App\Services;

use App\Config;
use App\Database;
use PDO;

class BackupService
{
    /**
     * Создать консистентный снапшот SQLite базы через VACUUM INTO
     */
    public static function createDbSnapshot(): string
    {
        $db = Database::getConnection();

        // Сбросить WAL-журнал в основную базу
        try {
            $db->exec("PRAGMA wal_checkpoint(TRUNCATE);");
        } catch (\Throwable $e) {
            // игнорируем, если не в WAL
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'node_bak_') . '.db';
        @unlink($tmpFile);

        $db->exec("VACUUM INTO " . $db->quote($tmpFile));

        if (!file_exists($tmpFile) || filesize($tmpFile) === 0) {
            throw new \RuntimeException("Не удалось создать резервную копию базы данных.");
        }

        return $tmpFile;
    }

    /**
     * Выгрузка базы данных в виде скачиваемого бинарного файла (.db)
     */
    public static function downloadDbBackup(string $host): void
    {
        $snapshot = self::createDbSnapshot();
        $cleanHost = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $host);
        $filename = "apinode-backup-{$cleanHost}-" . date('Ymd_His') . ".db";
        $filesize = filesize($snapshot);

        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/x-sqlite3');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $filesize);
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($snapshot);
        @unlink($snapshot);
        exit;
    }

    /**
     * Экспорт всех настроек, инбаундов и клиентов в структурированный JSON
     */
    public static function exportJsonBackup(string $host): array
    {
        $db = Database::getConnection();

        // Инбаунды
        $inboundsStmt = $db->query("SELECT * FROM inbounds");
        $inbounds = $inboundsStmt->fetchAll();

        // Клиенты
        $clientsStmt = $db->query("SELECT * FROM clients");
        $clients = $clientsStmt->fetchAll();

        // Связи клиент-инбаунд
        $ciStmt = $db->query("SELECT * FROM client_inbounds");
        $clientInbounds = $ciStmt->fetchAll();

        // Трафик
        $trafficStmt = $db->query("SELECT * FROM client_traffics");
        $traffics = $trafficStmt->fetchAll();

        // Настройки ноды (включая xrayTemplateConfig, warp и т.д.)
        $settingsStmt = $db->query("SELECT * FROM settings");
        $settings = $settingsStmt->fetchAll();

        return [
            "status"        => "success",
            "format"        => "apinode_backup_v1",
            "server_host"   => $host,
            "exported_at"   => date('Y-m-d H:i:s'),
            "timestamp_ms"  => (int)(microtime(true) * 1000),
            "counts"        => [
                "inbounds" => count($inbounds),
                "clients"  => count($clients),
                "settings" => count($settings),
            ],
            "data" => [
                "inbounds"        => $inbounds,
                "clients"         => $clients,
                "client_inbounds" => $clientInbounds,
                "client_traffics" => $traffics,
                "settings"        => $settings,
            ]
        ];
    }

    /**
     * Восстановление базы из загруженного файла SQLite
     */
    public static function restoreFromDb(string $uploadedFilePath): array
    {
        if (!file_exists($uploadedFilePath)) {
            throw new \RuntimeException("Файл бэкапа не найден", 400);
        }

        // Проверка заголовка SQLite 3
        $header = file_get_contents($uploadedFilePath, false, null, 0, 16);
        if ($header !== "SQLite format 3\0") {
            throw new \RuntimeException("Загруженный файл не является валидной базой данных SQLite 3", 400);
        }

        $targetDb = Config::getDbPath();

        // Создаем резервную копию текущей базы перед перезаписью
        if (file_exists($targetDb)) {
            @copy($targetDb, $targetDb . '.bak.' . time());
        }

        // Заменяем базу данных
        if (!@copy($uploadedFilePath, $targetDb)) {
            throw new \RuntimeException("Не удалось перезаписать файл базы данных {$targetDb}", 500);
        }

        // Синхронизируем всех активных клиентов в память ядра Xray
        $syncResult = ClientService::syncAllActiveClients();

        return [
            "status"      => "success",
            "message"     => "База данных успешно восстановлена из бэкапа",
            "synced_xray" => $syncResult,
        ];
    }

    /**
     * Восстановление из JSON payload (или base64 базы)
     */
    public static function restoreFromJsonOrBase64(array $payload): array
    {
        // Если база передана как base64 строка
        if (!empty($payload['database_base64'])) {
            $binary = base64_decode((string)$payload['database_base64'], true);
            if (!$binary) {
                throw new \RuntimeException("Невалидный base64", 400);
            }
            $tmpFile = tempnam(sys_get_temp_dir(), 'restore_b64_');
            file_put_contents($tmpFile, $binary);
            $res = self::restoreFromDb($tmpFile);
            @unlink($tmpFile);
            return $res;
        }

        // Если передан JSON массив data
        $data = $payload['data'] ?? $payload;
        if (empty($data['inbounds'])) {
            throw new \RuntimeException("JSON бэкап не содержит секции 'inbounds'", 400);
        }

        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            // Восстановление settings
            if (!empty($data['settings']) && is_array($data['settings'])) {
                $stmtSet = $db->prepare("INSERT OR REPLACE INTO settings (id, key, value) VALUES (?, ?, ?)");
                foreach ($data['settings'] as $s) {
                    $stmtSet->execute([$s['id'] ?? null, $s['key'], $s['value']]);
                }
            }

            // Восстановление inbounds
            if (!empty($data['inbounds']) && is_array($data['inbounds'])) {
                $stmtInb = $db->prepare("
                    INSERT OR REPLACE INTO inbounds 
                    (id, user_id, up, down, total, remark, enable, expiry_time, listen, port, protocol, settings, stream_settings, tag, sniffing)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($data['inbounds'] as $i) {
                    $stmtInb->execute([
                        $i['id'] ?? null,
                        $i['user_id'] ?? 0,
                        $i['up'] ?? 0,
                        $i['down'] ?? 0,
                        $i['total'] ?? 0,
                        $i['remark'] ?? '',
                        $i['enable'] ?? 1,
                        $i['expiry_time'] ?? 0,
                        $i['listen'] ?? '',
                        $i['port'] ?? 443,
                        $i['protocol'] ?? 'vless',
                        $i['settings'] ?? '{}',
                        $i['stream_settings'] ?? '{}',
                        $i['tag'] ?? '',
                        $i['sniffing'] ?? '{}'
                    ]);
                }
            }

            // Восстановление clients
            if (!empty($data['clients']) && is_array($data['clients'])) {
                $stmtCl = $db->prepare("
                    INSERT OR REPLACE INTO clients
                    (id, email, sub_id, uuid, password, auth, enable, expiry_time, total_gb, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($data['clients'] as $c) {
                    $stmtCl->execute([
                        $c['id'] ?? null,
                        $c['email'] ?? '',
                        $c['sub_id'] ?? '',
                        $c['uuid'] ?? '',
                        $c['password'] ?? '',
                        $c['auth'] ?? '',
                        $c['enable'] ?? 1,
                        $c['expiry_time'] ?? 0,
                        $c['total_gb'] ?? 0,
                        $c['created_at'] ?? 0,
                        $c['updated_at'] ?? 0,
                    ]);
                }
            }

            // Восстановление client_inbounds
            if (!empty($data['client_inbounds']) && is_array($data['client_inbounds'])) {
                $stmtCI = $db->prepare("
                    INSERT OR REPLACE INTO client_inbounds
                    (id, client_id, inbound_id, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?)
                ");
                foreach ($data['client_inbounds'] as $ci) {
                    $stmtCI->execute([
                        $ci['id'] ?? null,
                        $ci['client_id'] ?? 0,
                        $ci['inbound_id'] ?? 0,
                        $ci['created_at'] ?? 0,
                        $ci['updated_at'] ?? 0,
                    ]);
                }
            }

            // Восстановление client_traffics
            if (!empty($data['client_traffics']) && is_array($data['client_traffics'])) {
                $stmtTr = $db->prepare("
                    INSERT OR REPLACE INTO client_traffics
                    (id, inbound_id, enable, email, up, down, expiry_time, total, reset)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($data['client_traffics'] as $t) {
                    $stmtTr->execute([
                        $t['id'] ?? null,
                        $t['inbound_id'] ?? 0,
                        $t['enable'] ?? 1,
                        $t['email'] ?? '',
                        $t['up'] ?? 0,
                        $t['down'] ?? 0,
                        $t['expiry_time'] ?? 0,
                        $t['total'] ?? 0,
                        $t['reset'] ?? 0,
                    ]);
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // Синхронизация с Xray на лету
        $syncResult = ClientService::syncAllActiveClients();

        return [
            "status"      => "success",
            "message"     => "JSON конфигурация успешно импортирована в базу",
            "synced_xray" => $syncResult,
        ];
    }
}
