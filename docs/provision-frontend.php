<?php
/**
 * Reproduce production's front-end setup on the local site.
 *
 * Production does NOT use a static page for the app. It keeps
 * show_on_front = posts and overrides the block theme's `home` template with a
 * single shortcode block, so the app renders as a direct child of
 * .wp-site-blocks with no header, title, footer or constrained layout.
 *
 * That override lives in the database as a wp_template post tied to the theme
 * through the wp_theme taxonomy - it is not part of the theme files.
 */

define('WP_USE_THEMES', false);
require_once 'D:/xampp/htdocs/mhc-local/wp-load.php';

$theme = 'twentytwentyfive';
$content = "<!-- wp:shortcode -->\n[mhc_app]\n<!-- /wp:shortcode -->";

// 1) Front page is the posts index, like production
update_option('show_on_front', 'posts');
update_option('page_on_front', 0);
update_option('page_for_posts', 0);
echo "show_on_front = " . get_option('show_on_front') . "\n";

// 2) Override the `home` template
$existing = get_posts([
    'post_type'      => 'wp_template',
    'name'           => 'home',
    'post_status'    => 'any',
    'numberposts'    => 1,
    'tax_query'      => [[
        'taxonomy' => 'wp_theme',
        'field'    => 'name',
        'terms'    => $theme,
    ]],
]);

if ($existing) {
    $id = $existing[0]->ID;
    wp_update_post(['ID' => $id, 'post_content' => $content]);
    echo "updated existing wp_template#{$id}\n";
} else {
    $id = wp_insert_post([
        'post_type'    => 'wp_template',
        'post_name'    => 'home',
        'post_title'   => 'Blog Home',
        'post_content' => $content,
        'post_status'  => 'publish',
        'post_excerpt' => 'Renders the MHC payroll app full-bleed.',
    ], true);
    if (is_wp_error($id)) {
        echo "FAILED: " . $id->get_error_message() . "\n";
        exit(1);
    }
    wp_set_object_terms($id, $theme, 'wp_theme');
    echo "created wp_template#{$id} for theme {$theme}\n";
}

// 3) Verify WordPress actually resolves it
$resolved = get_block_template($theme . '//home', 'wp_template');
echo "resolved template source: " . ($resolved ? $resolved->source : 'NOT FOUND') . "\n";
echo "resolved content: " . trim(str_replace("\n", ' ', $resolved->content ?? '')) . "\n";

// The stale front page we created during provisioning is left in place - production
// also keeps an unused page at /home/.
$home_page = get_page_by_path('home');
echo "leftover 'home' page: " . ($home_page ? "id {$home_page->ID} (kept, not front page)" : 'none') . "\n";
