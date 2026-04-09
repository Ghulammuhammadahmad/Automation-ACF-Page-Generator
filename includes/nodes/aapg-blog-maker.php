<?php
/**
 * AAPG Blog Maker
 * Creates a blog post from JSON data (meta_title, meta_description, content). No AI/streaming.
 */

namespace AAPG\Nodes;

if (!defined('ABSPATH')) {
    exit;
}

class AAPG_Blog_Maker {

    /**
     * Create a post from blog schema data.
     *
     * @param array $data Must contain meta_title, meta_description, content.
     * @return array|WP_Error { post_id, post_type, post_url, ... } or WP_Error.
     */
    public static function create_post_from_data(array $data) {
        $meta_title = isset($data['meta_title']) ? sanitize_text_field($data['meta_title']) : '';
        $meta_description = isset($data['meta_description']) ? sanitize_textarea_field($data['meta_description']) : '';
        $content = isset($data['content']) ? wp_kses_post($data['content']) : '';

        if (empty($meta_title)) {
            return new \WP_Error('blog_missing_title', __('meta_title is required for blog.', 'aapg'));
        }

        $settings = get_option(AAPG_OPTION_KEY, []);
        $post_type = $settings['blog_post_type'] ?? 'post';

        $post_type_obj = get_post_type_object($post_type);
        if (!$post_type_obj || !post_type_supports($post_type, 'title') || !post_type_supports($post_type, 'editor')) {
            return new \WP_Error('blog_invalid_post_type', __('Invalid or unsupported post type for blog.', 'aapg'));
        }

        $post_id = wp_insert_post([
            'post_type'    => $post_type,
            'post_status'  => 'publish',
            'post_title'   => $meta_title,
            'post_content' => $content,
        ], true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        update_post_meta($post_id, 'isGeneratedByAutomation', 'true');
        update_post_meta($post_id, 'aiGenerated', 'true');
        update_post_meta($post_id, 'aapg_page_type', 'blog');
        update_post_meta($post_id, 'aapg_ai_generated_title', $meta_title);
        $post = get_post($post_id);
        update_post_meta($post_id, 'aapg_ai_generated_slug', $post ? $post->post_name : '');
        update_post_meta($post_id, 'aapg_original_title', $meta_title);

        if (!empty($meta_title)) {
            update_post_meta($post_id, 'rank_math_title', $meta_title);
        }
        if (!empty($meta_description)) {
            update_post_meta($post_id, 'rank_math_description', $meta_description);
        }

        return [
            'success'   => true,
            'post_id'   => $post_id,
            'post_type' => $post_type,
            'post_url'  => get_permalink($post_id),
            'post_title' => $meta_title,
        ];
    }
}
