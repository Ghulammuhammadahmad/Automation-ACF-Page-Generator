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
        add_action('wp_ajax_aapg_publish_page', [$this, 'ajax_publish_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
        add_shortcode('aapg_generator', [$this, 'render_shortcode']);
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
                ];
            },
            'default' => [ 
                'openai_api_key' => '', 
                'model' => 'gpt-4.1-mini',
                'prompt_id' => 'pmpt_697732cd233081979612e14e3c8b8f260bc2b578e7052e41',
                'prompt_version' => '1',
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
        $prompt_id = $settings['prompt_id'] ?? 'pmpt_697732cd233081979612e14e3c8b8f260bc2b578e7052e41';
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

        $page_id = absint($_POST['page_id'] ?? 0);

        if (!$page_id) {
            wp_send_json_error(['message' => __('Invalid page ID.', 'aapg')]);
        }

        // Verify this is a generated page
        $is_generated = get_post_meta($page_id, 'isGeneratedByAutomation', true);
        if ($is_generated !== 'true') {
            wp_send_json_error(['message' => __('This page was not generated by automation.', 'aapg')]);
        }

        // Check if page exists
        $page = get_post($page_id);
        if (!$page || $page->post_type !== 'page') {
            wp_send_json_error(['message' => __('Page not found.', 'aapg')]);
        }

        // Check if already published
        if ($page->post_status === 'publish') {
            wp_send_json_error(['message' => __('Page is already published.', 'aapg')]);
        }

        // Publish the page
        $result = wp_update_post([
            'ID' => $page_id,
            'post_status' => 'publish'
        ], true);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => __('Failed to publish page: ', 'aapg') . $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('Page published successfully!', 'aapg'),
            'view_url' => get_permalink($page_id),
            'edit_url' => get_edit_post_link($page_id)
        ]);
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
        $schema = $this->clhs_acf_schema_from_group($params['field_group_key']);

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

    private function clhs_acf_schema_from_group(string $field_group_key) {
        $fields = acf_get_fields($field_group_key);

        $schema = [
            "type" => "object",
            "properties" => [],
            "additionalProperties" => false,
        ];

        // ACF fields
        if (is_array($fields)) {
            foreach ($fields as $field) {
                $name = isset($field['name']) ? trim((string)$field['name']) : '';
                if ($name === '' || $field['type'] === 'image') {
                    continue; // avoid invalid keys or images
                }

                $schema["properties"][$name] = $this->clhs_acf_schema_for_field($field);
            }
        }

        // SEO fields
        $schema["properties"]["meta_title"] = [
            "type" => "string",
            "description" => "Meta Title for SEO",
        ];

        $schema["properties"]["meta_description"] = [
            "type" => "string",
            "description" => "Meta Description for SEO",
        ];

        // URL Resolution Table (array of objects)
        $schema["properties"]["URL_RESOLUTION_TABLE"] = [
            "type" => "array",
            "description" => "Array of link label to URL mappings. Include mappings for: [LINK_ALL_LOCATIONS], [LINK_AREAS_WE_SERVE], [LINK_APPOINTMENT_FORM], [LINK_DIRECTIONS_PAGE], [LINK_REVIEWS_PAGE], [LINK_TEAM_PAGE], [LINK_RESEARCH_CENTER]. Do NOT include map links like [LINK_FARMERS_BRANCH_MAP], etc.",
            "items" => [
                "type" => "object",
                "properties" => [
                    "link_label" => [
                        "type" => "string",
                        "description" => "The link label (e.g., [LINK_ALL_LOCATIONS])"
                    ],
                    "link" => [
                        "type" => "string", 
                        "description" => "The URL for this link label"
                    ]
                ],
                "required" => ["link_label", "link"],
                "additionalProperties" => false
            ]
        ];

        // REQUIRED MUST MATCH PROPERTIES EXACTLY (OpenAI schema rule)
        $schema["required"] = array_values(array_keys($schema["properties"]));

        return $schema;
    }

    private function clhs_acf_schema_for_field(array $f) {
        $type = $f['type'];
        $base = ["description" => $f['label']];

        switch ($type) {
            case 'text':
            case 'textarea':
            case 'wysiwyg':
            case 'email':
            case 'url':
                $base['type'] = 'string';
                break;

            case 'number':
                $base['type'] = 'number';
                break;

            case 'true_false':
                $base['type'] = 'boolean';
                break;

            case 'select':
                $base['type'] = 'string';
                break;

            case 'checkbox':
                $base['type'] = 'array';
                $base['items'] = ['type' => 'string'];
                break;

            case 'repeater':
                $sub_props = [];
                $sub_req = [];

                if (!empty($f['sub_fields'])) {
                    foreach ($f['sub_fields'] as $sub) {
                        if ($sub['type'] === 'image') {
                            continue; // Skip image sub-fields
                        }
                        $sub_props[$sub['name']] = $this->clhs_acf_schema_for_field($sub);
                        $sub_req[] = $sub['name'];
                    }
                }

                $base['type'] = 'array';
                $base['items'] = [
                    "type" => "object",
                    "properties" => $sub_props,
                    "required" => $sub_req,
                    "additionalProperties" => false
                ];
                break;

            case 'group':
                $sub_props = [];
                $sub_req = [];

                if (!empty($f['sub_fields'])) {
                    foreach ($f['sub_fields'] as $sub) {
                        if ($sub['type'] === 'image') {
                            continue;
                        }
                        $sub_props[$sub['name']] = $this->clhs_acf_schema_for_field($sub);
                        $sub_req[] = $sub['name'];
                    }
                }

                $base['type'] = 'object';
                $base['properties'] = $sub_props;
                $base['required'] = $sub_req;
                $base['additionalProperties'] = false;
                break;

            default:
                $base['type'] = 'string';
                break;
        }

        return $base;
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

        // Mini settings save (accessible from shortcode page)
        if (isset($_POST['aapg_action']) && $_POST['aapg_action'] === 'save_settings_mini') {
            check_admin_referer('aapg_settings');
            if (isset($_POST[AAPG_OPTION_KEY]) && is_array($_POST[AAPG_OPTION_KEY])) {
                $new_settings = wp_unslash($_POST[AAPG_OPTION_KEY]);
                $existing_settings = get_option(AAPG_OPTION_KEY, []);
                
                update_option(AAPG_OPTION_KEY, array_merge($existing_settings, [
                    'model' => isset($new_settings['model']) ? sanitize_text_field($new_settings['model']) : 'gpt-4.1-mini',
                    'prompt_id' => isset($new_settings['prompt_id']) ? sanitize_text_field($new_settings['prompt_id']) : '',
                    'prompt_version' => isset($new_settings['prompt_version']) ? sanitize_text_field($new_settings['prompt_version']) : '1',
                ]));
                echo '<div class="notice notice-success" style="margin-bottom: 20px;"><p>' . esc_html__('Generation settings saved.', 'aapg') . '</p></div>';
            }
        }

        $field_groups = $this->has_acf() ? acf_get_field_groups() : [];
        $templates = $this->has_elementor() ? $this->get_hubtemplates_elementor_templates() : [];
        $parent_pages = get_pages(['sort_column' => 'post_title', 'sort_order' => 'ASC', 'post_status' => 'publish']);
        $form_values = get_option('aapg_form_values', []);
        $settings = get_option(AAPG_OPTION_KEY, []);
        $generated_pages = $this->get_generated_pages();

        ob_start();
        ?>
        <div class="aapg-generator-wrap">
            <h2><?php esc_html_e('Generate Page', 'aapg'); ?></h2>
            
            <form method="post" class="aapg-section">
                <?php wp_nonce_field('aapg_settings'); ?>
                <input type="hidden" name="aapg_action" value="save_settings_mini" />
                <h3><?php esc_html_e('OpenAI Generation Settings', 'aapg'); ?></h3>
                
                <div class="aapg-form-group">
                    <label for="aapg_model"><?php esc_html_e('Model', 'aapg'); ?></label>
                    <input type="text" id="aapg_model" name="<?php echo esc_attr(AAPG_OPTION_KEY); ?>[model]" value="<?php echo esc_attr($settings['model'] ?? 'gpt-4.1-mini'); ?>" />
                </div>
                
                <div class="aapg-form-group">
                    <label for="aapg_prompt_id"><?php esc_html_e('Prompt ID', 'aapg'); ?></label>
                    <input type="text" id="aapg_prompt_id" name="<?php echo esc_attr(AAPG_OPTION_KEY); ?>[prompt_id]" value="<?php echo esc_attr($settings['prompt_id'] ?? ''); ?>" />
                </div>
                
               
                
                <p class="submit"><button type="submit" class="button button-secondary"><?php esc_html_e('Save Generation Settings', 'aapg'); ?></button></p>
            </form>

            <form id="aapg-generate-form" class="aapg-section">
                <h3><?php esc_html_e('Page Details', 'aapg'); ?></h3>
                
                <div class="aapg-form-group">
                    <label for="acf_field_group_key"><?php esc_html_e('ACF Field Group', 'aapg'); ?></label>
                    <select id="acf_field_group_key" name="acf_field_group_key">
                        <option value=""><?php esc_html_e('-- Select a field group --', 'aapg'); ?></option>
                        <?php foreach ($field_groups as $group) : 
                            $key = $group['key'] ?? '';
                            $title = $group['title'] ?? $key;
                            $selected = isset($form_values['acf_field_group_key']) && $form_values['acf_field_group_key'] === $key ? ' selected' : '';
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
                            $selected = isset($form_values['elementor_template_id']) && $form_values['elementor_template_id'] == $tpl['ID'] ? ' selected' : '';
                        ?>
                            <option value="<?php echo esc_attr($tpl['ID']); ?>"<?php echo $selected; ?>><?php echo esc_html($tpl['post_title']); ?> (#<?php echo esc_html($tpl['ID']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="aapg-form-group">
                    <label for="parent_page_id"><?php esc_html_e('Parent Page', 'aapg'); ?></label>
                    <select id="parent_page_id" name="parent_page_id">
                        <option value=""><?php esc_html_e('-- Select parent page --', 'aapg'); ?></option>
                        <?php foreach ($parent_pages as $p) : 
                            $selected = isset($form_values['parent_page_id']) && $form_values['parent_page_id'] == $p->ID ? ' selected' : '';
                        ?>
                            <option value="<?php echo esc_attr($p->ID); ?>"<?php echo $selected; ?>><?php echo esc_html($p->post_title); ?> (#<?php echo esc_html($p->ID); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="aapg-form-group">
                    <label for="page_title"><?php esc_html_e('New Page Title', 'aapg'); ?></label>
                    <input type="text" id="page_title" name="page_title" value="<?php echo esc_attr($form_values['page_title'] ?? ''); ?>" placeholder="<?php esc_attr_e('e.g. Service Area - Dallas', 'aapg'); ?>" required />
                </div>

                <div class="aapg-form-group">
                    <label for="input_text"><?php esc_html_e('Input Text', 'aapg'); ?></label>
                    <textarea id="input_text" name="input_text" rows="3" placeholder="<?php esc_attr_e('Paste the source content here for AI to process...', 'aapg'); ?>" required><?php echo esc_textarea($form_values['input_text'] ?? ''); ?></textarea>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary" id="aapg-generate-btn"><?php esc_html_e('Generate Page', 'aapg'); ?></button>
                </p>
            </form>

            <div id="aapg-progress" style="display:none;">
                <div id="aapg-progress-text"></div>
            </div>
            <div id="aapg-result" style="display:none;"></div>

            <?php if (!empty($generated_pages)) : ?>
                <div class="aapg-section aapg-generated-list">
                    <h3><?php esc_html_e('Generated Pages', 'aapg'); ?></h3>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Title', 'aapg'); ?></th>
                                <th><?php esc_html_e('Status', 'aapg'); ?></th>
                                <th><?php esc_html_e('Parent', 'aapg'); ?></th>
                                <th><?php esc_html_e('Actions', 'aapg'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($generated_pages as $gp) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($gp['post_title']); ?></strong></td>
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
                                            <button type="button" class="button button-small button-primary aapg-publish-btn" data-page-id="<?php echo esc_attr($gp['ID']); ?>" data-page-title="<?php echo esc_attr($gp['post_title']); ?>">
                                                <?php esc_html_e('Publish', 'aapg'); ?>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function get_generated_pages(): array {
        $args = [
            'post_type' => 'page',
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
                
                $pages[] = [
                    'ID' => $p->ID,
                    'post_title' => $p->post_title,
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
}