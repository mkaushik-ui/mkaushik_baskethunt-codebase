<?php
declare(strict_types=1);

namespace SOI\Core\Content;

use SOI\Core\Database;

/**
 * Resolves editor media references without hard-coding a storage host.
 * Later KC can swap this to SOI Files Service via the same assetId contract.
 */
final class AssetResolver
{
    /**
     * @param array<string, mixed> $data
     * @return array{id:?int,url:string,name:string,mime:string,alt:string}
     */
    public static function resolve(array $data): array
    {
        $empty = [
            'id' => null,
            'url' => '',
            'name' => '',
            'mime' => '',
            'alt' => Html::plainText((string) ($data['alt'] ?? '')),
        ];

        $assetId = isset($data['assetId']) && is_numeric($data['assetId']) ? (int) $data['assetId'] : 0;
        if ($assetId > 0 && class_exists(Database::class)) {
            try {
                if (Database::tableExists('media')) {
                    $row = Database::selectOne(
                        "SELECT id, url, original_name, mime_type, alt_text FROM `" . Database::prefix('media') . "` WHERE id = ? LIMIT 1",
                        [$assetId]
                    );
                    if ($row) {
                        $url = self::publicUrl((string) ($row['url'] ?? ''));
                        return [
                            'id' => (int) $row['id'],
                            'url' => $url,
                            'name' => (string) ($row['original_name'] ?? ''),
                            'mime' => (string) ($row['mime_type'] ?? ''),
                            'alt' => $empty['alt'] !== '' ? $empty['alt'] : Html::plainText((string) ($row['alt_text'] ?? '')),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // Fall through to explicit URL.
            }
        }

        $url = self::publicUrl((string) ($data['url'] ?? ''));
        if ($url === '') {
            return $empty;
        }

        return [
            'id' => $assetId > 0 ? $assetId : null,
            'url' => $url,
            'name' => Html::plainText((string) ($data['name'] ?? $data['filename'] ?? '')),
            'mime' => Html::plainText((string) ($data['mime'] ?? '')),
            'alt' => $empty['alt'],
        ];
    }

    public static function publicUrl(string $url): string
    {
        $url = Html::sanitizeUrl($url, true);
        if ($url === '') {
            return '';
        }
        return $url;
    }
}
