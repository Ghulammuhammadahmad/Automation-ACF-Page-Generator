<?php
/**
 * Plugin Name: AAPG Iframe Shortcode
 * Description: Shortcode to display an AI-generated page in an iframe by page ID.
 * Version: 1.0
 * Author: AAPG
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register the [aapg_iframe] shortcode.
 *
 * Usage:
 * [aapg_iframe id="123"]  // Shows page with ID 123 in iframe if AI-generated
 * [aapg_iframe]           // Uses current page ID if AI-generated
 * 
 * URL parameter:
 * ?aapg_iframe_id=123     // Sets the page ID via URL parameter
 *
 * @param array $atts Shortcode attributes.
 * @return string Iframe HTML or empty/fallback message.
 */
function aapg_iframe_shortcode($atts) {
    // Default attributes
    $atts = shortcode_atts(
        [
            'id' => null,           // Specific page ID
            'width' => '100%',      // Iframe width
            'height' => '600px',    // Iframe height
            'loading' => 'lazy',    // Loading attribute
            'style' => 'border:1px solid #ccc; border-radius:4px;', // Basic styling
            'fallback' => __('This content is not available or the page is not AI-generated.', 'aapg'),
        ],
        $atts,
        'aapg_iframe'
    );

    // Priority order for page ID:
    // 1. URL parameter ?aapg_iframe_id=123
    // 2. Shortcode attribute id="123"
    // 3. Current page ID
    $page_id = null;
    
    // Check URL parameter first
    if (isset($_GET['aapg_iframe_id']) && !empty($_GET['aapg_iframe_id'])) {
        $url_id = intval($_GET['aapg_iframe_id']);
        if ($url_id > 0) {
            $page_id = $url_id;
        }
    }
    
    // Then check shortcode attribute
    if (!$page_id && !empty($atts['id'])) {
        $page_id = intval($atts['id']);
    }
    
    // Finally use current page ID
    if (!$page_id) {
        $page_id = get_the_ID();
    }

    // Validate page ID
    if (!$page_id) {
        return '<p class="aapg-iframe-error">' . esc_html__('Invalid page ID.', 'aapg') . '</p>';
    }

    // Check if the page exists
    $page = get_post($page_id);
    if (!$page) {
        return '<p class="aapg-iframe-error">' . sprintf(esc_html__('Page with ID %d not found.', 'aapg'), $page_id) . '</p>';
    }

    // Check if the page is AI-generated
    $is_ai_generated = get_post_meta($page_id, 'isGeneratedByAutomation', true) === 'true';
    if (!$is_ai_generated) {
        return '<p class="aapg-iframe-fallback">' . esc_html($atts['fallback']) . '</p>';
    }

    // Check if the page is published (or at least not private for current user)
    if (!current_user_can('read_post', $page_id)) {
        return '<p class="aapg-iframe-error">' . esc_html__('You do not have permission to view this content.', 'aapg') . '</p>';
    }

    // Get the page URL
    $page_url = get_permalink($page_id);
    if (!$page_url) {
        return '<p class="aapg-iframe-error">' . esc_html__('Unable to retrieve page URL.', 'aapg') . '</p>';
    }

    // Sanitize attributes
    $width = esc_attr($atts['width']);
    $height = esc_attr($atts['height']);
    $loading = esc_attr($atts['loading']);
    $style = esc_attr($atts['style']);

    // Build iframe HTML
    $iframe_html = sprintf(
        '<div class="aapg-iframe-container" style="margin: 15px 0;">' .
        '<iframe src="%s" ' .
        'width="%s" ' .
        'height="%s" ' .
        'loading="%s" ' .
        'style="%s" ' .
        'title="%s" ' .
        'sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-modals">' .
        '</iframe>' .
        '<div class="aapg-iframe-info" style="margin-top: 8px; font-size: 12px; color: #666;">' .
        '<p>%s: <a href="%s" target="_blank">%s</a></p>' .
        '</div>',
        esc_url($page_url),
        $width,
        $height,
        $loading,
        $style,
        esc_attr(sprintf(__('AI Generated Page: %s', 'aapg'), get_the_title($page_id))),
        esc_html__('Viewing AI-generated page:', 'aapg'),
        esc_url($page_url),
        esc_html(get_the_title($page_id))
    );

    // Add AI generation info box
    $ai_info_html = aapg_get_generation_info_box($page_id);

    return $iframe_html . $ai_info_html;
}
add_shortcode('aapg_iframe', 'aapg_iframe_shortcode');

/**
 * Generate AI generation info box for a page.
 *
 * @param int $page_id The page ID.
 * @return string HTML for the info box.
 */
