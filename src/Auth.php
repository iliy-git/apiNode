<?php

namespace App;

class Auth
{
    public static function check(): bool
    {
        $keyFile = Config::getKeyPath();
        if (!file_exists($keyFile)) {
            return false;
        }

        $savedKey = trim((string)file_get_contents($keyFile));
        if ($savedKey === '') {
            return false;
        }

        $userToken = $_SERVER['HTTP_X_API_KEY'] ?? '';

        return hash_equals($savedKey, (string)$userToken);
    }
}
