<?php
namespace app\logging;

use app\common\AppConfig;

/**
 * Centralized Logger
 * Structured format: [category][subcategory] message
 * Automatic redaction of sensitive data (passwords, tokens, SSNs, taxpayer ids)
 */
class Logger
{
    private static array $sensitivePatterns = [
        '/password["\s]*[:=]["\s]*[^\s,}]+/i',
        '/token["\s]*[:=]["\s]*[^\s,}]+/i',
        '/authorization["\s]*[:=]["\s]*[^\s,}]+/i',
        '/\b\d{3}-\d{2}-\d{4}\b/',
        '/taxpayer[_\-]?id["\s]*[:=]["\s]*[^\s,}]+/i',
        '/phone["\s]*[:=]["\s]*[^\s,}]+/i',
        '/key_material["\s]*[:=]["\s]*[^\s,}]+/i',
    ];

    private static array $sensitiveFields = [
        'password', 'password_hash', 'password_salt', 'token', 'token_hash',
        'authorization', 'ssn', 'taxpayer_id', 'invoice_identifier',
        'customer_phone_enc', 'key_material', 'key_material_encrypted',
    ];

    public static function info(string $category, string $subcategory, string $message, array $context = []): void
    {
        self::log('INFO', $category, $subcategory, $message, $context);
    }

    public static function warning(string $category, string $subcategory, string $message, array $context = []): void
    {
        self::log('WARNING', $category, $subcategory, $message, $context);
    }

    public static function error(string $category, string $subcategory, string $message, array $context = []): void
    {
        self::log('ERROR', $category, $subcategory, $message, $context);
    }

    public static function security(string $subcategory, string $message, array $context = []): void
    {
        self::log('SECURITY', 'security', $subcategory, $message, $context);
    }

    public static function audit(string $subcategory, string $message, array $context = []): void
    {
        self::log('AUDIT', 'audit', $subcategory, $message, $context);
    }

    private static function log(string $level, string $category, string $subcategory, string $message, array $context): void
    {
        $redactedContext = self::redactSensitive($context);
        $redactedMessage = self::redactString($message);

        $logEntry = sprintf(
            "[%s][%s][%s][%s] %s %s",
            date('Y-m-d\TH:i:s.vP'),
            $level,
            $category,
            $subcategory,
            $redactedMessage,
            !empty($redactedContext) ? json_encode($redactedContext, JSON_UNESCAPED_UNICODE) : ''
        );

        $logFile = self::getLogPath();
        file_put_contents($logFile, trim($logEntry) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function redactSensitive(array $data): array
    {
        $redacted = [];
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), self::$sensitiveFields, true)) {
                $redacted[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $redacted[$key] = self::redactSensitive($value);
            } elseif (is_string($value)) {
                $redacted[$key] = self::redactString($value);
            } else {
                $redacted[$key] = $value;
            }
        }
        return $redacted;
    }

    public static function redactString(string $value): string
    {
        foreach (self::$sensitivePatterns as $pattern) {
            $value = preg_replace($pattern, '***REDACTED***', $value);
        }
        return $value;
    }

    private static function getLogPath(): string
    {
        $logDir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        return $logDir . '/app_' . date('Y-m-d') . '.log';
    }
}
