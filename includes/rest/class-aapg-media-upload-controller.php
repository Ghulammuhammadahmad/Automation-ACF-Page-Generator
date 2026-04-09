<?php
/**
 * REST: upload an image to the media library (API key).
 */

namespace AAPG\Rest;

if (!defined('ABSPATH')) {
    exit;
}

class AAPG_Media_Upload_Controller extends \WP_REST_Controller {

    const REST_NAMESPACE = 'aapg/v1';
    const REST_ROUTE = 'media';

    /** Maximum upload size (1 MB). */
    const MAX_UPLOAD_BYTES = 1048576;

    /** Allowed filename extensions (lowercase). */
    const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** Allowed MIME types after WordPress detection. */
    const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function register_routes(): void {
        register_rest_route(self::REST_NAMESPACE, '/' . self::REST_ROUTE, [
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'upload_file'],
                'permission_callback' => [$this, 'check_api_key'],
            ],
        ]);
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
     * POST multipart/form-data with field name "file".
     * Max size 1 MB; formats: JPG, JPEG, PNG, WebP only.
     */
    public function upload_file(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            return new \WP_Error(
                'aapg_no_file',
                __('Missing file upload. Send multipart/form-data with field name "file".', 'aapg'),
                ['status' => 400]
            );
        }

        $file = $_FILES['file'];
        if (!empty($file['error']) && (int) $file['error'] !== UPLOAD_ERR_OK) {
            return new \WP_Error(
                'aapg_upload_err',
                sprintf(/* translators: %d: PHP upload error code */ __('Upload error (%d).', 'aapg'), (int) $file['error']),
                ['status' => 400]
            );
        }

        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            return new \WP_Error(
                'aapg_file_too_large',
                sprintf(
                    /* translators: %s: max size like "1 MB" */
                    __('File must be between 1 byte and %s.', 'aapg'),
                    size_format(self::MAX_UPLOAD_BYTES)
                ),
                ['status' => 400]
            );
        }

        $original_name = isset($file['name']) ? (string) $file['name'] : '';
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return new \WP_Error(
                'aapg_invalid_type',
                __('Only JPG, JPEG, PNG, and WebP files are allowed.', 'aapg'),
                ['status' => 400]
            );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $upload = wp_handle_upload($file, ['test_form' => false]);
        if (isset($upload['error'])) {
            return new \WP_Error('aapg_upload_failed', $upload['error'], ['status' => 400]);
        }

        $file_path = $upload['file'] ?? '';
        if ($file_path !== '' && file_exists($file_path) && filesize($file_path) > self::MAX_UPLOAD_BYTES) {
            wp_delete_file($file_path);
            return new \WP_Error(
                'aapg_file_too_large',
                sprintf(
                    /* translators: %s: max size like "1 MB" */
                    __('File must not exceed %s.', 'aapg'),
                    size_format(self::MAX_UPLOAD_BYTES)
                ),
                ['status' => 400]
            );
        }

        $type = wp_check_filetype($file_path);
        $mime = $type['type'] ?? '';
        if ($mime === '' || !in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            if ($file_path !== '' && file_exists($file_path)) {
                wp_delete_file($file_path);
            }
            return new \WP_Error('aapg_invalid_type', __('Only JPG, JPEG, PNG, and WebP files are allowed.', 'aapg'), ['status' => 400]);
        }

        $attachment = [
            'post_mime_type' => $mime,
            'post_title'     => sanitize_file_name(pathinfo($file_path, PATHINFO_FILENAME)),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $attach_id = wp_insert_attachment($attachment, $file_path);
        if (is_wp_error($attach_id) || !$attach_id) {
            if ($file_path !== '' && file_exists($file_path)) {
                wp_delete_file($file_path);
            }
            return new \WP_Error('aapg_attachment_failed', __('Could not create attachment.', 'aapg'), ['status' => 500]);
        }

        $meta = wp_generate_attachment_metadata((int) $attach_id, $file_path);
        if (is_array($meta)) {
            wp_update_attachment_metadata((int) $attach_id, $meta);
        }

        $url = wp_get_attachment_url((int) $attach_id);

        return new \WP_REST_Response([
            'attachment_id' => (int) $attach_id,
            'url'           => $url ? (string) $url : '',
            'mime_type'     => $mime,
            'title'         => $attachment['post_title'],
        ], 201);
    }
}
