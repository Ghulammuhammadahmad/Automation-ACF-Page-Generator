<?php

namespace AAPG\Nodes;

if (!defined('ABSPATH')) {
    exit;
}

class AAPG_Research_Maker {

    public static function generate_research_with_streaming(
        string $post_type,
        string $prompt_id,
        string $prompt,
        $stream_callback = null
    ) {
        if (empty($post_type) || empty($prompt_id) || empty($prompt)) {
            return new \WP_Error('prompt_required', 'Post type, Prompt ID and prompt content are required');
        }

        $post_type_obj = get_post_type_object($post_type);
        if (!$post_type_obj) {
            return new \WP_Error('invalid_post_type', 'Invalid post type');
        }

        if (!post_type_supports($post_type, 'title') || !post_type_supports($post_type, 'editor')) {
            return new \WP_Error('unsupported_post_type', 'Selected post type must support title and editor');
        }

        $settings = get_option(AAPG_OPTION_KEY, []);
        $api_key = $settings['openai_api_key'] ?? '';
        if (empty($api_key)) {
            return new \WP_Error('no_api_key', 'OpenAI API key is not configured');
        }

        $schema = [
            'type' => 'object',
            'properties' => [
                'content' => [
                    'type' => 'string',
                    'description' => 'The research content to store as the post content.'
                ],
                'meta_title' => [
                    'type' => 'string',
                    'description' => 'SEO meta title.'
                ],
                'meta_description' => [
                    'type' => 'string',
                    'description' => 'SEO meta description.'
                ],
                'URL_RESOLUTION_TABLE' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'link_label' => ['type' => 'string'],
                            'link' => ['type' => 'string'],
                        ],
                        'required' => ['link_label', 'link'],
                    ],
                ],
                'research_title' => [
                    'type' => 'string',
                    'description' => 'Optional title for the research post.'
                ],
            ],
            'required' => ['content', 'meta_title', 'meta_description', 'URL_RESOLUTION_TABLE'],
        ];

        $request_data = [
            'model' => 'gpt-5.2',
            'stream' => true,
            'tools' => [
                [
                    'type' => 'web_search'
                ]
            ],
            'text' => [
                'format' => [
                    'type' => 'json_object'
                ],
            ],
            'input' => [
                [
                    'role' => 'system',
                    'content' =>
                        "You must respond with a single valid JSON object only.\n" .
                        "Do not include markdown, comments, or explanations.\n" .
                        "Do not wrap the output in code fences.\n\n" .
                        "The JSON must follow this structure exactly:\n" . json_encode($schema, JSON_PRETTY_PRINT) . "\n\n" .
                        "All required fields must be present.\n" .
                        "The output must start with { and end with }."
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ]
        ];

        $streamed_content = '';
        $final_data = null;

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
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$streamed_content, &$final_data, $stream_callback) {
                static $buffer = '';
                $buffer .= $chunk;

                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $block = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);

                    $event = null;
                    $data = '';

                    foreach (explode("\n", $block) as $line) {
                        $line = trim($line);
                        if (strpos($line, 'event:') === 0) {
                            $event = trim(substr($line, 6));
                            continue;
                        }
                        if (strpos($line, 'data:') === 0) {
                            $data .= trim(substr($line, 5));
                            continue;
                        }
                    }

                    if ($data === '' || $data === '[DONE]') {
                        continue;
                    }

                    $decoded = json_decode($data, true);
                    if (!is_array($decoded)) {
                        continue;
                    }

                    if ($event === 'response.output_text.delta' && !empty($decoded['delta'])) {
                        $streamed_content .= $decoded['delta'];
                        if (is_callable($stream_callback)) {
                            call_user_func($stream_callback, 'delta', ['delta' => $decoded['delta']]);
                        }
                    }

                    if ($event === 'response.error') {
                        if (!empty($decoded['error']['message']) && is_callable($stream_callback)) {
                            call_user_func($stream_callback, 'error', ['message' => $decoded['error']['message']]);
                        }
                    }

                    if ($event === 'response.completed') {
                        if (!empty($decoded['output'])) {
                            foreach ($decoded['output'] as $item) {
                                if (($item['type'] ?? '') !== 'message' || empty($item['content'])) {
                                    continue;
                                }
                                foreach ($item['content'] as $content) {
                                    if (($content['type'] ?? '') !== 'output_text' || empty($content['text'])) {
                                        continue;
                                    }
                                    $parsed = json_decode($content['text'], true);
                                    if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                                        $final_data = $parsed;
                                        if (is_callable($stream_callback)) {
                                            call_user_func($stream_callback, 'completed', ['final_data' => $final_data]);
                                        }
                                        break 2;
                                    }
                                }
                            }
                        }

                        if (!$final_data) {
                            $m = null;
                            if (preg_match('/\{.*\}/s', $streamed_content, $m)) {
                                $parsed = json_decode($m[0], true);
                                if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                                    $final_data = $parsed;
                                    if (is_callable($stream_callback)) {
                                        call_user_func($stream_callback, 'completed', ['final_data' => $final_data]);
                                    }
                                }
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

        if (!$final_data || !is_array($final_data)) {
            return new \WP_Error('no_valid_json', 'No valid JSON received from OpenAI stream');
        }

        $content = $final_data['content'] ?? '';
        $meta_title = $final_data['meta_title'] ?? '';
        $meta_description = $final_data['meta_description'] ?? '';
        $url_resolution_table = $final_data['URL_RESOLUTION_TABLE'] ?? [];
        $research_title = $final_data['research_title'] ?? '';

        if (empty($research_title)) {
            $research_title = !empty($meta_title) ? $meta_title : ('Research ' . $prompt_id);
        }

        $post_id = wp_insert_post([
            'post_type' => $post_type,
            'post_status' => 'draft',
            'post_title' => sanitize_text_field($research_title),
            'post_content' => wp_kses_post($content),
        ], true);

        if (is_wp_error($post_id)) {
            return new \WP_Error('research_creation_failed', 'Failed to create research post: ' . $post_id->get_error_message());
        }

        update_post_meta($post_id, 'aapg_prompt_id', sanitize_text_field($prompt_id));
        update_post_meta($post_id, 'aapg_prompt_content', sanitize_textarea_field($prompt));
        update_post_meta($post_id, 'aapg_url_resolution_table', $url_resolution_table);

        if (!empty($meta_title)) {
            update_post_meta($post_id, 'rank_math_title', sanitize_text_field($meta_title));
            update_post_meta($post_id, 'aapg_meta_title', sanitize_text_field($meta_title));
        }
        if (!empty($meta_description)) {
            update_post_meta($post_id, 'rank_math_description', sanitize_textarea_field($meta_description));
            update_post_meta($post_id, 'aapg_meta_description', sanitize_textarea_field($meta_description));
        }

        $post_url = get_permalink($post_id);

        return [
            'success' => true,
            'post_id' => $post_id,
            'post_url' => $post_url,
            'post_title' => get_the_title($post_id),
            'meta_title' => $meta_title,
            'meta_description' => $meta_description,
            'URL_RESOLUTION_TABLE' => $url_resolution_table,
            'streamed_content' => $streamed_content,
        ];
    }
}
