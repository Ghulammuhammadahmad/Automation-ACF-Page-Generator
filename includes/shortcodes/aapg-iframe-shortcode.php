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

    // Get the page URL (hub/stub pages show ACF group fields by default when viewed)
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
        '<iframe id="aapg-iframe-content" src="%s" ' .
        'width="%s" ' .
        'height="%s" ' .
        'loading="%s" ' .
        'style="%s" ' .
        'title="%s" ' .
        'sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-modals allow-top-navigation">' .
        '</iframe>' .
        '<div class="aapg-iframe-info" style="margin-top: 8px; font-size: 13px; color: #666;">' .
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

    // Publish / Unpublish bar (when user can edit) - shown at top
    $publish_bar_html = '';
    if (current_user_can('edit_post', $page_id)) {
        $publish_bar_html = aapg_iframe_get_publish_bar_html($page_id);
    }

    // Add AI generation info box - shown after iframe
    $ai_info_html = aapg_get_generation_info_box($page_id);

    // Edit with AI box - shown after AI info
    $edit_with_ai_html = aapg_get_edit_with_ai_box($page_id);

    // Page images box - shown after Edit with AI
    $page_images_html = aapg_get_page_images_box($page_id);

    // For hub/stub: add custom ACF edit form in shortcode output (nothing is added to the embedded page)
    $acf_form_html = '';
    $research_editor_html = '';
    $acf_group_id = get_post_meta($page_id, 'aapg_acf_group_id', true);
    if (!empty($acf_group_id) && current_user_can('edit_post', $page_id) && function_exists('acf_get_fields')) {
        $acf_form_html = aapg_iframe_get_acf_edit_form_html($page_id);
    } elseif (current_user_can('edit_post', $page_id)) {
        // Research center: no ACF group; show post content in a rich editor instead
        $research_editor_html = aapg_iframe_get_research_content_editor_html($page_id);
    }

    // New order: 1. Publish status, 2. iframe, 3. AI info, 4. Edit with AI, 5. Page images, 6. Edit content
    return $publish_bar_html . $iframe_html . $ai_info_html . $edit_with_ai_html . $page_images_html . $acf_form_html . $research_editor_html;
}
add_shortcode('aapg_iframe', 'aapg_iframe_shortcode');

/**
 * Enqueue CSS, JS, and TinyMCE when page has [aapg_iframe] shortcode.
 */
function aapg_iframe_enqueue_shortcode_assets() {
    if (!is_singular()) {
        return;
    }
    $post = get_post();
    if (!$post || !has_shortcode($post->post_content ?? '', 'aapg_iframe')) {
        return;
    }
    $version = defined('AAPG_PLUGIN_VERSION') ? AAPG_PLUGIN_VERSION : '1.0';
    $base = defined('AAPG_PLUGIN_URL') ? AAPG_PLUGIN_URL : plugin_dir_url(dirname(dirname(dirname(__FILE__))) . '/automation-acf-page-generator.php');

    wp_enqueue_style(
        'aapg-shortcode-iframe',
        $base . 'assets/shortcodeiframe.css',
        [],
        $version
    );

    wp_enqueue_script(
        'aapg-tinymce',
        'https://cdn.tiny.cloud/1/i4a9ti3c45qmnna4hc436beb365zv67d0bvq27vlcjidavu2/tinymce/8/tinymce.min.js',
        [],
        null,
        false
    );
    wp_script_add_data('aapg-tinymce', 'referrerpolicy', 'origin');
    wp_script_add_data('aapg-tinymce', 'crossorigin', 'anonymous');

    $tinymce_config = [
        'selector'   => 'textarea.aapg-tinymce',
        'menubar'    => false,
        'statusbar'  => false,
        'branding'   => false,
        'promotion'  => false,
        'plugins'    => 'anchor link lists',
        'toolbar'    => 'undo redo | bold italic underline strikethrough | link | removeformat',
    ];
    wp_enqueue_script(
        'aapg-shortcode-iframe',
        $base . 'assets/shortcodeiframe.js',
        ['jquery', 'aapg-tinymce'],
        $version,
        true
    );
    wp_localize_script('aapg-shortcode-iframe', 'aapgShortcodeIframe', [
        'tinymceConfig' => $tinymce_config,
    ]);
}
add_action('wp_enqueue_scripts', 'aapg_iframe_enqueue_shortcode_assets', 20);

/**
 * Get current page content as JSON for AI edit (hub/stub: ACF + title/slug; research: content + meta).
 *
 * @param int    $page_id   Post/page ID.
 * @param string $page_type One of 'hub', 'stub', 'research'.
 * @return string JSON-encoded content for the prompt.
 */
