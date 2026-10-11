<?php

namespace App\Services;

class LinkGenerator
{
    public static function generate(array $inbound, array $client, array $stream, string $host): ?string
    {
        $protocol = strtolower($inbound['protocol'] ?? '');
        $email = $client['email'] ?? 'client';
        $remarkStr = !empty($inbound['remark']) ? ($inbound['remark'] . "-" . $email) : $email;

        if ($protocol === 'vless') {
            return self::generateVless($inbound, $client, $stream, $host, $remarkStr);
        }

        if ($protocol === 'hysteria2' || $protocol === 'hysteria') {
            return self::generateHysteria($inbound, $client, $stream, $host, $remarkStr, $protocol);
        }

        return null;
    }

    private static function generateVless(array $inbound, array $client, array $stream, string $host, string $remark): ?string
    {
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

        // gRPC
        if ($networkType === 'grpc' && !empty($stream['grpcSettings'])) {
            $grpc = $stream['grpcSettings'];
            $paramsArray['serviceName'] = $grpc['serviceName'] ?? '';
            if (!empty($grpc['mode'])) {
                $paramsArray['mode'] = $grpc['mode'];
            }
            if (!empty($grpc['authority'])) {
                $paramsArray['authority'] = $grpc['authority'];
            } elseif (!empty($reality['serverNames'][0])) {
                $paramsArray['authority'] = $reality['serverNames'][0];
            }
            if (($stream['security'] ?? '') === 'reality') {
                $paramsArray['encryption'] = 'none';
            }
        }

        // WebSocket
        if ($networkType === 'ws' && !empty($stream['wsSettings'])) {
            $ws = $stream['wsSettings'];
            $paramsArray['path'] = $ws['path'] ?? '/';
            if (!empty($ws['host'])) {
                $paramsArray['host'] = $ws['host'];
            }
        }

        $paramsArray = array_filter($paramsArray, fn($v) => $v !== '' && $v !== null);
        ksort($paramsArray);

        $params = http_build_query($paramsArray);
        $clientId = $client['id'] ?? '';
        $port = $inbound['port'] ?? 443;

        return "vless://{$clientId}@{$host}:{$port}?" . $params . "#" . urlencode($remark);
    }

    private static function generateHysteria(array $inbound, array $client, array $stream, string $host, string $remark, string $protocol): ?string
    {
        $password = $client['auth'] ?? $client['password'] ?? $client['id'] ?? '';
        $hyParams = [];

        if ($protocol === 'hysteria' && isset($stream['hysteriaSettings']['version'])) {
            $hyParams['version'] = $stream['hysteriaSettings']['version'];
        }

        $tls = $stream['tlsSettings'] ?? [];
        if (!empty($tls['alpn']) && is_array($tls['alpn'])) {
            $hyParams['alpn'] = implode(',', $tls['alpn']);
        }

        if (!empty($stream['finalmask']['udp'][0]['type'])) {
            $hyParams['obfs'] = $stream['finalmask']['udp'][0]['type'];
            $obfsPass = $stream['finalmask']['udp'][0]['settings']['password'] ?? '';
            if ($obfsPass) {
                $hyParams['obfs-password'] = $obfsPass;
            }
        }

        $fp = $tls['settings']['fingerprint'] ?? $tls['fingerprint'] ?? 'chrome';
        if ($fp) {
            $hyParams['fingerprint'] = $fp;
        }

        $hyParams['security'] = $stream['security'] ?? 'tls';

        $sni = $tls['serverName'] ?? $tls['settings']['serverName'] ?? '';
        if ($sni) {
            $hyParams['sni'] = $sni;
        }

        ksort($hyParams);

        $paramsStr = http_build_query($hyParams);
        $port = $inbound['port'] ?? 443;

        return "hysteria2://{$password}@{$host}:{$port}" . ($paramsStr ? "?{$paramsStr}" : "") . "#" . urlencode($remark);
    }
}
