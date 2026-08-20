<?php
/**
 * Plugin Name: Hello World
 * Version: 1.0.0
 * Description: A minimal sample plugin demonstrating the SOI (School Of Interns) CMS plugin API. Shows how to use actions, filters, and admin menus.
 * Author: SOI (School Of Interns) CMS
 * Author URI: https://soicms.example.com
 */

// Prevent direct access
if (!defined('SOI_ROOT')) exit;

// 1. Hook into site init
add_action('soi_init', function () {
    // This runs on every page load after the CMS boots
    // Perfect for registering custom routes, loading assets, etc.
});

// 2. Add content to the bottom of every post
add_action('soi_after_post_content', function ($post) {
    echo '<div style="margin-top:2rem;padding:1rem;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;font-size:0.88rem;">';
    echo '👋 <strong>Hello from Hello World Plugin!</strong> This message is added by the sample plugin.';
    echo '</div>';
});

// 3. Filter post content (example: auto-link URLs)
add_filter('the_content', function ($content) {
    // Example: you could transform the content here
    return $content;
}, 20);

// 4. Add a widget to the sidebar
add_action('soi_sidebar_widgets', function () {
    echo '<div class="widget">';
    echo '<h3 class="widget-title">Hello World Plugin</h3>';
    echo '<div class="widget-body" style="font-size:.85rem;color:var(--text-muted);">This widget is registered by the Hello World plugin!</div>';
    echo '</div>';
});

// 5. Register an admin menu item
\SOI\Core\Plugin::addAdminMenu(
    title:    'Hello World',
    slug:     'hello-world',
    url:      SOI_ADMIN_URL . '/plugins.php', // Link to plugins page for demo
    icon:     '👋',
    position: 60
);
