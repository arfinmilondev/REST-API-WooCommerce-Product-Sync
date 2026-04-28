<?php
/**
 * Plugin Name: REST API WooCommerce Product Sync
 * Description: Syncs products from Site A to Site B via WooCommerce REST API.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

// ─────────────────────────────────────────────
// Allow localhost-to-localhost HTTP requests
// ─────────────────────────────────────────────
add_filter('block_local_requests', '__return_false');

// ─────────────────────────────────────────────
// Hooks
// ─────────────────────────────────────────────
add_action('woocommerce_new_product',    'sync_product_to_site_b', 10, 2);
add_action('woocommerce_update_product', 'sync_product_to_site_b', 10, 2);


// ─────────────────────────────────────────────
// Credentials — move these to wp-config.php
// in production, e.g.:
//   define('SYNC_B_KEY',    'ck_...');
//   define('SYNC_B_SECRET', 'cs_...');
//   define('SYNC_B_URL',    'http://localhost/themetest/wp-json/wc/v3');
// ─────────────────────────────────────────────
define('SYNC_B_KEY',    'ck_b3d56da023c73b14d0022dc0a43e747abfceb921');
define('SYNC_B_SECRET', 'cs_864935a97544e8bdde128a0f759efe4ce3878ad2');
define('SYNC_B_URL',    'https://localhost/themetest/wp-json/wc/v3');


// ─────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────

/**
 * Build the Authorization header once.
 */
function sync_auth_header() {
    return 'Basic ' . base64_encode(SYNC_B_KEY . ':' . SYNC_B_SECRET);
}

/**
 * Find or create a taxonomy term (category / tag) on Site B.
 * Uses exact-name matching to avoid partial-match false positives.
 *
 * @param string $name      Term name (e.g. "Shoes")
 * @param string $taxonomy  "categories" or "tags"
 * @return int|null         Term ID on Site B, or null on failure
 */
