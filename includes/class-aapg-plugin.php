<?php
namespace AAPG;

if (!defined('ABSPATH')) { exit; }

final class Plugin {
    private static $instance = null;

    // Excluded link labels that should not be replaced
    const CLHS_EXCLUDED_LINK_LABELS = [
        '[LINK_FARMERS_BRANCH_MAP]',
        '[LINK_FORT_WORTH_MAP]',
        '[LINK_DALLAS_MAP]',
        '[LINK_PLANO_MAP]',
        '[LINK_IRVING_MAP]',
        '[LINK_GRAPEVINE_MAP]',
        '[LINK_ARLINGTON_MAP]',
        '[LINK_DENTON_MAP]',
        '[LINK_LEWISVILLE_MAP]',
        '[LINK_MCKINNEY_MAP]',
    ];

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_aapg_generate_page', [$this, 'ajax_generate_page']);
        add_action('wp_ajax_aapg_save_hub_maker_settings', [$this, 'ajax_save_hub_maker_settings']);
        add_action('wp_ajax_aapg_save_stub_maker_settings', [$this, 'ajax_save_stub_maker_settings']);
        add_action('wp_ajax_aapg_save_research_settings', [$this, 'ajax_save_research_settings']);
        add_action('wp_ajax_aapg_publish_page', [$this, 'ajax_publish_page']);
        add_action('wp_ajax_aapg_test_stream', [$this, 'ajax_test_stream']);
        add_action('wp_ajax_aapg_test_image', [$this, 'ajax_test_image']);
        add_action('wp_ajax_aapg_stub_node_generate', [$this, 'ajax_stub_node_generate']);
        add_action('wp_ajax_aapg_stub_node_generate_stream', [$this, 'ajax_stub_node_generate_stream']);
        add_action('wp_ajax_aapg_research_generate_stream', [$this, 'ajax_research_generate_stream']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
        add_shortcode('aapg_generator', [$this, 'render_shortcode']);
    }

    public function ajax_save_hub_maker_settings(): void {
        check_ajax_referer('aapg_generate_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'aapg')]);
        }

        $acf_field_group_key = sanitize_text_field($_POST['acf_field_group_key'] ?? '');
        $elementor_template_id = absint($_POST['elementor_template_id'] ?? 0);
        $page_title = sanitize_text_field($_POST['page_title'] ?? '');
        $prompt_id = sanitize_text_field($_POST['prompt_id'] ?? '');

        $existing_settings = get_option(AAPG_OPTION_KEY, []);
        update_option(AAPG_OPTION_KEY, array_merge($existing_settings, [
            'hub_maker_default_acf_group' => $acf_field_group_key,
            'hub_maker_default_elementor_template' => $elementor_template_id,
            'hub_maker_default_page_title' => $page_title,
            'hub_maker_default_prompt_id' => $prompt_id,
        ]));

        wp_send_json_success(['message' => __('Hub Maker settings saved.', 'aapg')]);
    }

    public function ajax_save_stub_maker_settings(): void {
        check_ajax_referer('aapg_generate_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'aapg')]);
        }

        $elementor_template_id = absint($_POST['elementor_template_id'] ?? 0);
        $acf_group_id = sanitize_text_field($_POST['acf_group_id'] ?? '');
        $prompt_id = sanitize_text_field($_POST['prompt_id'] ?? '');
        // Unslash first to prevent double-escaping, then sanitize
        $prompt = sanitize_textarea_field(wp_unslash($_POST['prompt'] ?? ''));
        $library = sanitize_text_field($_POST['library'] ?? '');
        $parent_page_id = absint($_POST['parent_page_id'] ?? 0);

        $existing_settings = get_option(AAPG_OPTION_KEY, []);
        update_option(AAPG_OPTION_KEY, array_merge($existing_settings, [
            'default_elementor_template' => $elementor_template_id,
            'default_acf_group' => $acf_group_id,
            'default_prompt_id' => $prompt_id,
            'default_prompt' => $prompt,
            'stub_maker_default_library' => $library,
            'stub_maker_default_parent_page_id' => $parent_page_id,
        ]));

        wp_send_json_success(['message' => __('Stub Maker settings saved.', 'aapg')]);
    }

    public function admin_menu(): void {
        add_menu_page(
            __('Automation ACF Page Generator', 'aapg'),
            __('Automation ACF Page Generator', 'aapg'),
            'manage_options',
            'aapg',
            [$this, 'render_admin_page'],
            'dashicons-admin-page',
            25
        );

        add_settings_section(
            'aapg_stub_node_section',
            __('Stub Node Settings', 'aapg'),
            [$this, 'stub_node_section_callback'],
            AAPG_OPTION_KEY
        );

        add_settings_field(
            'aapg_default_elementor_template',
            __('Default Elementor Template', 'aapg'),
            [$this, 'field_elementor_template'],
            AAPG_OPTION_KEY,
            'aapg_stub_node_section'
        );

        add_settings_field(
            'aapg_default_acf_group',
            __('Default ACF Field Group', 'aapg'),
            [$this, 'field_acf_group'],
            AAPG_OPTION_KEY,
            'aapg_stub_node_section'
        );

        add_settings_field(
            'aapg_default_prompt_id',
            __('Default Prompt ID', 'aapg'),
            [$this, 'field_text_input'],
            AAPG_OPTION_KEY,
            'aapg_stub_node_section',
            'aapg_default_prompt_id',
            'Enter the default OpenAI prompt ID for stub node generation.'
        );

        add_settings_field(
            'aapg_default_prompt',
            __('Default Prompt Content', 'aapg'),
            [$this, 'field_textarea'],
            AAPG_OPTION_KEY,
            'aapg_stub_node_section',
            'aapg_default_prompt',
            'Enter the default prompt content for stub node generation.'
        );

        add_settings_field(
            'aapg_default_research_trigger',
            __('Default Research Trigger', 'aapg'),
            [$this, 'field_textarea'],
            AAPG_OPTION_KEY,
            'aapg_stub_node_section',
            'aapg_default_research_trigger',
            'Enter the default research trigger placeholder.'
        );

        add_settings_field(
            'aapg_default_seo_master_trigger',
            __('Default SEO Master Trigger', 'aapg'),
            [$this, 'field_textarea'],
            AAPG_OPTION_KEY,
            'aapg_stub_node_section',
            'aapg_default_seo_master_trigger',
            'Enter the default SEO master trigger placeholder.'
        );
    }