function aapg_iframe_get_current_content_json($page_id, $page_type) {
    $page_id = (int) $page_id;
    $post = get_post($page_id);
    if (!$post) {
        return '{}';
    }
    if ($page_type === 'research') {
        $meta_title = get_post_meta($page_id, 'rank_math_title', true);
        $meta_desc = get_post_meta($page_id, 'rank_math_description', true);
        return wp_json_encode([
            'content' => $post->post_content,
            'meta_title' => $meta_title,
            'meta_description' => $meta_desc,
            'research_title' => $post->post_title,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    // hub or stub: ACF fields + page_title, page_slug
    $acf_group_id = get_post_meta($page_id, 'aapg_acf_group_id', true);
    $current = [
        'page_title' => $post->post_title,
        'page_slug' => $post->post_name,
    ];
    if (!empty($acf_group_id) && function_exists('acf_get_fields') && function_exists('get_field')) {
        $fields = acf_get_fields($acf_group_id);
        if (is_array($fields)) {
            foreach ($fields as $field) {
                $name = $field['name'] ?? '';
                if ($name === '' || ($field['type'] ?? '') === 'image' || ($field['type'] ?? '') === 'file') {
                    continue;
                }
                $current[$name] = get_field($name, $page_id);
            }
        }
    }
    return wp_json_encode($current, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

/**
 * Edit with AI box HTML (before AI Generation Information). Uses stored prompt id, ACF group, stored prompt; user adds edit prompt and selects page type.
 *
 * @param int $page_id The page ID shown in the iframe.
 * @return string HTML for the box or empty if not allowed.
 */
function aapg_get_edit_with_ai_box($page_id) {
    $page_id = (int) $page_id;
    if (!current_user_can('edit_post', $page_id)) {
        return '';
    }
    $prompt_id = get_post_meta($page_id, 'aapg_prompt_id', true);
    $acf_group_id = get_post_meta($page_id, 'aapg_acf_group_id', true);
    $stored_page_type = get_post_meta($page_id, 'aapg_page_type', true);
    $post = get_post($page_id);
    $post_type = $post ? $post->post_type : 'page';
    // Show for hub/stub (have prompt_id + acf_group_id) or research (have prompt_id; post can be any type)
    if (empty($prompt_id)) {
        return '';
    }
    
    // Default to stub if no page type is stored
    if (empty($stored_page_type) || !in_array($stored_page_type, ['hub', 'stub', 'research'], true)) {
        $stored_page_type = 'stub';
    }
    
    $nonce = wp_create_nonce('aapg_iframe_edit_with_ai_' . $page_id);
    $ajaxurl = admin_url('admin-ajax.php');

    $html = '<div class="aapg-edit-with-ai-box" id="aapg-edit-with-ai-box">';
    $html .= '<div class="aapg-edit-with-ai-header" id="aapg-edit-with-ai-toggle" role="button" tabindex="0" aria-expanded="false" aria-controls="aapg-edit-with-ai-body">';
    $html .= '<span class="aapg-edit-with-ai-title-text"><span class="aapg-edit-with-ai-icon" aria-hidden="true">✎</span> ' . esc_html__('Edit with AI', 'aapg') . '</span>';
    $html .= '<span class="aapg-toggle-icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg></span>';
    $html .= '</div>';
    $html .= '<div class="aapg-edit-with-ai-body" id="aapg-edit-with-ai-body" style="display:none;">';
    $html .= '<p class="aapg-edit-with-ai-desc">' . esc_html__('Describe how you want to change the content. The current content and stored prompt will be used automatically.', 'aapg') . '</p>';
    $html .= '<div class="aapg-edit-with-ai-form-wrap">';
    $html .= '<form id="aapg-edit-with-ai-form" class="aapg-edit-with-ai-form" data-page-id="' . esc_attr((string) $page_id) . '" data-ajaxurl="' . esc_attr($ajaxurl) . '" data-nonce="' . esc_attr($nonce) . '" data-post-type="' . esc_attr($post_type) . '">';
    $html .= '<div class="aapg-edit-with-ai-row aapg-edit-with-ai-row-type">';
    $html .= '<label class="aapg-edit-with-ai-label">' . esc_html__('Page type', 'aapg') . '</label>';
    $html .= '<div class="aapg-edit-with-ai-type-options">';
    $html .= '<label class="aapg-edit-with-ai-radio"><input type="radio" name="aapg_edit_page_type" value="hub"' . ($stored_page_type === 'hub' ? ' checked="checked"' : '') . ' /> ' . esc_html__('Hub', 'aapg') . '</label>';
    $html .= '<label class="aapg-edit-with-ai-radio"><input type="radio" name="aapg_edit_page_type" value="stub"' . ($stored_page_type === 'stub' ? ' checked="checked"' : '') . ' /> ' . esc_html__('Stub', 'aapg') . '</label>';
    $html .= '<label class="aapg-edit-with-ai-radio"><input type="radio" name="aapg_edit_page_type" value="research"' . ($stored_page_type === 'research' ? ' checked="checked"' : '') . ' /> ' . esc_html__('Research Center', 'aapg') . '</label>';
    $html .= '</div></div>';
    $html .= '<div class="aapg-edit-with-ai-row">';
    $html .= '<label class="aapg-edit-with-ai-label" for="aapg-edit-prompt">' . esc_html__('Edit prompt', 'aapg') . '</label>';
    $html .= '<textarea id="aapg-edit-prompt" name="aapg_edit_prompt" class="aapg-edit-with-ai-textarea" rows="3" placeholder="' . esc_attr__('e.g. Shorten the intro, add a section about pricing, make the tone more formal', 'aapg') . '" required></textarea>';
    $html .= '</div>';
    $html .= '<div class="aapg-edit-with-ai-actions">';
    $html .= '<button type="submit" class="aapg-edit-with-ai-submit" id="aapg-edit-with-ai-submit">' . esc_html__('Apply AI Edit', 'aapg') . '</button>';
    $html .= '<span id="aapg-edit-with-ai-status" class="aapg-edit-with-ai-status"></span>';
    $html .= '</div>';
    $html .= '</form>';
    $html .= '<div id="aapg-edit-with-ai-stream" class="aapg-edit-with-ai-stream" style="display:none;" aria-live="polite"></div>';
    $html .= '</div></div></div>';

    return $html;
}

/**
 * Return CSS for the custom ACF edit form. (CSS is in assets/shortcodeiframe.css, enqueued when shortcode is used.)
 *
 * @return string Empty string; styles are in enqueued file.
 */
function aapg_iframe_get_acf_form_css() {
    return '';
}

/**
 * Return HTML for the Publish / Unpublish bar (toggle post status) below the iframe.
 *
 * @param int $page_id Post ID.
 * @return string HTML.
 */
function aapg_iframe_get_publish_bar_html($page_id) {
    $page_id = (int) $page_id;
    $post = get_post($page_id);
    if (!$post) {
        return '';
    }
    $status = $post->post_status;
    $is_published = ($status === 'publish');
    $nonce = wp_create_nonce('aapg_iframe_publish_' . $page_id);
    $ajaxurl = admin_url('admin-ajax.php');

    $status_label = $is_published ? __('Published', 'aapg') : __('Draft', 'aapg');
    $action_label = $is_published ? __('Unpublish', 'aapg') : __('Publish', 'aapg');
    $new_status = $is_published ? 'draft' : 'publish';

    $html = '<div id="aapg-iframe-publish-bar" class="aapg-iframe-publish-bar" data-post-id="' . esc_attr((string) $page_id) . '" data-nonce="' . esc_attr($nonce) . '" data-ajaxurl="' . esc_attr($ajaxurl) . '" data-status="' . esc_attr($status) . '">';
    $html .= '<span class="aapg-iframe-publish-status">' . esc_html__('Status:', 'aapg') . ' <strong class="aapg-iframe-status-value">' . esc_html($status_label) . '</strong></span>';
    $html .= ' <button type="button" class="aapg-iframe-publish-btn" data-set-status="' . esc_attr($new_status) . '">' . esc_html($action_label) . '</button>';
    $html .= '<span id="aapg-iframe-publish-status-msg" class="aapg-iframe-publish-status-msg" aria-live="polite"></span>';
    $html .= '</div>';

    return $html;
}

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
    $acf_group_id = get_post_meta($page_id, 'aapg_acf_group_id', true);
    $seo_master_launch_request = get_post_meta($page_id, 'aapg_seo_master_launch_request', true);
    $page_type = get_post_meta($page_id, 'aapg_page_type', true);
    
    // Get post creation date
    $post = get_post($page_id);
    $created_date = $post ? $post->post_date : '';
    
    $html = '<div class="aapg-ai-info-box" style="margin: 20px 0; border: 1px solid #ddd; border-radius: 6px; background: #f9f9f9;">';
    $html .= '<div class="aapg-ai-info-header" id="aapg-ai-info-toggle" role="button" tabindex="0" aria-expanded="false" aria-controls="aapg-ai-info-content" style="padding: 14px 18px; background: #f6f7f7; color: #1d2327; border-radius: 6px 6px 0 0; font-weight: 600; font-size: 14px; display: flex; align-items: center; justify-content: space-between; cursor: pointer; border-bottom: 1px solid #c3c4c7;">';
    $html .= '<span><span class="dashicons dashicons-info" style="margin-right: 8px;"></span>' . esc_html__('AI Generation Information', 'aapg') . '</span>';
    $html .= '<span class="aapg-toggle-icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg></span>';
    $html .= '</div>';
    
    $html .= '<div class="aapg-ai-info-content" id="aapg-ai-info-content" style="padding: 15px; display: none;">';
    
    // Basic info
    $html .= '<div class="aapg-info-section" style="margin-bottom: 15px;">';
    $html .= '<h4 class="aapg-info-heading" style="margin: 0 0 8px 0; color: #333; font-size: 14px;">' . esc_html__('Generation Details', 'aapg') . '</h4>';
    $html .= '<table class="aapg-info-table" style="width: 100%; border-collapse: collapse;">';
    
    if ($page_type) {
        $page_type_label = ucfirst($page_type);
        if ($page_type === 'research') {
            $page_type_label = __('Research Center', 'aapg');
        }
        $html .= '<tr>';
        $html .= '<td style="padding: 4px 8px; font-weight: 600; color: #555; width: 140px; vertical-align: top;">' . esc_html__('Page Type:', 'aapg') . '</td>';
        $html .= '<td style="padding: 4px 8px;"><span style="background: #dcdcde; color: #1d2327; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase;">' . esc_html($page_type_label) . '</span></td>';
        $html .= '</tr>';
    }
    
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

    if ($acf_group_id && function_exists('acf_get_field_group')) {
        $group = acf_get_field_group($acf_group_id);
        $group_label = is_array($group) && !empty($group['title']) ? $group['title'] : $acf_group_id;
        $html .= '<tr>';
        $html .= '<td style="padding: 4px 8px; font-weight: 600; color: #555; vertical-align: top;">' . esc_html__('ACF Field Group (hub/stub):', 'aapg') . '</td>';
        $html .= '<td style="padding: 4px 8px;"><code style="background: #f0f0f0; padding: 2px 6px; border-radius: 3px;">' . esc_html($group_label) . '</code></td>';
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
    
    // SEO Master Launch Request
    if ($seo_master_launch_request) {
        $html .= '<div class="aapg-info-section" style="margin-bottom: 15px;">';
        $html .= '<h4 style="margin: 0 0 8px 0; color: #333; font-size: 14px;">' . esc_html__('SEO Master Launch Request (SEO Bundle Planner)', 'aapg') . '</h4>';
        $html .= '<div class="aapg-seo-master-content" style="background: #fff; border: 1px solid #ccc; padding: 12px; border-radius: 4px; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 12px; line-height: 1.5; white-space: pre-wrap; word-break: break-word;">';
        $html .= esc_html($seo_master_launch_request);
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
 * Extract all images from Elementor data
 *
 * @param int $page_id The page ID.
 * @return array Array of image data (id, url, alt, title)
 */
function aapg_extract_elementor_images($page_id) {
    $elementor_data = get_post_meta($page_id, '_elementor_data', true);
    
    if (empty($elementor_data)) {
        return [];
    }
    
    // Decode JSON data
    $data = json_decode($elementor_data, true);
    if (!is_array($data)) {
        return [];
    }
    
    $images = [];
    
    // Recursive function to find images
    $find_images = function($element) use (&$find_images, &$images) {
        if (!is_array($element)) {
            return;
        }
        
        // Check for image widget
        if (isset($element['widgetType']) && $element['widgetType'] === 'image') {
            if (isset($element['settings']['image']['id']) && !empty($element['settings']['image']['id'])) {
                $image_id = $element['settings']['image']['id'];
                $image_url = $element['settings']['image']['url'] ?? wp_get_attachment_url($image_id);
                
                if ($image_url) {
                    $images[] = [
                        'id' => $image_id,
                        'url' => $image_url,
                        'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true),
                        'title' => get_the_title($image_id),
                    ];
                }
            }
        }
        
        // Check for background images in settings
        if (isset($element['settings'])) {
            $settings = $element['settings'];
            
            // Background image
            if (isset($settings['background_image']['id']) && !empty($settings['background_image']['id'])) {
                $image_id = $settings['background_image']['id'];
                $image_url = $settings['background_image']['url'] ?? wp_get_attachment_url($image_id);
                
                if ($image_url) {
                    $images[] = [
                        'id' => $image_id,
                        'url' => $image_url,
                        'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true),
                        'title' => get_the_title($image_id),
                        'type' => 'background',
                    ];
                }
            }
            
            // Background overlay image
            if (isset($settings['background_overlay_image']['id']) && !empty($settings['background_overlay_image']['id'])) {
                $image_id = $settings['background_overlay_image']['id'];
                $image_url = $settings['background_overlay_image']['url'] ?? wp_get_attachment_url($image_id);
                
                if ($image_url) {
                    $images[] = [
                        'id' => $image_id,
                        'url' => $image_url,
                        'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true),
                        'title' => get_the_title($image_id),
                        'type' => 'background_overlay',
                    ];
                }
            }
        }
        
        // Recursively check elements
        if (isset($element['elements']) && is_array($element['elements'])) {
            foreach ($element['elements'] as $child) {
                $find_images($child);
            }
        }
    };
    
    // Start recursive search
    foreach ($data as $element) {
        $find_images($element);
    }
    
    // Remove duplicates based on image ID
    $unique_images = [];
    $seen_ids = [];
    
    foreach ($images as $image) {
        if (!in_array($image['id'], $seen_ids)) {
            $unique_images[] = $image;
            $seen_ids[] = $image['id'];
        }
    }
    
    return $unique_images;
}

/**
 * Generate page images display box
 *
 * @param int $page_id The page ID.
 * @return string HTML for the images box.
 */
function aapg_get_page_images_box($page_id) {
    $images = aapg_extract_elementor_images($page_id);
    
    if (empty($images)) {
        return '';
    }
    
    $can_edit = current_user_can('edit_post', $page_id);
    $nonce = wp_create_nonce('aapg_update_page_images_' . $page_id);
    $ajaxurl = admin_url('admin-ajax.php');
    
    $html = '<div class="aapg-page-images-box" style="margin: 20px 0; border: 1px solid #ddd; border-radius: 6px; background: #f9f9f9;">';
    $html .= '<div class="aapg-page-images-header" id="aapg-page-images-toggle" role="button" tabindex="0" aria-expanded="false" aria-controls="aapg-page-images-content" style="padding: 14px 18px; background: #f6f7f7; color: #1d2327; border-radius: 6px 6px 0 0; font-weight: 600; font-size: 14px; display: flex; align-items: center; justify-content: space-between; cursor: pointer; border-bottom: 1px solid #c3c4c7;">';
    $html .= '<span><span class="dashicons dashicons-format-gallery" style="margin-right: 8px;"></span>' . sprintf(esc_html__('Page Images (%d)', 'aapg'), count($images)) . '</span>';
    $html .= '<span class="aapg-toggle-icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg></span>';
    $html .= '</div>';
    
    $html .= '<div class="aapg-page-images-content" id="aapg-page-images-content" style="padding: 15px; display: none;">';
    
    if ($can_edit) {
        $html .= '<div id="aapg-images-form" data-page-id="' . esc_attr($page_id) . '" data-ajaxurl="' . esc_attr($ajaxurl) . '" data-nonce="' . esc_attr($nonce) . '">';
    }
    
    $html .= '<div class="aapg-images-grid" id="aapg-images-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px;">';
    
    foreach ($images as $index => $image) {
        $type_label = isset($image['type']) ? ' (' . ucfirst(str_replace('_', ' ', $image['type'])) . ')' : '';
        $html .= '<div class="aapg-image-item" data-image-id="' . esc_attr($image['id']) . '" data-image-index="' . esc_attr($index) . '" style="border: 1px solid #ddd; border-radius: 4px; overflow: hidden; background: #fff; position: relative;">';
        
        if ($can_edit) {
            $html .= '<button type="button" class="aapg-image-replace-btn" data-image-id="' . esc_attr($image['id']) . '" style="position: absolute; top: 5px; right: 5px; background: rgba(0,0,0,0.7); color: white; border: none; border-radius: 3px; padding: 5px 8px; font-size: 12px; cursor: pointer; z-index: 10;">Replace</button>';
        }
        
        $html .= '<div class="aapg-image-wrapper" style="aspect-ratio: 16/9; overflow: hidden; background: #f0f0f0;">';
        $html .= '<img class="aapg-image-preview" src="' . esc_url($image['url']) . '" alt="' . esc_attr($image['alt']) . '" style="width: 100%; height: 100%; object-fit: cover;" loading="lazy" />';
        $html .= '</div>';
        $html .= '<div class="aapg-image-info" style="padding: 8px; font-size: 12px;">';
        
        if (!empty($image['title'])) {
            $html .= '<div class="aapg-image-title" style="font-weight: 600; margin-bottom: 4px; color: #333;">' . esc_html($image['title']) . esc_html($type_label) . '</div>';
        }
        
        if (!empty($image['alt'])) {
            $html .= '<div class="aapg-image-alt" style="color: #666; margin-bottom: 4px;">Alt: ' . esc_html($image['alt']) . '</div>';
        }
        
        $html .= '<div class="aapg-image-id" style="color: #999; font-size: 12px;">ID: <span class="aapg-current-image-id">' . esc_html($image['id']) . '</span></div>';
        $html .= '<a href="' . esc_url($image['url']) . '" target="_blank" class="aapg-image-view" style="display: inline-block; margin-top: 6px; color: #2271b1; text-decoration: none; font-size: 12px;">View Full Size →</a>';
        $html .= '</div>';
        $html .= '</div>';
    }
    
    $html .= '</div>'; // Close grid
    
    if ($can_edit) {
        $html .= '<div class="aapg-images-actions" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd; display: flex; gap: 10px; align-items: center;">';
        $html .= '<button type="button" id="aapg-update-images-btn" class="aapg-update-images-btn" style="padding: 10px 20px; background: #2EA3F2; color: #fff; border: 1px solid #c3c4c7; border-radius: 4px; font-weight: 600; cursor: pointer; font-size: 14px;">Update Images</button>';
        $html .= '<span id="aapg-images-status" class="aapg-images-status" style="font-size: 13px;"></span>';
        $html .= '</div>';
        $html .= '</div>'; // Close form
    }
    
    $html .= '</div>'; // Close content
    $html .= '</div>'; // Close box
    
    // Add media upload modal
    if ($can_edit) {
        $html .= '<div id="aapg-media-upload-modal" class="aapg-media-upload-modal" style="display:none;">';
        $html .= '<div class="aapg-modal-overlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 9998;"></div>';
        $html .= '<div class="aapg-modal-content" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; padding: 25px; max-width: 500px; width: 90%; z-index: 9999; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">';
        $html .= '<div class="aapg-modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;">';
        $html .= '<h3 style="margin: 0; color: #333; font-size: 16px;">Replace Image</h3>';
        $html .= '<button type="button" class="aapg-modal-close" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #666; line-height: 1;">&times;</button>';
        $html .= '</div>';
        $html .= '<div class="aapg-modal-body">';
        $html .= '<div class="aapg-upload-area" style="border: 2px dashed #ccc; border-radius: 8px; padding: 40px 20px; text-align: center; background: #fafafa; margin-bottom: 20px;">';
        $html .= '<input type="file" id="aapg-image-upload-input" accept="image/*" style="display: none;">';
        $html .= '<label for="aapg-image-upload-input" style="cursor: pointer; display: block;">';
        $html .= '<span class="dashicons dashicons-cloud-upload" style="font-size: 24px; color: #787c82; width: 24px; height: 24px;"></span>';
        $html .= '<p style="margin: 10px 0 5px; font-size: 14px; color: #333; font-weight: 600;">Click to upload or drag and drop</p>';
        $html .= '<p style="margin: 0; font-size: 13px; color: #999;">PNG, JPG, GIF, WebP up to 10MB</p>';
        $html .= '</label>';
        $html .= '</div>';
        $html .= '<div id="aapg-upload-preview" class="aapg-upload-preview" style="display: none; margin-bottom: 15px;">';
        $html .= '<img id="aapg-preview-image" style="max-width: 100%; border-radius: 4px; border: 1px solid #ddd;" />';
        $html .= '</div>';
        $html .= '<div id="aapg-upload-progress" class="aapg-upload-progress" style="display: none; margin-bottom: 15px;">';
        $html .= '<div style="background: #f0f0f0; height: 6px; border-radius: 3px; overflow: hidden;">';
        $html .= '<div id="aapg-progress-bar" style="background: #787c82; height: 100%; width: 0%; transition: width 0.3s;"></div>';
        $html .= '</div>';
        $html .= '<p id="aapg-upload-status" style="margin: 8px 0 0; font-size: 13px; color: #666; text-align: center;">Uploading...</p>';
        $html .= '</div>';
        $html .= '<div class="aapg-modal-actions" style="display: flex; gap: 10px; justify-content: flex-end;">';
        $html .= '<button type="button" class="aapg-modal-cancel" style="padding: 8px 16px; background: #f0f0f0; color: #333; border: none; border-radius: 4px; cursor: pointer; font-size: 14px;">Cancel</button>';
        $html .= '<button type="button" id="aapg-modal-upload-btn" class="aapg-modal-upload" style="padding: 8px 16px; background: #f0f0f1; color: #1d2327; border: 1px solid #c3c4c7; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 14px;" disabled>Upload</button>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
    }
    
    return $html;
}


/**
 * Get populated value for an ACF field (saved value or default).
 *
 * @param string $name Field name.
 * @param int    $post_id Post ID.
 * @param array  $field ACF field config.
 * @return mixed
 */
function aapg_iframe_get_field_value_for_form($name, $post_id, $field) {
    $value = get_field($name, $post_id);
    // Use ACF default only when no value has been saved (null/false)
    if ($value === null || $value === false) {
        $value = $field['default_value'] ?? '';
    }
    return $value;
}

/**
 * Return full ACF edit form HTML for use inside [aapg_iframe] shortcode (CSS + form + script).
 * Nothing is added to the embedded page.
 *
 * @param int $page_id Post ID of the hub/stub page shown in the iframe.
 * @return string HTML.
 */
function aapg_iframe_get_acf_edit_form_html($page_id) {
    $page_id = (int) $page_id;
    $acf_group_id = get_post_meta($page_id, 'aapg_acf_group_id', true);
    if (empty($acf_group_id) || !function_exists('acf_get_fields')) {
        return '';
    }

    $fields = acf_get_fields($acf_group_id);
    if (!is_array($fields) || empty($fields)) {
        return '';
    }

    $group = function_exists('acf_get_field_group') ? acf_get_field_group($acf_group_id) : null;
    $group_title = is_array($group) && !empty($group['title']) ? $group['title'] : __('ACF Fields', 'aapg');

    $nonce = wp_create_nonce('aapg_iframe_acf_save_' . $page_id);
    $ajaxurl = admin_url('admin-ajax.php');

    $html = aapg_iframe_get_acf_form_css();

    $html .= '<div class="aapg-acf-edit-form-wrapper">';
    $html .= '<div class="aapg-acf-edit-form-inner aapg-form-collapsed" id="aapg-acf-edit-form-inner">';
    $html .= '<div class="aapg-acf-edit-form-header" id="aapg-acf-edit-form-toggle" role="button" tabindex="0" aria-expanded="true" aria-controls="aapg-acf-edit-form-body">';
    $html .= '<span class="aapg-acf-edit-form-title-text">' . esc_html(sprintf(__('Edit AI content fields (%s)', 'aapg'), $group_title)) . '</span>';
    $html .= '<span class="aapg-toggle-icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg></span>';
    $html .= '</div>';
    $html .= '<div class="aapg-acf-edit-form-body" id="aapg-acf-edit-form-body">';
    $html .= '<form id="aapg-iframe-acf-form" class="aapg-acf-edit-form" method="post" action="" data-ajaxurl="' . esc_attr($ajaxurl) . '" data-nonce="' . esc_attr($nonce) . '" data-post-id="' . esc_attr((string) $page_id) . '">';
    $html .= '<div class="aapg-acf-edit-form-fields">';

    foreach ($fields as $field) {
        $name = $field['name'] ?? '';
        $type = $field['type'] ?? 'text';
        if (empty($name) || $type === 'image' || $type === 'file') {
            continue;
        }
        $value = aapg_iframe_get_field_value_for_form($name, $page_id, $field);
        $key = $field['key'] ?? $name;
        $label = $field['label'] ?? $name;
        $input_name = 'acf_field_' . $key;
        $placeholder = !empty($field['placeholder']) ? $field['placeholder'] : '';
        $label_for = ($type === 'wysiwyg') ? 'aapg_wysiwyg_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $input_name) : $input_name;

        $html .= '<div class="aapg-acf-edit-form-field" data-type="' . esc_attr($type) . '">';
        $html .= '<label for="' . esc_attr($label_for) . '" class="aapg-acf-edit-form-label">' . esc_html($label) . '</label>';
        $html .= '<div class="aapg-acf-edit-form-input-wrap">';
        $html .= aapg_iframe_render_acf_field_input($input_name, $type, $value, $field, $placeholder);
        $html .= '</div></div>';
    }

    $html .= '</div>';
    $html .= '<div class="aapg-acf-edit-form-actions">';
    $html .= '<button type="submit" class="aapg-acf-edit-form-submit" id="aapg-iframe-acf-save-btn">' . esc_html__('Save changes', 'aapg') . '</button>';
    $html .= '<span id="aapg-iframe-acf-status" class="aapg-acf-edit-form-status"></span>';
    $html .= '</div></form></div></div></div>';

    return $html;
}

/**
 * Return HTML for the Research Center content editor (post content in a rich editor).
 * Shown when the page is AI-generated but has no ACF group (research center).
 *
 * @param int $page_id Post ID.
 * @return string HTML.
 */
function aapg_iframe_get_research_content_editor_html($page_id) {
    $page_id = (int) $page_id;
    $post = get_post($page_id);
    if (!$post) {
        return '';
    }
    $content = $post->post_content ?? '';

    $nonce = wp_create_nonce('aapg_iframe_research_content_' . $page_id);
    $ajaxurl = admin_url('admin-ajax.php');
    $textarea_id = 'aapg_research_content_editor';

    $html = '<div class="aapg-research-editor-wrapper">';
    $html .= '<div class="aapg-research-editor-inner" id="aapg-research-editor-inner">';
    $html .= '<div class="aapg-research-editor-header" id="aapg-research-editor-toggle" role="button" tabindex="0" aria-expanded="true" aria-controls="aapg-research-editor-body">';
    $html .= '<span class="aapg-research-editor-title-text">' . esc_html__('Edit Research Center content', 'aapg') . '</span>';
    $html .= '<span class="aapg-toggle-icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg></span>';
    $html .= '</div>';
    $html .= '<div class="aapg-research-editor-body" id="aapg-research-editor-body">';
    $html .= '<form id="aapg-research-content-form" class="aapg-research-content-form" method="post" action="" data-ajaxurl="' . esc_attr($ajaxurl) . '" data-nonce="' . esc_attr($nonce) . '" data-post-id="' . esc_attr((string) $page_id) . '">';
    $html .= '<div class="aapg-wysiwyg-wrapper">';
    $html .= '<textarea id="' . esc_attr($textarea_id) . '" name="aapg_research_content" rows="14" class="aapg-acf-input aapg-tinymce">' . esc_textarea($content) . '</textarea>';
    $html .= '</div>';
    $html .= '<div class="aapg-research-editor-actions">';
    $html .= '<button type="submit" class="aapg-acf-edit-form-submit" id="aapg-research-content-save-btn">' . esc_html__('Save content', 'aapg') . '</button>';
    $html .= '<span id="aapg-research-content-status" class="aapg-acf-edit-form-status"></span>';
    $html .= '</div></form></div></div></div>';

    return $html;
}

/**
 * Render a single ACF field input for the custom form (populated with current value).
 *
 * @param string $input_name Form input name.
 * @param string $type ACF field type.
 * @param mixed  $value Current value (from get_field or default).
 * @param array  $field ACF field config.
 * @param string $placeholder Optional placeholder.
 * @return string HTML for the input.
 */
function aapg_iframe_render_acf_field_input($input_name, $type, $value, $field, $placeholder = '') {
    // Normalize value for display: use saved/default, stringify arrays/objects
    $raw = $value;
    if (is_array($raw) || is_object($raw)) {
        $raw = wp_json_encode($raw);
    }
    $raw = (string) $raw;
    $value_attr = esc_attr($raw);
    $placeholder_attr = $placeholder !== '' ? ' placeholder="' . esc_attr($placeholder) . '"' : '';
    $id = esc_attr($input_name);

    switch ($type) {
        case 'textarea':
            return '<textarea id="' . $id . '" name="' . esc_attr($input_name) . '" rows="8" class="aapg-acf-input"' . $placeholder_attr . '>' . esc_textarea($raw) . '</textarea>';
        case 'wysiwyg':
            $safe_id = 'aapg_wysiwyg_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $input_name);
            return '<div class="aapg-wysiwyg-wrapper"><textarea id="' . esc_attr($safe_id) . '" name="' . esc_attr($input_name) . '" rows="10" class="aapg-acf-input aapg-tinymce"' . $placeholder_attr . '>' . esc_textarea($raw) . '</textarea></div>';
        case 'repeater':
            return aapg_iframe_render_repeater_field($input_name, $value, $field);
        case 'number':
            return '<input type="number" id="' . $id . '" name="' . esc_attr($input_name) . '" value="' . $value_attr . '" class="aapg-acf-input" />';
        case 'true_false':
            $checked = ($raw === '1' || $raw === 'true' || $value === true) ? ' checked' : '';
            return '<label class="aapg-acf-checkbox-label"><input type="checkbox" id="' . $id . '" name="' . esc_attr($input_name) . '" value="1"' . $checked . ' class="aapg-acf-input" /> ' . esc_html__('Yes', 'aapg') . '</label>';
        case 'select':
            $choices = $field['choices'] ?? [];
            $html = '<select id="' . $id . '" name="' . esc_attr($input_name) . '" class="aapg-acf-input">';
            $html .= '<option value="">— ' . esc_html__('Select', 'aapg') . ' —</option>';
            foreach ($choices as $opt_value => $opt_label) {
                $selected = ((string) $opt_value === $raw) ? ' selected' : '';
                $html .= '<option value="' . esc_attr($opt_value) . '"' . $selected . '>' . esc_html($opt_label) . '</option>';
            }
            $html .= '</select>';
            return $html;
        case 'checkbox':
            $choices = $field['choices'] ?? [];
            $current = is_array($value) ? $value : (array) $raw;
            if (!empty($field['return_format']) && $field['return_format'] === 'value') {
                $current = array_map('strval', $current);
            }
            $html = '';
            foreach ($choices as $opt_value => $opt_label) {
                $opt_val_str = (string) $opt_value;
                $checked = in_array($opt_value, $current, true) || in_array($opt_val_str, $current, true) ? ' checked' : '';
                $html .= '<label class="aapg-acf-checkbox-option"><input type="checkbox" name="' . esc_attr($input_name) . '[]" value="' . esc_attr($opt_value) . '"' . $checked . ' class="aapg-acf-input" /> ' . esc_html($opt_label) . '</label>';
            }
            return $html ?: '<input type="text" id="' . $id . '" name="' . esc_attr($input_name) . '" value="' . $value_attr . '" class="aapg-acf-input" readonly />';
        default:
            $input_type = $type === 'email' ? 'email' : ($type === 'url' ? 'url' : 'text');
            return '<input type="' . esc_attr($input_type) . '" id="' . $id . '" name="' . esc_attr($input_name) . '" value="' . $value_attr . '" class="aapg-acf-input"' . $placeholder_attr . ' />';
    }
}

/**
 * Render repeater field with custom UI (add/remove rows).
 *
 * @param string $input_name Base input name.
 * @param mixed  $value Current value (array of rows).
 * @param array  $field ACF field config.
 * @return string HTML for repeater.
 */
function aapg_iframe_render_repeater_field($input_name, $value, $field) {
    $rows = is_array($value) ? $value : [];
    $sub_fields = $field['sub_fields'] ?? [];
    if (empty($sub_fields)) {
        return '<p class="aapg-repeater-empty">' . esc_html__('No sub-fields defined for this repeater.', 'aapg') . '</p>';
    }

    $html = '<div class="aapg-repeater-field" data-field-name="' . esc_attr($input_name) . '">';
    $html .= '<div class="aapg-repeater-rows">';

    foreach ($rows as $row_index => $row_data) {
        $html .= aapg_iframe_render_repeater_row($input_name, $row_index, $row_data, $sub_fields);
    }

    $html .= '</div>';
    $html .= '<button type="button" class="aapg-repeater-add-row" data-field-name="' . esc_attr($input_name) . '">' . esc_html__('+ Add Row', 'aapg') . '</button>';
    $html .= '</div>';

    return $html;
}

/**
 * Render a single repeater row.
 *
 * @param string $base_name Base field name.
 * @param int    $index Row index.
 * @param array  $row_data Row data.
 * @param array  $sub_fields Sub-fields config.
 * @return string HTML for one row.
 */
function aapg_iframe_render_repeater_row($base_name, $index, $row_data, $sub_fields) {
    $html = '<div class="aapg-repeater-row aapg-row-collapsed" data-index="' . esc_attr((string) $index) . '">';
    $html .= '<div class="aapg-repeater-row-header" role="button" tabindex="0" aria-expanded="false">';
    $html .= '<span class="aapg-repeater-row-header-left"><span class="aapg-repeater-row-toggle" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg></span><span class="aapg-repeater-row-number">' . esc_html__('Row', 'aapg') . ' ' . ($index + 1) . '</span></span>';
    $html .= '<button type="button" class="aapg-repeater-remove-row" title="' . esc_attr__('Remove row', 'aapg') . '"><svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M11.7612 9.99893L19.6305 2.14129C19.8657 1.90606 19.9979 1.58701 19.9979 1.25434C19.9979 0.921668 19.8657 0.602622 19.6305 0.367388C19.3953 0.132153 19.0763 0 18.7437 0C18.411 0 18.092 0.132153 17.8568 0.367388L10 8.23752L2.14319 0.367388C1.90799 0.132153 1.58897 2.95361e-07 1.25634 2.97839e-07C0.923701 3.00318e-07 0.604689 0.132153 0.36948 0.367388C0.134271 0.602622 0.00213201 0.921668 0.002132 1.25434C0.002132 1.58701 0.134271 1.90606 0.36948 2.14129L8.23878 9.99893L0.36948 17.8566C0.252404 17.9727 0.159479 18.1109 0.0960643 18.2631C0.0326494 18.4153 0 18.5786 0 18.7435C0 18.9084 0.0326494 19.0717 0.0960643 19.224C0.159479 19.3762 0.252404 19.5143 0.36948 19.6305C0.4856 19.7476 0.623751 19.8405 0.775965 19.9039C0.928178 19.9673 1.09144 20 1.25634 20C1.42123 20 1.5845 19.9673 1.73671 19.9039C1.88892 19.8405 2.02708 19.7476 2.14319 19.6305L10 11.7603L17.8568 19.6305C17.9729 19.7476 18.1111 19.8405 18.2633 19.9039C18.4155 19.9673 18.5788 20 18.7437 20C18.9086 20 19.0718 19.9673 19.224 19.9039C19.3763 19.8405 19.5144 19.7476 19.6305 19.6305C19.7476 19.5143 19.8405 19.3762 19.9039 19.224C19.9674 19.0717 20 18.9084 20 18.7435C20 18.5786 19.9674 18.4153 19.9039 18.2631C19.8405 18.1109 19.7476 17.9727 19.6305 17.8566L11.7612 9.99893Z" fill="black"/></svg></button>';
    $html .= '</div>';
    $html .= '<div class="aapg-repeater-row-fields">';

    foreach ($sub_fields as $sub_field) {
        $sub_name = $sub_field['name'] ?? '';
        $sub_type = $sub_field['type'] ?? 'text';
        if (empty($sub_name) || $sub_type === 'image' || $sub_type === 'file') {
            continue;
        }
        $sub_value = $row_data[$sub_name] ?? '';
        $sub_key = $sub_field['key'] ?? $sub_name;
        $sub_label = $sub_field['label'] ?? $sub_name;
        $sub_input_name = $base_name . '[' . $index . '][' . $sub_name . ']';
        $sub_placeholder = !empty($sub_field['placeholder']) ? $sub_field['placeholder'] : '';
        $sub_field['_is_repeater_sub'] = true;

        $html .= '<div class="aapg-repeater-sub-field" data-type="' . esc_attr($sub_type) . '">';
        $html .= '<label class="aapg-repeater-sub-label">' . esc_html($sub_label) . '</label>';
        $html .= aapg_iframe_render_acf_field_input($sub_input_name, $sub_type, $sub_value, $sub_field, $sub_placeholder);
        $html .= '</div>';
    }

    $html .= '</div></div>';
    return $html;
}

/**
 * AJAX handler: Edit with AI – uses stored prompt id, ACF group, stored prompt; passes current content + user edit prompt; streams and updates page via hub/stub/research node.
 */
function aapg_iframe_ajax_edit_with_ai() {
    $page_id = isset($_POST['page_id']) ? absint($_POST['page_id']) : 0;
    $edit_prompt = isset($_POST['edit_prompt']) ? sanitize_textarea_field(wp_unslash($_POST['edit_prompt'])) : '';
    $page_type = isset($_POST['page_type']) ? sanitize_text_field($_POST['page_type']) : 'stub';
    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'post';

    if (!$page_id || $edit_prompt === '') {
        header('Content-Type: text/event-stream');
        echo "event: error\n";
        echo 'data: ' . wp_json_encode(['message' => __('Invalid request: page ID and edit prompt required.', 'aapg')]) . "\n\n";
        exit;
    }
    if (!current_user_can('edit_post', $page_id)) {
        header('Content-Type: text/event-stream');
        echo "event: error\n";
        echo 'data: ' . wp_json_encode(['message' => __('You do not have permission to edit this post.', 'aapg')]) . "\n\n";
        exit;
    }
    $nonce_key = 'aapg_iframe_edit_with_ai_' . $page_id;
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), $nonce_key)) {
        header('Content-Type: text/event-stream');
        echo "event: error\n";
        echo 'data: ' . wp_json_encode(['message' => __('Security check failed.', 'aapg')]) . "\n\n";
        exit;
    }

    $prompt_id = get_post_meta($page_id, 'aapg_prompt_id', true);
    $stored_prompt = get_post_meta($page_id, 'aapg_prompt_content', true);
    $acf_group_id = get_post_meta($page_id, 'aapg_acf_group_id', true);
    $elementor_template_id = (int) get_post_meta($page_id, 'aapg_elementor_template_id', true);

    if (empty($prompt_id)) {
        header('Content-Type: text/event-stream');
        echo "event: error\n";
        echo 'data: ' . wp_json_encode(['message' => __('No stored prompt ID for this page.', 'aapg')]) . "\n\n";
        exit;
    }

    $current_content_json = aapg_iframe_get_current_content_json($page_id, $page_type);
    // Prompt for edit: stored prompt + user edit request. Existing content is passed separately to nodes as a user-role input.
    $edit_prompt_only = $stored_prompt . "\n\n[USER EDIT REQUEST]\n" . $edit_prompt;

    @set_time_limit(0);
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    while (ob_get_level()) {
        ob_end_flush();
    }
    ob_implicit_flush(true);

    $stream_callback = function ($type, $payload) use ($page_id) {
        if ($type === 'delta' && !empty($payload['delta'])) {
            echo "event: delta\n";
            echo 'data: ' . wp_json_encode(['delta' => $payload['delta']]) . "\n\n";
            flush();
            return;
        }
        if ($type === 'error') {
            echo "event: error\n";
            echo 'data: ' . wp_json_encode(['message' => $payload['message'] ?? 'Stream error']) . "\n\n";
            flush();
            return;
        }
        if ($type === 'completed') {
            if (!empty($payload['result'])) {
                $r = $payload['result'];
                $r['page_url'] = get_permalink($r['page_id']);
                echo "event: result\n";
                echo 'data: ' . wp_json_encode($r) . "\n\n";
                flush();
                return;
            }
            // Stub/Research send final_data; emit result so client can clear "Processing…" and refresh.
            if (!empty($payload['final_data']) && $page_id) {
                echo "event: result\n";
                echo 'data: ' . wp_json_encode(['page_id' => (int) $page_id, 'page_url' => get_permalink($page_id)]) . "\n\n";
                flush();
                return;
            }
        }
    };

    if ($page_type === 'research') {
        if (empty($stored_prompt)) {
            $settings = get_option(AAPG_OPTION_KEY, []);
            $stored_prompt = $settings['default_research_prompt'] ?? '';
        }
        require_once AAPG_PLUGIN_DIR . 'includes/nodes/aapg-research-maker.php';
        $result = \AAPG\Nodes\AAPG_Research_Maker::generate_research_with_streaming(
            $post_type,
            $prompt_id,
            $edit_prompt_only,
            $stream_callback,
            $page_id,
            $current_content_json
        );
    } elseif ($page_type === 'hub') {
        if (empty($acf_group_id)) {
            echo "event: error\n";
            echo 'data: ' . wp_json_encode(['message' => __('No ACF group for this page (required for Hub).', 'aapg')]) . "\n\n";
            echo "event: done\n";
            echo 'data: ' . wp_json_encode(['done' => true]) . "\n\n";
            exit;
        }
        if ($elementor_template_id <= 0) {
            $elementor_template_id = 0;
        }
        require_once AAPG_PLUGIN_DIR . 'includes/nodes/aapg-hub-maker.php';
        $page_title = get_the_title($page_id);
        $parent_page_id = (int) get_post_field('post_parent', $page_id);
        $result = \AAPG\Nodes\AAPG_Hub_Maker::generate_with_streaming(
            $elementor_template_id,
            $acf_group_id,
            $prompt_id,
            $edit_prompt_only,
            $page_title,
            $parent_page_id,
            $stream_callback,
            $page_id,
            $current_content_json
        );
    } else {
        // stub
        if (empty($acf_group_id)) {
            echo "event: error\n";
            echo 'data: ' . wp_json_encode(['message' => __('No ACF group for this page (required for Stub).', 'aapg')]) . "\n\n";
            echo "event: done\n";
            echo 'data: ' . wp_json_encode(['done' => true]) . "\n\n";
            exit;
        }
        if ($elementor_template_id <= 0) {
            $elementor_template_id = 0;
        }
        require_once AAPG_PLUGIN_DIR . 'includes/nodes/aapg-stub-node.php';
        $page_title = get_the_title($page_id);
        $parent_page_id = (int) get_post_field('post_parent', $page_id);
        $settings = get_option(AAPG_OPTION_KEY, []);
        $research_trigger = $settings['default_research_trigger'] ?? '';
        $seo_master_trigger = '';
        $result = \AAPG\Nodes\AAPG_Stub_Node::generate_page_with_streaming(
            $elementor_template_id,
            $acf_group_id,
            $research_trigger,
            $seo_master_trigger,
            $prompt_id,
            $edit_prompt_only,
            $page_title,
            $parent_page_id,
            $stream_callback,
            $page_id,
            $current_content_json
        );
    }

    if (is_wp_error($result)) {
        echo "event: error\n";
        echo 'data: ' . wp_json_encode(['message' => $result->get_error_message()]) . "\n\n";
    }
    echo "event: done\n";
    echo 'data: ' . wp_json_encode(['done' => true]) . "\n\n";
    exit;
}
add_action('wp_ajax_aapg_iframe_edit_with_ai', 'aapg_iframe_ajax_edit_with_ai');

/**
 * AJAX handler: set post status (publish / draft) from iframe shortcode publish bar.
 */
function aapg_iframe_ajax_set_post_status() {
    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    if (!$post_id) {
        wp_send_json_error(['message' => __('Invalid post.', 'aapg')]);
    }
    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(['message' => __('You do not have permission to edit this post.', 'aapg')]);
    }
    $nonce_key = 'aapg_iframe_publish_' . $post_id;
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), $nonce_key)) {
        wp_send_json_error(['message' => __('Security check failed.', 'aapg')]);
    }
    $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '';
    $allowed = ['publish', 'draft'];
    if (!in_array($status, $allowed, true)) {
        wp_send_json_error(['message' => __('Invalid status.', 'aapg')]);
    }
    $updated = wp_update_post([
        'ID' => $post_id,
        'post_status' => $status,
    ], true);
    if (is_wp_error($updated)) {
        wp_send_json_error(['message' => $updated->get_error_message()]);
    }
    $status_label = ($status === 'publish') ? __('Published', 'aapg') : __('Draft', 'aapg');
    $next_action = ($status === 'publish') ? __('Unpublish', 'aapg') : __('Publish', 'aapg');
    $next_status = ($status === 'publish') ? 'draft' : 'publish';
    wp_send_json_success([
        'message' => ($status === 'publish') ? __('Page published.', 'aapg') : __('Page unpublished.', 'aapg'),
        'status' => $status,
        'status_label' => $status_label,
        'next_action' => $next_action,
        'next_status' => $next_status,
    ]);
}
add_action('wp_ajax_aapg_iframe_set_post_status', 'aapg_iframe_ajax_set_post_status');

