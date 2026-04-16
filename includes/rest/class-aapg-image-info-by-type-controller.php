<?php
/**
 * REST: GET image field definitions by page type only (no post ID).
 */

namespace AAPG\Rest;

if (!defined('ABSPATH')) {
    exit;
}

class AAPG_Image_Info_By_Type_Controller extends \WP_REST_Controller {

    const REST_NAMESPACE = 'aapg/v1';
    const REST_ROUTE = 'content-images/info';

    public function register_routes(): void {
        register_rest_route(self::REST_NAMESPACE, '/' . self::REST_ROUTE, [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_schema_by_type'],
                'permission_callback' => [$this, 'check_api_key'],
                'args'                => $this->get_read_args(),
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'get_schema_by_type'],
                'permission_callback' => [$this, 'check_api_key'],
                'args'                => $this->get_read_args(),
            ],
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function get_read_args(): array {
        return [
            'type' => [
                'description' => __('Page kind: researchcenter, research, blog, stub, hub, or hubmodeb.', 'aapg'),
                'type'          => 'string',
                'enum'          => ['researchcenter', 'research', 'blog', 'stub', 'hub', 'hubmodeb'],
                'required'      => true,
            ],
        ];
    }

    /**
     * @return 'researchcenter'|'blog'|'stub'|'hub'|'hubmodeb'|null
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
        if (in_array($t, ['researchcenter', 'blog', 'stub', 'hub', 'hubmodeb'], true)) {
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
     * GET /aapg/v1/content-images/info?type=blog
     *
     * Returns type, image_upload_instructions (type/global fallback), and fields with upload_instructions per field_key.
     * Includes static link fields video_thumbnail and video_link in the fields array.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_schema_by_type(\WP_REST_Request $request) {
        $kind = $this->parse_type_param($request);
        if ($kind === null) {
            return new \WP_Error(
                'rest_invalid_param',
                __('Invalid or missing type. Use researchcenter, blog, stub, or hub.', 'aapg'),
                ['status' => 400]
            );
        }

        if (in_array($kind, ['stub', 'hub', 'hubmodeb'], true) && aapg_content_images_acf_group_for_kind($kind) === '') {
            return new \WP_Error(
                'aapg_api_acf_group_missing',
                __('ACF field group for this type is not set in API Mode Settings (Stub, Hub, or Hub Mode B).', 'aapg'),
                ['status' => 400]
            );
        }

        if (in_array($kind, ['stub', 'hub', 'hubmodeb'], true)) {
            $acf_group = aapg_content_images_acf_group_for_kind($kind);
            $entries = $acf_group !== '' ? aapg_acf_collect_media_field_entries_schema_only($acf_group) : [];
            $payload = [];
            foreach ($entries as $e) {
                $payload[] = [
                    'field_key'     => isset($e['field_key']) ? (string) $e['field_key'] : '',
                    'field_name'    => isset($e['field_name']) ? (string) $e['field_name'] : '',
                    'field_title'   => isset($e['field_title']) ? (string) $e['field_title'] : '',
                    'value'         => '',
                    'is_repeater'   => !empty($e['is_repeater']),
                    'repeater_path' => isset($e['repeater_path']) ? (string) $e['repeater_path'] : '',
                    'example_index' => isset($e['example_index']) && is_numeric($e['example_index']) ? (int) $e['example_index'] : null,
                ];
            }
            $payload = aapg_video_merge_static_rows_into_field_list($payload, null);
        } elseif ($kind === 'researchcenter' || $kind === 'blog') {
            $title = __('Featured image', 'aapg');
            $payload = [
                [
                    'field_key'   => 'feature_image',
                    'field_name'  => 'feature_image',
                    'field_title' => $title,
                    'value'       => '',
                    'is_repeater' => false,
                    'repeater_path' => '',
                    'example_index' => null,
                ],
            ];
            $payload = aapg_video_merge_static_rows_into_field_list($payload, null);
        }

        $payload = aapg_content_images_enrich_fields_with_instructions($payload, $kind);

        $instructions = aapg_content_images_upload_instructions_for_kind($kind);

        return new \WP_REST_Response([
            'type'                       => $kind,
            'image_upload_instructions'  => $instructions,
            'fields'                     => $payload,
        ], 200);
    }
}