    public function register_settings(): void {
        register_setting('aapg_settings_group', AAPG_OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => function($value) {
                $value = is_array($value) ? $value : [];
                return [
                    'openai_api_key' => isset($value['openai_api_key']) ? sanitize_text_field($value['openai_api_key']) : '',
                    'model' => isset($value['model']) ? sanitize_text_field($value['model']) : 'gpt-4.1-mini',
                    'prompt_id' => isset($value['prompt_id']) ? sanitize_text_field($value['prompt_id']) : 'pmpt_697732cd233081979612e14e3c8b8f260bc2b578e7052e41',
                    'prompt_version' => isset($value['prompt_version']) ? sanitize_text_field($value['prompt_version']) : '1',
                    'hub_maker_default_prompt_id' => isset($value['hub_maker_default_prompt_id']) ? sanitize_text_field($value['hub_maker_default_prompt_id']) : '',
                    // Stub node defaults
                    'default_elementor_template' => isset($value['default_elementor_template']) ? intval($value['default_elementor_template']) : 0,
                    'default_acf_group' => isset($value['default_acf_group']) ? sanitize_text_field($value['default_acf_group']) : '',
                    'default_prompt_id' => isset($value['default_prompt_id']) ? sanitize_text_field($value['default_prompt_id']) : '',
                    'default_prompt' => isset($value['default_prompt']) ? sanitize_textarea_field(wp_unslash($value['default_prompt'])) : '',
                    'default_research_trigger' => isset($value['default_research_trigger']) ? sanitize_textarea_field(wp_unslash($value['default_research_trigger'])) : '',
                    'default_seo_master_trigger' => isset($value['default_seo_master_trigger']) ? sanitize_textarea_field(wp_unslash($value['default_seo_master_trigger'])) : '',
                    'stub_maker_default_library' => isset($value['stub_maker_default_library']) ? sanitize_textarea_field(wp_unslash($value['stub_maker_default_library'])) : '',
                    'stub_maker_default_parent_page_id' => isset($value['stub_maker_default_parent_page_id']) ? intval($value['stub_maker_default_parent_page_id']) : 0,
                    // Research maker defaults
                    'default_research_post_type' => isset($value['default_research_post_type']) ? sanitize_text_field($value['default_research_post_type']) : '',
                    'default_research_prompt_id' => isset($value['default_research_prompt_id']) ? sanitize_text_field($value['default_research_prompt_id']) : '',
                    'default_research_prompt' => isset($value['default_research_prompt']) ? sanitize_textarea_field(wp_unslash($value['default_research_prompt'])) : '',
                ];
            },
            'default' => [ 
                'openai_api_key' => '', 
                'model' => 'gpt-4.1-mini',
                'prompt_id' => 'pmpt_697732cd233081979612e14e3c8b8f260bc2b578e7052e41',
                'prompt_version' => '1',
                'hub_maker_default_prompt_id' => '',
                // Stub node defaults
                'default_elementor_template' => 0,
                'default_acf_group' => '',
                'default_prompt_id' => '',
                'default_prompt' => '',
                'default_research_trigger' => '',
                'default_seo_master_trigger' => '',
                'stub_maker_default_library' => '',
                'stub_maker_default_parent_page_id' => 0,
                // Research maker defaults
                'default_research_post_type' => '',
                'default_research_prompt_id' => '',
                'default_research_prompt' => '',
            ],
        ]);