/**
 * AJAX handler: save Research Center post content from the rich editor.
 */
function aapg_iframe_ajax_save_research_content() {
    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    if (!$post_id) {
        wp_send_json_error(['message' => __('Invalid post.', 'aapg')]);
    }
    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(['message' => __('You do not have permission to edit this post.', 'aapg')]);
    }
    $nonce_key = 'aapg_iframe_research_content_' . $post_id;
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), $nonce_key)) {
        wp_send_json_error(['message' => __('Security check failed.', 'aapg')]);
    }
    $content = isset($_POST['content']) ? wp_unslash($_POST['content']) : '';
    if (!is_string($content)) {
        $content = '';
    }
    $updated = wp_update_post([
        'ID' => $post_id,
        'post_content' => $content,
    ], true);
    if (is_wp_error($updated)) {
        wp_send_json_error(['message' => $updated->get_error_message()]);
    }
    wp_send_json_success(['message' => __('Content saved.', 'aapg')]);
}
add_action('wp_ajax_aapg_iframe_save_research_content', 'aapg_iframe_ajax_save_research_content');

/**
 * AJAX handler: update page images in Elementor data.
 */
function aapg_iframe_ajax_update_page_images() {
    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    if (!$post_id) {
        wp_send_json_error(['message' => __('Invalid post.', 'aapg')]);
    }
    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(['message' => __('You do not have permission to edit this post.', 'aapg')]);
    }
    $nonce_key = 'aapg_update_page_images_' . $post_id;
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), $nonce_key)) {
        wp_send_json_error(['message' => __('Security check failed.', 'aapg')]);
    }
    
    $image_replacements = isset($_POST['image_replacements']) ? json_decode(wp_unslash($_POST['image_replacements']), true) : [];
    if (empty($image_replacements) || !is_array($image_replacements)) {
        wp_send_json_error(['message' => __('No image replacements provided.', 'aapg')]);
    }
    
    // Get current Elementor data
    $elementor_data = get_post_meta($post_id, '_elementor_data', true);
    if (empty($elementor_data)) {
        wp_send_json_error(['message' => __('No Elementor data found.', 'aapg')]);
    }
    
    // Decode JSON data
    $data = json_decode($elementor_data, true);
    if (!is_array($data)) {
        wp_send_json_error(['message' => __('Invalid Elementor data.', 'aapg')]);
    }
    
    // Recursive function to update images
    $update_images = function(&$element) use (&$update_images, $image_replacements) {
        if (!is_array($element)) {
            return;
        }
        
        // Check for image widget
        if (isset($element['widgetType']) && $element['widgetType'] === 'image') {
            if (isset($element['settings']['image']['id'])) {
                $old_id = (string) $element['settings']['image']['id'];
                if (isset($image_replacements[$old_id])) {
                    $new_id = absint($image_replacements[$old_id]);
                    $element['settings']['image']['id'] = $new_id;
                    $element['settings']['image']['url'] = wp_get_attachment_url($new_id);
                }
            }
        }
        
        // Check for background images in settings
        if (isset($element['settings'])) {
            // Background image
            if (isset($element['settings']['background_image']['id'])) {
                $old_id = (string) $element['settings']['background_image']['id'];
                if (isset($image_replacements[$old_id])) {
                    $new_id = absint($image_replacements[$old_id]);
                    $element['settings']['background_image']['id'] = $new_id;
                    $element['settings']['background_image']['url'] = wp_get_attachment_url($new_id);
                }
            }
            
            // Background overlay image
            if (isset($element['settings']['background_overlay_image']['id'])) {
                $old_id = (string) $element['settings']['background_overlay_image']['id'];
                if (isset($image_replacements[$old_id])) {
                    $new_id = absint($image_replacements[$old_id]);
                    $element['settings']['background_overlay_image']['id'] = $new_id;
                    $element['settings']['background_overlay_image']['url'] = wp_get_attachment_url($new_id);
                }
            }
        }
        
        // Recursively check elements
        if (isset($element['elements']) && is_array($element['elements'])) {
            foreach ($element['elements'] as &$child) {
                $update_images($child);
            }
        }
    };
    
    // Update all elements
    foreach ($data as &$element) {
        $update_images($element);
    }
    
    // Save updated Elementor data
    $updated_json = wp_json_encode($data, JSON_UNESCAPED_UNICODE);
    update_post_meta($post_id, '_elementor_data', wp_slash($updated_json));
    
    // Clear Elementor cache if available
    if (class_exists('\Elementor\Plugin')) {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    }
    
    wp_send_json_success([
        'message' => __('Images updated successfully.', 'aapg'),
        'updated_count' => count($image_replacements)
    ]);
}
add_action('wp_ajax_aapg_iframe_update_page_images', 'aapg_iframe_ajax_update_page_images');

