<?php
namespace SOI\Core\Services;

/**
 * SOI Knowledge Center
 * Runtime Error Tracking Collector
 */
class ErrorTracker
{
    /**
     * @var array
     */
    protected static $errors = [];

    /**
     * Log a runtime error.
     *
     * @param string $message The error message.
     * @param array $context Additional error context.
     * @return void
     */
    public static function log(string $message, array $context = []): void
    {
        self::$errors[] = [
            'message'   => $message,
            'context'   => $context,
            'timestamp' => time(),
        ];
    }

    /**
     * Retrieve all tracked errors.
     *
     * @return array
     */
    public static function getErrors(): array
    {
        return self::$errors;
    }

    /**
     * Clear tracked errors.
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$errors = [];
    }
}
