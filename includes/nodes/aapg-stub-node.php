<?php
/**
 * AAPG Stub Node
 * Handles complete page generation workflow with streaming OpenAI API
 */

namespace AAPG\Nodes;

if (!defined('ABSPATH')) {
    exit;
}

class AAPG_Stub_Node {

    /**
     * Generate page with streaming OpenAI API
     * 
     * NOTE: This function does NOT save or modify any plugin settings.
     * It only reads the OpenAI API key from settings and generates a page.
     * Settings are only modified when user explicitly saves them via the settings form.
     * 
     * @param int $elementor_template_id Elementor template ID
     * @param string $acf_group_id ACF field group ID
     * @param string $research_trigger_placeholder Research trigger placeholder
     * @param string $seo_master_trigger_placeholder SEO master trigger placeholder
     * @param string $prompt_id OpenAI prompt ID
     * @param string $prompt OpenAI prompt content (required)
     * @param string $page_title Page title
     * @param int $parent_page_id Parent page ID (optional)
     * @param callable $stream_callback Optional callback for streaming events (delta, error, completed)
     * @param int    $existing_page_id  If > 0, update this page with the result instead of creating a new one (for AI edit flow).
     * @param string $existing_content  When editing existing page, current content to send as a separate user message (JSON or text).
     * @return array Generated page data with streaming results
     */
    public static function generate_page_with_streaming(
        int $elementor_template_id,
        string $acf_group_id,
        string $research_trigger_placeholder,
        string $seo_master_trigger_placeholder,
        string $prompt_id,
        string $prompt,
        string $page_title,
        $parent_page_id = 0,
        $stream_callback = null,
        $existing_page_id = 0,
        $existing_content = ''
    ) {
        // Validate required prompt
        if (empty($prompt)) {
            return new \WP_Error('prompt_required', 'Prompt content is required for stub node generation');
        }

        // Include required files
        require_once AAPG_PLUGIN_DIR . 'includes/ulities/aapg-acf-group-openaijsonschema.php';
        
        // Get ACF schema
        $schema = \AAPG\Utilities\AAPG_ACF_Group_OpenAIJSONSchema::acf_schema_from_group($acf_group_id);
        
        // Add custom fields to schema
        $schema['properties']['RC_IMPORT_PACKET_CORE_V1'] = [
            'type' => 'string',
            'description' => 'SO MAKE THE RC_IMPORT_PACKET_CORE_V1',
        ];

        $schema['properties']['RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C1'] = [
            'type' => 'string',
            'description' => 'SO MAKE THE RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C1',
        ];
        
        $schema['properties']['RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C2'] = [
            'type' => 'string',
            'description' => 'SO MAKE THE RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C2',
        ];

        $schema['properties']['RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C3'] = [
            'type' => 'string',
            'description' => 'SO MAKE THE RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C3',
        ];

        $schema['properties']['RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C4'] = [
            'type' => 'string',
            'description' => 'SO MAKE THE RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C4',
        ];
        $schema['properties']['SEO_MASTER_LAUNCH_REQUEST_V2_FOR_SEO_BUNDLE_PLANNER'] = [
            'type' => 'string', 
            'description' => 'SO MAKE THE SEO_MASTER_LAUNCH_REQUEST_V2_FOR_SEO_BUNDLE_PLANNER',
        ];
        
        // Add page_title field to schema
        $schema['properties']['page_title'] = [
            'type' => 'string',
            'description' => 'The title for the generated page',
        ];
        
        // Add page_slug field to schema
        $schema['properties']['page_slug'] = [
            'type' => 'string',
            'description' => 'The URL slug for the generated page (lowercase, hyphens only, no special characters)',
        ];
        
        // Safer ensure $schema['required'] is an array
        if (!isset($schema['required']) || !is_array($schema['required'])) {
            $schema['required'] = [];
        }
        $schema['required'][] = 'RC_IMPORT_PACKET_CORE_V1';
        $schema['required'][] = 'RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C1';
        $schema['required'][] = 'RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C2';
        $schema['required'][] = 'RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C3';
        $schema['required'][] = 'RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C4';
        $schema['required'][] = 'SEO_MASTER_LAUNCH_REQUEST_V2_FOR_SEO_BUNDLE_PLANNER';
        $schema['required'][] = 'page_title';
        $schema['required'][] = 'page_slug';

        // Get OpenAI API key from settings (read-only - we do NOT modify settings)
        $settings = get_option(AAPG_OPTION_KEY, []);
        $api_key = $settings['openai_api_key'] ?? '';
        
        if (empty($api_key)) {
            return new \WP_Error('no_api_key', 'OpenAI API key is not configured');
        }

        // Build input: system, then (when editing) user message with existing content, then user message with prompt
        $input = [
            [
                'role' => 'system',
                'content' =>
                    "You must respond with a single valid JSON object only.
Do not include markdown, comments, or explanations.
Do not wrap the output in code fences.

The JSON must follow this structure exactly:
" . json_encode($schema, JSON_PRETTY_PRINT) . "

All required fields must be present.
If something is missing or invalid, fix it before finishing.
The output must start with { and end with }. aLWAYS PROVIDE LABELS IN [] AND MAKE RESOLUTION TABLE using Full Stack."
            ],
        ];
        if ($existing_page_id > 0 && $existing_content !== '') {
            $input[] = [
                'role' => 'user',
                'content' => '[CURRENT CONTENT – use as base and apply the user edit request in the next message. Return the same JSON structure with edits applied.]' . "\n\n" . $existing_content,
            ];
        }
        $input[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        // Prepare streaming request data with the provided prompt
        $request_data = [
            'model' => 'gpt-5.2',
            'stream' => true,
            'prompt' => [
                'id' => $prompt_id,
            ],
            'reasoning' => [
                'effort' => 'medium'
            ],
            'text' => [
                'format' => [
                    'type' => 'json_object'
                ],
            ],
            'input' => $input,
        ];

        $streamed_content = '';
        $final_data = null;
        $debug_last_type = '';
        $debug_tail = '';

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://api.openai.com/v1/responses',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => wp_json_encode($request_data),

            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_ENCODING => '',
            CURLOPT_BUFFERSIZE => 1024,

            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json',
                'Accept: text/event-stream',
            ],

            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$final_data, $stream_callback, &$debug_last_type, &$debug_tail, &$streamed_content) {
                static $buffer = '';
                $buffer .= $chunk;

                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $block = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);