/**
 * AJAX handler: upload image to media library (for page images replace).
 */
function aapg_iframe_ajax_upload_image() {
    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    if (!$post_id) {
        wp_send_json_error(['message' => __('Invalid post.', 'aapg')]);
    }
    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(['message' => __('You do not have permission to edit this post.', 'aapg')]);
    }
    $nonce_key = 'aapg_update_page_images_' . $post_id;
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), $nonce_key)) {
        wp_send_json_error(['message' => __('Security check failed.', 'aapg')]);
    }
    
    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $msg = __('No file uploaded or upload error.', 'aapg');
        if (!empty($_FILES['image']['error'])) {
            switch ($_FILES['image']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $msg = __('File is too large.', 'aapg');
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $msg = __('File was only partially uploaded.', 'aapg');
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $msg = __('No file was uploaded.', 'aapg');
                    break;
            }
        }
        wp_send_json_error(['message' => $msg]);
    }
    
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    
    $file = $_FILES['image'];
    
    // Validate file type
    $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = wp_check_filetype($file['name']);
    if (empty($finfo['type']) || !in_array($finfo['type'], $allowed, true)) {
        wp_send_json_error(['message' => __('Invalid file type. Allowed: JPG, PNG, GIF, WebP.', 'aapg')]);
    }
    
    // 10MB limit
    if ($file['size'] > 10 * 1024 * 1024) {
        wp_send_json_error(['message' => __('File size must be less than 10MB.', 'aapg')]);
    }
    
    $upload = wp_handle_upload($file, ['test_form' => false]);
    
    if (isset($upload['error'])) {
        wp_send_json_error(['message' => $upload['error']]);
    }
    
    $attachment = [
        'post_mime_type' => $upload['type'],
        'post_title'     => sanitize_file_name(pathinfo($file['name'], PATHINFO_FILENAME)),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];
    
    $attach_id = wp_insert_attachment($attachment, $upload['file'], $post_id);
    
    if (is_wp_error($attach_id)) {
        wp_send_json_error(['message' => $attach_id->get_error_message()]);
    }
    
    $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
    if (!is_wp_error($attach_data)) {
        wp_update_attachment_metadata($attach_id, $attach_data);
    }
    
    $url = wp_get_attachment_url($attach_id);
    
    wp_send_json_success([
        'id'  => $attach_id,
        'url' => $url,
    ]);
}
add_action('wp_ajax_aapg_iframe_upload_image', 'aapg_iframe_ajax_upload_image');

