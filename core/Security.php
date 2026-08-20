<?php
declare(strict_types=1);

namespace SOI\Core;

/**
 * Server Security & Hotlink Protection Manager
 */
class Security {
    
    private const HTACCESS_MARKER_START = '# BEGIN SOI HOTLINK PROTECTION';
    private const HTACCESS_MARKER_END = '# END SOI HOTLINK PROTECTION';

    public static function getHotlinkSettings(): array {
        return [
            'enabled' => (bool)Database::getOption('hotlink_enabled', '0'),
            'block_direct' => (bool)Database::getOption('hotlink_block_direct', '1'), // Default to block direct access
            'allowed_domains' => Database::getOption('hotlink_domains', $_SERVER['HTTP_HOST'] ?? 'localhost'),
            'extensions' => Database::getOption('hotlink_extensions', 'jpg, jpeg, png, gif, webp, svg, mp4, mp3, pdf, zip, rar')
        ];
    }

    public static function updateHotlinkSettings(bool $enabled, bool $blockDirect, string $domains, string $extensions): bool {
        Database::setOption('hotlink_enabled', $enabled ? '1' : '0');
        Database::setOption('hotlink_block_direct', $blockDirect ? '1' : '0');
        Database::setOption('hotlink_domains', trim($domains));
        Database::setOption('hotlink_extensions', trim($extensions));
        
        return self::writeHtaccess();
    }

    public static function writeHtaccess(): bool {
        $htaccessPath = SOI_ROOT . '/.htaccess';
        
        $settings = self::getHotlinkSettings();
        $rules = "";

        if ($settings['enabled']) {
            $domains = array_map('trim', explode(',', $settings['allowed_domains']));
            $domainRegexes = [];
            foreach ($domains as $domain) {
                if (empty($domain)) continue;
                $domainRegex = str_replace('.', '\.', $domain);
                $domainRegexes[] = "RewriteCond %{HTTP_REFERER} !^https?://([^/]+\.)?{$domainRegex}/ [NC]";
            }

            $exts = array_map('trim', explode(',', $settings['extensions']));
            $exts = array_filter($exts);
            $extRegex = implode('|', $exts);

            if (!empty($domainRegexes) && !empty($exts)) {
                $rules .= self::HTACCESS_MARKER_START . "\n";
                $rules .= "<IfModule mod_rewrite.c>\n";
                $rules .= "RewriteEngine On\n";
                
                if (!$settings['block_direct']) {
                    $rules .= "RewriteCond %{HTTP_REFERER} !^$ \n"; 
                }
                
                foreach ($domainRegexes as $regex) {
                    $rules .= $regex . "\n";
                }
                $rules .= "RewriteRule \.({$extRegex})$ - [F,NC,L]\n";
                $rules .= "</IfModule>\n";
                $rules .= self::HTACCESS_MARKER_END . "\n";
            }
        }

        $success = true;

        // Apply to ROOT .htaccess
        $success = $success && self::injectIntoHtaccess($htaccessPath, $settings, $rules);

        // Apply to UPLOADS .htaccess (because subdirectories with .htaccess override root mod_rewrite)
        $uploadsHtaccess = SOI_ROOT . '/uploads/.htaccess';
        if (is_dir(SOI_ROOT . '/uploads')) {
            $success = $success && self::injectIntoHtaccess($uploadsHtaccess, $settings, $rules);
        }

        return $success;
    }

    private static function injectIntoHtaccess(string $path, array $settings, string $rules): bool {
        if (file_exists($path)) {
            $content = file_get_contents($path);
            $pattern = '/' . preg_quote(self::HTACCESS_MARKER_START, '/') . '.*?' . preg_quote(self::HTACCESS_MARKER_END, '/') . '\s*/s';
            $content = preg_replace($pattern, '', $content);
            $content = trim($content);
            
            if ($settings['enabled'] && !empty($rules)) {
                if (stripos($content, 'RewriteEngine On') !== false) {
                    $content = preg_replace('/(RewriteEngine On\s*)/i', "$1\n" . $rules . "\n\n", $content);
                } else {
                    $content = $rules . "\n\n" . $content;
                }
            }
            
            return file_put_contents($path, $content . "\n") !== false;
        } else {
            if ($settings['enabled'] && !empty($rules)) {
                return file_put_contents($path, $rules . "\n") !== false;
            }
        }
        return true;
    }

    public static function getNginxRules(): string {
        $settings = self::getHotlinkSettings();
        if (!$settings['enabled']) return "# Hotlink protection is currently disabled.";

        $domains = array_map('trim', explode(',', $settings['allowed_domains']));
        $domainList = implode(' ', array_filter($domains));
        
        $exts = array_map('trim', explode(',', $settings['extensions']));
        $extRegex = implode('|', array_filter($exts));
        
        $noneRule = $settings['block_direct'] ? "" : "none ";

        return "location ^~ /uploads/ {\n" .
               "    return 403;\n" .
               "}\n\n" .
               "location ^~ /updates/ {\n" .
               "    return 403;\n" .
               "}\n\n" .
               "location ^~ /media/view/ {\n" .
               "    try_files \$uri /index.php?\$query_string;\n" .
               "}\n\n" .
               "location ~ \.({$extRegex})$ {\n" .
               "    valid_referers {$noneRule}blocked server_names {$domainList} *.{$domainList};\n" .
               "    if (\$invalid_referer) {\n" .
               "        return 403;\n" .
               "    }\n" .
               "}\n\n" .
               "# For LiteSpeed/OpenLiteSpeed, ensure .htaccess override is enabled for the document root and uploads directory.";
    }
}
