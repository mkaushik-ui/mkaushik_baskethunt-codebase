<?php
namespace SOI\Core;

/**
 * App - Application bootstrap and front-controller
 */
class App {
    public function __construct() {
        // Load core classes
        spl_autoload_register(function (string $class) {
            $class = ltrim($class, '\\/');
            $class = str_replace('\\', '/', $class);
            $class = str_replace('SOI/Core/', '', $class);
            $root = defined('SOI_ROOT') ? SOI_ROOT : dirname(__DIR__);
            $file = $root . "/core/{$class}.php";
            if (file_exists($file)) require_once $file;
        });
    }

    public function run(): void {
        // Initialize session
        Auth::init();

        // Connect to database
        Database::connect([
            'host'   => SOI_DB_HOST,
            'name'   => SOI_DB_NAME,
            'user'   => SOI_DB_USER,
            'pass'   => SOI_DB_PASS,
            'port'   => SOI_DB_PORT,
            'prefix' => SOI_DB_PREFIX,
        ]);

        SoiCentralAuth::bootstrap();

        Blog::ensureMigrated();
        if (class_exists(\SOI\Core\Content\EditorSchema::class)) {
            \SOI\Core\Content\EditorSchema::ensure();
        }

        // Load active plugins
        Plugin::loadActive();

        // Fire init hook
        Hook::doAction('soi_init');

        // Maintenance mode — public frontend only (admin, SAML, assets bypass)
        $requestUri = trim(Router::getUri(), '/');
        if (Maintenance::shouldShowMaintenancePage($requestUri)) {
            http_response_code(503);
            $msg = Database::getOption('maintenance_message', 'We are under maintenance. Please check back later.');
            $maintenanceTemplate = SOI_ROOT . '/themes/maintenance.php';
            if (file_exists($maintenanceTemplate)) {
                include $maintenanceTemplate;
            } else {
                echo "<!DOCTYPE html><html><head><title>Maintenance</title></head><body style='font-family:sans-serif;text-align:center;padding:5rem'><h1>🔧 Under Maintenance</h1><p>" . esc($msg) . "</p></body></html>";
            }
            exit;
        }

        // Route the request
        $this->route();
    }

    private function route(): void {
        $uri    = trim(Router::getUri(), '/');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if (SoiCentralAuth::handleRoute($uri, $method)) {
            exit;
        }

        // REST API interception (Bypasses session SSO, uses API Keys)
        if (str_starts_with($uri, 'api/')) {
            \SOI\Core\Api::handle($uri, $method);
            exit;
        }

        // Extensionless Media Decoding Router
        if (str_starts_with($uri, 'media/view/')) {
            $hash = substr($uri, 11); // Length of 'media/view/'
            \SOI\Core\MediaHandler::serve($hash);
            exit;
        }

        // Theme static assets (CSS/JS/images) must bypass SSO and template resolution.
        // OpenLiteSpeed and some hosts route these through index.php instead of serving the physical file.
        if ($this->serveThemePublicAsset($uri)) {
            exit;
        }

        if ($this->serveRootPublicAsset($uri)) {
            exit;
        }

        // Enforce login for the entire CMS website
        if (class_exists(SoiCentralAuth::class)) {
            SoiCentralAuth::enforceFrontendRoute($uri);
        } else {
            Auth::requireAuth('subscriber');
        }

        // Hook for plugins to add routes
        Hook::doAction('soi_routes', $uri, $method);

        // Load and render the active theme
        $theme    = Database::getOption('active_theme', 'default');
        $themeDir = SOI_ROOT . "/themes/{$theme}";

        if (!is_dir($themeDir)) {
            $themeDir = SOI_ROOT . '/themes/default';
        }

        // Define global theme constants
        define('SOI_THEME_DIR', $themeDir);
        define('SOI_THEME_URI', SOI_HOME_URL . "/themes/{$theme}");

        // Load theme functions
        if (file_exists($themeDir . '/functions.php')) {
            require_once $themeDir . '/functions.php';
        }

        Hook::doAction('soi_theme_loaded', $theme);

        // Emit LiteSpeed caching headers for frontend output
        \SOI\Core\Cache::setCacheable();

        // Resolve which template to load
        [$template, $vars] = $this->resolveTemplate($uri, $themeDir);

        // Expose vars to template
        foreach ($vars as $key => $value) {
            $$key = $value;
        }

        if (file_exists($template)) {
            require $template;
        } else {
            http_response_code(404);
            $f404 = $themeDir . '/404.php';
            if (file_exists($f404)) {
                require $f404;
            } else {
                echo "<h1>404 Not Found</h1>";
            }
        }
    }

