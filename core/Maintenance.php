<?php
namespace SOI\Core;

/**
 * Maintenance mode — public frontend only; admin, SSO, and required assets bypass.
 */
class Maintenance {
    /** @var string[] */
    private const EXACT_ROUTES = [
        'admin',
        'favicon.ico',
    ];

    /** @var string[] */
    private const PREFIX_ROUTES = [
        'admin/',
        'saml/',
        'soi-central/',
        'api/session/',
        'api/sso/',
        'assets/',
        'themes/',
        'media/view/',
        'install/',
    ];

    public static function isBypassRoute(string $uri): bool {
        $uri = strtolower(trim($uri, '/'));
        if ($uri === '') {
            return false;
        }

        if (in_array($uri, self::EXACT_ROUTES, true)) {
            return true;
        }

        foreach (self::PREFIX_ROUTES as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public static function shouldShowMaintenancePage(string $uri): bool {
        if (Database::getOption('maintenance_mode', '0') !== '1') {
            return false;
        }

        if (Auth::check()) {
            return false;
        }

        return !self::isBypassRoute($uri);
    }
}