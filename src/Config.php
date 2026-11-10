<?php

namespace App;

class Config
{
    public static function getDbPath(): string
    {
        return getenv('XUI_DB_PATH') ?: '/etc/x-ui/x-ui.db';
    }

    public static function getKeyPath(): string
    {
        return dirname(__DIR__) . '/.key';
    }

    public static function getXrayBinary(): string
    {
        return getenv('XRAY_BIN') ?: '/usr/local/bin/xray';
    }

    public static function getXrayApiServer(): string
    {
        return getenv('XRAY_API_SERVER') ?: '';
    }

    public static function getRestartFlagPath(): string
    {
        return '/etc/x-ui/restart.flag';
    }

    public static function triggerRestart(): void
    {
        $flag = self::getRestartFlagPath();
        @touch($flag);
    }
}
