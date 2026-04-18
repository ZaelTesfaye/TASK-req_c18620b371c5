<?php
namespace app\common;

/**
 * AppConfig - Single source of truth for all configuration values.
 * Application logic must use AppConfig::get() instead of getenv() or $_ENV.
 */
class AppConfig
{
    private static ?array $config = null;

    public static function load(): void
    {
        if (self::$config === null) {
            self::$config = require dirname(__DIR__, 2) . '/config/app.php';
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        return self::$config[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::load();
        self::$config[$key] = $value;
    }

    public static function all(): array
    {
        self::load();
        return self::$config;
    }

    public static function isDebug(): bool
    {
        return (bool) self::get('app_debug', false);
    }

    public static function isProduction(): bool
    {
        return self::get('app_env') === 'production';
    }

    public static function isTesting(): bool
    {
        return self::get('app_env') === 'testing';
    }

    public static function dbConfig(): array
    {
        return [
            'host'     => self::get('db_host'),
            'port'     => self::get('db_port'),
            'name'     => self::get('db_name'),
            'user'     => self::get('db_user'),
            'password' => self::get('db_password'),
        ];
    }
}
