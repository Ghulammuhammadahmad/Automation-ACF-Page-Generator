<?php

namespace AAPG\Nodes;

if (!defined('ABSPATH')) {
    exit;
}

class AAPG_Research_Maker
{
    public static function generate_research_with_streaming(
        string $post_type,
        string $prompt_id,
        string $prompt,
        $stream_callback = null
    ) {
        if (empty($post_type) || empty($prompt_id) || empty($prompt)) {
            return new \WP_Error(
                'prompt_required',
                'Post type, Prompt ID and prompt content are required'
            );
        }

        $post_type_obj = get_post_type_object($post_type);
        if (!$post_type_obj) {
            return new \WP_Error('invalid_post_type', 'Invalid post type');
        }

        if (!post_type_supports($post_type, 'title') || !post_type_supports($post_type, 'editor')) {
            return new \WP_Error(
                'unsupported_post_type',
                'Selected post type must support title and editor'
            );
        }

        $settings = get_option(AAPG_OPTION_KEY, []);
        $api_key  = $settings['openai_api_key'] ?? '';

        if (empty($api_key)) {
            return new \WP_Error('no_api_key', 'OpenAI API key is not configured');
        }

        /**
         * ✅ Correct JSON schema for strict structured output
         */
        $schema = [
            'type'       => 'object',
            'properties' => [
                'content' => [
                    'type'        => 'string',
                    'description' => 'The ARTICLE BODY in html format'
                ],
                'meta_title' => [
                    'type'        => 'string',
                    'description' => 'SEO meta title.'
                ],
                'meta_description' => [
                    'type'        => 'string',
                    'description' => 'SEO meta description.'
                ],
                'URL_RESOLUTION_TABLE' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'link_label' => ['type' => 'string'],
                            'link'       => ['type' => 'string'],
                        ],
                        'required' => ['link_label', 'link'],
                    ],
                ],
                'research_title' => [
                    'type'        => 'string',
                    'description' => 'Meta Title for the research post.'
                ],
            ],
            'required' => [
                'content',
                'meta_title',
                'meta_description',
                'URL_RESOLUTION_TABLE'
            ],
        ];

        /**
         * ✅ Correct OpenAI Responses API payload
         */
        $request_data = [
            'model'  => 'gpt-5.2',
            'stream' => true,
            'prompt' => [
                'id' => $prompt_id,
            ],
            'tools' => [
                ['type' => 'web_search']
            ],
            'input' => [
                [
                    'role'    => 'system',
                    'content' =>
                        "You must respond with a single valid JSON object only.
Do not include markdown, comments, or explanations.
Do not wrap the output in code fences.

The JSON must follow this structure exactly:
" . json_encode($schema, JSON_PRETTY_PRINT) . "

All required fields must be present.
If something is missing or invalid, fix it before finishing.
The output must start with { and end with }. aLWAYS PROVIDE LABELS IN [] AND MAKE RESOLUTION TABLE using Full Stack. In references provide ancher tag with href attribute."
                ],
                [
                    'role'    => 'user',
                    'content' => $prompt
                ]
            ]
        ];

        $streamed_content = '';
        $final_data       = null;

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => 'https://api.openai.com/v1/responses',
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => wp_json_encode($request_data),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER         => false,
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_BUFFERSIZE     => 1024,

            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json',
                'Accept: text/event-stream',
            ],

            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (
                &$streamed_content,
                &$final_data,
                $stream_callback
            ) {
                static $buffer = '';
                $buffer .= $chunk;

                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $block  = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);

                    $data = '';
                    foreach (explode("\n", $block) as $line) {
                        $line = trim($line);

                        if (strpos($line, 'data:') === 0) {
                            $data .= trim(substr($line, 5));
                        }
                    }

                    if (empty($data)) {
                        continue;
                    }

                    if ($data === '[DONE]') {
                        continue;
                    }

                    $decoded = json_decode($data, true);
                    if (!is_array($decoded)) {
                        continue;
                    }

                    $type = $decoded['type'] ?? '';

                    /**
                     * ✅ Handle streaming deltas correctly
                     */
                    if ($type === 'response.output_text.delta' && !empty($decoded['delta'])) {
                        $streamed_content .= $decoded['delta'];

                        if (is_callable($stream_callback)) {
                            call_user_func($stream_callback, 'delta', [
                                'delta' => $decoded['delta']
                            ]);
                        }
                    }

                    /**
                     * ✅ On completion parse final JSON safely
                     */
                    if ($type === 'response.completed') {

                        $json = trim($streamed_content);

                        $parsed = json_decode($json, true);

                        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                            $final_data = $parsed;

                            if (is_callable($stream_callback)) {
                                call_user_func($stream_callback, 'completed', [
                                    'final_data' => $final_data
                                ]);
                            }
                        }
                    }

                    if ($type === 'response.error') {
                        $msg = $decoded['error']['message'] ?? 'Unknown error';

                        if (is_callable($stream_callback)) {
                            call_user_func($stream_callback, 'error', [
                                'message' => $msg
                            ]);
                        }
                    }
                }

                return strlen($chunk);
            }
        ]);

        $ok = curl_exec($curl);

        if ($ok === false) {
            $error = curl_error($curl);
            curl_close($curl);
            return new \WP_Error('curl_error', $error);
        }

        curl_close($curl);

        if (!$final_data || !is_array($final_data)) {
            return new \WP_Error(
                'no_valid_json',
                'No valid structured JSON received from OpenAI.'
            );
        }

        /**
         * ✅ Extract final fields
         */
        $content              = $final_data['content'] ?? '';
        $meta_title           = $final_data['meta_title'] ?? '';
        $meta_description     = $final_data['meta_description'] ?? '';
        $url_resolution_table = $final_data['URL_RESOLUTION_TABLE'] ?? [];
        $research_title       = $final_data['research_title'] ?? '';

        if (empty($research_title)) {
            $research_title = $meta_title ?: ('Research ' . $prompt_id);
        }

        /**
         * ✅ Create WP Post
         */
        $post_id = wp_insert_post([
            'post_type'    => $post_type,
            'post_status'  => 'draft',
            'post_title'   => sanitize_text_field($research_title),
            'post_content' => wp_kses_post($content),
        ], true);

        if (is_wp_error($post_id)) {
            return new \WP_Error(
                'research_creation_failed',
                'Failed: ' . $post_id->get_error_message()
            );
        }

        // Get the created post to extract slug
        $created_post = get_post($post_id);
        $generated_slug = $created_post ? $created_post->post_name : '';

        // Mark as generated by automation (same metadata as stub node)
        update_post_meta($post_id, 'isGeneratedByAutomation', 'true');
        update_post_meta($post_id, 'aiGenerated', 'true');
        
        // Store prompt information
        update_post_meta($post_id, 'aapg_prompt_id', sanitize_text_field($prompt_id));
        update_post_meta($post_id, 'aapg_prompt_content', sanitize_textarea_field($prompt));
        
        // Store title and slug information (matching stub node format)
        update_post_meta($post_id, 'aapg_ai_generated_title', sanitize_text_field($research_title));
        update_post_meta($post_id, 'aapg_ai_generated_slug', sanitize_text_field($generated_slug));
        // For research posts, original title is the same as generated since there's no separate input
        update_post_meta($post_id, 'aapg_original_title', sanitize_text_field($research_title));
        
        // Store URL resolution table
        update_post_meta($post_id, 'aapg_url_resolution_table', $url_resolution_table);

        // Store SEO meta fields
        if (!empty($meta_title)) {
            update_post_meta($post_id, 'rank_math_title', sanitize_text_field($meta_title));
        }

        if (!empty($meta_description)) {
            update_post_meta($post_id, 'rank_math_description', sanitize_textarea_field($meta_description));
        }

        return [
            'success'         => true,
            'post_id'         => $post_id,
            'post_url'        => get_permalink($post_id),
            'post_title'      => get_the_title($post_id),
            'meta_title'      => $meta_title,
            'meta_description'=> $meta_description,
            'URL_RESOLUTION_TABLE' => $url_resolution_table,
        ];
    }
}