        // Register form values option
        register_setting('aapg_form_values', 'aapg_form_values', [
            'type' => 'array',
            'sanitize_callback' => function($value) {
                return is_array($value) ? $value : [];
            },
            'default' => [],
        ]);
    }

    public function enqueue_admin_scripts($hook): void {
        if (strpos($hook, 'aapg') === false) {
            return;
        }
        $this->enqueue_shared_assets();
    }

    public function enqueue_frontend_scripts(): void {
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'aapg_generator')) {
            $this->enqueue_shared_assets();
        }
    }

    private function enqueue_shared_assets(): void {
        wp_enqueue_script('aapg-admin', AAPG_PLUGIN_URL . 'assets/admin.js', ['jquery'], AAPG_PLUGIN_VERSION, true);
        wp_localize_script('aapg-admin', 'aapgAjax', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aapg_generate_nonce'),
            'openaiResponseTitle' => __('OpenAI Response:', 'aapg'),
        ]);
        wp_enqueue_style('aapg-admin', AAPG_PLUGIN_URL . 'assets/admin.css', [], AAPG_PLUGIN_VERSION);
    }

    public function ajax_generate_page(): void {
        @set_time_limit(0);
        check_ajax_referer('aapg_generate_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'aapg')]);
        }

        // Validate
        $field_group_key = sanitize_text_field($_POST['acf_field_group_key'] ?? '');
        $template_id = absint($_POST['elementor_template_id'] ?? 0);
        $parent_page_id = absint($_POST['parent_page_id'] ?? 0);
        $page_title = sanitize_text_field($_POST['page_title'] ?? '');
        $input_text = wp_kses_post($_POST['input_text'] ?? '');
        $posted_prompt_id = sanitize_text_field($_POST['prompt_id'] ?? '');

        $errors = [];
        if (!$field_group_key) $errors[] = __('No ACF field group selected.', 'aapg');
        if (!$template_id)   $errors[] = __('No Elementor template chosen.', 'aapg');
        if (!$page_title)    $errors[] = __('Page title is required.', 'aapg');
        if (!$input_text)    $errors[] = __('Input text is required.', 'aapg');

        if (!empty($errors)) {
            wp_send_json_error(['message' => implode('<br>', $errors)]);
        }

        $settings = get_option(AAPG_OPTION_KEY, []);
        $api_key = $settings['openai_api_key'] ?? '';
        $model = $settings['model'] ?? 'gpt-4.1-mini';
        $prompt_id = $posted_prompt_id ?: ($settings['hub_maker_default_prompt_id'] ?? ($settings['prompt_id'] ?? 'pmpt_697732cd233081979612e14e3c8b8f260bc2b578e7052e41'));
        $prompt_version = $settings['prompt_version'] ?? '1';

        if (!$api_key) {
            wp_send_json_error(['message' => __('Missing OpenAI API key.', 'aapg')]);
        }

        // Save form values
        update_option('aapg_form_values', [
            'acf_field_group_key' => $field_group_key,
            'elementor_template_id' => $template_id,
            'parent_page_id' => $parent_page_id,
            'page_title' => $page_title,
            'input_text' => $input_text,
            'prompt_id' => $posted_prompt_id,
        ]);

        // Guard ACF + Elementor before doing anything
        if (!$this->has_acf()) {
            wp_send_json_error(['message' => 'ACF is not loaded or required ACF functions are missing.']);
        }
        if (!$this->has_elementor()) {
            wp_send_json_error(['message' => 'Elementor is not loaded.']);
        }

        try {
            $result = $this->generate_page_from_openai([
                'field_group_key' => $field_group_key,
                'template_id' => $template_id,
                'parent_page_id' => $parent_page_id,
                'page_title' => $page_title,
                'input_text' => $input_text,
                'api_key' => $api_key,
                'prompt_id' => $prompt_id,
                'prompt_version' => $prompt_version,
            ]);

            wp_send_json_success([
                'page_id' => $result['page_id'],
                'page_title' => $result['page_title'],
                'edit_url' => get_edit_post_link($result['page_id']),
                'view_url' => get_permalink($result['page_id']),
                'openai_response' => $result['openai_response'],
            ]);
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[AAPG] ' . $e->getMessage());
                error_log($e->getTraceAsString());
            }
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function ajax_publish_page(): void {
        check_ajax_referer('aapg_generate_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'aapg')]);
        }

        $post_id = absint($_POST['page_id'] ?? 0);

        if (!$post_id) {
            wp_send_json_error(['message' => __('Invalid post ID.', 'aapg')]);
        }

        // Verify this is a generated post/page
        $is_generated = get_post_meta($post_id, 'isGeneratedByAutomation', true);
        if ($is_generated !== 'true') {
            wp_send_json_error(['message' => __('This content was not generated by automation.', 'aapg')]);
        }

        // Check if post exists
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => __('Post not found.', 'aapg')]);
        }

        // Get post type label for messages
        $post_type_obj = get_post_type_object($post->post_type);
        $post_type_label = $post_type_obj ? $post_type_obj->labels->singular_name : $post->post_type;

        // Check if already published
        if ($post->post_status === 'publish') {
            wp_send_json_error(['message' => sprintf(__('%s is already published.', 'aapg'), $post_type_label)]);
        }

        // Publish the post/page
        $result = wp_update_post([
            'ID' => $post_id,
            'post_status' => 'publish'
        ], true);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => sprintf(__('Failed to publish %s: ', 'aapg'), strtolower($post_type_label)) . $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => sprintf(__('%s published successfully!', 'aapg'), $post_type_label),
            'view_url' => get_permalink($post_id),
            'edit_url' => get_edit_post_link($post_id)
        ]);
    }

    public function ajax_test_stream(): void {
        @set_time_limit(0);
        check_ajax_referer('aapg_generate_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'aapg')]);
        }

        $test_prompt = sanitize_textarea_field($_POST['test_prompt'] ?? 'Make a stub for the Frisco of topic01 full stack');

        if (empty($test_prompt)) {
            wp_send_json_error(['message' => __('Test prompt is required.', 'aapg')]);
        }

        // Include the stream request handler
        require_once AAPG_PLUGIN_DIR . 'includes/ulities/aapg-stream-request.php';

        // Set headers for SSE
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Cache-Control');

        // Custom callback to send SSE data
        $callback = function($event, $data) {
            static $full_text = '';

            if ($event === 'response.output_text.delta') {
                if (!empty($data['delta'])) {
                    $full_text .= $data['delta'];
                    $content = $data['delta'];
                    echo "data: " . json_encode(['content' => $content]) . "\n\n";
                    ob_flush();
                    flush();
                }
            }
            
            if ($event === 'response.completed') {
                // Prefer authoritative final output if present
                $final_text = $full_text;

                if (!empty($data['output'])) {
                    foreach ($data['output'] as $item) {
                        if ($item['type'] === 'message' && !empty($item['content'])) {
                            foreach ($item['content'] as $content) {
                                if ($content['type'] === 'output_text') {
                                    $final_text = $content['text'];
                                }
                            }
                        }
                    }
                }

                echo "data: " . json_encode(['done' => true, 'reason' => 'completed', 'final_text' => $final_text]) . "\n\n";
                ob_flush();
                flush();
            }
            
            if ($event === 'response.error') {
                echo "data: " . json_encode(['error' => 'Stream error: ' . print_r($data, true)]) . "\n\n";
                ob_flush();
                flush();
            }
        };

        // Test request data - Correct Responses API format
        $request_data = [
            'model' => 'gpt-5.2',
            
            'stream' => true,

            'reasoning' => [
                'effort' => 'none',
                'summary' => 'auto'
            ],

            'prompt' => [
                'id' => 'pmpt_6968f6823e788194af48638752b7ad8008a8aa0bb9111a2e'
            ],

            'input' => [
                [
                    'role' => 'user',
                    'content' => $test_prompt
                ]
            ],
           
        ];

        $stream_request = new AAPG_Stream_Request();
        $result = $stream_request->stream_request($request_data, $callback);

        if (is_wp_error($result)) {
            echo "data: " . json_encode(['error' => $result->get_error_message()]) . "\n\n";
            ob_flush();
            flush();
        }

        echo "data: " . json_encode(['done' => true]) . "\n\n";
        ob_flush();
        flush();
        exit;
    }

    public function ajax_research_generate_stream(): void {
        check_ajax_referer('aapg_generate_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            status_header(403);
            echo "event: error\n";
            echo 'data: ' . wp_json_encode(['message' => __('Permission denied.', 'aapg')]) . "\n\n";
            exit;
        }

        @set_time_limit(0);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);

        $post_type = sanitize_text_field($_POST['post_type'] ?? '');
        $prompt_id = sanitize_text_field($_POST['prompt_id'] ?? '');
        $prompt = sanitize_textarea_field($_POST['prompt'] ?? '');

        if (empty($post_type) || empty($prompt_id) || empty($prompt)) {
            echo "event: error\n";
            echo 'data: ' . wp_json_encode(['message' => __('Post Type, Prompt ID and Prompt content are required.', 'aapg')]) . "\n\n";
            echo "event: done\n";
            echo 'data: ' . wp_json_encode(['done' => true]) . "\n\n";
            exit;
        }

        require_once AAPG_PLUGIN_DIR . 'includes/nodes/aapg-research-maker.php';

        $stream_callback = function(string $type, array $payload) {
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
        };

        $result = \AAPG\Nodes\AAPG_Research_Maker::generate_research_with_streaming(
            $post_type,
            $prompt_id,
            $prompt,
            $stream_callback
        );

        if (is_wp_error($result)) {
            echo "event: error\n";
            echo 'data: ' . wp_json_encode(['message' => $result->get_error_message()]) . "\n\n";
            echo "event: done\n";
            echo 'data: ' . wp_json_encode(['done' => true]) . "\n\n";
            exit;
        }

        echo "event: result\n";
        echo 'data: ' . wp_json_encode($result) . "\n\n";
        echo "event: done\n";
        echo 'data: ' . wp_json_encode(['done' => true]) . "\n\n";
        exit;
    }

    public function ajax_test_image(): void {
        check_ajax_referer('aapg_generate_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'aapg')]);
        }

        $positive_prompt = sanitize_textarea_field($_POST['positive_prompt'] ?? 'A beautiful modern clinic exterior with glass windows, professional architecture, daylight');
        $negative_prompt = sanitize_textarea_field($_POST['negative_prompt'] ?? 'blurry, low quality, text, watermark, ugly, distorted');
        $resolution = $_POST['resolution'] ?? '1280x720     (16:9 Landscape)';
        $custom_width = intval($_POST['custom_width'] ?? 1664);
        $custom_height = intval($_POST['custom_height'] ?? 928);

        if (empty($positive_prompt)) {
            wp_send_json_error(['message' => __('Positive prompt is required.', 'aapg')]);
        }

        // Include the image generation handler
        require_once AAPG_PLUGIN_DIR . 'includes/ulities/aapg-image-generation.php';

        $result = aapg_generate_image($positive_prompt, $negative_prompt, $resolution, $custom_width, $custom_height);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['image_url' => $result]);
    }

    /**
     * AJAX handler for stub node generation (non-streaming)
     * NOTE: This function does NOT save any settings - it only generates pages.
     * Settings are only saved when user explicitly clicks "Save Stub Maker Settings" button.
     */
    public function ajax_stub_node_generate(): void {
        check_ajax_referer('aapg_generate_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'aapg')]);
        }

        $elementor_template_id = intval($_POST['elementor_template_id'] ?? 0);
        $acf_group_id = sanitize_text_field($_POST['acf_group_id'] ?? '');
        $library = sanitize_text_field($_POST['library'] ?? '');
        $research_trigger_placeholder = '';
        $seo_master_trigger_placeholder = '';
        $prompt_id = sanitize_text_field($_POST['prompt_id'] ?? '');
        $prompt = sanitize_textarea_field($_POST['prompt'] ?? '');
        $page_title = 'Generated Page';
        $parent_page_id = intval($_POST['parent_page_id'] ?? 0);

        if (empty($elementor_template_id) || empty($acf_group_id) || empty($prompt_id) || empty($prompt)) {
            wp_send_json_error(['message' => __('Template ID, ACF Group ID, Prompt ID, and Prompt content are required.', 'aapg')]);
        }

        // Include the stub node
        require_once AAPG_PLUGIN_DIR . 'includes/nodes/aapg-stub-node.php';

        // Generate page - this does NOT modify any settings
        $result = \AAPG\Nodes\AAPG_Stub_Node::generate_page_with_streaming(
            $elementor_template_id,
            $acf_group_id,
            $research_trigger_placeholder,
            $seo_master_trigger_placeholder,
            $prompt_id,
            $prompt,
            $page_title,
            $parent_page_id
        );

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    public function ajax_save_research_settings(): void {
        check_ajax_referer('aapg_generate_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'aapg')]);
        }

        $post_type = sanitize_text_field($_POST['research_post_type'] ?? '');
        $prompt_id = sanitize_text_field($_POST['research_prompt_id'] ?? '');
        // Unslash first to prevent double-escaping, then sanitize
        $prompt = sanitize_textarea_field(wp_unslash($_POST['research_prompt'] ?? ''));

        // Get existing settings and ensure it's an array
        $existing = get_option(AAPG_OPTION_KEY, []);
        if (!is_array($existing)) {
            $existing = [];
        }

        // Merge new research settings with existing settings
        $updated = array_merge($existing, [
            'default_research_post_type' => $post_type,
            'default_research_prompt_id' => $prompt_id,
            'default_research_prompt' => $prompt,
        ]);

        // Save settings
        $result = update_option(AAPG_OPTION_KEY, $updated, false);
        
        if ($result === false) {
            wp_send_json_error(['message' => __('Failed to save research settings.', 'aapg')]);
        }

        wp_send_json_success([
            'message' => __('Research settings saved.', 'aapg'),
            'saved_settings' => [
                'post_type' => $post_type,
                'prompt_id' => $prompt_id,
                'prompt' => $prompt
            ]
        ]);
    }

    /**
     * AJAX handler for stub node generation (streaming)
     * NOTE: This function does NOT save any settings - it only generates pages.
     * Settings are only saved when user explicitly clicks "Save Stub Maker Settings" button.
     */
    public function ajax_stub_node_generate_stream(): void {
        check_ajax_referer('aapg_generate_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            status_header(403);
            echo "event: error\n";
            echo 'data: ' . wp_json_encode(['message' => __('Permission denied.', 'aapg')]) . "\n\n";
            exit;
        }

        @set_time_limit(0);

        // SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);

        $elementor_template_id = intval($_POST['elementor_template_id'] ?? 0);
        $acf_group_id = sanitize_text_field($_POST['acf_group_id'] ?? '');
        $prompt_id = sanitize_text_field($_POST['prompt_id'] ?? '');
        $prompt = sanitize_textarea_field($_POST['prompt'] ?? '');
        $parent_page_id = intval($_POST['parent_page_id'] ?? 0);

        if (empty($elementor_template_id) || empty($acf_group_id) || empty($prompt_id) || empty($prompt)) {
            echo "event: error\n";
            echo 'data: ' . wp_json_encode(['message' => __('Template ID, ACF Group ID, Prompt ID, and Prompt content are required.', 'aapg')]) . "\n\n";
            echo "event: done\n";
            echo 'data: ' . wp_json_encode(['done' => true]) . "\n\n";
            exit;
        }

        require_once AAPG_PLUGIN_DIR . 'includes/nodes/aapg-stub-node.php';

        // Generate page - this does NOT modify any settings

        $stream_callback = function(string $type, array $payload) {
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
        };

        $result = \AAPG\Nodes\AAPG_Stub_Node::generate_page_with_streaming(
            $elementor_template_id,
            $acf_group_id,
            '',
            '',
            $prompt_id,
            $prompt,
            'Generated Page',
            $parent_page_id,
            $stream_callback
        );

        if (is_wp_error($result)) {
            echo "event: error\n";
            echo 'data: ' . wp_json_encode(['message' => $result->get_error_message()]) . "\n\n";
            echo "event: done\n";
            echo 'data: ' . wp_json_encode(['done' => true]) . "\n\n";
            exit;
        }

        echo "event: result\n";
        echo 'data: ' . wp_json_encode($result) . "\n\n";
        echo "event: done\n";
        echo 'data: ' . wp_json_encode(['done' => true]) . "\n\n";
        exit;
    }

    private function has_acf(): bool {
        return function_exists('acf_get_field_groups') && function_exists('acf_get_fields') && function_exists('update_field');
    }

    private function has_elementor(): bool {
        return class_exists('Elementor\\Plugin');
    }

    private function clhs_replace_link_labels($data, $url_resolution_table) {
        // Make sure we have a valid array
        if (!is_array($url_resolution_table) || empty($url_resolution_table)) {
            return $data;
        }
        
        // Convert array of objects to associative array for easier lookup
        $link_map = [];
        $excluded_labels = self::CLHS_EXCLUDED_LINK_LABELS;
        
        foreach ($url_resolution_table as $item) {
            if (isset($item['link_label'], $item['link'])) {
                // Skip excluded labels
                if (in_array($item['link_label'], $excluded_labels)) {
                    continue;
                }
                $link_map[$item['link_label']] = $item['link'];
            }
        }

        if (is_array($data)) {
            $result = array();
            foreach ($data as $key => $value) {
                // Skip URL_RESOLUTION_TABLE itself to avoid recursion
                if ($key === 'URL_RESOLUTION_TABLE') {
                    continue;
                }
                $result[$key] = $this->clhs_replace_link_labels($value, $url_resolution_table);
            }
            return $result;
        } elseif (is_string($data)) {
            // Replace all [LINK_...] patterns that are in the map
            $out = $data;
            foreach ($link_map as $label => $url) {
                $out = str_replace($label, $url, $out);
            }
            return $out;
        } else {
            return $data;
        }
    }

    private function generate_page_from_openai(array $params): array {
        // Include the utility class
        require_once AAPG_PLUGIN_DIR . 'includes/ulities/aapg-acf-group-openaijsonschema.php';
        
        $schema = \AAPG\Utilities\AAPG_ACF_Group_OpenAIJSONSchema::acf_schema_from_group($params['field_group_key']);

        // Send JSON Schema request to OpenAI
        $openai_json = $this->call_openai_json_schema([
            'api_key' => $params['api_key'],
            'prompt_id' => $params['prompt_id'],
            'prompt_version' => $params['prompt_version'],
            'input_text' => $params['input_text'],
            'json_schema' => $schema,
        ]);

        // Create WP Page
        $page_id = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_title' => $params['page_title'],
            'post_parent' => $params['parent_page_id'],
        ], true);

        if (is_wp_error($page_id)) {
            throw new \Exception('Failed to create page.');
        }

        // Add automation meta
        update_post_meta($page_id, 'isGeneratedByAutomation', 'true');

        // Copy Elementor
        $this->copy_elementor_template_to_page($params['template_id'], $page_id);

        // Apply link label replacement to all data except URL_RESOLUTION_TABLE
        $url_resolution_table = $openai_json['URL_RESOLUTION_TABLE'] ?? [];
        $processed_json = $this->clhs_replace_link_labels($openai_json, $url_resolution_table);

        // Save ACF JSON into fields (excluding special fields)
        $this->apply_json_to_acf($page_id, $processed_json, $params['field_group_key']);

        // Handle SEO meta fields (Rank Math)
        if (isset($processed_json['meta_title']) && !empty($processed_json['meta_title'])) {
            update_post_meta($page_id, 'rank_math_title', sanitize_text_field($processed_json['meta_title']));
        }

        if (isset($processed_json['meta_description']) && !empty($processed_json['meta_description'])) {
            update_post_meta($page_id, 'rank_math_description', sanitize_textarea_field($processed_json['meta_description']));
        }

        return [
            'page_id' => $page_id,
            'page_title' => $params['page_title'],
            'openai_response' => $openai_json,
        ];
    }

    private function copy_elementor_template_to_page(int $template_id, int $page_id) {
        if (!class_exists('\Elementor\Plugin')) {
            throw new \Exception('Elementor not loaded.');
        }

        $doc = \Elementor\Plugin::$instance->documents->get($template_id);

        if (!$doc) {
            throw new \Exception('Elementor template not found.');
        }

        $data = $doc->get_elements_data();

        if (!is_array($data)) {
            throw new \Exception('Elementor template data invalid.');
        }

        update_post_meta($page_id, '_elementor_data', wp_slash(wp_json_encode($data, JSON_UNESCAPED_UNICODE)));
        update_post_meta($page_id, '_elementor_edit_mode', 'builder');
        update_post_meta($page_id, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.0.0');
    }

    private function call_openai_json_schema(array $args): array {
        $api_key = $args['api_key'];
        $prompt_id = $args['prompt_id'];
        $prompt_version = $args['prompt_version'];
// print_r(json_encode($args['json_schema']));
// exit();
        $payload = [
            'prompt' => [
                'id' => $prompt_id,
                'version' => $prompt_version,
            ],
            // (Optional) add reasoning settings
            'reasoning' => [
                'effort' => $args['reasoning_effort'] ?? 'medium'
            ],
            // (Optional) override or include additional inputs the prompt expects
            'input' => $args['input'] ?? [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => $args['input_text']."Dont include NON VIEWER FACING IMPLEMENTATION NOTES"]
                    ],
                ],
            ],
            // (Optional) keep JSON Schema settings if your prompt uses them
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $args['schema_name'] ?? 'acf',
                    'schema' => $args['json_schema'],
                    // 'strict' => true,
                ],
            ],
        ];

        // Increase timeout and fix potential DNS resolution issues
        add_filter('http_api_curl', function($handle) {
            curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 30);
            if (defined('CURL_IPRESOLVE_V4')) {
                curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            }
            return $handle;
        });

        $res = wp_remote_post('https://api.openai.com/v1/responses', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 300,
        ]);

        if (is_wp_error($res)) {
            throw new \Exception('OpenAI request failed: ' . $res->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($res);
        $body   = wp_remote_retrieve_body($res);
        $data   = json_decode($body, true);

        if ($status < 200 || $status >= 300 || !is_array($data)) {
            throw new \Exception('OpenAI returned non-OK response: ' . $status . ' body=' . substr((string)$body, 0, 2000));
        }

        // ——— PARSE JSON OUTPUT ———

        // 1) Try structured parsed output (if model returns it)
        if (!empty($data['output_parsed']) && is_array($data['output_parsed'])) {
            return $data['output_parsed'];
        }

        // 2) Fallback: collect output_text pieces
        $outputText = '';
        if (!empty($data['output']) && is_array($data['output'])) {
            foreach ($data['output'] as $item) {
                if (($item['type'] ?? null) !== 'message') {
                    continue;
                }
                foreach ($item['content'] as $part) {
                    if (($part['type'] ?? null) === 'output_text' && isset($part['text'])) {
                        $outputText .= $part['text'];
                    }
                }
            }
        }

        $text = trim($outputText);

        if ($text === '') {
            throw new \Exception('Invalid JSON from OpenAI: missing output_text.');
        }

        // Handle code fence
        if (preg_match('/^```json\s*(.*?)\s*```$/s', $text, $m)) {
            $text = $m[1];
        }

        $json = json_decode($text, true);

        if (!is_array($json)) {
            throw new \Exception('Failed parsing JSON output: ' . json_last_error_msg() . ' raw=' . substr($text, 0, 2000));
        }

        return $json;
    }


    private function apply_json_to_acf(int $post_id, array $json, string $field_group_key = ''): void {
        if (!$this->has_acf()) {
            throw new \Exception('ACF not available.');
        }

        // Build map: field_name => field_key from the selected group
        $name_to_key = [];
        if ($field_group_key) {
            $fields = acf_get_fields($field_group_key);
            if (is_array($fields)) {
                $this->flatten_acf_fields($fields, $name_to_key);
            }
        }

        foreach ($json as $field_name => $value) {
            // Skip special fields that are handled separately
            if (in_array($field_name, ['meta_title', 'meta_description', 'URL_RESOLUTION_TABLE'])) {
                continue;
            }
            
            $field_key = $name_to_key[$field_name] ?? $field_name; // fallback to name
            $ok = update_field($field_key, $value, $post_id);

            if ($ok === false) {
                // update_field returns false on failure
                throw new \Exception("Failed updating ACF field: {$field_name}");
            }
        }
    }

    private function flatten_acf_fields(array $fields, array &$map): void {
        foreach ($fields as $f) {
            if (!empty($f['name']) && !empty($f['key'])) {
                $map[$f['name']] = $f['key'];
            }

            // Repeater sub_fields
            if (!empty($f['sub_fields']) && is_array($f['sub_fields'])) {
                $this->flatten_acf_fields($f['sub_fields'], $map);
            }

            // Flexible content layouts
            if (!empty($f['layouts']) && is_array($f['layouts'])) {
                foreach ($f['layouts'] as $layout) {
                    if (!empty($layout['sub_fields']) && is_array($layout['sub_fields'])) {
                        $this->flatten_acf_fields($layout['sub_fields'], $map);
                    }
                }
            }

            // Group fields
            if (!empty($f['type']) && $f['type'] === 'group' && !empty($f['sub_fields'])) {
                $this->flatten_acf_fields($f['sub_fields'], $map);
            }
        }
    }

    public function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'aapg'));
        }

        $notices = [];

        // Settings save
        if (isset($_POST['aapg_action']) && $_POST['aapg_action'] === 'save_settings') {
            check_admin_referer('aapg_settings');
            if (isset($_POST[AAPG_OPTION_KEY]) && is_array($_POST[AAPG_OPTION_KEY])) {
                $new_settings = wp_unslash($_POST[AAPG_OPTION_KEY]);
                $existing_settings = get_option(AAPG_OPTION_KEY, []);
                
                update_option(AAPG_OPTION_KEY, array_merge($existing_settings, [
                    'openai_api_key' => isset($new_settings['openai_api_key']) ? sanitize_text_field($new_settings['openai_api_key']) : '',
                ]));
                $notices[] = __('Settings saved.', 'aapg');
            }
        }

        $settings = get_option(AAPG_OPTION_KEY, [ 
            'openai_api_key' => '', 
        ]);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Hub ACF Page Generator Settings', 'aapg') . '</h1>';

        foreach ($notices as $msg) {
            echo '<div class="notice notice-success"><p>' . wp_kses_post($msg) . '</p></div>';
        }

        echo '<form method="post">';
        wp_nonce_field('aapg_settings');
        echo '<input type="hidden" name="aapg_action" value="save_settings" />';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="aapg_api_key">' . esc_html__('OpenAI API Key', 'aapg') . '</label></th>';
        echo '<td><input type="password" id="aapg_api_key" name="' . esc_attr(AAPG_OPTION_KEY) . '[openai_api_key]" value="' . esc_attr($settings['openai_api_key'] ?? '') . '" class="regular-text" autocomplete="off" />'
            . '<p class="description">' . esc_html__('Stored in wp_options. Use a restricted key if possible.', 'aapg') . '</p></td></tr>';
        echo '</table>';
        
        // Stub Node Settings Section
        echo '<h2>' . esc_html__('Stub Node Settings', 'aapg') . '</h2>';
        echo '<p>' . esc_html__('Configure default values for the Stub Node generation. These values will be pre-filled in the test form.', 'aapg') . '</p>';
        echo '<table class="form-table" role="presentation">';
        do_settings_sections(AAPG_OPTION_KEY);
        echo '</table>';
        
        submit_button(__('Save Settings', 'aapg'));
        echo '</form>';
        echo '</div>';
        echo '<div>';
        echo '<h2>' . esc_html__('Generate Page', 'aapg') . '</h2>';
        echo '<code>[aapg_generator]</code>';
        echo '</div>';
    }

    public function render_shortcode(): string {
        if (!current_user_can('manage_options')) {
            return '';
        }

        $field_groups = $this->has_acf() ? acf_get_field_groups() : [];
        $templates = $this->has_elementor() ? $this->get_hubtemplates_elementor_templates() : [];
        $parent_pages = get_pages(['sort_column' => 'post_title', 'sort_order' => 'ASC', 'post_status' => 'publish']);
        $form_values = get_option('aapg_form_values', []);
        $settings = get_option(AAPG_OPTION_KEY, []);
        // Ensure settings is an array
        if (!is_array($settings)) {
            $settings = [];
        }
        // Unslash textarea fields to prevent double-escaping
        if (isset($settings['default_prompt'])) {
            $settings['default_prompt'] = wp_unslash($settings['default_prompt']);
        }
        if (isset($settings['default_research_prompt'])) {
            $settings['default_research_prompt'] = wp_unslash($settings['default_research_prompt']);
        }
        $generated_pages = $this->get_generated_pages();

        ob_start();
        ?>
        <div class="aapg-generator-wrap">
            <h2><?php esc_html_e('Generate Page', 'aapg'); ?></h2>

            <form id="aapg-generate-form" class="aapg-section">
                <div class="aapg-section-header">
                    <h3><?php esc_html_e('Hub Maker', 'aapg'); ?></h3>
                    <span class="dashicons dashicons-arrow-down-alt2 aapg-section-toggle"></span>
                </div>
                <div class="aapg-section-content">
                
                <div class="aapg-form-group">
                    <label for="acf_field_group_key"><?php esc_html_e('ACF Field Group', 'aapg'); ?></label>
                    <select id="acf_field_group_key" name="acf_field_group_key">
                        <option value=""><?php esc_html_e('-- Select a field group --', 'aapg'); ?></option>
                        <?php foreach ($field_groups as $group) : 
                            $key = $group['key'] ?? '';
                            $title = $group['title'] ?? $key;
                            $selected_value = $form_values['acf_field_group_key'] ?? ($settings['hub_maker_default_acf_group'] ?? '');
                            $selected = ($selected_value === $key) ? ' selected' : '';
                        ?>
                            <option value="<?php echo esc_attr($key); ?>"<?php echo $selected; ?>><?php echo esc_html($title); ?> (<?php echo esc_html($key); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="aapg-form-group">
                    <label for="elementor_template_id"><?php esc_html_e('Elementor Template', 'aapg'); ?></label>
                    <select id="elementor_template_id" name="elementor_template_id">
                        <option value=""><?php esc_html_e('-- Select a template --', 'aapg'); ?></option>
                        <?php foreach ($templates as $tpl) : 
                            $selected_value = $form_values['elementor_template_id'] ?? ($settings['hub_maker_default_elementor_template'] ?? 0);
                            $selected = ((string)$selected_value === (string)$tpl['ID']) ? ' selected' : '';
                        ?>
                            <option value="<?php echo esc_attr($tpl['ID']); ?>"<?php echo $selected; ?>><?php echo esc_html($tpl['post_title']); ?> (#<?php echo esc_html($tpl['ID']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="aapg-form-group">
                    <label for="parent_page_id"><?php esc_html_e('Parent Page', 'aapg'); ?></label>
                    <select id="parent_page_id" name="parent_page_id">
                        <option value="0"><?php esc_html_e('-- No parent --', 'aapg'); ?></option>
                        <?php foreach ($parent_pages as $p) :
                            $selected = isset($form_values['parent_page_id']) && (string)$form_values['parent_page_id'] === (string)$p->ID ? ' selected' : '';
                        ?>
                            <option value="<?php echo esc_attr($p->ID); ?>"<?php echo $selected; ?>><?php echo esc_html($p->post_title); ?> (#<?php echo esc_html($p->ID); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="aapg-form-group">
                    <label for="page_title"><?php esc_html_e('New Page Title', 'aapg'); ?></label>
                    <input type="text" id="page_title" name="page_title" value="<?php echo esc_attr($form_values['page_title'] ?? ($settings['hub_maker_default_page_title'] ?? '')); ?>" placeholder="<?php esc_attr_e('e.g. Service Area - Dallas', 'aapg'); ?>" required />
                </div>

                <div class="aapg-form-group">
                    <label for="prompt_id"><?php esc_html_e('Prompt ID', 'aapg'); ?></label>
                    <input type="text" id="prompt_id" name="prompt_id" value="<?php echo esc_attr($form_values['prompt_id'] ?? ($settings['hub_maker_default_prompt_id'] ?? ($settings['prompt_id'] ?? ''))); ?>" placeholder="<?php esc_attr_e('e.g. pmpt_...', 'aapg'); ?>" />
                </div>

                <div class="aapg-form-group">
                    <label for="input_text"><?php esc_html_e('Input Text', 'aapg'); ?></label>
                    <textarea id="input_text" name="input_text" rows="3" placeholder="<?php esc_attr_e('Paste the source content here for AI to process...', 'aapg'); ?>" required><?php echo esc_textarea($form_values['input_text'] ?? ''); ?></textarea>
                </div>

                <p class="submit">
                    <button type="button" class="button button-secondary" id="aapg-save-hub-maker-settings-btn"><?php esc_html_e('Save Hub Maker Settings', 'aapg'); ?></button>
                    <button type="submit" class="button button-primary" id="aapg-generate-btn"><?php esc_html_e('Generate Page', 'aapg'); ?></button>
                </p>
                </div>
            </form>

            <div id="aapg-progress" style="display:none;">
                <div id="aapg-progress-text"></div>
            </div>
            <div id="aapg-result" style="display:none;"></div>
            <hr style="margin: 30px 0;">

            <!-- Stub Node Test Section -->
            <div class="aapg-section">
                <div class="aapg-section-header">
                    <h3><?php esc_html_e('Stub Generation', 'aapg'); ?></h3>
                    <span class="dashicons dashicons-arrow-down-alt2 aapg-section-toggle"></span>
                </div>
                <div class="aapg-section-content">
                <div class="aapg-form-group">
                    <label for="stub_elementor_template_id"><?php esc_html_e('Elementor Template', 'aapg'); ?></label>
                    <select id="stub_elementor_template_id">
                        <option value="">Select a template...</option>
                        <?php
                        if (class_exists('\Elementor\Plugin')) {
                            $args = [
                                'post_type' => 'elementor_library',
                                'post_status' => 'publish',
                                'numberposts' => 50,
                                'orderby' => 'title',
                                'order' => 'ASC'
                            ];

                            if (taxonomy_exists('elementor_library_category')) {
                                $args['tax_query'] = [
                                    [
                                        'taxonomy' => 'elementor_library_category',
                                        'field' => 'slug',
                                        'terms' => AAPG_TEMPLATE_CATEGORY_SLUG,
                                    ]
                                ];
                            }

                            $templates = get_posts($args);
                            $default_template = $settings['default_elementor_template'] ?? 0;
                            foreach ($templates as $template) {
                                $selected = $template->ID == $default_template ? ' selected' : '';
                                echo '<option value="' . esc_attr($template->ID) . '"' . $selected . '>' . esc_html($template->post_title) . ' (ID: ' . $template->ID . ')</option>';
                            }
                        } else {
                            echo '<option value="" disabled>Elementor not available</option>';
                        }
                        ?>
                    </select>
                </div>
                <div class="aapg-form-group">
                    <label for="stub_acf_group_id"><?php esc_html_e('ACF Field Group', 'aapg'); ?></label>
                    <select id="stub_acf_group_id">
                        <option value="">Select a field group...</option>
                        <?php
                        if (function_exists('acf_get_field_groups')) {
                            $field_groups = acf_get_field_groups();
                            $default_group = $settings['default_acf_group'] ?? '';
                            foreach ($field_groups as $group) {
                                $selected = $group['key'] === $default_group ? ' selected' : '';
                                echo '<option value="' . esc_attr($group['key']) . '"' . $selected . '>' . esc_html($group['title']) . ' (' . $group['key'] . ')</option>';
                            }
                        } else {
                            echo '<option value="" disabled>ACF not available</option>';
                        }
                        ?>
                    </select>
                </div>
                
                <div class="aapg-form-group">
                    <label for="stub_prompt_id"><?php esc_html_e('OpenAI Prompt ID', 'aapg'); ?></label>
                    <input type="text" id="stub_prompt_id" placeholder="prompt_456def" value="<?php echo esc_attr($settings['default_prompt_id'] ?? ''); ?>">
                </div>
                
                <div class="aapg-form-group">
                    <label for="stub_prompt"><?php esc_html_e('OpenAI Prompt Content', 'aapg'); ?></label>
                    <textarea id="stub_prompt" rows="6" placeholder="Enter your complete prompt here..." required><?php echo esc_textarea($settings['default_prompt'] ?? ''); ?></textarea>
                </div>
                
                <div class="aapg-form-group">
                    <label>
                        <input type="checkbox" id="stub_enable_research_batch" checked="checked" style="margin-right: 5px;">
                        <?php esc_html_e('Enable Research Center Batch Generation', 'aapg'); ?>
                    </label>
                    <p class="description" style="margin-top: 5px; margin-left: 25px;">
                        <?php esc_html_e('If checked, extract RC_IMPORT_PACKET prompts from stub generation and show batch generation interface.', 'aapg'); ?>
                    </p>
                </div>
                
                <div class="aapg-form-group">
                    <label for="stub_parent_page_id"><?php esc_html_e('Parent Page (optional)', 'aapg'); ?></label>
                    <select id="stub_parent_page_id">
                        <option value="0">No Parent (Top Level)</option>
                        <?php
                        $default_stub_parent_page_id = absint($settings['stub_maker_default_parent_page_id'] ?? 0);
                        $automation_parent_pages = get_posts([
                            'post_type' => 'page',
                            'post_status' => 'publish',
                            'posts_per_page' => 200,
                            'orderby' => 'title',
                            'order' => 'ASC',
                            'meta_query' => [
                                [
                                    'key' => 'isGeneratedByAutomation',
                                    'value' => 'true',
                                    'compare' => '=',
                                ]
                            ]
                        ]);

                        foreach ($automation_parent_pages as $page) {
                            $selected = ($default_stub_parent_page_id === (int) $page->ID) ? ' selected' : '';
                            echo '<option value="' . esc_attr($page->ID) . '"' . $selected . '>' . esc_html($page->post_title) . ' (ID: ' . $page->ID . ')</option>';
                        }
                        ?>
                    </select>
                </div>
                
                <p class="submit">
                    <button type="button" class="button button-secondary" id="aapg-save-stub-maker-settings-btn"><?php esc_html_e('Save Stub Maker Settings', 'aapg'); ?></button>
                    <button type="button" class="button button-primary" id="aapg-test-stub-node-btn"><?php esc_html_e('Generate Page with Stub Node', 'aapg'); ?></button>
                </p>
                
                <div id="aapg-stub-output" style="display:none; margin-top: 20px;">
                    <h4><?php esc_html_e('Stub Node Generation Result:', 'aapg'); ?></h4>
                    <div id="aapg-stub-content" style="border: 1px solid #ccc; padding: 10px; background: #f9f9f9;">
                        <div id="aapg-stub-loading" style="display:none;">
                            <p><?php esc_html_e('Generating page with streaming OpenAI API... This may take a moment.', 'aapg'); ?></p>
                        </div>
                        <div id="aapg-stub-result" style="display:none;">
                            <!-- Results will be populated here -->
                        </div>
                    </div>
                </div>

                <div id="aapg-research-center-batch" class="aapg-section" style="display:none; margin-top: 30px; border: 2px solid #46b450; border-radius: 4px; padding: 20px; background: #f7fff7; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <h4 style="margin-top: 0; color: #46b450; font-size: 16px;">
                        <span class="dashicons dashicons-admin-post" style="vertical-align: middle;"></span>
                        <?php esc_html_e('Research Center Batch Generation', 'aapg'); ?>
                    </h4>
                    <p style="color: #555; margin-bottom: 15px;">
                        <?php esc_html_e('The following prompts were extracted from the generated stub. You can start batch generation of Research Center articles.', 'aapg'); ?>
                    </p>
                    <div id="aapg-research-center-prompts" style="margin: 15px 0; padding: 10px; background: #fff; border: 1px solid #ddd; border-radius: 3px;">
                        <!-- Prompts will be listed here -->
                    </div>
                    <div id="aapg-batch-controls" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd;">
                        <button type="button" class="button button-primary" id="aapg-start-batch-btn">
                            <span class="dashicons dashicons-controls-play" style="vertical-align: middle;"></span>
                            <?php esc_html_e('Start Making Research Articles', 'aapg'); ?>
                        </button>
                        <button type="button" class="button button-secondary" id="aapg-abort-batch-btn" style="margin-left: 10px;">
                            <span class="dashicons dashicons-dismiss" style="vertical-align: middle;"></span>
                            <?php esc_html_e('Abort', 'aapg'); ?>
                        </button>
                    </div>
                    <div id="aapg-batch-progress" style="display:none; margin-top: 20px;">
                        <div style="margin-bottom: 10px;">
                            <strong style="color: #0073aa;"><?php esc_html_e('Generation Progress:', 'aapg'); ?></strong>
                        </div>
                        <div id="aapg-batch-log" style="border: 1px solid #ccc; background: #fff; padding: 12px; height: 200px; overflow-y: auto; font-family: 'Courier New', monospace; font-size: 12px; white-space: pre-wrap; border-radius: 3px; line-height: 1.6;"></div>
                    </div>
                    <div id="aapg-batch-articles-list" style="margin-top: 20px;">
                        <!-- Created articles will be listed here -->
                    </div>
                </div>
                </div>
            </div>

            <hr style="margin: 30px 0;">
        <div class="aapg-section">
            <div class="aapg-section-header">
                <h3><?php esc_html_e('Research Maker', 'aapg'); ?></h3>
                <span class="dashicons dashicons-arrow-down-alt2 aapg-section-toggle"></span>
            </div>
            <div class="aapg-section-content">
            <div class="aapg-form-group">
                <label for="research_post_type"><?php esc_html_e('Target Post Type', 'aapg'); ?></label>
                <select id="research_post_type">
                    <?php
                    $research_post_types = get_post_types(['show_ui' => true], 'objects');
                    $default_research_post_type = $settings['default_research_post_type'] ?? 'post';
                    foreach ($research_post_types as $pt) {
                        $selected = ($pt->name === $default_research_post_type) ? ' selected' : '';
                        echo '<option value="' . esc_attr($pt->name) . '"' . $selected . '>' . esc_html($pt->labels->singular_name) . ' (' . esc_html($pt->name) . ')</option>';
                    }
                    ?>
                </select>
            </div>

            <div class="aapg-form-group">
                <label for="research_prompt_id"><?php esc_html_e('OpenAI Prompt ID', 'aapg'); ?></label>
                <input type="text" id="research_prompt_id" placeholder="pmpt_xxx" value="<?php echo esc_attr($settings['default_research_prompt_id'] ?? ''); ?>">
            </div>

            <div class="aapg-form-group">
                <label for="research_prompt"><?php esc_html_e('OpenAI Prompt Content', 'aapg'); ?></label>
                <textarea id="research_prompt" rows="6" placeholder="Enter your research prompt..." required><?php echo esc_textarea($settings['default_research_prompt'] ?? ''); ?></textarea>
            </div>

            <p class="submit">
                <button type="button" class="button button-secondary" id="aapg-save-research-settings-btn"><?php esc_html_e('Save Research Settings', 'aapg'); ?></button>
                <button type="button" class="button button-primary" id="aapg-generate-research-btn"><?php esc_html_e('Generate Research (Streaming)', 'aapg'); ?></button>
            </p>

            <div id="aapg-research-output" style="display:none; margin-top: 20px;">
                <h4><?php esc_html_e('Research Generation Result:', 'aapg'); ?></h4>
                <div id="aapg-research-content" style="border: 1px solid #ccc; padding: 10px; background: #f9f9f9;">
                    <div id="aapg-research-loading" style="display:none;">
                        <p><?php esc_html_e('Generating research with streaming OpenAI API... This may take a moment.', 'aapg'); ?></p>
                    </div>
                    <div id="aapg-research-result" style="display:none;"></div>
                </div>
            </div>
            </div>
        </div>
            <hr style="margin: 30px 0;">
            <?php if (!empty($generated_pages)) : ?>
                <div class="aapg-section aapg-generated-list">
                    <div class="aapg-section-header">
                        <h3><?php esc_html_e('Generated Pages & Posts', 'aapg'); ?></h3>
                        <span class="dashicons dashicons-arrow-down-alt2 aapg-section-toggle"></span>
                    </div>
                    <div class="aapg-section-content">
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Title', 'aapg'); ?></th>
                                <th><?php esc_html_e('Type', 'aapg'); ?></th>
                                <th><?php esc_html_e('Status', 'aapg'); ?></th>
                                <th><?php esc_html_e('Parent', 'aapg'); ?></th>
                                <th><?php esc_html_e('Actions', 'aapg'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($generated_pages as $gp) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($gp['post_title']); ?></strong></td>
                                    <td>
                                        <span class="aapg-post-type-badge" style="display: inline-block; padding: 3px 8px; background: #<?php echo $gp['post_type'] === 'page' ? '2271b1' : ($gp['post_type'] === 'post' ? '00a32a' : '826eb4'); ?>; color: #fff; border-radius: 3px; font-size: 11px; font-weight: 600;">
                                            <?php echo esc_html($gp['post_type_label']); ?>
                                        </span>
                                    </td>
                                    <td><span class="aapg-status-badge status-<?php echo esc_attr($gp['status']); ?>"><?php echo esc_html(ucfirst($gp['status'])); ?></span></td>
                                    <td>
                                        <?php if (!empty($gp['parent_info'])) : ?>
                                            <?php if ($gp['parent_info']['is_generated']) : ?>
                                                <span class="aapg-parent-tag"><?php echo esc_html($gp['parent_info']['title']); ?></span>
                                            <?php else : ?>
                                                <span class="aapg-parent-tag aapg-parent-regular"><?php echo esc_html($gp['parent_info']['title']); ?></span>
                                            <?php endif; ?>
                                        <?php else : ?>
                                            <span class="aapg-no-parent">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo esc_url($gp['edit_url']); ?>" class="button button-small" target="_blank"><?php esc_html_e('Edit', 'aapg'); ?></a>
                                        <a href="<?php echo esc_url($gp['view_url']); ?>" class="button button-small" target="_blank"><?php esc_html_e('View', 'aapg'); ?></a>
                                        <?php if ($gp['status'] === 'draft') : ?>
                                            <button type="button" class="button button-small button-primary aapg-publish-btn" data-page-id="<?php echo esc_attr($gp['ID']); ?>" data-page-title="<?php echo esc_attr($gp['post_title']); ?>" data-post-type="<?php echo esc_attr($gp['post_type']); ?>">
                                                <?php esc_html_e('Publish', 'aapg'); ?>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function get_generated_pages(): array {
        // Get all public post types that support title and editor
        $post_types = get_post_types(['public' => true], 'names');
        $supported_post_types = [];
        
        foreach ($post_types as $post_type) {
            if (post_type_supports($post_type, 'title') && post_type_supports($post_type, 'editor')) {
                $supported_post_types[] = $post_type;
            }
        }
        
        // If no supported types found, default to page and post
        if (empty($supported_post_types)) {
            $supported_post_types = ['page', 'post'];
        }
        
        $args = [
            'post_type' => $supported_post_types,
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'isGeneratedByAutomation',
                    'value' => 'true',
                    'compare' => '='
                ]
            ],
            'orderby' => 'date',
            'order' => 'DESC'
        ];

        $q = new \WP_Query($args);
        $pages = [];
        if ($q->have_posts()) {
            foreach ($q->posts as $p) {
                $parent_info = '';
                if ($p->post_parent > 0) {
                    $parent_post = get_post($p->post_parent);
                    if ($parent_post) {
                        $is_parent_generated = get_post_meta($p->post_parent, 'isGeneratedByAutomation', true);
                        $parent_info = [
                            'title' => $parent_post->post_title,
                            'is_generated' => ($is_parent_generated === 'true')
                        ];
                    }
                }
                
                // Get post type object for display
                $post_type_obj = get_post_type_object($p->post_type);
                $post_type_label = $post_type_obj ? $post_type_obj->labels->singular_name : $p->post_type;
                
                $pages[] = [
                    'ID' => $p->ID,
                    'post_title' => $p->post_title,
                    'post_type' => $p->post_type,
                    'post_type_label' => $post_type_label,
                    'status' => $p->post_status,
                    'parent_info' => $parent_info,
                    'edit_url' => get_edit_post_link($p->ID),
                    'view_url' => get_permalink($p->ID)
                ];
            }
        }
        wp_reset_postdata();
        return $pages;
    }

    private function get_hubtemplates_elementor_templates(): array {
        // Elementor templates live in post type elementor_library.
        $args = [
            'post_type' => 'elementor_library',
            'post_status' => 'publish',
            'posts_per_page' => 200,
            'orderby' => 'title',
            'order' => 'ASC',
        ];

        $tax_query = [];
        // Elementor template categories taxonomy is commonly 'elementor_library_category'.
        if (taxonomy_exists('elementor_library_category')) {
            $tax_query[] = [
                'taxonomy' => 'elementor_library_category',
                'field' => 'slug',
                'terms' => [AAPG_TEMPLATE_CATEGORY_SLUG],
            ];
        }

        if (!empty($tax_query)) {
            $args['tax_query'] = $tax_query;
        }

        $q = new \WP_Query($args);
        $posts = [];
        if ($q->have_posts()) {
            foreach ($q->posts as $p) {
                $posts[] = [ 'ID' => $p->ID, 'post_title' => $p->post_title ];
            }
        }
        wp_reset_postdata();

        // If taxonomy isn't available or no results, fall back: return all templates.
        return $posts;
    }

    // Settings callback methods
    public function stub_node_section_callback(): void {
        echo '<p>' . esc_html__('Configure default values for the Stub Node generation. These values will be pre-filled in the test form.', 'aapg') . '</p>';
    }

    public function field_elementor_template(): void {
        $settings = get_option(AAPG_OPTION_KEY, []);
        $selected = $settings['default_elementor_template'] ?? 0;
        $templates = $this->has_elementor() ? $this->get_hubtemplates_elementor_templates() : [];
        
        echo '<select name="' . esc_attr(AAPG_OPTION_KEY) . '[default_elementor_template]">';
        echo '<option value="0">' . esc_html__('Select a template...', 'aapg') . '</option>';
        
        foreach ($templates as $template) {
            printf(
                '<option value="%d" %s>%s (ID: %d)</option>',
                esc_attr($template['ID']),
                selected($selected, $template['ID'], false),
                esc_html($template['post_title']),
                esc_html($template['ID'])
            );
        }
        
        echo '</select>';
    }

    public function field_acf_group(): void {
        $settings = get_option(AAPG_OPTION_KEY, []);
        $selected = $settings['default_acf_group'] ?? '';
        $field_groups = $this->has_acf() ? acf_get_field_groups() : [];
        
        echo '<select name="' . esc_attr(AAPG_OPTION_KEY) . '[default_acf_group]">';
        echo '<option value="">' . esc_html__('Select a field group...', 'aapg') . '</option>';
        
        foreach ($field_groups as $group) {
            printf(
                '<option value="%s" %s>%s (%s)</option>',
                esc_attr($group['key']),
                selected($selected, $group['key'], false),
                esc_html($group['title']),
                esc_html($group['key'])
            );
        }
        
        echo '</select>';
    }

    public function field_text_input($args): void {
        $settings = get_option(AAPG_OPTION_KEY, []);
        $field_name = $args[0] ?? '';
        $description = $args[1] ?? '';
        $value = $settings[$field_name] ?? '';
        
        printf(
            '<input type="text" name="%s[%s]" value="%s" class="regular-text" />',
            esc_attr(AAPG_OPTION_KEY),
            esc_attr($field_name),
            esc_attr($value)
        );
        
        if ($description) {
            printf('<p class="description">%s</p>', esc_html($description));
        }
    }

    public function field_textarea($args): void {
        $settings = get_option(AAPG_OPTION_KEY, []);
        $field_name = $args[0] ?? '';
        $description = $args[1] ?? '';
        $value = $settings[$field_name] ?? '';
        
        printf(
            '<textarea name="%s[%s]" rows="4" class="large-text">%s</textarea>',
            esc_attr(AAPG_OPTION_KEY),
            esc_attr($field_name),
            esc_textarea($value)
        );
        
        if ($description) {
            printf('<p class="description">%s</p>', esc_html($description));
        }
    }
}