/**
 * AJAX handler: save ACF field values from iframe form (hub/stub).
 */
function aapg_iframe_ajax_save_acf() {
    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    if (!$post_id) {
        wp_send_json_error(['message' => __('Invalid post.', 'aapg')]);
    }
    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(['message' => __('You do not have permission to edit this post.', 'aapg')]);
    }
    $nonce_key = 'aapg_iframe_acf_save_' . $post_id;
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), $nonce_key)) {
        wp_send_json_error(['message' => __('Security check failed.', 'aapg')]);
    }

    $acf_group_id = get_post_meta($post_id, 'aapg_acf_group_id', true);
    if (empty($acf_group_id) || !function_exists('acf_get_fields') || !function_exists('update_field')) {
        wp_send_json_error(['message' => __('ACF group not found or ACF not available.', 'aapg')]);
    }

    $fields = acf_get_fields($acf_group_id);
    if (!is_array($fields)) {
        wp_send_json_error(['message' => __('No fields to save.', 'aapg')]);
    }

    $raw_fields = isset($_POST['fields']) ? $_POST['fields'] : [];
    if (is_string($raw_fields)) {
        $raw_fields = json_decode(wp_unslash($raw_fields), true);
    }
    if (!is_array($raw_fields)) {
        $raw_fields = [];
    }
    $updated = 0;
    foreach ($fields as $field) {
        $key = $field['key'] ?? '';
        $name = $field['name'] ?? '';
        if ($key === '' || $name === '' || $field['type'] === 'image' || $field['type'] === 'file') {
            continue;
        }
        $input_name = 'acf_field_' . $key;
        if (!isset($raw_fields[$input_name])) {
            continue;
        }
        $value = $raw_fields[$input_name];
        if (is_string($value)) {
            $value = wp_unslash($value);
        }
        if ($field['type'] === 'true_false') {
            $value = ($value === '1' || $value === 'true') ? true : false;
        }
        if ($field['type'] === 'number') {
            $value = is_numeric($value) ? (float) $value : 0;
        }
        if ($field['type'] === 'checkbox') {
            $value = is_array($value) ? $value : (array) $value;
        }
        if ($field['type'] === 'repeater') {
            // Repeater value should be an array of rows
            if (!is_array($value)) {
                $value = [];
            }
        }
        if (update_field($key, $value, $post_id)) {
            $updated++;
        }
    }

    wp_send_json_success(['message' => sprintf(__('Saved. %d field(s) updated.', 'aapg'), $updated)]);
}
add_action('wp_ajax_aapg_save_iframe_acf', 'aapg_iframe_ajax_save_acf');

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