    public function resolveTemplate(string $uri, string $themeDir): array {
        $uri    = trim($uri, '/');
        $vars   = [];

        // Home
        if ($uri === '' || $uri === '/') {
            if (Database::isConnected()) {
                $homePage = Database::getOption('home_page', '');
                if ($homePage) {
                    $prefix = Database::prefix('pages');
                    $page = Database::selectOne("SELECT * FROM `$prefix` WHERE id = ? AND status = 'published'", [$homePage]);
                    if ($page) {
                        $vars['page'] = $page;
                        return [$themeDir . '/page.php', $vars];
                    }
                }
            }
            if (Database::isConnected() && Blog::isEnabled()) {
                return [$themeDir . '/index.php', $vars];
            }
            $landing = $themeDir . '/landing.php';
            return [file_exists($landing) ? $landing : $themeDir . '/404.php', $vars];
        }

        // Knowledge Center / Spaces Public Reader Shell (Task KS-06)
        if ($uri === 'docs' || str_starts_with($uri, 'docs/') ||
            $uri === 'library' || str_starts_with($uri, 'library/') ||
            $uri === 'tech' || str_starts_with($uri, 'tech/')) {
            $readerResult = $this->resolveReaderShellRoute($uri, $themeDir);
            if ($readerResult !== null) {
                return $readerResult;
            }
        }

        if (Database::isConnected() && Blog::isEnabled()) {
            // Blog archive: /blog or /posts
            if (in_array($uri, ['blog', 'posts', 'news'])) {
                return [$themeDir . '/archive.php', $vars];
            }

            // Category: /category/{slug}
            if (preg_match('#^category/(.+)$#', $uri, $m)) {
                if (Database::isConnected()) {
                    $catPrefix = Database::prefix('categories');
                    $cat = Database::selectOne("SELECT * FROM `$catPrefix` WHERE slug = ?", [$m[1]]);
                    $vars['category'] = $cat;
                }
                return [$themeDir . '/archive.php', $vars];
            }
        } elseif (in_array($uri, ['blog', 'posts', 'news']) || preg_match('#^category/(.+)$#', $uri)) {
            http_response_code(404);
            return [$themeDir . '/404.php', $vars];
        }

        // Try as a Page slug
        if (Database::isConnected()) {
            $prefix = Database::prefix('pages');
            $page = Database::selectOne("SELECT * FROM `$prefix` WHERE slug = ? AND status = 'published'", [$uri]);
            if ($page) {
                $vars['page'] = $page;
                return [$themeDir . '/page.php', $vars];
            }
        }

        // Try as a Post slug (blog module only)
        if (Database::isConnected() && Blog::isEnabled()) {
            $postPrefix = Database::prefix('posts');
            $post = Database::selectOne("SELECT * FROM `$postPrefix` WHERE slug = ? AND status = 'published'", [$uri]);
            if ($post) {
                $vars['post'] = $post;
                return [$themeDir . '/post.php', $vars];
            }
        }

        // Let plugins handle it
        $pluginTemplate = Hook::applyFilters('soi_resolve_template', null, $uri);
        if ($pluginTemplate) {
            return [$pluginTemplate, $vars];
        }

        // 404
        http_response_code(404);
        return [$themeDir . '/404.php', $vars];
    }

