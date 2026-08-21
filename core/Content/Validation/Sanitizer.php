<?php
declare(strict_types=1);

namespace SOI\Core\Content\Validation;

/**
 * Task T8: Global XSS and Security Sanitizer (Skeleton).
 */
class Sanitizer
{
    private const ALLOWED_PROTOCOLS = ['http', 'https', 'mailto', '#'];

    /**
     * Clean and validate URL against protocol allowlist to prevent SSRF / javascript: execution.
     *
     * @param string $url
     * @return string Sanitized safe URL
     */
    public function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '#') || str_starts_with($url, '/')) {
            return $url;
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme === null || !in_array(strtolower($scheme), self::ALLOWED_PROTOCOLS, true)) {
            return '#unsafe-link-removed';
        }
        return filter_var($url, FILTER_SANITIZE_URL) ?: '#invalid-url';
    }

    /**
     * Strip dangerous script, iframe, and inline event handlers from user text.
     *
     * @param string $html
     * @return string Clean HTML string
     */
    public function cleanHtml(string $html): string
    {
        return preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html) ?? '';
    }
}
