<?php
/**
 * REST: GET/POST image slots for AI-generated content (Elementor + ACF + featured).
 */

namespace AAPG\Rest;

if (!defined('ABSPATH')) {
    exit;
}

class AAPG_Content_Images_Controller extends \WP_REST_Controller {

    const REST_NAMESPACE = 'aapg/v1';
    const REST_ROUTE = 'ai-content/(?P<id>\\d+)/images';

    public function register_routes(): void {
        register_rest_route(self::REST_NAMESPACE, '/' . self::REST_ROUTE, [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_images'],
                'permission_callback' => [$this, 'check_api_key'],
                'args'                => $this->get_read_args(),
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'update_images'],
                'permission_callback' => [$this, 'check_api_key'],
                'args'                => $this->get_write_args(),
            ],
        ]);
    }

    /**
     * Query param `type`: shapes the JSON (omit for legacy full slots + schema).
     *
     * @return array<string, array<string, mixed>>
     */
    private function get_read_args(): array {
        return [
            'type' => [
                'description' => __('Page kind: researchcenter or blog = featured image only; stub or hub = ACF image fields only (no Elementor). Omit for full slots list.', 'aapg'),
                'type'          => 'string',
                'enum'          => ['researchcenter', 'research', 'blog', 'stub', 'hub'],
                'required'      => false,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function get_write_args(): array {
        return [
            'type' => [
                'description' => __('Same as GET: simplified response shape when set.', 'aapg'),
                'type'          => 'string',
                'enum'          => ['researchcenter', 'research', 'blog', 'stub', 'hub'],
                'required'      => false,
            ],
        ];
    }

    /**
     * @return 'researchcenter'|'blog'|'stub'|'hub'|null
     */
    private function parse_type_param(\WP_REST_Request $request): ?string {
        $raw = $request->get_param('type');
        if ($raw === null || $raw === '') {
            return null;
        }
        $t = strtolower((string) $raw);
        if ($t === 'research') {
            $t = 'researchcenter';
        }
        if (in_array($t, ['researchcenter', 'blog', 'stub', 'hub'], true)) {
            return $t;
        }
        return null;
    }

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

    /**
     * GET /aapg/v1/ai-content/{id}/images
     */
    public function get_images(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $post_id = (int) $request['id'];
        $ai = aapg_rest_require_ai_generated_post($post_id);
        if (is_wp_error($ai)) {
            return $ai;
        }

        $page_type = (string) get_post_meta($post_id, 'aapg_page_type', true);
        $kind = $this->parse_type_param($request);
        if ($kind !== null) {
            if (($kind === 'stub' || $kind === 'hub') && aapg_content_images_acf_group_for_kind($kind) === '') {
                return new \WP_Error(
                    'aapg_api_acf_group_missing',
                    __('ACF field group for this type is not set in API Mode Settings (Stub or Hub).', 'aapg'),
                    ['status' => 400]
                );
            }
            return new \WP_REST_Response($this->build_response_for_kind($post_id, $kind, false), 200);
        }

        $slots = $this->collect_all_slots($post_id, $page_type);

        return new \WP_REST_Response([
            'post_id'         => $post_id,
            'aapg_page_type'  => $page_type,
            'slots'           => $slots,
            'post_body_schema'=> aapg_images_post_body_json_schema(),
        ], 200);
    }

    /**
     * POST /aapg/v1/ai-content/{id}/images
     *
     * Applies updates in order: legacy ACF slot map, Elementor, featured (feature_image URL or attachment id),
     * featured_attachment_id, then stub/hub flat field_name => URL for ACF image fields.
     */
    public function update_images(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $post_id = (int) $request['id'];
        $ai = aapg_rest_require_ai_generated_post($post_id);
        if (is_wp_error($ai)) {
            return $ai;
        }

        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = [];
        }

        $page_type = (string) get_post_meta($post_id, 'aapg_page_type', true);
        $kind = $this->parse_type_param($request);
        $acf_group = (string) get_post_meta($post_id, 'aapg_acf_group_id', true);
        if ($kind === 'stub' || $kind === 'hub') {
            $g = aapg_content_images_acf_group_for_kind($kind);
            if ($g !== '') {
                $acf_group = $g;
            }
        }

        if (!empty($body['acf'])) {
            if (!is_array($body['acf'])) {
                return new \WP_Error('rest_invalid_param', __('acf must be an object.', 'aapg'), ['status' => 400]);
            }
            if ($acf_group === '') {
                return new \WP_Error('aapg_no_acf_group', __('This post has no ACF field group; cannot update acf slots.', 'aapg'), ['status' => 400]);
            }
            $acf_map = [];
            foreach ($body['acf'] as $k => $v) {
                // Keep raw value so slot updates can resolve either attachment ID or media URL.
                $acf_map[(string) $k] = $v;
            }
            $acf_result = aapg_acf_apply_slot_updates($post_id, $acf_group, $acf_map);
            if (is_wp_error($acf_result)) {
                return $acf_result;
            }
        }

        if (!empty($body['elementor_replacements'])) {
            if (!is_array($body['elementor_replacements'])) {
                return new \WP_Error('rest_invalid_param', __('elementor_replacements must be an object.', 'aapg'), ['status' => 400]);
            }
            $el_map = [];
            foreach ($body['elementor_replacements'] as $old_k => $new_v) {
                $el_map[(string) $old_k] = (int) $new_v;
            }
            if ($el_map !== []) {
                $el_result = aapg_elementor_apply_attachment_replacements($post_id, $el_map);
                if (is_wp_error($el_result)) {
                    return $el_result;
                }
            }
        }

        // Do not reserve feature_image: stub/hub ACF groups may define an image field with that name.
        $reserved_flat_acf_urls = array_merge(
            ['acf', 'elementor_replacements', 'featured_attachment_id'],
            function_exists('aapg_video_static_request_keys') ? aapg_video_static_request_keys() : []
        );

        if (array_key_exists('feature_image', $body) && in_array($page_type, ['research', 'blog'], true)) {
            $resolved = aapg_resolve_image_url_or_attachment_id($body['feature_image']);
            if (is_wp_error($resolved)) {
                return $resolved;
            }
            $feat_result = aapg_featured_apply($post_id, $resolved);
            if (is_wp_error($feat_result)) {
                return $feat_result;
            }
        } elseif (array_key_exists('featured_attachment_id', $body)) {
            if (!in_array($page_type, ['research', 'blog'], true)) {
                return new \WP_Error(
                    'aapg_featured_wrong_type',
                    __('featured_attachment_id is only allowed for research or blog posts.', 'aapg'),
                    ['status' => 400]
                );
            }
            $fid = (int) $body['featured_attachment_id'];
            $feat_result = aapg_featured_apply($post_id, $fid);
            if (is_wp_error($feat_result)) {
                return $feat_result;
            }
        }

        $video_apply = aapg_apply_video_static_fields_from_body($post_id, $body);
        if (is_wp_error($video_apply)) {
            return $video_apply;
        }

        $is_stub_or_hub_request =
            $kind === 'stub' || $kind === 'hub'
            || ($kind === null && in_array($page_type, ['stub', 'hub'], true));
        if ($is_stub_or_hub_request) {
            // For stub/hub, also accept flat image fields (e.g. feature_image) alongside acf slot updates.
            // Remove control keys so only flat ACF field-name keys are applied here.
            $flat_body = is_array($body) ? $body : [];
            unset($flat_body['acf'], $flat_body['elementor_replacements'], $flat_body['featured_attachment_id']);
            if ($flat_body !== []) {
                $flat_result = aapg_acf_apply_image_fields_from_url_body($post_id, $flat_body, $reserved_flat_acf_urls, $acf_group);
                if (is_wp_error($flat_result)) {
                    return $flat_result;
                }
            }
        }
        if ($kind !== null) {
            if (($kind === 'stub' || $kind === 'hub') && aapg_content_images_acf_group_for_kind($kind) === '') {
                return new \WP_Error(
                    'aapg_api_acf_group_missing',
                    __('ACF field group for this type is not set in API Mode Settings (Stub or Hub).', 'aapg'),
                    ['status' => 400]
                );
            }
            return new \WP_REST_Response(
                array_merge(['success' => true], $this->build_response_for_kind($post_id, $kind, true)),
                200
            );
        }

        $slots = $this->collect_all_slots($post_id, $page_type);

        return new \WP_REST_Response([
            'success'          => true,
            'post_id'          => $post_id,
            'aapg_page_type'   => $page_type,
            'slots'            => $slots,
            'post_body_schema' => aapg_images_post_body_json_schema(),
        ], 200);
    }

    /**
     * Simplified payloads per client `type` (no Elementor rows for stub/hub).
     *
     * GET: researchcenter|blog | stub|hub → [ { field_key, field_name, field_title, value }, ... ].
     * POST body: blog|researchcenter → { "feature_image", optional "video_thumbnail", "video_link" }; stub|hub → ACF image field names plus optional video_thumbnail/video_link (URLs).
     * POST response: researchcenter|blog → post_id, type, feature_image (URL); stub|hub → post_id, type, api_field_key => URL.
     *
     * Stub/hub use the ACF field group from API Mode Settings (not post meta).
     *
     * @param 'researchcenter'|'blog'|'stub'|'hub' $kind
     * @return array<int|string, mixed>|\stdClass
     */
    private function build_response_for_kind(int $post_id, string $kind, bool $for_post = false) {
        if ($kind === 'researchcenter' || $kind === 'blog') {
            $f = aapg_featured_collect_slot($post_id);
            $url = $f['url'] !== '' ? $f['url'] : '';
            $title = isset($f['field_label']) && $f['field_label'] !== ''
                ? (string) $f['field_label']
                : __('Featured image', 'aapg');
            if ($for_post) {
                $flat = ['post_id' => $post_id, 'type' => $kind, 'feature_image' => $url];
                foreach (aapg_video_static_field_values_if_missing_by_name($post_id, []) as $vk => $vv) {
                    $flat[$vk] = $vv;
                }
                return $flat;
            }
            $rows = [
                [
                    'field_key'   => 'feature_image',
                    'field_name'  => 'feature_image',
                    'field_title' => $title,
                    'value'       => $url,
                ],
            ];
            $rows = aapg_video_merge_static_rows_into_field_list($rows, $post_id);
            return aapg_content_images_enrich_fields_with_instructions($rows, $kind);
        }

        // stub | hub — all image/gallery fields from API Mode Settings group (empty values included).
        $acf_group = aapg_content_images_acf_group_for_kind($kind);
        $entries = $acf_group !== '' ? aapg_acf_collect_media_field_entries_for_rest($post_id, $acf_group) : [];

        if ($for_post) {
            $flat = ['post_id' => $post_id, 'type' => $kind];
            foreach ($entries as $e) {
                $k = isset($e['api_field_key']) ? (string) $e['api_field_key'] : '';
                if ($k === '') {
                    continue;
                }
                $flat[$k] = isset($e['value']) ? (string) $e['value'] : '';
            }
            foreach (aapg_video_static_field_values_if_missing_by_name($post_id, $entries) as $vk => $vv) {
                $flat[$vk] = $vv;
            }
            return $flat;
        }

        $list = [];
        foreach ($entries as $e) {
            $list[] = [
                'field_key'   => isset($e['field_key']) ? (string) $e['field_key'] : '',
                'field_name'  => isset($e['field_name']) ? (string) $e['field_name'] : '',
                'field_title' => isset($e['field_title']) ? (string) $e['field_title'] : '',
                'value'       => isset($e['value']) ? (string) $e['value'] : '',
            ];
        }

        $list = aapg_video_merge_static_rows_into_field_list($list, $post_id);
        return aapg_content_images_enrich_fields_with_instructions($list, $kind);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function collect_all_slots(int $post_id, string $page_type): array {
        $slots = [];

        foreach (aapg_elementor_collect_image_slots($post_id) as $s) {
            $slots[] = [
                'slot_key'       => $s['slot_key'],
                'source'         => 'elementor',
                'attachment_id'  => $s['attachment_id'],
                'url'            => $s['url'],
                'alt'            => $s['alt'],
                'title'          => $s['title'],
                'field_label'    => $s['title'] !== '' ? $s['title'] : __('Elementor image', 'aapg'),
                'elementor_hint' => $s['elementor_hint'],
            ];
        }

        $acf_group = (string) get_post_meta($post_id, 'aapg_acf_group_id', true);
        if ($acf_group !== '') {
            foreach (aapg_acf_collect_media_slots($post_id, $acf_group) as $s) {
                $row = [
                    'slot_key'      => $s['slot_key'],
                    'source'        => 'acf',
                    'attachment_id' => $s['attachment_id'],
                    'url'           => $s['url'],
                    'alt'           => $s['alt'],
                    'title'         => $s['title'],
                    'field_label'   => $s['field_label'],
                    'acf_path'      => $s['acf_path'],
                ];
                $slots[] = $row;
            }
        }

        if (in_array($page_type, ['research', 'blog'], true)) {
            $f = aapg_featured_collect_slot($post_id);
            $slots[] = [
                'slot_key'      => $f['slot_key'],
                'source'        => 'featured',
                'attachment_id' => $f['attachment_id'],
                'url'           => $f['url'],
                'alt'           => $f['alt'],
                'title'         => $f['title'],
                'field_label'   => $f['field_label'],
            ];
        }

        return $slots;
    }
}