    /**
     * Resolve dynamic public reader shell routes (Task KS-06).
     *
     * Patterns:
     * - /docs
     * - /docs/{section}/{slug}
     * - /library/{library-slug}
     * - /library/{library-slug}/{section}/{slug}
     * - /tech/{product-slug}
     * - /tech/{product-slug}/{section}/{slug}
     *
     * @param string $uri Relative request URI
     * @param string $themeDir Current active theme directory
     * @return array{0:string, 1:array<string,mixed>} Template file path and variable bindings
     */
    public function resolveReaderShellRoute(string $uri, string $themeDir): array {
        $parts = explode('/', trim($uri, '/'));
        $prefix = strtolower($parts[0] ?? '');
        $vars = [];

        $spaceType = null;
        $spaceSlug = null;
        $sectionSlug = null;
        $docSlug = null;

        if ($prefix === 'docs') {
            $spaceType = 'docs';
            $spaceSlug = 'docs';
            if (count($parts) === 1) {
                // /docs
            } elseif (count($parts) === 3) {
                // /docs/{section}/{slug}
                $sectionSlug = $parts[1];
                $docSlug = $parts[2];
            } else {
                // Invalid segment count for /docs route
                http_response_code(404);
                return [$themeDir . '/404.php', $vars];
            }
        } elseif ($prefix === 'library') {
            $spaceType = 'library';
            if (count($parts) === 2) {
                // /library/{library-slug}
                $spaceSlug = $parts[1];
            } elseif (count($parts) === 4) {
                // /library/{library-slug}/{section}/{slug}
                $spaceSlug = $parts[1];
                $sectionSlug = $parts[2];
                $docSlug = $parts[3];
            } else {
                // Invalid segment count for /library route
                http_response_code(404);
                return [$themeDir . '/404.php', $vars];
            }
        } elseif ($prefix === 'tech') {
            $spaceType = 'tech';
            if (count($parts) === 2) {
                // /tech/{product-slug}
                $spaceSlug = $parts[1];
            } elseif (count($parts) === 4) {
                // /tech/{product-slug}/{section}/{slug}
                $spaceSlug = $parts[1];
                $sectionSlug = $parts[2];
                $docSlug = $parts[3];
            } else {
                // Invalid segment count for /tech route
                http_response_code(404);
                return [$themeDir . '/404.php', $vars];
            }
        } else {
            http_response_code(404);
            return [$themeDir . '/404.php', $vars];
        }

        // Validate and normalize slug segments to prevent path traversal and injection
        $validateSlug = static function (?string $slug): bool {
            if ($slug === null) {
                return true;
            }
            return (bool) preg_match('/^[a-zA-Z0-9_\-]+$/', $slug);
        };

        if (!$validateSlug($spaceSlug) || !$validateSlug($sectionSlug) || !$validateSlug($docSlug)) {
            http_response_code(404);
            return [$themeDir . '/404.php', $vars];
        }

        // 1. Resolve Space
        $space = \SOI\Core\Spaces\SpaceDocumentService::findSpace($spaceType, (string)$spaceSlug);
        if (!$space || ($space['status'] ?? '') !== 'published') {
            http_response_code(404);
            return [$themeDir . '/404.php', $vars];
        }

        $spaceId = (int)$space['id'];

        // 2. Resolve Document & Section
        $section = null;
        $document = null;

        if ($docSlug !== null && $sectionSlug !== null) {
            // Verify section exists and belongs to the resolved space
            $section = \SOI\Core\Spaces\SpaceDocumentService::findSectionBySlug($spaceId, $sectionSlug);
            if (!$section) {
                http_response_code(404);
                return [$themeDir . '/404.php', $vars];
            }

            // Resolve document strictly by slug and space ID (cross-space isolation)
            $document = \SOI\Core\Spaces\SpaceDocumentService::findDocumentBySlugAndSpace($docSlug, $spaceId, $sectionSlug);
            if (!$document || ($document['status'] ?? '') !== 'published') {
                http_response_code(404);
                return [$themeDir . '/404.php', $vars];
            }
        } else {
            // Space landing view: resolve default/landing document
            $document = \SOI\Core\Spaces\SpaceDocumentService::findLandingDocument($spaceId);
            if ($document && !empty($document['section_id'])) {
                $section = [
                    'id' => $document['section_id'],
                    'slug' => $document['section_slug'] ?? '',
                    'title' => $document['section_title'] ?? ''
                ];
            }
        }

        // 3. Build Navigation Tree
        $tree = \SOI\Core\Spaces\TaxonomyService::instance()->buildNavigationTree($spaceId);
        $sections = $tree['sections'] ?? $tree;

        // 4. Build Breadcrumbs Hierarchy
        $breadcrumbs = \SOI\Core\Spaces\SpaceDocumentService::buildBreadcrumbs($space, $section, $document);

        // 5. Expose variables
        $vars['space'] = $space;
        $vars['sections'] = $sections;
        $vars['document'] = $document;
        $vars['breadcrumbs'] = $breadcrumbs;
        $vars['activeSection'] = $sectionSlug ?? ($document['section_slug'] ?? '');
        $vars['activeSlug'] = $docSlug ?? ($document['slug'] ?? '');

        // 6. Return Reader Shell Template
        $rootDir = defined('SOI_ROOT') ? SOI_ROOT : dirname(__DIR__);
        $readerTemplate = $rootDir . '/templates/reader-shell.php';
        if (file_exists($readerTemplate)) {
            return [$readerTemplate, $vars];
        }

        $themeFallback = $themeDir . '/page.php';
        return [file_exists($themeFallback) ? $themeFallback : ($themeDir . '/404.php'), $vars];
    }