function aapg_get_generation_info_box($page_id) {
    // Get AI generation metadata
    $prompt_id = get_post_meta($page_id, 'aapg_prompt_id', true);
    $prompt_content = get_post_meta($page_id, 'aapg_prompt_content', true);
    $ai_generated_title = get_post_meta($page_id, 'aapg_ai_generated_title', true);
    $ai_generated_slug = get_post_meta($page_id, 'aapg_ai_generated_slug', true);
    $original_title = get_post_meta($page_id, 'aapg_original_title', true);
    $url_resolution_table = get_post_meta($page_id, 'aapg_url_resolution_table', true);
    $generation_time = get_post_meta($page_id, 'aapg_generation_time', true);
    
    // Get post creation date
    $post = get_post($page_id);
    $created_date = $post ? $post->post_date : '';
    
    $html = '<div class="aapg-ai-info-box" style="margin: 20px 0; border: 1px solid #ddd; border-radius: 6px; background: #f9f9f9;">';
    $html .= '<div class="aapg-ai-info-header" style="padding: 12px 15px; background: #0073aa; color: white; border-radius: 6px 6px 0 0; font-weight: 600; display: flex; align-items: center;">';
    $html .= '<span class="dashicons dashicons-info" style="margin-right: 8px;"></span>';
    $html .= esc_html__('AI Generation Information', 'aapg');
    $html .= '</div>';
    
    $html .= '<div class="aapg-ai-info-content" style="padding: 15px;">';
    
    // Basic info
    $html .= '<div class="aapg-info-section" style="margin-bottom: 15px;">';
    $html .= '<h4 style="margin: 0 0 8px 0; color: #333; font-size: 14px;">' . esc_html__('Generation Details', 'aapg') . '</h4>';
    $html .= '<table class="aapg-info-table" style="width: 100%; border-collapse: collapse;">';
    
    if ($prompt_id) {
        $html .= '<tr>';
        $html .= '<td style="padding: 4px 8px; font-weight: 600; color: #555; width: 140px; vertical-align: top;">' . esc_html__('Prompt ID:', 'aapg') . '</td>';
        $html .= '<td style="padding: 4px 8px;"><code style="background: #f0f0f0; padding: 2px 6px; border-radius: 3px;">' . esc_html($prompt_id) . '</code></td>';
        $html .= '</tr>';
    }
    
    if ($ai_generated_title) {
        $html .= '<tr>';
        $html .= '<td style="padding: 4px 8px; font-weight: 600; color: #555; vertical-align: top;">' . esc_html__('AI Generated Title:', 'aapg') . '</td>';
        $html .= '<td style="padding: 4px 8px;">' . esc_html($ai_generated_title) . '</td>';
        $html .= '</tr>';
    }
    
    if ($ai_generated_slug) {
        $html .= '<tr>';
        $html .= '<td style="padding: 4px 8px; font-weight: 600; color: #555; vertical-align: top;">' . esc_html__('Generated Slug:', 'aapg') . '</td>';
        $html .= '<td style="padding: 4px 8px;"><code style="background: #f0f0f0; padding: 2px 6px; border-radius: 3px;">' . esc_html($ai_generated_slug) . '</code></td>';
        $html .= '</tr>';
    }
    
    if ($original_title && $original_title !== $ai_generated_title) {
        $html .= '<tr>';
        $html .= '<td style="padding: 4px 8px; font-weight: 600; color: #555; vertical-align: top;">' . esc_html__('Original Title:', 'aapg') . '</td>';
        $html .= '<td style="padding: 4px 8px;">' . esc_html($original_title) . '</td>';
        $html .= '</tr>';
    }
    
    if ($created_date) {
        $html .= '<tr>';
        $html .= '<td style="padding: 4px 8px; font-weight: 600; color: #555; vertical-align: top;">' . esc_html__('Created:', 'aapg') . '</td>';
        $html .= '<td style="padding: 4px 8px;">' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($created_date))) . '</td>';
        $html .= '</tr>';
    }
    
    $html .= '</table>';
    $html .= '</div>';
    
    // Prompt content
    if ($prompt_content) {
        $html .= '<div class="aapg-info-section" style="margin-bottom: 15px;">';
        $html .= '<h4 style="margin: 0 0 8px 0; color: #333; font-size: 14px;">' . esc_html__('Prompt Used', 'aapg') . '</h4>';
        $html .= '<div class="aapg-prompt-content" style="background: #fff; border: 1px solid #ccc; padding: 12px; border-radius: 4px; max-height: 200px; overflow-y: auto; font-family: monospace; font-size: 12px; line-height: 1.5; white-space: pre-wrap; word-break: break-word;">';
        $html .= esc_html($prompt_content);
        $html .= '</div>';
        $html .= '</div>';
    }
    
    // // URL Resolution Table
    // if ($url_resolution_table && is_array($url_resolution_table) && !empty($url_resolution_table)) {
    //     $html .= '<div class="aapg-info-section">';
    //     $html .= '<h4 style="margin: 0 0 8px 0; color: #333; font-size: 14px;">' . esc_html__('URL Resolution Table', 'aapg') . '</h4>';
    //     $html .= '<div class="aapg-url-table" style="background: #fff; border: 1px solid #ccc; border-radius: 4px; overflow: hidden;">';
    //     $html .= '<table style="width: 100%; border-collapse: collapse; font-size: 12px;">';
    //     $html .= '<thead><tr style="background: #f5f5f5;">';
    //     $html .= '<th style="padding: 8px; text-align: left; border-bottom: 1px solid #ddd;">' . esc_html__('URL', 'aapg') . '</th>';
    //     $html .= '<th style="padding: 8px; text-align: left; border-bottom: 1px solid #ddd;">' . esc_html__('Resolution', 'aapg') . '</th>';
    //     $html .= '</tr></thead>';
    //     $html .= '<tbody>';
        
    //     foreach ($url_resolution_table as $index => $row) {
    //         $url = is_array($row) ? ($row['url'] ?? '') : $row;
    //         $resolution = is_array($row) ? ($row['resolution'] ?? '') : '';
            
    //         $html .= '<tr' . ($index % 2 === 0 ? ' style="background: #fafafa;"' : '') . '>';
    //         $html .= '<td style="padding: 6px 8px; border-bottom: 1px solid #eee; word-break: break-all;">' . esc_html($url) . '</td>';
    //         $html .= '<td style="padding: 6px 8px; border-bottom: 1px solid #eee; word-break: break-all;">' . esc_html($resolution) . '</td>';
    //         $html .= '</tr>';
    //     }
        
    //     $html .= '</tbody></table>';
    //     $html .= '</div>';
    //     $html .= '</div>';
    // }
    
    $html .= '</div>'; // Close content div
    $html .= '</div>'; // Close info box
    
    return $html;
}

