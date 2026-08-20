<?php
/**
 * Default Theme — functions.php
 * Register theme features and default hooks
 */

// Register theme support
add_action('soi_theme_loaded', function ($theme) {
    // Theme is loaded — register any theme-specific features here
});

// Apply content filters (allow basic HTML through) + safe lazy media attrs
add_filter('the_content', function ($content) {
    if (!is_string($content) || $content === '') {
        return $content;
    }
    if (function_exists('soi_perf_enhance_media_html')) {
        return soi_perf_enhance_media_html($content, ['eager_first_image' => true]);
    }
    return $content;
}, 20);

// Register sidebar widget area hook for plugins
add_action('soi_sidebar_widgets', function () {
    // Plugins can add widgets here via:
    // add_action('soi_sidebar_widgets', 'my_plugin_widget');
});

// Add theme body class
add_action('soi_body_class', function () {
    echo 'class="soi-theme-default"';
});

// Performance CSS (non-blocking critical path: normal stylesheet, scoped rules)
add_action('soi_head', function () {
    $href = function_exists('soi_theme_asset_url')
        ? soi_theme_asset_url('performance.css')
        : (defined('SOI_THEME_URI') ? SOI_THEME_URI . '/performance.css' : '');
    if ($href === '') {
        return;
    }
    echo '<link rel="stylesheet" href="' . esc($href) . '?v=1.2.10.1">' . "\n";
});

// Performance JS — deferred, not required for first paint or security
add_action('soi_footer_scripts', function () {
    $src = function_exists('soi_theme_asset_url')
        ? soi_theme_asset_url('performance.js')
        : (defined('SOI_THEME_URI') ? SOI_THEME_URI . '/performance.js' : '');
    if ($src === '') {
        return;
    }
    echo '<script src="' . esc($src) . '?v=1.2.10.1" defer></script>' . "\n";
});
