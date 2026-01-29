<?php
/**
 * AAPG Image Generation Handler
 * Handles ComfyUI image generation and WordPress media upload
 */

namespace AAPG;

if (!defined('ABSPATH')) {
    exit;
}

class AAPG_Image_Generation {

    private $api_base = 'http://10.200.32.100:8288';

    public function __construct() {
        // Constructor - can be extended for settings
    }

    /**
     * Generate image and upload to WordPress Media Library
     * 
     * @param string $positive_prompt
     * @param string $negative_prompt
     * @param string $resolution
     * @param int $custom_width
     * @param int $custom_height
     * @return string|WP_Error Media URL on success, WP_Error on failure
     */
    public function generate_image_and_upload_to_wp(
        $positive_prompt,
        $negative_prompt = '',
        $resolution = '1280x720     (16:9 Landscape)',
        $custom_width = 1664,
        $custom_height = 928
    ) {
        // Generate random 10-digit seed (starting from 1)
        $random_seed = rand(1000000000, 1999999999);

        // -------------------------------
        // 1. SEND PROMPT REQUEST
        // -------------------------------
        $payload = [
            "client_id" => "jersy-modal-upload-generation",
            "prompt" => [
                "1" => [
                    "inputs" => [
                        "unet_name" => "qwen_image_2512_fp8_e4m3fn_scaled_comfyui_4steps_v1.0.safetensors",
                        "weight_dtype" => "fp8_e4m3fn"
                    ],
                    "class_type" => "UNETLoader"
                ],
                "2" => [
                    "inputs" => [
                        "clip_name" => "qwen_2.5_vl_7b_fp8_scaled.safetensors",
                        "type" => "lumina2",
                        "device" => "default"
                    ],
                    "class_type" => "CLIPLoader"
                ],
                "3" => [
                    "inputs" => [
                        "vae_name" => "qwen_image_vae.safetensors"
                    ],
                    "class_type" => "VAELoader"
                ],
                "4" => [
                    "inputs" => [
                        "text" => $negative_prompt,
                        "clip" => ["2", 0]
                    ],
                    "class_type" => "CLIPTextEncode"
                ],
                "6" => [
                    "inputs" => [
                        "text" => $positive_prompt,
                        "clip" => ["2", 0]
                    ],
                    "class_type" => "CLIPTextEncode"
                ],
                "7" => [
                    "inputs" => [
                        "seed" => ["14", 0],
                        "steps" => 4,
                        "cfg" => 1,
                        "sampler_name" => "euler",
                        "scheduler" => "bong_tangent",
                        "denoise" => 1,
                        "model" => ["1", 0],
                        "positive" => ["6", 0],
                        "negative" => ["4", 0],
                        "latent_image" => ["12", 0]
                    ],
                    "class_type" => "KSampler"
                ],
                "12" => [
                    "inputs" => [
                        "width" => ["27", 0],
                        "height" => ["27", 1],
                        "batch_size" => 1
                    ],
                    "class_type" => "EmptyLatentImage"
                ],
                "14" => [
                    "inputs" => [
                        "seed" => $random_seed
                    ],
                    "class_type" => "Seed (rgthree)"
                ],
                "25" => [
                    "inputs" => [
                        "samples" => ["7", 0],
                        "vae" => ["3", 0]
                    ],
                    "class_type" => "VAEDecode"
                ],
                "26" => [
                    "inputs" => [
                        "images" => ["25", 0]
                    ],
                    "class_type" => "PreviewImage"
                ],
                "27" => [
                    "inputs" => [
                        "model" => "Qwen Image",
                        "resolution" => $resolution,
                        "resolution_multiplier" => "1x",
                        "batch_size" => 1,
                        "custom_width" => (int)$custom_width,
                        "custom_height" => (int)$custom_height,
                        "custom_multiplier" => "1x",
                        "custom_batch" => 1
                    ],
                    "class_type" => "ResolutionSelector"
                ]
            ]
        ];
        $response = wp_remote_post("$this->api_base/prompt", [
            'timeout' => 300,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($payload)
        ]);
        // print_r(json_encode($response));
        // die;

        if (is_wp_error($response)) {
            return new \WP_Error('prompt_failed', 'Failed to submit prompt to ComfyUI: ' . $response->get_error_message());
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($http_code !== 200 || !$response_body) {
            return new \WP_Error('prompt_failed', 'Failed to submit prompt to ComfyUI - HTTP ' . $http_code);
        }

        $data = json_decode($response_body, true);
        if (empty($data['prompt_id'])) {
            return new \WP_Error('prompt_failed', 'Failed to get prompt_id from ComfyUI response');
        }

        $prompt_id = $data['prompt_id'];

        // -------------------------------
        // 2. POLL HISTORY UNTIL SUCCESS
        // -------------------------------
        $filename = null;
        for ($i = 0; $i < 15; $i++) {
            sleep(1);

            $history_response = wp_remote_get("$this->api_base/history/$prompt_id", [
                'timeout' => 100,
                'headers' => [
                    'Content-Type' => 'application/json',
                ]
            ]);

            if (is_wp_error($history_response)) {
                continue;
            }

            $history_json = wp_remote_retrieve_body($history_response);
            if (!$history_json) {
                continue;
            }

            $history = json_decode($history_json, true);
            if (empty($history)) {
                continue;
            }

            foreach ($history as $item) {
                if (
                    isset($item['status']['status_str']) &&
                    $item['status']['status_str'] === 'success'
                ) {
                    $filename = $item['outputs']['26']['images'][0]['filename'] ?? null;
                    if ($filename) {
                        break 2;
                    }
                }
            }
        }

        if (empty($filename)) {
            return new \WP_Error('generation_failed', 'Image generation failed or timed out');
        }
// print_r("start downloading image");
// print_r($filename);
// exit();
        // -------------------------------
        // 3. DOWNLOAD IMAGE
        // -------------------------------
        $image_response = wp_remote_get("$this->api_base/view?filename=" . urlencode($filename) . "&type=temp", [
            'timeout' => 250, // 2 minutes for large images
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'stream' => true, // Enable streaming for large files
            'filename' => tempnam(sys_get_temp_dir(), 'aapg_image_')
        ]);

        if (is_wp_error($image_response)) {
            return new \WP_Error('download_failed', 'Failed to download generated image: ' . $image_response->get_error_message());
        }

        $image_data = wp_remote_retrieve_body($image_response);
        if (!$image_data) {
            return new \WP_Error('download_failed', 'Failed to download generated image - empty response');
        }

        // -------------------------------
        // 4. SAVE TO WORDPRESS MEDIA
        // -------------------------------
        $upload = wp_upload_bits($filename, null, $image_data);

        if ($upload['error']) {
            return new \WP_Error('upload_failed', $upload['error']);
        }

        $filetype = wp_check_filetype($upload['file'], null);

        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $filetype['type'],
            'post_title'     => sanitize_file_name($filename),
            'post_status'    => 'inherit'
        ], $upload['file']);

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attach_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $attach_data);

        // -------------------------------
        // 5. RETURN MEDIA URL
        // -------------------------------
        return wp_get_attachment_url($attachment_id);
    }
}

// Convenience function for global access
function aapg_generate_image($positive_prompt, $negative_prompt = '', $resolution = '1280x720     (16:9 Landscape)', $custom_width = 1664, $custom_height = 928) {
    $generator = new AAPG_Image_Generation();
    return $generator->generate_image_and_upload_to_wp($positive_prompt, $negative_prompt, $resolution, $custom_width, $custom_height);
}