/**
 * Add some basic CSS for the iframe container.
 */
function aapg_iframe_styles() {
    echo '<style>
        .aapg-iframe-container {
            max-width: 100%;
            overflow: hidden;
        }
        .aapg-iframe-container iframe {
            max-width: 100%;
            height: auto;
            min-height: 400px;
            aspect-ratio: 16/9;
        }
        main#content{
            max-width:1440px;
        }
        .aapg-iframe-error,
        .aapg-iframe-fallback {
            padding: 15px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            color: #6c757d;
        }
        .aapg-iframe-error {
            background: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
        }
        .aapg-ai-info-box {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .aapg-ai-info-header .dashicons {
            font-size: 16px;
            width: 16px;
            height: 16px;
        }
        .aapg-prompt-content {
            scrollbar-width: thin;
            scrollbar-color: #ccc transparent;
        }
        .aapg-prompt-content::-webkit-scrollbar {
            width: 6px;
        }
        .aapg-prompt-content::-webkit-scrollbar-track {
            background: transparent;
        }
        .aapg-prompt-content::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 3px;
        }
        .aapg-prompt-content::-webkit-scrollbar-thumb:hover {
            background: #999;
        }
        .aapg-url-table {
            max-height: 300px;
            overflow-y: auto;
        }
        .aapg-url-table table {
            margin: 0;
        }
        .aapg-info-table td {
            border-bottom: 1px solid #eee;
        }
        .aapg-info-table tr:last-child td {
            border-bottom: none;
        }
        @media (max-width: 768px) {
            .aapg-iframe-container iframe {
                min-height: 300px;
            }
            .aapg-info-table td {
                display: block;
                width: 100% !important;
                padding: 6px 0 !important;
            }
            .aapg-info-table td:first-child {
                font-weight: 600;
                color: #333;
                border-bottom: none;
            }
        }
    </style>';
}
add_action('wp_head', 'aapg_iframe_styles');

/**
 * Admin notice for shortcode usage.
 */
function aapg_iframe_admin_notice() {
    $screen = get_current_screen();
    if ($screen && $screen->base === 'post' && $screen->post_type === 'page') {
        $page_id = get_the_ID();
        $is_ai_generated = get_post_meta($page_id, 'isGeneratedByAutomation', true) === 'true';
        
        if ($is_ai_generated) {
            $current_url = remove_query_arg('aapg_iframe_id', get_permalink($page_id));
            $iframe_url = add_query_arg('aapg_iframe_id', $page_id, $current_url);
            
            echo '<div class="notice notice-info is-dismissible">';
            echo '<p>';
            echo '<strong>' . esc_html__('AI Generated Page Detected!', 'aapg') . '</strong><br>';
            printf(
                esc_html__('Shortcode: %s', 'aapg'),
                '<code>[aapg_iframe id="' . $page_id . '"]</code>'
            );
            echo '<br>';
            printf(
                esc_html__('URL Parameter: %s', 'aapg'),
                '<code>?aapg_iframe_id=' . $page_id . '</code>'
            );
            echo '<br>';
            printf(
                esc_html__('Full URL: %s', 'aapg'),
                '<a href="' . esc_url($iframe_url) . '" target="_blank">' . esc_html($iframe_url) . '</a>'
            );
            echo '</p>';
            echo '</div>';
        }
    }
}
add_action('admin_notices', 'aapg_iframe_admin_notice');