                    foreach (explode("\n", $block) as $line) {
                        $line = trim($line);

                        if (strpos($line, 'data:') !== 0) {
                            continue;
                        }

                        $json = trim(substr($line, 5));

                        if ($json === '' || $json === '[DONE]') {
                            continue;
                        }

                        $decoded = json_decode($json, true);
                        if (!is_array($decoded)) {
                            continue;
                        }

                        $type = $decoded['type'] ?? '';
                        if ($type !== '') {
                            $debug_last_type = $type;
                        }

                        if ($type === 'response.output_text.delta') {
                            $delta = $decoded['delta'] ?? '';
                            if (is_string($delta) && $delta !== '') {
                                $streamed_content .= $delta;
                                $debug_tail = substr($streamed_content, -400);
                            }
                            if (is_callable($stream_callback)) {
                                call_user_func($stream_callback, 'delta', ['delta' => is_string($delta) ? $delta : '']);
                            }
                        }

                        if ($type === 'response.error') {
                            $msg = $decoded['error']['message'] ?? '';
                            if (is_string($msg) && $msg !== '' && is_callable($stream_callback)) {
                                call_user_func($stream_callback, 'error', ['message' => $msg]);
                            }
                        }

                        if ($type === 'response.completed') {
                            $resp = $decoded['response'] ?? $decoded;

                            // 1) Get the full final output from the completed payload
                            $final_text = '';
                            if (is_array($resp)) {
                                $final_text = self::extract_response_output_text($resp);
                            }

                            // 2) Fallback: if completed payload doesn't contain it, use accumulated deltas
                            if (!is_string($final_text) || trim($final_text) === '') {
                                $final_text = $streamed_content;
                            }

                            $debug_tail = substr($final_text, -400);

                            // 3) Parse as JSON (robust)
                            $parsed = self::parse_json_loose($final_text);
                            if (is_array($parsed)) {
                                $final_data = $parsed;
                            } else {
                                // Keep the raw text so you can inspect it instead of losing it
                                $final_data = null;
                                if (is_callable($stream_callback)) {
                                    call_user_func($stream_callback, 'error', [
                                        'message' => 'Completed received, but JSON parsing failed',
                                        'tail' => $debug_tail,
                                    ]);
                                }
                            }

                            if (is_callable($stream_callback)) {
                                call_user_func($stream_callback, 'completed', ['final_data' => $final_data]);
                            }
                        }
                    }
                }

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($curl);
        if ($ok === false) {
            $error = curl_error($curl);
            curl_close($curl);
            return new \WP_Error('curl_error', $error);
        }

        curl_close($curl);

        if (!$final_data) {
            $msg = 'No valid JSON received from OpenAI stream.';
            if ($debug_last_type !== '') {
                $msg .= ' Last type: ' . $debug_last_type . '.';
            }
            if ($debug_tail !== '') {
                $msg .= ' Tail: ' . $debug_tail;
            }
            return new \WP_Error('no_valid_json', $msg);
        }

        $existing_page_id = (int) $existing_page_id;
        if ($existing_page_id > 0) {
            $update_result = self::update_existing_page_with_json($existing_page_id, $final_data, $acf_group_id);
            if (is_wp_error($update_result)) {
                return $update_result;
            }
            return $update_result;
        }

        // Prepare page creation arguments
        // Use title from OpenAI response, fallback to provided title
        $generated_title = $final_data['page_title'] ?? $page_title;
        
        // Use slug from OpenAI response, sanitize and fallback to title-based slug
        $generated_slug = $final_data['page_slug'] ?? '';
        if (!empty($generated_slug)) {
            $generated_slug = sanitize_title($generated_slug);
        } else {
            $generated_slug = sanitize_title($generated_title);
        }
        
        $page_args = [
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_title' => sanitize_text_field($generated_title),
            'post_name' => $generated_slug,
        ];

        $parent_page_id = (int) $parent_page_id;

        // Add parent page if specified
        if ($parent_page_id > 0) {
            $page_args['post_parent'] = $parent_page_id;
        }

        // Create WordPress page
        $page_id = wp_insert_post($page_args, true);

        if (is_wp_error($page_id)) {
            return new \WP_Error('page_creation_failed', 'Failed to create page: ' . $page_id->get_error_message());
        }

        // Add automation metadata
        update_post_meta($page_id, 'isGeneratedByAutomation', 'true');
        update_post_meta($page_id, 'aiGenerated', 'true'); // New AI generated meta
        update_post_meta($page_id, 'aapg_acf_group_id', $acf_group_id); // Store ACF group ID
        update_post_meta($page_id, 'aapg_elementor_template_id', $elementor_template_id); // Store template ID
        update_post_meta($page_id, 'aapg_prompt_id', $prompt_id); // Store prompt ID
        update_post_meta($page_id, 'aapg_prompt_content', $prompt); // Store prompt content
        update_post_meta($page_id, 'aapg_original_title', $page_title); // Store original provided title
        update_post_meta($page_id, 'aapg_ai_generated_title', $generated_title); // Store AI generated title
        update_post_meta($page_id, 'aapg_ai_generated_slug', $generated_slug); // Store AI generated slug

        // Copy Elementor template to page
        self::copy_elementor_template_to_page($elementor_template_id, $page_id);

        // Apply link label replacement
        $url_resolution_table = $final_data['URL_RESOLUTION_TABLE'] ?? [];
        $processed_data = self::replace_link_labels($final_data, $url_resolution_table);

        // Save ACF data to fields
        self::apply_json_to_acf($page_id, $processed_data, $acf_group_id);

        // Handle SEO meta fields
        if (isset($processed_data['meta_title']) && !empty($processed_data['meta_title'])) {
            update_post_meta($page_id, 'rank_math_title', sanitize_text_field($processed_data['meta_title']));
        }

        if (isset($processed_data['meta_description']) && !empty($processed_data['meta_description'])) {
            update_post_meta($page_id, 'rank_math_description', sanitize_textarea_field($processed_data['meta_description']));
        }

        // Get page URL
        $page_url = get_permalink($page_id);

        return [
            'success' => true,
            'page_id' => $page_id,
            'page_url' => $page_url,
            'page_title' => $generated_title, // Use the AI-generated title
            'page_slug' => $generated_slug, // Include the AI-generated slug
            'original_title' => $page_title, // Include original title for reference
            'parent_page_id' => $parent_page_id,
            'RC_IMPORT_PACKET_CORE_V1' => $processed_data['RC_IMPORT_PACKET_CORE_V1'] ?? '',
            'RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C1' => $processed_data['RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C1'] ?? '',
            'RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C2' => $processed_data['RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C2'] ?? '',
            'RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C3' => $processed_data['RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C3'] ?? '',
            'RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C4' => $processed_data['RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C4'] ?? '',
            'SEO_MASTER_LAUNCH_REQUEST_V2_FOR_SEO_BUNDLE_PLANNER' => $processed_data['SEO_MASTER_LAUNCH_REQUEST_V2_FOR_SEO_BUNDLE_PLANNER'] ?? '',
            'streamed_content' => $streamed_content,
            'full_response' => $processed_data,
            'metadata_stored' => [
                'acf_group_id' => $acf_group_id,
                'elementor_template_id' => $elementor_template_id,
                'prompt_id' => $prompt_id,
                'prompt_content' => $prompt,
                'original_title' => $page_title,
                'ai_generated_title' => $generated_title,
                'ai_generated_slug' => $generated_slug
            ]
        ];
    }

    /**
     * Update an existing page with parsed JSON from AI (for edit-with-AI flow).
     * Applies link label replacement, ACF data, title/slug, and SEO meta.
     *
     * @param int    $page_id     Existing page ID.
     * @param array  $final_data  Parsed JSON from OpenAI (page_title, page_slug, ACF fields, URL_RESOLUTION_TABLE, etc.)
     * @param string $acf_group_id ACF field group key.
     * @return array|WP_Error Result array or WP_Error.
     */
    public static function update_existing_page_with_json($page_id, array $final_data, string $acf_group_id) {
        $page = get_post($page_id);
        if (!$page || $page->post_type !== 'page') {
            return new \WP_Error('invalid_page', 'Invalid or non-page post.');
        }
        $url_resolution_table = $final_data['URL_RESOLUTION_TABLE'] ?? [];
        $processed_data = self::replace_link_labels($final_data, $url_resolution_table);
        self::apply_json_to_acf($page_id, $processed_data, $acf_group_id);

        $generated_title = $processed_data['page_title'] ?? $page->post_title;
        $generated_slug = $processed_data['page_slug'] ?? $page->post_name;
        if (!empty($generated_slug)) {
            $generated_slug = sanitize_title($generated_slug);
        } else {
            $generated_slug = sanitize_title($generated_title);
        }
        wp_update_post([
            'ID'         => $page_id,
            'post_title' => sanitize_text_field($generated_title),
            'post_name'  => $generated_slug,
        ]);

        update_post_meta($page_id, 'aapg_ai_generated_title', sanitize_text_field($generated_title));
        update_post_meta($page_id, 'aapg_ai_generated_slug', $generated_slug);
        if (!empty($processed_data['meta_title'])) {
            update_post_meta($page_id, 'rank_math_title', sanitize_text_field($processed_data['meta_title']));
        }
        if (!empty($processed_data['meta_description'])) {
            update_post_meta($page_id, 'rank_math_description', sanitize_textarea_field($processed_data['meta_description']));
        }
        return [
            'success'      => true,
            'page_id'      => $page_id,
            'page_url'     => get_permalink($page_id),
            'page_title'   => $generated_title,
            'page_slug'    => $generated_slug,
        ];
    }

    /**
     * Create a page from parsed OpenAI JSON result. Used by Hub Maker and other nodes.
     *
     * @param array  $final_data Parsed JSON from OpenAI (page_title, page_slug, ACF fields, URL_RESOLUTION_TABLE, etc.)
     * @param string $page_title Fallback page title if not in final_data
     * @param int    $parent_page_id Parent page ID
     * @param int    $elementor_template_id Elementor template ID
     * @param string $acf_group_id ACF field group key
     * @param string $prompt_id OpenAI prompt ID
     * @param string $prompt Prompt content
     * @return array|WP_Error Result array (success, page_id, page_url, ...) or WP_Error
     */
    public static function create_page_from_json_result(
        array $final_data,
        string $page_title,
        int $parent_page_id,
        int $elementor_template_id,
        string $acf_group_id,
        string $prompt_id,
        string $prompt
    ) {
        $generated_title = $final_data['page_title'] ?? $page_title;
        $generated_slug = $final_data['page_slug'] ?? '';
        if (!empty($generated_slug)) {
            $generated_slug = sanitize_title($generated_slug);
        } else {
            $generated_slug = sanitize_title($generated_title);
        }

        $page_args = [
            'post_type'   => 'page',
            'post_status' => 'draft',
            'post_title'  => sanitize_text_field($generated_title),
            'post_name'   => $generated_slug,
        ];
        if ($parent_page_id > 0) {
            $page_args['post_parent'] = $parent_page_id;
        }

        $page_id = wp_insert_post($page_args, true);
        if (is_wp_error($page_id)) {
            return new \WP_Error('page_creation_failed', 'Failed to create page: ' . $page_id->get_error_message());
        }

        update_post_meta($page_id, 'isGeneratedByAutomation', 'true');
        update_post_meta($page_id, 'aiGenerated', 'true');
        update_post_meta($page_id, 'aapg_acf_group_id', $acf_group_id);
        update_post_meta($page_id, 'aapg_elementor_template_id', $elementor_template_id);
        update_post_meta($page_id, 'aapg_prompt_id', $prompt_id);
        update_post_meta($page_id, 'aapg_prompt_content', $prompt);
        update_post_meta($page_id, 'aapg_original_title', $page_title);
        update_post_meta($page_id, 'aapg_ai_generated_title', $generated_title);
        update_post_meta($page_id, 'aapg_ai_generated_slug', $generated_slug);

        self::copy_elementor_template_to_page($elementor_template_id, $page_id);

        $url_resolution_table = $final_data['URL_RESOLUTION_TABLE'] ?? [];
        $processed_data = self::replace_link_labels($final_data, $url_resolution_table);
        self::apply_json_to_acf($page_id, $processed_data, $acf_group_id);

        if (!empty($processed_data['meta_title'])) {
            update_post_meta($page_id, 'rank_math_title', sanitize_text_field($processed_data['meta_title']));
        }
        if (!empty($processed_data['meta_description'])) {
            update_post_meta($page_id, 'rank_math_description', sanitize_textarea_field($processed_data['meta_description']));
        }

        return [
            'success'         => true,
            'page_id'         => $page_id,
            'page_url'        => get_permalink($page_id),
            'page_title'      => $generated_title,
            'page_slug'       => $generated_slug,
            'original_title'  => $page_title,
            'parent_page_id'  => $parent_page_id,
        ];
    }

    /**
     * Extract final output text from a Responses API payload (handles multiple shapes).
     */
    public static function extract_response_output_text(array $resp): string {
        // Best-case shortcut
        if (isset($resp['output_text']) && is_string($resp['output_text'])) {
            return $resp['output_text'];
        }

        $out = '';

        // Typical: resp.output[*].content[*].text
        if (isset($resp['output']) && is_array($resp['output'])) {
            foreach ($resp['output'] as $item) {
                if (!is_array($item)) continue;

                if (isset($item['content']) && is_array($item['content'])) {
                    foreach ($item['content'] as $c) {
                        if (!is_array($c)) continue;
                        if (isset($c['text']) && is_string($c['text'])) {
                            $out .= $c['text'];
                        }
                    }
                }

                // Some variants: item.text directly
                if (isset($item['text']) && is_string($item['text'])) {
                    $out .= $item['text'];
                }
            }
        }

        return $out;
    }

    /**
     * Try to parse JSON robustly. If there is leading/trailing junk, crop to first { and last }.
     */
    public static function parse_json_loose(string $text) {
        $text = trim($text);
        if ($text === '') return null;

        $parsed = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            return $parsed;
        }

        // Try crop to a JSON object region
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $slice = substr($text, $start, $end - $start + 1);
            $parsed2 = json_decode($slice, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed2)) {
                return $parsed2;
            }
        }

        return null;
    }

    /**
     * Copy Elementor template to page
     */
    private static function copy_elementor_template_to_page(int $template_id, int $page_id) {
        if (!class_exists('\Elementor\Plugin')) {
            return new \WP_Error('elementor_not_loaded', 'Elementor not loaded.');
        }

        $doc = \Elementor\Plugin::$instance->documents->get($template_id);

        if (!$doc) {
            return new \WP_Error('template_not_found', 'Elementor template not found.');
        }

        $data = $doc->get_elements_data();

        if (!is_array($data)) {
            return new \WP_Error('invalid_template_data', 'Elementor template data invalid.');
        }

        update_post_meta($page_id, '_elementor_data', wp_slash(wp_json_encode($data, JSON_UNESCAPED_UNICODE)));
        update_post_meta($page_id, '_elementor_edit_mode', 'builder');
        update_post_meta($page_id, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.0.0');

        return true;
    }

    /**
     * Replace link labels with URLs
     */
    private static function replace_link_labels($data, $url_resolution_table) {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                if ($key === 'URL_RESOLUTION_TABLE') {
                    continue;
                }
                $result[$key] = self::replace_link_labels($value, $url_resolution_table);
            }
            return $result;
        } elseif (is_string($data)) {
            $out = $data;
            foreach ($url_resolution_table as $mapping) {
                if (isset($mapping['link_label']) && isset($mapping['link'])) {
                    $out = str_replace($mapping['link_label'], $mapping['link'], $out);
                }
            }
            return $out;
        }
        return $data;
    }

    /**
     * Apply JSON data to ACF fields
     */
    private static function apply_json_to_acf($page_id, $data, $field_group_key) {
        if (!function_exists('acf_get_fields') || !function_exists('update_field')) {
            return new \WP_Error('acf_not_loaded', 'ACF not loaded.');
        }

        $fields = acf_get_fields($field_group_key);
        if (!is_array($fields)) {
            return;
        }

        foreach ($fields as $field) {
            $field_name = $field['name'] ?? '';
            if (empty($field_name) || $field['type'] === 'image') {
                continue;
            }

            if (isset($data[$field_name])) {
                update_field($field['key'], $data[$field_name], $page_id);
            }
        }
    }
}