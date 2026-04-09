<?php
/**
 * Stable post URLs by ID (unaffected when slug changes).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Front-end URL that resolves the post by ID only (stable when slug or permalink structure changes).
 *
 * @param int $post_id Post ID.
 * @return string Empty string if the post does not exist.
 */
function aapg_id_based_post_url(int $post_id): string {
    $post = get_post($post_id);
    if (!$post) {
        return '';
    }
    if ($post->post_type === 'page') {
        return add_query_arg('page_id', $post_id, home_url('/'));
    }
    return add_query_arg('p', $post_id, home_url('/'));
}
