<?php
/**
 * AAPG Stream Request Handler
 * Handles streaming requests to OpenAI Responses API with SSE support
 */

namespace AAPG;

if (!defined('ABSPATH')) {
    exit;
}

class AAPG_Stream_Request {

    private $api_key;
    private $api_url = 'https://api.openai.com/v1/responses';

    public function __construct() {
        $settings = get_option(AAPG_OPTION_KEY, []);
        $this->api_key = $settings['openai_api_key'] ?? '';
    }

    /**
     * Run a streaming request to the OpenAI Responses API.
     *
     * @param array    $request_data Request body (model, input, etc.).
     * @param callable $callback     Callback (event_type, decoded_data). Event type from API is decoded_data['type'] when present.
     * @param bool     $send_headers When true (default), send SSE headers and clear output buffer. Set false when caller already sent headers.
     * @return true|\WP_Error
     */
    public function stream_request($request_data, $callback, $send_headers = true) {
        if (empty($this->api_key)) {
            return new \WP_Error('no_api_key', 'OpenAI API key is not configured');
        }

        // Force streaming
        $request_data['stream'] = true;

        if ($send_headers) {
            // Disable all output buffering (VERY IMPORTANT for streaming)
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            ob_implicit_flush(true);

            // Tell browser we are streaming
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no'); // nginx
            header('Connection: keep-alive');
        }

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $this->api_url,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => wp_json_encode($request_data),

            // CRITICAL streaming flags
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_ENCODING => '', // disable gzip buffering
            CURLOPT_BUFFERSIZE => 1024,

            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->api_key,
                'Content-Type: application/json',
                'Accept: text/event-stream',
            ],

            CURLOPT_WRITEFUNCTION => function ($curl, $data) use ($callback) {
                return $this->process_sse_chunk($data, $callback);
            },
        ]);

        $ok = curl_exec($curl);

        if ($ok === false) {
            $error = curl_error($curl);
            curl_close($curl);
            return new \WP_Error('curl_error', $error);
        }

        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($http_code !== 200) {
            return new \WP_Error('api_error', 'API request failed with status code ' . $http_code);
        }

        return true;
    }

    private function process_sse_chunk($chunk, $callback) {
        static $buffer = '';
        $buffer .= $chunk;

        while (($pos = strpos($buffer, "\n\n")) !== false) {
            $eventBlock = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 2);

            $lines = explode("\n", $eventBlock);
            $event = null;
            $data = '';

            foreach ($lines as $line) {
                $line = trim($line);

                if (strpos($line, 'event:') === 0) {
                    $event = trim(substr($line, 6));
                } elseif (strpos($line, 'data:') === 0) {
                    // IMPORTANT: append, do not overwrite
                    $data .= trim(substr($line, 5));
                }
            }

            if ($data !== '') {
                if ($data === '[DONE]') {
                    call_user_func($callback, 'response.completed', []);
                    continue;
                }

                $decoded = json_decode($data, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    // Prefer API event type from decoded data when present (OpenAI Responses API)
                    $eventType = isset($decoded['type']) ? $decoded['type'] : $event;
                    call_user_func($callback, $eventType, $decoded);
                }
            }
        }

        return strlen($chunk);
    }
}

/**
 * Default streaming callback
 */
function aapg_default_stream_callback($event, $data) {
    static $full_text = '';

    // Streaming deltas
    if ($event === 'response.output_text.delta') {
        if (!empty($data['delta'])) {
            $full_text .= $data['delta'];

            // Live stream to browser
            echo $data['delta'];
            echo str_repeat(' ', 1024); // force flush
            flush();
        }
    }

    // Final completion event
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

        // 🔚 FINAL OUTPUT (returned once, at the end)
        echo "\n\n";
        echo "=== FINAL OUTPUT ===\n";
        echo $final_text;
        flush();

        error_log('OpenAI stream completed');
    }

    if ($event === 'response.error') {
        error_log('OpenAI stream error: ' . print_r($data, true));
    }
}

/**
 * Convenience function
 */
function aapg_stream_openai_request($request_data, $callback = null) {
    if ($callback === null) {
        $callback = __NAMESPACE__ . '\\aapg_default_stream_callback';
    }

    $stream = new AAPG_Stream_Request();
    return $stream->stream_request($request_data, $callback);
}