function sync_get_or_create_term($name, $taxonomy) {
    $base_url = SYNC_B_URL;
    $auth     = sync_auth_header();

    // Search by name
    $response = wp_remote_get(
        $base_url . '/products/' . $taxonomy . '?search=' . urlencode($name),
        [
            'sslverify' => false,
            'headers'   => ['Authorization' => $auth],
        ]
    );

    if (is_wp_error($response)) {
        error_log('[SYNC] Term search failed (' . $taxonomy . '/' . $name . '): ' . $response->get_error_message());
        return null;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    // Exact-name match (API search is a contains-match, not exact)
    if (!empty($body) && is_array($body)) {
        foreach ($body as $item) {
            if (isset($item['name']) && strtolower($item['name']) === strtolower($name)) {
                return (int) $item['id'];
            }
        }
    }

    // Not found — create it
    $create = wp_remote_post(
        $base_url . '/products/' . $taxonomy,
        [
            'sslverify' => false,
            'headers'   => [
                'Authorization' => $auth,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode(['name' => $name]),
        ]
    );

    if (is_wp_error($create)) {
        error_log('[SYNC] Term create failed (' . $taxonomy . '/' . $name . '): ' . $create->get_error_message());
        return null;
    }

    $new = json_decode(wp_remote_retrieve_body($create), true);

    if (empty($new['id'])) {
        // If term already exists, the API returns a 400 error with the resource_id
        if (isset($new['code']) && $new['code'] === 'term_exists' && isset($new['data']['resource_id'])) {
            return (int) $new['data']['resource_id'];
        }
        error_log('[SYNC] Term create returned no ID. Body: ' . wp_remote_retrieve_body($create));
        return null;
    }

    return (int) $new['id'];
}


// ─────────────────────────────────────────────
// Main sync function
// ─────────────────────────────────────────────

function sync_product_to_site_b($post_id, $post = null) {

    // 🔒 Skip if this request came FROM Site B (loop prevention)
    // Note: for production, replace this with an HMAC shared-secret check.
    if (!empty($_SERVER['HTTP_X_SYNC_ORIGIN'])) {
        error_log('[SYNC] Skipped post ' . $post_id . ' — X-Sync-Origin header present (loop guard).');
        return;
    }

    // 🔒 Transient lock — prevent duplicate syncs from rapid saves / bulk actions
    $lock_key = 'sync_lock_' . $post_id;
    if (get_transient($lock_key)) {
        error_log('[SYNC] Skipped post ' . $post_id . ' — lock active.');
        return;
    }
    set_transient($lock_key, 1, 30); // lock for 30 seconds

    $product = wc_get_product($post_id);
    if (!$product) {
        error_log('[SYNC] wc_get_product() returned false for post_id ' . $post_id);
        return;
    }

    $base_url = SYNC_B_URL;
    $auth     = sync_auth_header();

    error_log('[SYNC] Starting sync for product ' . $post_id . ' — "' . $product->get_name() . '"');

    // ── Categories ──────────────────────────────
    $categories = [];
    $cat_terms  = get_the_terms($post_id, 'product_cat');

    if ($cat_terms && !is_wp_error($cat_terms)) {
        foreach ($cat_terms as $term) {
            $cat_id = sync_get_or_create_term($term->name, 'categories');
            if ($cat_id) {
                $categories[] = ['id' => $cat_id];
            }
        }
    }

    // ── Tags ────────────────────────────────────
    $tags      = [];
    $tag_terms = get_the_terms($post_id, 'product_tag');

    if ($tag_terms && !is_wp_error($tag_terms)) {
        foreach ($tag_terms as $term) {
            $tag_id = sync_get_or_create_term($term->name, 'tags');
            if ($tag_id) {
                $tags[] = ['id' => $tag_id];
            }
        }
    }

    // ── Featured image ───────────────────────────
    $images   = [];
    $image_id = $product->get_image_id();

    if ($image_id) {
        $image_url = wp_get_attachment_url($image_id);
        if ($image_url) {
            $images[] = ['src' => $image_url];
        }
    }

    // ── Product payload ──────────────────────────
    $data = [
        'name'              => $product->get_name(),
        'type'              => $product->get_type(), 
        'status'            => $product->get_status(),
        'regular_price'     => $product->get_regular_price(),
        'sale_price'        => $product->get_sale_price(),
        'description'       => $product->get_description(),
        'short_description' => $product->get_short_description(),
        'sku'               => $product->get_sku(),
        'manage_stock'      => $product->get_manage_stock(),
        'stock_quantity'    => $product->get_stock_quantity(),
        'categories'        => $categories,
        'tags'              => $tags,
        'images'            => $images,
    ];

    // ── Find existing product on Site B ──────────
    $target_id = (int) get_post_meta($post_id, '_synced_to_site_b_id', true);

    if (!$target_id) {
        // Fall back to slug search on Site B
        $search = wp_remote_get(
            $base_url . '/products?slug=' . urlencode($product->get_slug()),
            [
                'sslverify' => false,
                'headers'   => ['Authorization' => $auth],
            ]
        );

        if (!is_wp_error($search)) {
            $search_body = json_decode(wp_remote_retrieve_body($search), true);
            if (!empty($search_body[0]['id'])) {
                $target_id = (int) $search_body[0]['id'];
                update_post_meta($post_id, '_synced_to_site_b_id', $target_id);
            }
        }
    }

    // ── Push to Site B ───────────────────────────
    $common_args = [
        'sslverify' => false,
        'headers'   => [
            'Authorization' => $auth,
            'Content-Type'  => 'application/json',
            'X-Sync-Origin' => 'site-a', // tells Site B to skip its own sync hook
        ],
        'body' => json_encode($data),
    ];

    if ($target_id) {
        // UPDATE
        error_log('[SYNC] Updating existing product on Site B — target ID: ' . $target_id);
        $response = wp_remote_request(
            $base_url . '/products/' . $target_id,
            array_merge($common_args, ['method' => 'PUT'])
        );
    } else {
        // CREATE
        error_log('[SYNC] Creating new product on Site B.');
        $response = wp_remote_post(
            $base_url . '/products',
            $common_args
        );

        if (!is_wp_error($response)) {
            $new = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($new['id'])) {
                $target_id = (int) $new['id'];
                update_post_meta($post_id, '_synced_to_site_b_id', $target_id);
                error_log('[SYNC] Created on Site B — target ID: ' . $target_id);
            }
        }
    }

    // ── Log result ───────────────────────────────
    if (is_wp_error($response)) {
        error_log('[SYNC] HTTP error: ' . $response->get_error_message());
    } else {
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        error_log('[SYNC] Response code: ' . $code);
        if ($code < 200 || $code >= 300) {
            error_log('[SYNC] Unexpected response body: ' . $body);
        }
    }
}