    /**
     * Serve a theme static asset when the request reached the front controller.
     */
    private function serveThemePublicAsset(string $uri): bool {
        $uri = trim($uri, '/');
        if ($uri === '' || !str_starts_with($uri, 'themes/')) {
            return false;
        }

        if (!preg_match('#^themes/([a-zA-Z0-9_-]+)/(.+)$#', $uri, $matches)) {
            return false;
        }

        $theme = $matches[1];
        $relativeFile = $matches[2];
        if ($relativeFile === '' || str_contains($relativeFile, '..')) {
            return false;
        }

        $ext = strtolower(pathinfo($relativeFile, PATHINFO_EXTENSION));
        $allowedExtensions = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'map'];
        if (!in_array($ext, $allowedExtensions, true)) {
            return false;
        }

        $themeRoot = realpath(SOI_ROOT . '/themes/' . $theme);
        if ($themeRoot === false) {
            return false;
        }

        $filePath = realpath($themeRoot . '/' . $relativeFile);
        if ($filePath === false || !is_file($filePath) || !str_starts_with($filePath, $themeRoot)) {
            return false;
        }

        $mimeTypes = [
            'css'   => 'text/css; charset=UTF-8',
            'js'    => 'application/javascript; charset=UTF-8',
            'png'   => 'image/png',
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'gif'   => 'image/gif',
            'webp'  => 'image/webp',
            'svg'   => 'image/svg+xml',
            'ico'   => 'image/x-icon',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf'   => 'font/ttf',
            'eot'   => 'application/vnd.ms-fontobject',
            'map'   => 'application/json',
        ];

        header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . (string) filesize($filePath));
        readfile($filePath);
        return true;
    }

    /**
     * Serve root-level public assets (e.g. assets/soi-session-sync.js) via front controller fallback.
     */
    private function serveRootPublicAsset(string $uri): bool {
        $uri = trim($uri, '/');
        if ($uri === '' || !str_starts_with($uri, 'assets/')) {
            return false;
        }

        $relativeFile = substr($uri, strlen('assets/'));
        if ($relativeFile === '' || str_contains($relativeFile, '..')) {
            return false;
        }

        $ext = strtolower(pathinfo($relativeFile, PATHINFO_EXTENSION));
        $allowedExtensions = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'map'];
        if (!in_array($ext, $allowedExtensions, true)) {
            return false;
        }

        $assetsRoot = realpath(SOI_ROOT . '/assets');
        if ($assetsRoot === false) {
            return false;
        }

        $filePath = realpath($assetsRoot . '/' . $relativeFile);
        if ($filePath === false || !is_file($filePath) || !str_starts_with($filePath, $assetsRoot)) {
            return false;
        }

        $mimeTypes = [
            'css'   => 'text/css; charset=UTF-8',
            'js'    => 'application/javascript; charset=UTF-8',
            'png'   => 'image/png',
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'gif'   => 'image/gif',
            'webp'  => 'image/webp',
            'svg'   => 'image/svg+xml',
            'ico'   => 'image/x-icon',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf'   => 'font/ttf',
            'eot'   => 'application/vnd.ms-fontobject',
            'map'   => 'application/json',
        ];

        header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . (string) filesize($filePath));
        readfile($filePath);
        return true;
    }
}
