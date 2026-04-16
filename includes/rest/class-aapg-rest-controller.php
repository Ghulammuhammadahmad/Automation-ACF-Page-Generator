<?php
/**
 * AAPG REST API Controller
 * Single endpoint: GET (OnlySchema) / POST (AddPage) for modes stub, hub, researchcenter, blog.
 */

namespace AAPG\Rest;

if (!defined('ABSPATH')) {
    exit;
}

class AAPG_REST_Controller extends \WP_REST_Controller {

    const REST_NAMESPACE = 'aapg/v1';
    const REST_ROUTE = 'generate';

    public function register_routes(): void {
        register_rest_route(self::REST_NAMESPACE, '/' . self::REST_ROUTE, [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_schema'],
                'permission_callback' => [$this, 'check_api_key'],
                'args'                => $this->get_collection_params(),
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create_item'],
                'permission_callback' => [$this, 'check_api_key'],
                'args'                => $this->get_create_params(),
            ],
        ]);
    }

    /**
     * Permission callback: validate API key from header or query. Returns WP_Error with 401 when invalid.
     */
    public function check_api_key(\WP_REST_Request $request): bool|\WP_Error {
        $settings = get_option(AAPG_OPTION_KEY, []);
        $stored_key = $settings['aapg_rest_api_key'] ?? '';
        if (empty($stored_key)) {
            return new \WP_Error('rest_disabled', __('REST API is disabled. Set an API key in settings.', 'aapg'), ['status' => 401]);
        }
        $header_key = $request->get_header('X-AAPG-API-Key');
        $query_key = $request->get_param('api_key');
        $provided = $header_key ?: $query_key;
        if (!is_string($provided) || $provided === '' || !hash_equals($stored_key, $provided)) {
            return new \WP_Error('rest_forbidden', __('Invalid or missing API key.', 'aapg'), ['status' => 401]);
        }
        return true;
    }

    public function get_collection_params(): array {
        return [
            'mode' => [
                'required'          => true,
                'type'              => 'string',
                'enum'              => ['stub', 'hub', 'hubmodeb', 'researchcenter', 'blog'],
                'description'       => __('Mode: stub, hub, hubmodeb, researchcenter, or blog', 'aapg'),
            ],
            'OnlySchema' => [
                'required'          => false,
                'type'              => 'string',
                'default'           => 'false',
                'enum'              => ['true', 'false'],
                'description'       => __('If true, return only the JSON schema for the mode', 'aapg'),
            ],
        ];
    }

    public function get_create_params(): array {
        return [
            'mode' => [
                'required'          => true,
                'type'              => 'string',
                'enum'              => ['stub', 'hub', 'hubmodeb', 'researchcenter', 'blog'],
                'description'       => __('Mode: stub, hub, hubmodeb, researchcenter, or blog', 'aapg'),
            ],
            'AddPage' => [
                'required'          => true,
                'type'              => 'string',
                'enum'              => ['true', 'false'],
                'description'       => __('If true, create page/post from JSON body', 'aapg'),
            ],
        ];
    }

    /**
     * GET: return schema when OnlySchema=true.
     */
    public function get_schema(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $mode = $request->get_param('mode');
        $only_schema = $request->get_param('OnlySchema') === 'true';

        if (!$only_schema) {
            return new \WP_Error(
                'rest_invalid_param',
                __('Use OnlySchema=true to retrieve the schema for the given mode.', 'aapg'),
                ['status' => 400]
            );
        }

        $schema = $this->get_schema_for_mode($mode);
        if (is_wp_error($schema)) {
            return $schema;
        }

        return new \WP_REST_Response([
            'mode'   => $mode,
            'schema' => $schema,
        ], 200);
    }

    /**
     * Return JSON Schema for a mode (root object includes `required` per JSON Schema; no duplicate top-level list).
     */
    private function get_schema_for_mode(string $mode): array|\WP_Error {
        if ($mode === 'stub') {
            $settings = get_option(AAPG_OPTION_KEY, []);
            $acf_group = $settings['stub_acf_group'] ?? $settings['default_acf_group'] ?? '';
            if (empty($acf_group) || !function_exists('acf_get_fields')) {
                return new \WP_Error('rest_missing_config', __('Stub ACF group not configured in settings.', 'aapg'), ['status' => 500]);
            }
            require_once AAPG_PLUGIN_DIR . 'includes/ulities/aapg-acf-group-openaijsonschema.php';
            $schema = \AAPG\Utilities\AAPG_ACF_Group_OpenAIJSONSchema::acf_schema_from_group($acf_group);
            $schema = $this->strip_video_fields_from_generate_schema($schema);
            $schema['properties']['page_title'] = ['type' => 'string', 'description' => 'Page title'];
            $schema['properties']['page_slug'] = ['type' => 'string', 'description' => 'Page slug'];
            // API stub: no RC_IMPORT_PACKET or SEO_MASTER_LAUNCH fields (used only for admin batch generation)
            $required = $schema['required'] ?? [];
            $required = array_unique(array_merge($required, ['page_title', 'page_slug']));
            $schema['required'] = array_values($required);
            return $schema;
        }

        if ($mode === 'hub') {
            $settings = get_option(AAPG_OPTION_KEY, []);
            $acf_group = (string) ($settings['hub_acf_group'] ?? '');
            if ($acf_group === '') {
                $acf_group = (string) ($settings['hub_maker_default_acf_group'] ?? '');
            }
            if ($acf_group === '') {
                $acf_group = (string) ($settings['stub_acf_group'] ?? '');
            }
            if ($acf_group === '') {
                $acf_group = (string) ($settings['default_acf_group'] ?? '');
            }
            if (empty($acf_group) || !function_exists('acf_get_fields')) {
                return new \WP_Error('rest_missing_config', __('Hub ACF group not configured in settings.', 'aapg'), ['status' => 500]);
            }
            require_once AAPG_PLUGIN_DIR . 'includes/ulities/aapg-acf-group-openaijsonschema.php';
            $schema = \AAPG\Utilities\AAPG_ACF_Group_OpenAIJSONSchema::acf_schema_from_group($acf_group);
            $schema = $this->strip_video_fields_from_generate_schema($schema);
            $schema['properties']['page_title'] = ['type' => 'string', 'description' => 'Page title'];
            $schema['properties']['page_slug'] = ['type' => 'string', 'description' => 'Page slug'];
            $required = $schema['required'] ?? [];
            $required = array_unique(array_merge($required, ['page_title', 'page_slug']));
            $schema['required'] = array_values($required);
            return $schema;
        }

        if ($mode === 'hubmodeb') {
            $settings = get_option(AAPG_OPTION_KEY, []);
            $acf_group = (string) ($settings['hubmodeb_acf_group'] ?? '');
            if ($acf_group === '') {
                $acf_group = (string) ($settings['hub_acf_group'] ?? '');
            }
            if ($acf_group === '') {
                $acf_group = (string) ($settings['hub_maker_default_acf_group'] ?? '');
            }
            if ($acf_group === '') {
                $acf_group = (string) ($settings['stub_acf_group'] ?? '');
            }
            if ($acf_group === '') {
                $acf_group = (string) ($settings['default_acf_group'] ?? '');
            }
            if (empty($acf_group) || !function_exists('acf_get_fields')) {
                return new \WP_Error('rest_missing_config', __('Hub Mode B ACF group not configured in settings.', 'aapg'), ['status' => 500]);
            }
            require_once AAPG_PLUGIN_DIR . 'includes/ulities/aapg-acf-group-openaijsonschema.php';
            $schema = \AAPG\Utilities\AAPG_ACF_Group_OpenAIJSONSchema::acf_schema_from_group($acf_group);
            $schema = $this->strip_video_fields_from_generate_schema($schema);
            $schema['properties']['page_title'] = ['type' => 'string', 'description' => 'Page title'];
            $schema['properties']['page_slug'] = ['type' => 'string', 'description' => 'Page slug'];
            $required = $schema['required'] ?? [];
            $required = array_unique(array_merge($required, ['page_title', 'page_slug']));
            $schema['required'] = array_values($required);
            return $schema;
        }

        if ($mode === 'researchcenter') {
            $schema = [
                'type'       => 'object',
                'properties' => [
                    'content' => ['type' => 'string', 'description' => 'Article body (HTML)'],
                    'meta_title' => ['type' => 'string'],
                    'meta_description' => ['type' => 'string'],
                    'URL_RESOLUTION_TABLE' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => ['link_label' => ['type' => 'string'], 'link' => ['type' => 'string']],
                            'required'   => ['link_label', 'link'],
                        ],
                    ],
                    'research_title' => ['type' => 'string', 'description' => 'Post title'],
                    'category' => [
                        'type'        => 'string',
                        'description' => 'Optional article category slug or name to assign (taxonomy: article-category). Options: conditions, diagnosis, recovery, symptoms, topics, treatments',
                    ],
                    'cluster_article' => [
                        'type'        => 'array',
                        'description' => 'ACF repeater: cluster articles with article_title and article_link.',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'article_title' => ['type' => 'string'],
                                'article_link'  => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
                'required' => ['content', 'meta_title', 'meta_description', 'URL_RESOLUTION_TABLE'],
            ];
            return $schema;
        }

        if ($mode === 'blog') {
            $schema = [
                'type'       => 'object',
                'properties' => [
                    'meta_title' => ['type' => 'string'],
                    'meta_description' => ['type' => 'string'],
                    'content' => ['type' => 'string', 'description' => 'Post content (HTML)'],
                ],
                'required' => ['meta_title', 'meta_description', 'content'],
            ];
            return $schema;
        }

        return new \WP_Error('rest_invalid_mode', __('Invalid mode.', 'aapg'), ['status' => 400]);
    }

    /**
     * POST: create page/post when AddPage=true.
     */
    public function create_item( $request ) {
        $mode = $request->get_param('mode');
        $add_page = $request->get_param('AddPage') === 'true';

        if (!$add_page) {
            return new \WP_Error(
                'rest_invalid_param',
                __('Use AddPage=true and provide JSON body to create a page/post.', 'aapg'),
                ['status' => 400]
            );
        }

        $body = $request->get_json_params();
        if (!is_array($body)) {
            return new \WP_Error('rest_invalid_body', __('Invalid or missing JSON body.', 'aapg'), ['status' => 400]);
        }

        if ($mode === 'stub') {
            return $this->create_stub_page($body);
        }
        if ($mode === 'hub') {
            return $this->create_hub_page($body);
        }
        if ($mode === 'hubmodeb') {
            return $this->create_hubmodeb_page($body);
        }
        if ($mode === 'researchcenter') {
            return $this->create_research_post($body);
        }
        if ($mode === 'blog') {
            return $this->create_blog_post($body);
        }

        return new \WP_Error('rest_invalid_mode', __('Invalid mode.', 'aapg'), ['status' => 400]);
    }

    /** Keys to strip from stub API body (used only for admin batch generation, not API). */
    private const STUB_API_STRIP_KEYS = [
        'RC_IMPORT_PACKET_CORE_V1',
        'RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C1',
        'RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C2',
        'RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C3',
        'RC_IMPORT_PACKET_CLUSTER_V1__TOPIC01_C4',
        'SEO_MASTER_LAUNCH_REQUEST_V2_FOR_SEO_BUNDLE_PLANNER',
    ];

    /** Video fields belong to content-images API only; never in /generate schema or POST body. */
    private const GENERATE_EXCLUDE_VIDEO_KEYS = [
        'video_thumbnail',
        'video_link',
    ];

    /**
     * Remove video_thumbnail / video_link from JSON Schema (root and nested object/repeater item shapes).
     */
    private function strip_video_fields_from_generate_schema(array $schema): array {
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach (self::GENERATE_EXCLUDE_VIDEO_KEYS as $k) {
                unset($schema['properties'][$k]);
            }
            if (isset($schema['required']) && is_array($schema['required'])) {
                $schema['required'] = array_values(array_diff($schema['required'], self::GENERATE_EXCLUDE_VIDEO_KEYS));
            }
            foreach ($schema['properties'] as $name => $prop) {
                if (is_array($prop)) {
                    $schema['properties'][$name] = $this->strip_video_fields_from_generate_schema($prop);
                }
            }
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->strip_video_fields_from_generate_schema($schema['items']);
        }
        return $schema;
    }

    private function create_stub_page(array $body): \WP_REST_Response|\WP_Error {
        $settings = get_option(AAPG_OPTION_KEY, []);
        $template_id = (int) ($settings['stub_elementor_template'] ?? $settings['default_elementor_template'] ?? 0);
        $acf_group = $settings['stub_acf_group'] ?? $settings['default_acf_group'] ?? '';
        $parent_id = (int) ($settings['stub_maker_default_parent_page_id'] ?? 0);

        if (empty($acf_group)) {
            return new \WP_Error('rest_missing_config', __('Stub ACF group not configured.', 'aapg'), ['status' => 500]);
        }

        $page_title = isset($body['page_title']) ? sanitize_text_field($body['page_title']) : '';
        if (empty($page_title)) {
            return new \WP_Error('rest_missing_field', __('page_title is required.', 'aapg'), ['status' => 400]);
        }

        // Remove batch-generation-only fields and video fields (use content-images API for those).
        $body = array_diff_key($body, array_flip(array_merge(self::STUB_API_STRIP_KEYS, self::GENERATE_EXCLUDE_VIDEO_KEYS)));

        require_once AAPG_PLUGIN_DIR . 'includes/nodes/aapg-stub-node.php';
        $result = \AAPG\Nodes\AAPG_Stub_Node::create_page_from_json_result(
            $body,
            $page_title,
            $parent_id,
            $template_id,
            $acf_group,
            '',
            '',
            'stub',
            'publish'
        );

        if (is_wp_error($result)) {
            return new \WP_Error('rest_create_failed', $result->get_error_message(), ['status' => 500]);
        }

        return $this->build_success_response($result['page_id'], 'page');
    }

    private function create_hub_page(array $body): \WP_REST_Response|\WP_Error {
        $settings = get_option(AAPG_OPTION_KEY, []);
        $template_id = (int) ($settings['hub_elementor_template'] ?? 0);
        if ($template_id <= 0) {
            $template_id = (int) ($settings['hub_maker_default_elementor_template'] ?? 0);
        }
        if ($template_id <= 0) {
            $template_id = (int) ($settings['default_elementor_template'] ?? 0);
        }

        $acf_group = (string) ($settings['hub_acf_group'] ?? '');
        if ($acf_group === '') {
            $acf_group = (string) ($settings['hub_maker_default_acf_group'] ?? '');
        }
        if ($acf_group === '') {
            $acf_group = (string) ($settings['stub_acf_group'] ?? '');
        }
        if ($acf_group === '') {
            $acf_group = (string) ($settings['default_acf_group'] ?? '');
        }
        $parent_id = (int) ($settings['hub_default_parent_page_id'] ?? 0);

        if (empty($acf_group)) {
            return new \WP_Error('rest_missing_config', __('Hub ACF group not configured.', 'aapg'), ['status' => 500]);
        }
        if ($template_id <= 0) {
            return new \WP_Error('rest_missing_config', __('Hub Elementor template not configured.', 'aapg'), ['status' => 500]);
        }

        $page_title = isset($body['page_title']) ? sanitize_text_field($body['page_title']) : '';
        if (empty($page_title)) {
            return new \WP_Error('rest_missing_field', __('page_title is required.', 'aapg'), ['status' => 400]);
        }

        // Remove batch-generation-only fields and video fields (use content-images API for those).
        $body = array_diff_key($body, array_flip(array_merge(self::STUB_API_STRIP_KEYS, self::GENERATE_EXCLUDE_VIDEO_KEYS)));

        require_once AAPG_PLUGIN_DIR . 'includes/nodes/aapg-stub-node.php';
        $result = \AAPG\Nodes\AAPG_Stub_Node::create_page_from_json_result(
            $body,
            $page_title,
            $parent_id,
            $template_id,
            $acf_group,
            '',
            '',
            'hub',
            'publish'
        );

        if (is_wp_error($result)) {
            return new \WP_Error('rest_create_failed', $result->get_error_message(), ['status' => 500]);
        }

        return $this->build_success_response($result['page_id'], 'page');
    }

    private function create_hubmodeb_page(array $body): \WP_REST_Response|\WP_Error {
        $settings = get_option(AAPG_OPTION_KEY, []);
        $template_id = (int) ($settings['hubmodeb_elementor_template'] ?? 0);
        if ($template_id <= 0) {
            $template_id = (int) ($settings['hub_elementor_template'] ?? 0);
        }
        if ($template_id <= 0) {
            $template_id = (int) ($settings['hub_maker_default_elementor_template'] ?? 0);
        }
        if ($template_id <= 0) {
            $template_id = (int) ($settings['default_elementor_template'] ?? 0);
        }

        $acf_group = (string) ($settings['hubmodeb_acf_group'] ?? '');
        if ($acf_group === '') {
            $acf_group = (string) ($settings['hub_acf_group'] ?? '');
        }
        if ($acf_group === '') {
            $acf_group = (string) ($settings['hub_maker_default_acf_group'] ?? '');
        }
        if ($acf_group === '') {
            $acf_group = (string) ($settings['stub_acf_group'] ?? '');
        }
        if ($acf_group === '') {
            $acf_group = (string) ($settings['default_acf_group'] ?? '');
        }

        $parent_id = (int) ($settings['hubmodeb_default_parent_page_id'] ?? 0);
        if ($parent_id <= 0) {
            $parent_id = (int) ($settings['hub_default_parent_page_id'] ?? 0);
        }

        if (empty($acf_group)) {
            return new \WP_Error('rest_missing_config', __('Hub Mode B ACF group not configured.', 'aapg'), ['status' => 500]);
        }
        if ($template_id <= 0) {
            return new \WP_Error('rest_missing_config', __('Hub Mode B Elementor template not configured.', 'aapg'), ['status' => 500]);
        }

        $page_title = isset($body['page_title']) ? sanitize_text_field($body['page_title']) : '';
        if (empty($page_title)) {
            return new \WP_Error('rest_missing_field', __('page_title is required.', 'aapg'), ['status' => 400]);
        }

        $body = array_diff_key($body, array_flip(array_merge(self::STUB_API_STRIP_KEYS, self::GENERATE_EXCLUDE_VIDEO_KEYS)));

        require_once AAPG_PLUGIN_DIR . 'includes/nodes/aapg-stub-node.php';
        $result = \AAPG\Nodes\AAPG_Stub_Node::create_page_from_json_result(
            $body,
            $page_title,
            $parent_id,
            $template_id,
            $acf_group,
            '',
            '',
            'hubmodeb',
            'publish'
        );

        if (is_wp_error($result)) {
            return new \WP_Error('rest_create_failed', $result->get_error_message(), ['status' => 500]);
        }

        return $this->build_success_response($result['page_id'], 'page');
    }

    private function create_research_post(array $body): \WP_REST_Response|\WP_Error {
        $settings = get_option(AAPG_OPTION_KEY, []);
        $post_type = $settings['researchcenter_post_type'] ?? $settings['default_research_post_type'] ?? 'post';

        $post_type_obj = get_post_type_object($post_type);
        if (!$post_type_obj || !post_type_supports($post_type, 'title') || !post_type_supports($post_type, 'editor')) {
            return new \WP_Error('rest_invalid_post_type', __('Invalid or unsupported post type for research.', 'aapg'), ['status' => 400]);
        }

        $content = isset($body['content']) ? wp_kses_post($body['content']) : '';
        $meta_title = isset($body['meta_title']) ? sanitize_text_field($body['meta_title']) : '';
        $meta_description = isset($body['meta_description']) ? sanitize_textarea_field($body['meta_description']) : '';
        $research_title = isset($body['research_title']) ? sanitize_text_field($body['research_title']) : $meta_title;
        if (empty($research_title)) {
            $research_title = 'Research ' . current_time('Y-m-d-H-i');
        }

        $category = isset($body['category']) ? sanitize_text_field($body['category']) : '';

        $post_id = wp_insert_post([
            'post_type'    => $post_type,
            'post_status'  => 'publish',
            'post_title'   => $research_title,
            'post_content' => $content,
        ], true);

        if (is_wp_error($post_id)) {
            return new \WP_Error('rest_create_failed', $post_id->get_error_message(), ['status' => 500]);
        }

        // Assign article category when provided (taxonomy: article-category).
        if ($category !== '' && taxonomy_exists('article-category')) {
            $term_id = null;
            if (is_numeric($category)) {
                $term_id = (int) $category;
            } else {
                $existing = term_exists($category, 'article-category');
                if (is_array($existing) && isset($existing['term_id'])) {
                    $term_id = (int) $existing['term_id'];
                } else {
                    $created = wp_insert_term($category, 'article-category');
                    if (!is_wp_error($created) && isset($created['term_id'])) {
                        $term_id = (int) $created['term_id'];
                    }
                }
            }
            if ($term_id) {
                wp_set_post_terms($post_id, [$term_id], 'article-category', false);
            }
        }

        update_post_meta($post_id, 'isGeneratedByAutomation', 'true');
        update_post_meta($post_id, 'aiGenerated', 'true');
        update_post_meta($post_id, 'aapg_page_type', 'research');
        update_post_meta($post_id, 'aapg_ai_generated_title', $research_title);
        $post = get_post($post_id);
        update_post_meta($post_id, 'aapg_ai_generated_slug', $post ? $post->post_name : '');
        update_post_meta($post_id, 'aapg_original_title', $research_title);

        if (!empty($meta_title)) {
            update_post_meta($post_id, 'rank_math_title', $meta_title);
        }
        if (!empty($meta_description)) {
            update_post_meta($post_id, 'rank_math_description', $meta_description);
        }
        $url_table = $body['URL_RESOLUTION_TABLE'] ?? [];
        if (is_array($url_table)) {
            update_post_meta($post_id, 'aapg_url_resolution_table', $url_table);
        }

        // Save cluster_article ACF repeater when provided.
        $cluster_article = $body['cluster_article'] ?? null;
        if (is_array($cluster_article) && function_exists('update_field')) {
            $rows = [];
            foreach ($cluster_article as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rows[] = [
                    'article_title' => isset($row['article_title']) ? sanitize_text_field($row['article_title']) : '',
                    'article_link'  => isset($row['article_link']) ? sanitize_text_field($row['article_link']) : '',
                ];
            }
            if (!empty($rows)) {
                update_field('cluster_article', $rows, $post_id);
            }
        }

        $response = $this->build_success_response($post_id, $post_type);
        $data = $response->get_data();
        if (function_exists('get_field')) {
            $data['cluster_article'] = get_field('cluster_article', $post_id) ?: [];
        }
        return new \WP_REST_Response($data, 201);
    }

    private function create_blog_post(array $body): \WP_REST_Response|\WP_Error {
        require_once AAPG_PLUGIN_DIR . 'includes/nodes/aapg-blog-maker.php';
        $result = \AAPG\Nodes\AAPG_Blog_Maker::create_post_from_data($body);
        if (is_wp_error($result)) {
            return new \WP_Error('rest_create_failed', $result->get_error_message(), ['status' => 500]);
        }
        return $this->build_success_response($result['post_id'], $result['post_type']);
    }

    private function build_success_response(int $post_id, string $post_type): \WP_REST_Response {
        $settings = get_option(AAPG_OPTION_KEY, []);
        $iframe_base = isset($settings['aapg_iframe_edit_page_url']) ? trim($settings['aapg_iframe_edit_page_url']) : '';
        $iframe_edit_url = $iframe_base !== '' ? add_query_arg('aapg_iframe_id', $post_id, $iframe_base) : '';

        $stable_url = aapg_id_based_post_url($post_id);
        return new \WP_REST_Response([
            'success'        => true,
            'post_id'       => $post_id,
            'post_type'     => $post_type,
            'url'           => $stable_url,
            'edit_url'      => get_edit_post_link($post_id, 'raw'),
            'view_url'      => $stable_url,
            'iframe_edit_url' => $iframe_edit_url,
        ], 201);
    }
}
