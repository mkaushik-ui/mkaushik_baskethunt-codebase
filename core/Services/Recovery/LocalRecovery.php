<?php

namespace SOI\Core\Services\Recovery;

class LocalRecovery {
    /**
     * Generate local recovery configuration containing the server's revision timestamp.
     * This allows the client to know if its localStorage draft is newer or older than the server state.
     *
     * @param array $record The current document record (post or page)
     * @return array Configuration dictionary
     */
    public static function getConfig(array $record): array {
        // Convert the updated_at string to a JS-compatible unix timestamp (milliseconds)
        $updatedAtStr = $record['updated_at'] ?? '';
        $serverTimestamp = $updatedAtStr !== '' ? strtotime($updatedAtStr) * 1000 : 0;
        
        return [
            'enabled' => true,
            'serverTimestamp' => $serverTimestamp
        ];
    }
}
