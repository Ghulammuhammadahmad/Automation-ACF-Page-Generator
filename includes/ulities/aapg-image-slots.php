<?php
/**
 * Elementor + ACF + featured image slots for AI content (REST + iframe).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether attachment exists and is an image.
 */
function aapg_validate_attachment_is_image(int $attachment_id): bool {
    if ($attachment_id <= 0) {
        return false;
    }
    return wp_attachment_is_image($attachment_id);
}

/**
 * Collect Elementor image slots (widget + background + overlay).
 *
 * @return list<array{slot_key:string,source:string,attachment_id:int,url:string,alt:string,title:string,elementor_hint:string}>
 */
function aapg_elementor_collect_image_slots(int $post_id): array {
    $elementor_data = get_post_meta($post_id, '_elementor_data', true);
    if (empty($elementor_data)) {
        return [];
    }
    $data = json_decode($elementor_data, true);
    if (!is_array($data)) {
        return [];
    }

    $found = [];

    $find = function ($element) use (&$find, &$found) {
        if (!is_array($element)) {
            return;
        }
        if (isset($element['widgetType']) && $element['widgetType'] === 'image') {
            if (!empty($element['settings']['image']['id'])) {
                $image_id = (int) $element['settings']['image']['id'];
                $image_url = $element['settings']['image']['url'] ?? wp_get_attachment_url($image_id);
                if ($image_url) {
                    $found[] = [
                        'slot_key'       => (string) $image_id,
                        'source'         => 'elementor',
                        'attachment_id'  => $image_id,
                        'url'            => $image_url,
                        'alt'            => (string) get_post_meta($image_id, '_wp_attachment_image_alt', true),
                        'title'          => get_the_title($image_id),
                        'elementor_hint' => 'image_widget',
                    ];
                }
            }
        }
        if (isset($element['settings']) && is_array($element['settings'])) {
            $settings = $element['settings'];
            if (!empty($settings['background_image']['id'])) {
                $image_id = (int) $settings['background_image']['id'];
                $image_url = $settings['background_image']['url'] ?? wp_get_attachment_url($image_id);
                if ($image_url) {
                    $found[] = [
                        'slot_key'       => (string) $image_id,
                        'source'         => 'elementor',
                        'attachment_id'  => $image_id,
                        'url'            => $image_url,
                        'alt'            => (string) get_post_meta($image_id, '_wp_attachment_image_alt', true),
                        'title'          => get_the_title($image_id),
                        'elementor_hint' => 'background',
                    ];
                }
            }
            if (!empty($settings['background_overlay_image']['id'])) {
                $image_id = (int) $settings['background_overlay_image']['id'];
                $image_url = $settings['background_overlay_image']['url'] ?? wp_get_attachment_url($image_id);
                if ($image_url) {
                    $found[] = [
                        'slot_key'       => (string) $image_id,
                        'source'         => 'elementor',
                        'attachment_id'  => $image_id,
                        'url'            => $image_url,
                        'alt'            => (string) get_post_meta($image_id, '_wp_attachment_image_alt', true),
                        'title'          => get_the_title($image_id),
                        'elementor_hint' => 'background_overlay',
                    ];
                }
            }
        }
        if (isset($element['elements']) && is_array($element['elements'])) {
            foreach ($element['elements'] as $child) {
                $find($child);
            }
        }
    };

    foreach ($data as $element) {
        $find($element);
    }

    $unique = [];
    $seen = [];
    foreach ($found as $row) {
        $id = $row['attachment_id'];
        if (!isset($seen[$id])) {
            $seen[$id] = true;
            $unique[] = $row;
        }
    }
    return $unique;
}

/**
 * Apply Elementor attachment replacements (keys = string old attachment id).
 *
 * @param array<string,int|string> $replacements Old id string => new attachment id.
 * @return true|\WP_Error
 */
function aapg_elementor_apply_attachment_replacements(int $post_id, array $replacements) {
    if (empty($replacements)) {
        return new \WP_Error('aapg_no_replacements', __('No elementor replacements provided.', 'aapg'), ['status' => 400]);
    }
    foreach ($replacements as $new_id) {
        $new_id = (int) $new_id;
        if ($new_id <= 0) {
            return new \WP_Error('aapg_invalid_attachment', __('Elementor replacements require a positive image attachment ID.', 'aapg'), ['status' => 400]);
        }
        if (!aapg_validate_attachment_is_image($new_id)) {
            return new \WP_Error('aapg_invalid_attachment', __('Replacement target must be a valid image attachment.', 'aapg'), ['status' => 400]);
        }
    }

    $elementor_data = get_post_meta($post_id, '_elementor_data', true);
    if (empty($elementor_data)) {
        return new \WP_Error('aapg_no_elementor', __('No Elementor data found.', 'aapg'), ['status' => 400]);
    }
    $data = json_decode($elementor_data, true);
    if (!is_array($data)) {
        return new \WP_Error('aapg_invalid_elementor', __('Invalid Elementor data.', 'aapg'), ['status' => 400]);
    }

    $update = function (&$element) use (&$update, $replacements) {
        if (!is_array($element)) {
            return;
        }
        if (isset($element['widgetType']) && $element['widgetType'] === 'image' && isset($element['settings']['image']['id'])) {
            $old_id = (string) $element['settings']['image']['id'];
            if (isset($replacements[$old_id])) {
                $new_id = (int) $replacements[$old_id];
                $element['settings']['image']['id'] = $new_id;
                $element['settings']['image']['url'] = $new_id ? (string) wp_get_attachment_url($new_id) : '';
            }
        }
        if (isset($element['settings']) && is_array($element['settings'])) {
            if (isset($element['settings']['background_image']['id'])) {
                $old_id = (string) $element['settings']['background_image']['id'];
                if (isset($replacements[$old_id])) {
                    $new_id = (int) $replacements[$old_id];
                    $element['settings']['background_image']['id'] = $new_id;
                    $element['settings']['background_image']['url'] = $new_id ? (string) wp_get_attachment_url($new_id) : '';
                }
            }
            if (isset($element['settings']['background_overlay_image']['id'])) {
                $old_id = (string) $element['settings']['background_overlay_image']['id'];
                if (isset($replacements[$old_id])) {
                    $new_id = (int) $replacements[$old_id];
                    $element['settings']['background_overlay_image']['id'] = $new_id;
                    $element['settings']['background_overlay_image']['url'] = $new_id ? (string) wp_get_attachment_url($new_id) : '';
                }
            }
        }
        if (isset($element['elements']) && is_array($element['elements'])) {
            foreach ($element['elements'] as &$child) {
                $update($child);
            }
        }
    };

    foreach ($data as &$element) {
        $update($element);
    }

    update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($data, JSON_UNESCAPED_UNICODE)));
    if (class_exists('\Elementor\Plugin')) {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    }
    return true;
}

/**
 * Normalize ACF image field value to attachment ID.
 */
function aapg_acf_normalize_attachment_id($value): ?int {
    if (is_numeric($value)) {
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }
    if (is_string($value) && $value !== '') {
        $url_id = attachment_url_to_postid($value);
        if ($url_id > 0) {
            return (int) $url_id;
        }
    }
    if (is_array($value)) {
        if (!empty($value['ID'])) {
            return (int) $value['ID'];
        }
        if (!empty($value['id'])) {
            return (int) $value['id'];
        }
    }
    return null;
}

/**
 * @return int[]
 */
function aapg_acf_normalize_gallery_ids($value): array {
    if (!is_array($value) || $value === []) {
        return [];
    }
    $ids = [];
    foreach ($value as $item) {
        $id = aapg_acf_normalize_attachment_id($item);
        if ($id) {
            $ids[] = $id;
        }
    }
    return $ids;
}

/**
 * Build REST JSON key from ACF field key + path (repeaters / gallery indices).
 */
function aapg_acf_api_field_key(array $field, array $segs, string $type): string {
    $fk = (string) ($field['key'] ?? '');
    if ($fk === '') {
        $fk = (string) ($field['name'] ?? 'field');
    }
    if ($type === 'gallery') {
        $idx = $segs !== [] ? (int) end($segs) : 0;
        return $fk . '_' . $idx;
    }
    $parts = $segs;
    if ($parts !== []) {
        array_pop($parts);
    }
    $nums = [];
    foreach ($parts as $p) {
        if (is_numeric($p)) {
            $nums[] = (int) $p;
        }
    }
    if ($nums === []) {
        return $fk;
    }
    return $fk . '_' . implode('_', $nums);
}

/**
 * Metadata to help UI build repeater-aware controls from schema-only entries.
 *
 * @return array{is_repeater:bool,repeater_path:string,example_index:int|null}
 */
function aapg_acf_entry_ui_meta_from_segments(array $segs): array {
    $indices = [];
    $path = [];
    foreach ($segs as $seg) {
        if (is_numeric($seg)) {
            $indices[] = (int) $seg;
            continue;
        }
        $path[] = (string) $seg;
    }
    if ($indices === []) {
        return [
            'is_repeater'  => false,
            'repeater_path'=> '',
            'example_index'=> null,
        ];
    }
    return [
        'is_repeater'   => true,
        'repeater_path' => implode('.', $path),
        'example_index' => $indices[0],
    ];
}

/**
 * ACF field group key from plugin settings for stub/hub image API (not post meta).
 */
function aapg_content_images_acf_group_for_kind(string $kind): string {
    $settings = get_option(AAPG_OPTION_KEY, []);
    if (!is_array($settings)) {
        $settings = [];
    }
    if ($kind === 'stub') {
        $stub_group = isset($settings['stub_acf_group']) ? (string) $settings['stub_acf_group'] : '';
        if ($stub_group !== '') {
            return $stub_group;
        }
        return isset($settings['default_acf_group']) ? (string) $settings['default_acf_group'] : '';
    }
    if ($kind === 'hub') {
        $hub_group = isset($settings['hub_acf_group']) ? (string) $settings['hub_acf_group'] : '';
        if ($hub_group !== '') {
            return $hub_group;
        }
        $hub_maker_group = isset($settings['hub_maker_default_acf_group']) ? (string) $settings['hub_maker_default_acf_group'] : '';
        if ($hub_maker_group !== '') {
            return $hub_maker_group;
        }
        $stub_group = isset($settings['stub_acf_group']) ? (string) $settings['stub_acf_group'] : '';
        if ($stub_group !== '') {
            return $stub_group;
        }
        return isset($settings['default_acf_group']) ? (string) $settings['default_acf_group'] : '';
    }
    return '';
}

/**
 * Dedicated ACF field group key for video fields in content-images API.
 */
function aapg_content_images_video_acf_group_key(): string {
    $settings = get_option(AAPG_OPTION_KEY, []);
    if (!is_array($settings)) {
        $settings = [];
    }
    return isset($settings['content_images_video_acf_group'])
        ? (string) $settings['content_images_video_acf_group']
        : '';
}

/**
 * Image upload instructions for REST GET content-images/info (dimensions, resolution, format, etc.).
 * Per-type text overrides the default when non-empty.
 */
function aapg_content_images_upload_instructions_for_kind(string $kind): string {
    $settings = get_option(AAPG_OPTION_KEY, []);
    if (!is_array($settings)) {
        $settings = [];
    }
    $default = isset($settings['content_images_instructions_default'])
        ? trim((string) $settings['content_images_instructions_default'])
        : '';
    $map = [
        'researchcenter' => 'content_images_instructions_researchcenter',
        'blog'             => 'content_images_instructions_blog',
        'stub'             => 'content_images_instructions_stub',
        'hub'              => 'content_images_instructions_hub',
    ];
    $opt_key = $map[$kind] ?? '';
    if ($opt_key !== '' && !empty($settings[$opt_key])) {
        $specific = trim((string) $settings[$opt_key]);
        if ($specific !== '') {
            return $specific;
        }
    }
    return $default;
}

/**
 * Sanitize nested field instructions: [ kind => [ field_key => text ] ].
 *
 * @param mixed $value Raw from settings form.
 * @return array<string, array<string, string>>
 */
function aapg_content_images_sanitize_field_instructions_array($value): array {
    if (!is_array($value)) {
        return [];
    }
    $kinds = ['researchcenter', 'blog', 'stub', 'hub'];
    $out = [];
    foreach ($kinds as $kind) {
        if (!isset($value[$kind]) || !is_array($value[$kind])) {
            continue;
        }
        $out[$kind] = [];
        foreach ($value[$kind] as $field_key => $text) {
            $fk = is_string($field_key) ? trim($field_key) : '';
            if ($fk === '' || strlen($fk) > 240) {
                continue;
            }
            $out[$kind][$fk] = sanitize_textarea_field(wp_unslash($text));
        }
    }
    return $out;
}

/**
 * Discover slots that need upload instructions in admin (featured, ACF image/gallery, video links).
 *
 * @return list<array{key:string,label:string}>
 */
function aapg_content_images_discover_instruction_slots(string $kind): array {
    $rows = [];
    if ($kind === 'researchcenter' || $kind === 'blog') {
        $rows[] = ['key' => 'feature_image', 'label' => __('Featured image', 'aapg')];
    } elseif ($kind === 'stub' || $kind === 'hub') {
        $g = aapg_content_images_acf_group_for_kind($kind);
        if ($g !== '' && function_exists('acf_get_fields')) {
            foreach (aapg_acf_collect_media_field_entries_schema_only($g) as $e) {
                $k = isset($e['api_field_key']) ? (string) $e['api_field_key'] : '';
                if ($k === '') {
                    continue;
                }
                $rows[] = [
                    'key'   => $k,
                    'label' => isset($e['field_title']) ? (string) $e['field_title'] : $k,
                ];
            }
        }
        foreach (aapg_video_static_field_definitions() as $def) {
            $rows[] = [
                'key'   => (string) $def['field_key'],
                'label' => (string) $def['field_title'],
            ];
        }
        return $rows;
    }
    foreach (aapg_video_static_field_definitions() as $def) {
        $rows[] = [
            'key'   => (string) $def['field_key'],
            'label' => (string) $def['field_title'],
        ];
    }
    return $rows;
}

/**
 * Upload instructions for one field key (per-field → type fallback → global default).
 */
function aapg_content_images_instruction_for_field(string $kind, string $field_key): string {
    $settings = get_option(AAPG_OPTION_KEY, []);
    if (!is_array($settings)) {
        $settings = [];
    }
    $field_key = trim($field_key);
    if ($field_key === '') {
        return '';
    }
    $map = $settings['content_images_field_instructions'] ?? null;
    if (is_array($map) && isset($map[$kind][$field_key])) {
        $t = trim((string) $map[$kind][$field_key]);
        if ($t !== '') {
            return $t;
        }
    }
    return aapg_content_images_upload_instructions_for_kind($kind);
}

/**
 * Add upload_instructions to each field row (matches field_key).
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function aapg_content_images_enrich_fields_with_instructions(array $rows, string $kind): array {
    foreach ($rows as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $fk = isset($row['field_key']) ? (string) $row['field_key'] : '';
        if ($fk === '' && isset($row['field_name'])) {
            $fk = (string) $row['field_name'];
        }
        $rows[$i]['upload_instructions'] = $fk !== ''
            ? aapg_content_images_instruction_for_field($kind, $fk)
            : '';
    }
    return $rows;
}

/**
 * @param list<string> $path_segments Names / row indices for slot_key.
 * @return list<array{slot_key:string,source:string,attachment_id:int,url:string,alt:string,title:string,field_label:string,acf_path:string,api_field_key:string}>
 */
function aapg_acf_collect_media_slots(int $post_id, string $field_group_key): array {
    if ($field_group_key === '' || !function_exists('acf_get_fields')) {
        return [];
    }
    $fields = acf_get_fields($field_group_key);
    if (!is_array($fields)) {
        return [];
    }
    $slots = [];
    aapg_acf_collect_walk($post_id, $fields, [], null, $slots);
    return $slots;
}

/**
 * All image + gallery fields in a group (including empty), for GET ?type=stub|hub.
 *
 * @return list<array{field_key:string,field_name:string,field_title:string,value:string,api_field_key:string,slot_key:string}>
 */
function aapg_acf_collect_media_field_entries_for_rest(int $post_id, string $field_group_key): array {
    if ($field_group_key === '' || !function_exists('acf_get_fields')) {
        return [];
    }
    $fields = acf_get_fields($field_group_key);
    if (!is_array($fields)) {
        return [];
    }
    $entries = [];
    aapg_acf_collect_media_field_entries_walk($post_id, $fields, [], null, $entries);
    return $entries;
}

/**
 * @param list<array<string,mixed>> $entries
 */
function aapg_acf_collect_media_field_entries_walk(int $post_id, array $field_defs, array $path_segments, $parent_row, array &$entries): void {
    foreach ($field_defs as $field) {
        $name = isset($field['name']) ? (string) $field['name'] : '';
        if ($name === '') {
            continue;
        }
        $type = $field['type'] ?? '';

        if ($parent_row === null) {
            $raw = get_field($name, $post_id);
        } else {
            $raw = is_array($parent_row) ? ($parent_row[$name] ?? null) : null;
        }

        $segs = array_merge($path_segments, [$name]);
        $label = (string) ($field['label'] ?? $name);

        if ($type === 'image') {
            $aid = aapg_acf_normalize_attachment_id($raw);
            $url = '';
            if ($aid && aapg_validate_attachment_is_image($aid)) {
                $url = (string) wp_get_attachment_url($aid);
            }
            $api_key = aapg_acf_api_field_key($field, $segs, 'image');
            $entries[] = [
                'field_key'     => $api_key,
                'field_name'    => $name,
                'field_title'   => $label,
                'value'         => $url,
                'api_field_key' => $api_key,
                'slot_key'      => 'acf:' . implode(':', $segs),
            ];
            continue;
        }

        if ($type === 'gallery') {
            $ids = aapg_acf_normalize_gallery_ids($raw);
            if ($ids === []) {
                $gsegs = array_merge($segs, ['0']);
                $api_key = aapg_acf_api_field_key($field, $gsegs, 'gallery');
                $entries[] = [
                    'field_key'     => $api_key,
                    'field_name'    => $name,
                    'field_title'   => $label,
                    'value'         => '',
                    'api_field_key' => $api_key,
                    'slot_key'      => 'acf:' . implode(':', $gsegs),
                ];
                continue;
            }
            foreach ($ids as $idx => $aid) {
                $url = '';
                if ($aid && aapg_validate_attachment_is_image($aid)) {
                    $url = (string) wp_get_attachment_url($aid);
                }
                $gsegs = array_merge($segs, [(string) $idx]);
                $api_key = aapg_acf_api_field_key($field, $gsegs, 'gallery');
                $entries[] = [
                    'field_key'     => $api_key,
                    'field_name'    => $name,
                    'field_title'   => $label . ' #' . ($idx + 1),
                    'value'         => $url,
                    'api_field_key' => $api_key,
                    'slot_key'      => 'acf:' . implode(':', $gsegs),
                ];
            }
            continue;
        }

        if ($type === 'group') {
            $row = is_array($raw) ? $raw : [];
            aapg_acf_collect_media_field_entries_walk($post_id, $field['sub_fields'] ?? [], $segs, $row, $entries);
            continue;
        }

        if ($type === 'repeater' && is_array($raw)) {
            foreach ($raw as $row_index => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rsegs = array_merge($segs, [(string) (int) $row_index]);
                aapg_acf_collect_media_field_entries_walk($post_id, $field['sub_fields'] ?? [], $rsegs, $row, $entries);
            }
        }
    }
}

/**
 * Image/gallery fields for an ACF group without reading post meta (empty values).
 * Uses one synthetic repeater row (index 0) so nested fields are listed like a new post.
 *
 * @return list<array{field_key:string,field_name:string,field_title:string,value:string,api_field_key:string,slot_key:string,is_repeater:bool,repeater_path:string,example_index:int|null}>
 */
function aapg_acf_collect_media_field_entries_schema_only(string $field_group_key): array {
    if ($field_group_key === '' || !function_exists('acf_get_fields')) {
        return [];
    }
    $fields = acf_get_fields($field_group_key);
    if (!is_array($fields)) {
        return [];
    }
    $entries = [];
    aapg_acf_collect_media_field_entries_schema_walk($fields, [], $entries);
    return $entries;
}

/**
 * @param list<array<string,mixed>> $entries
 */
function aapg_acf_collect_media_field_entries_schema_walk(array $field_defs, array $path_segments, array &$entries): void {
    foreach ($field_defs as $field) {
        $name = isset($field['name']) ? (string) $field['name'] : '';
        if ($name === '') {
            continue;
        }
        $type = $field['type'] ?? '';
        $segs = array_merge($path_segments, [$name]);
        $label = (string) ($field['label'] ?? $name);

        if ($type === 'image') {
            $api_key = aapg_acf_api_field_key($field, $segs, 'image');
            $meta = aapg_acf_entry_ui_meta_from_segments($segs);
            $entries[] = [
                'field_key'     => $api_key,
                'field_name'    => $name,
                'field_title'   => $label,
                'value'         => '',
                'api_field_key' => $api_key,
                'slot_key'      => 'acf:' . implode(':', $segs),
                'is_repeater'   => $meta['is_repeater'],
                'repeater_path' => $meta['repeater_path'],
                'example_index' => $meta['example_index'],
            ];
            continue;
        }

        if ($type === 'gallery') {
            $gsegs = array_merge($segs, ['0']);
            $api_key = aapg_acf_api_field_key($field, $gsegs, 'gallery');
            $meta = aapg_acf_entry_ui_meta_from_segments($gsegs);
            $entries[] = [
                'field_key'     => $api_key,
                'field_name'    => $name,
                'field_title'   => $label,
                'value'         => '',
                'api_field_key' => $api_key,
                'slot_key'      => 'acf:' . implode(':', $gsegs),
                'is_repeater'   => $meta['is_repeater'],
                'repeater_path' => $meta['repeater_path'],
                'example_index' => $meta['example_index'],
            ];
            continue;
        }

        if ($type === 'group') {
            aapg_acf_collect_media_field_entries_schema_walk($field['sub_fields'] ?? [], $segs, $entries);
            continue;
        }

        if ($type === 'repeater') {
            $rsegs = array_merge($segs, ['0']);
            aapg_acf_collect_media_field_entries_schema_walk($field['sub_fields'] ?? [], $rsegs, $entries);
        }
    }
}

/**
 * @param mixed $parent_row Group row array or repeater row array; null at root.
 * @param list<array<string,mixed>> $slots
 */
function aapg_acf_collect_walk(int $post_id, array $field_defs, array $path_segments, $parent_row, array &$slots): void {
    foreach ($field_defs as $field) {
        $name = isset($field['name']) ? (string) $field['name'] : '';
        if ($name === '') {
            continue;
        }
        $type = $field['type'] ?? '';

        if ($parent_row === null) {
            $raw = get_field($name, $post_id);
        } else {
            $raw = is_array($parent_row) ? ($parent_row[$name] ?? null) : null;
        }

        $segs = array_merge($path_segments, [$name]);

        if ($type === 'image') {
            $aid = aapg_acf_normalize_attachment_id($raw);
            if ($aid && aapg_validate_attachment_is_image($aid)) {
                $url = (string) wp_get_attachment_url($aid);
                $slots[] = [
                    'slot_key'       => 'acf:' . implode(':', $segs),
                    'source'         => 'acf',
                    'attachment_id'  => $aid,
                    'url'            => $url,
                    'alt'            => (string) get_post_meta($aid, '_wp_attachment_image_alt', true),
                    'title'          => get_the_title($aid),
                    'field_label'    => (string) ($field['label'] ?? $name),
                    'acf_path'       => implode(' → ', $segs),
                    'api_field_key'  => aapg_acf_api_field_key($field, $segs, 'image'),
                ];
            }
            continue;
        }

        if ($type === 'gallery') {
            $ids = aapg_acf_normalize_gallery_ids($raw);
            foreach ($ids as $idx => $aid) {
                if (!aapg_validate_attachment_is_image($aid)) {
                    continue;
                }
                $url = (string) wp_get_attachment_url($aid);
                $gsegs = array_merge($segs, [(string) $idx]);
                $slots[] = [
                    'slot_key'       => 'acf:' . implode(':', $gsegs),
                    'source'         => 'acf',
                    'attachment_id'  => $aid,
                    'url'            => $url,
                    'alt'            => (string) get_post_meta($aid, '_wp_attachment_image_alt', true),
                    'title'          => get_the_title($aid),
                    'field_label'    => (string) ($field['label'] ?? $name) . ' #' . ($idx + 1),
                    'acf_path'       => implode(' → ', $segs) . ' [' . $idx . ']',
                    'api_field_key'  => aapg_acf_api_field_key($field, $gsegs, 'gallery'),
                ];
            }
            continue;
        }

        if ($type === 'group') {
            $row = is_array($raw) ? $raw : [];
            aapg_acf_collect_walk($post_id, $field['sub_fields'] ?? [], $segs, $row, $slots);
            continue;
        }

        if ($type === 'repeater' && is_array($raw)) {
            foreach ($raw as $row_index => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rsegs = array_merge($segs, [(string) (int) $row_index]);
                aapg_acf_collect_walk($post_id, $field['sub_fields'] ?? [], $rsegs, $row, $slots);
            }
        }
    }
}

/**
 * Resolve an ACF slot_key from GET to update instructions.
 *
 * @return array<string,mixed>|null
 */
function aapg_acf_parse_slot_key(string $field_group_key, string $slot_key): ?array {
    if (strpos($slot_key, 'acf:') !== 0) {
        return null;
    }
    $body = substr($slot_key, 4);
    if ($body === '') {
        return null;
    }
    $segs = explode(':', $body);
    $fields = acf_get_fields($field_group_key);
    if (!is_array($fields)) {
        return null;
    }
    return aapg_acf_parse_segments($fields, $segs, 0, []);
}

/**
 * @param list<string> $prefix Built selector prefix for update_sub_field (1-based repeater indices).
 * @return array<string,mixed>|null
 */
function aapg_acf_parse_segments(array $fields, array $segs, int $i, array $prefix) {
    if ($i >= count($segs)) {
        return null;
    }
    $name = $segs[$i];
    foreach ($fields as $f) {
        if (($f['name'] ?? '') !== $name) {
            continue;
        }
        $t = $f['type'] ?? '';
        if ($t === 'image') {
            if ($i !== count($segs) - 1) {
                return null;
            }
            if ($prefix === []) {
                return ['op' => 'field', 'name' => $name];
            }
            return ['op' => 'subfield', 'selector' => array_merge($prefix, [$name])];
        }
        if ($t === 'gallery') {
            if ($i !== count($segs) - 2) {
                return null;
            }
            if (!isset($segs[$i + 1]) || !is_numeric($segs[$i + 1])) {
                return null;
            }
            return ['op' => 'gallery', 'name' => $name, 'index' => (int) $segs[$i + 1]];
        }
        if ($t === 'group') {
            return aapg_acf_parse_segments($f['sub_fields'] ?? [], $segs, $i + 1, array_merge($prefix, [$name]));
        }
        if ($t === 'repeater') {
            if ($i + 1 >= count($segs) || !is_numeric($segs[$i + 1])) {
                return null;
            }
            $row = (int) $segs[$i + 1];
            if ($row < 0) {
                return null;
            }
            $next_prefix = array_merge($prefix, [$name, $row + 1]);
            return aapg_acf_parse_segments($f['sub_fields'] ?? [], $segs, $i + 2, $next_prefix);
        }
    }
    return null;
}

/**
 * @param array<string,int|string> $acf_map slot_key => attachment_id or attachment URL
 * @return true|\WP_Error
 */
function aapg_acf_apply_slot_updates(int $post_id, string $field_group_key, array $acf_map) {
    if ($field_group_key === '' || !function_exists('update_field') || !function_exists('update_sub_field')) {
        return new \WP_Error('aapg_acf_unavailable', __('ACF is not available.', 'aapg'), ['status' => 500]);
    }

    // Only allow slot keys that already exist for this post.
    // This prevents accidental repeater row creation from arbitrary indices.
    $allowed_slot_keys = [];
    foreach (aapg_acf_collect_media_field_entries_for_rest($post_id, $field_group_key) as $entry) {
        $k = isset($entry['slot_key']) ? (string) $entry['slot_key'] : '';
        if ($k !== '') {
            $allowed_slot_keys[$k] = true;
        }
    }

    $gallery_pending = [];

    foreach ($acf_map as $slot_key => $new_raw) {
        $slot_key = (string) $slot_key;
        if (!isset($allowed_slot_keys[$slot_key])) {
            // Ignore non-existing slot keys to allow partial updates.
            continue;
        }

        $new_id = aapg_resolve_image_url_or_attachment_id($new_raw);
        if (is_wp_error($new_id)) {
            return $new_id;
        }

        $parsed = aapg_acf_parse_slot_key($field_group_key, $slot_key);
        if (!$parsed) {
            // Ignore unparseable keys; update the rest.
            continue;
        }

        if ($parsed['op'] === 'gallery') {
            $gname = $parsed['name'];
            if (!isset($gallery_pending[$gname])) {
                $gallery_pending[$gname] = [];
            }
            $gallery_pending[$gname][(int) $parsed['index']] = $new_id;
            continue;
        }

        if ($parsed['op'] === 'field') {
            if ($new_id === 0) {
                update_field($parsed['name'], false, $post_id);
            } else {
                update_field($parsed['name'], $new_id, $post_id);
            }
            continue;
        }

        if ($parsed['op'] === 'subfield') {
            $sel = $parsed['selector'];
            if ($new_id === 0) {
                update_sub_field($sel, false, $post_id);
            } else {
                update_sub_field($sel, $new_id, $post_id);
            }
        }
    }

    foreach ($gallery_pending as $gname => $index_map) {
        $current = get_field($gname, $post_id);
        $ids = aapg_acf_normalize_gallery_ids($current);
        if ($ids === [] && $current !== null && $current !== false && $current !== '') {
            return new \WP_Error('aapg_gallery_read', sprintf(/* translators: %s field */ __('Could not read gallery field: %s', 'aapg'), $gname), ['status' => 400]);
        }
        foreach ($index_map as $idx => $nid) {
            if (!isset($ids[$idx])) {
                // Ignore missing gallery indexes; update any valid indexes.
                continue;
            }
            if ($nid > 0) {
                $ids[$idx] = $nid;
            } else {
                unset($ids[$idx]);
                $ids = array_values($ids);
            }
        }
        update_field($gname, $ids, $post_id);
    }

    return true;
}

/**
 * Featured image slot for research/blog.
 *
 * @return array{slot_key:string,source:string,attachment_id:int,url:string,alt:string,title:string,field_label:string}
 */
function aapg_featured_collect_slot(int $post_id): array {
    $thumb_id = (int) get_post_thumbnail_id($post_id);
    if ($thumb_id > 0 && !aapg_validate_attachment_is_image($thumb_id)) {
        $thumb_id = 0;
    }
    $url = $thumb_id ? (string) wp_get_attachment_url($thumb_id) : '';
    return [
        'slot_key'       => 'featured',
        'source'         => 'featured',
        'attachment_id'  => $thumb_id,
        'url'            => $url,
        'alt'            => $thumb_id ? (string) get_post_meta($thumb_id, '_wp_attachment_image_alt', true) : '',
        'title'          => $thumb_id ? get_the_title($thumb_id) : '',
        'field_label'    => __('Featured image', 'aapg'),
    ];
}

/**
 * Static REST keys for video thumbnail URL and video page/embed URL (slug-independent links).
 *
 * @return list<string>
 */
function aapg_video_static_field_names(): array {
    return ['video_thumbnail', 'video_link'];
}

/**
 * @return list<array{canonical_name:string,field_key:string,field_name:string,field_title:string}>
 */
function aapg_video_static_field_definitions(): array {
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $defaults = [
        'video_thumbnail' => [
            'canonical_name' => 'video_thumbnail',
            'field_key'      => 'video_thumbnail',
            'field_name'     => 'video_thumbnail',
            'field_title'    => __('Video thumbnail', 'aapg'),
        ],
        'video_link' => [
            'canonical_name' => 'video_link',
            'field_key'      => 'video_link',
            'field_name'     => 'video_link',
            'field_title'    => __('Video link', 'aapg'),
        ],
    ];
    $defs = $defaults;
    $group_key = aapg_content_images_video_acf_group_key();
    if ($group_key !== '' && function_exists('acf_get_fields')) {
        foreach (aapg_video_static_field_names() as $canonical_name) {
            $f = aapg_video_find_field_in_group_by_name($group_key, $canonical_name);
            if ($f === null) {
                continue;
            }
            $type = isset($f['type']) ? (string) $f['type'] : '';
            if (!aapg_video_acf_type_allowed_for_name($canonical_name, $type)) {
                continue;
            }
            $fk = isset($f['key']) ? (string) $f['key'] : '';
            $fn = isset($f['name']) ? (string) $f['name'] : '';
            $label = isset($f['label']) ? (string) $f['label'] : $defaults[$canonical_name]['field_title'];
            $defs[$canonical_name] = [
                'canonical_name' => $canonical_name,
                'field_key'      => $fk !== '' ? $fk : $canonical_name,
                'field_name'     => $fn !== '' ? $fn : $canonical_name,
                'field_title'    => $label !== '' ? $label : $defaults[$canonical_name]['field_title'],
            ];
        }
    }
    $cache = array_values($defs);
    return $cache;
}

/**
 * @return list<string>
 */
function aapg_video_static_field_keys(): array {
    $keys = [];
    foreach (aapg_video_static_field_definitions() as $def) {
        $k = isset($def['field_key']) ? (string) $def['field_key'] : '';
        if ($k !== '') {
            $keys[] = $k;
        }
    }
    return $keys;
}

/**
 * Accepted request keys for video fields: field_key + canonical name + field_name.
 *
 * @return list<string>
 */
function aapg_video_static_request_keys(): array {
    $keys = [];
    foreach (aapg_video_static_field_definitions() as $def) {
        foreach (['field_key', 'canonical_name', 'field_name'] as $part) {
            $k = isset($def[$part]) ? trim((string) $def[$part]) : '';
            if ($k !== '') {
                $keys[$k] = true;
            }
        }
    }
    return array_keys($keys);
}

/**
 * @return array{canonical_name:string,field_key:string,field_name:string,field_title:string}|null
 */
function aapg_video_static_field_def_by_identifier(string $identifier): ?array {
    $id = trim($identifier);
    if ($id === '') {
        return null;
    }
    foreach (aapg_video_static_field_definitions() as $def) {
        $ck = isset($def['canonical_name']) ? (string) $def['canonical_name'] : '';
        $fk = isset($def['field_key']) ? (string) $def['field_key'] : '';
        $fn = isset($def['field_name']) ? (string) $def['field_name'] : '';
        if ($id === $ck || $id === $fk || $id === $fn) {
            return $def;
        }
    }
    return null;
}

/**
 * Find field definition by name within one ACF field group (supports nested group/repeater).
 *
 * @return array<string,mixed>|null
 */
function aapg_video_find_field_in_group_by_name(string $field_group_key, string $name): ?array {
    if ($field_group_key === '' || !function_exists('acf_get_fields')) {
        return null;
    }
    $fields = acf_get_fields($field_group_key);
    if (!is_array($fields)) {
        return null;
    }
    return aapg_acf_find_field_def_in_tree_by_name($fields, $name);
}

/**
 * @param array<int, array<string,mixed>> $fields
 * @return array<string,mixed>|null
 */
function aapg_acf_find_field_def_in_tree_by_name(array $fields, string $name): ?array {
    foreach ($fields as $f) {
        if (!is_array($f)) {
            continue;
        }
        if (($f['name'] ?? '') === $name) {
            return $f;
        }
        if (($f['type'] ?? '') === 'group' && !empty($f['sub_fields'])) {
            $found = aapg_acf_find_field_def_in_tree_by_name($f['sub_fields'], $name);
            if ($found !== null) {
                return $found;
            }
        }
        if (($f['type'] ?? '') === 'repeater' && !empty($f['sub_fields'])) {
            $found = aapg_acf_find_field_def_in_tree_by_name($f['sub_fields'], $name);
            if ($found !== null) {
                return $found;
            }
        }
    }
    return null;
}

/**
 * Whether an ACF field type is allowed for video_thumbnail / video_link REST keys.
 */
function aapg_video_acf_type_allowed_for_name(string $name, string $type): bool {
    $type = strtolower($type);
    if ($name === 'video_thumbnail') {
        return in_array($type, ['image', 'url', 'text'], true);
    }
    if ($name === 'video_link') {
        return in_array($type, ['url', 'link', 'text', 'textarea'], true);
    }
    return false;
}

/**
 * DFS: find first field named $name on this post's field groups (any depth under group/repeater).
 *
 * @return array{field: array<string,mixed>, ancestors: list<array<string,mixed>>}|null
 */
function aapg_video_find_field_leaf_for_post(int $post_id, string $name): ?array {
    if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
        return null;
    }
    $video_group_key = aapg_content_images_video_acf_group_key();
    if ($video_group_key !== '') {
        $video_fields = acf_get_fields($video_group_key);
        if (is_array($video_fields)) {
            $found = aapg_video_dfs_find_leaf($video_fields, [], $name);
            if ($found !== null) {
                return $found;
            }
        }
    }
    $groups = acf_get_field_groups(['post_id' => $post_id]);
    if (!is_array($groups)) {
        return null;
    }
    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }
        $gkey = isset($group['key']) ? (string) $group['key'] : '';
        if ($gkey === '' && !empty($group['ID'])) {
            $fields = acf_get_fields((int) $group['ID']);
        } else {
            $fields = $gkey !== '' ? acf_get_fields($gkey) : null;
        }
        if (!is_array($fields)) {
            continue;
        }
        $found = aapg_video_dfs_find_leaf($fields, [], $name);
        if ($found !== null) {
            return $found;
        }
    }
    return null;
}

/**
 * @param list<array<string,mixed>> $ancestors
 * @return array{field: array<string,mixed>, ancestors: list<array<string,mixed>>}|null
 */
function aapg_video_dfs_find_leaf(array $fields, array $ancestors, string $name): ?array {
    foreach ($fields as $f) {
        if (!is_array($f)) {
            continue;
        }
        $nm = (string) ($f['name'] ?? '');
        $ty = (string) ($f['type'] ?? '');
        if ($nm === $name) {
            return ['field' => $f, 'ancestors' => $ancestors];
        }
        if ($ty === 'group') {
            $sub = aapg_video_dfs_find_leaf($f['sub_fields'] ?? [], array_merge($ancestors, [$f]), $name);
            if ($sub !== null) {
                return $sub;
            }
        }
        if ($ty === 'repeater') {
            $sub = aapg_video_dfs_find_leaf($f['sub_fields'] ?? [], array_merge($ancestors, [$f]), $name);
            if ($sub !== null) {
                return $sub;
            }
        }
    }
    return null;
}

/**
 * Build update_sub_field selector (field keys) or null when leaf is at group root.
 *
 * @param list<array<string,mixed>> $ancestors
 * @param array<string,mixed>       $leaf
 * @return list<mixed>|null
 */
function aapg_video_build_subfield_selector(array $ancestors, array $leaf): ?array {
    if ($ancestors === []) {
        return null;
    }
    $sel = [];
    foreach ($ancestors as $anc) {
        $atype = (string) ($anc['type'] ?? '');
        if ($atype === 'repeater') {
            $ak = (string) ($anc['key'] ?? '');
            if ($ak === '') {
                return null;
            }
            $sel[] = $ak;
            $sel[] = 1;
        } elseif ($atype === 'group') {
            $ak = (string) ($anc['key'] ?? '');
            if ($ak === '') {
                return null;
            }
            $sel[] = $ak;
        } else {
            return null;
        }
    }
    $lk = (string) ($leaf['key'] ?? '');
    if ($lk === '') {
        return null;
    }
    $sel[] = $lk;
    return $sel;
}

/**
 * Ensure first row exists for the first repeater in the ancestor chain (root-level repeater only).
 * Nested repeaters may require manual row data; first row is created empty when missing.
 */
function aapg_video_ensure_first_repeater_row(int $post_id, array $ancestors): void {
    if ($ancestors === [] || !function_exists('add_row') || !function_exists('get_field')) {
        return;
    }
    $first = $ancestors[0];
    if ((string) ($first['type'] ?? '') !== 'repeater') {
        return;
    }
    $fname = (string) ($first['name'] ?? '');
    if ($fname === '') {
        return;
    }
    $rows = get_field($fname, $post_id);
    if (!is_array($rows) || $rows === []) {
        add_row($fname, [], $post_id);
    }
}

/**
 * Read raw ACF value for a leaf (supports group/repeater nesting).
 *
 * @param list<array<string,mixed>> $ancestors
 * @param array<string,mixed>       $leaf
 * @return mixed
 */
function aapg_video_read_raw_value(int $post_id, array $ancestors, array $leaf) {
    if ($ancestors === []) {
        return get_field($leaf['name'] ?? '', $post_id);
    }
    return aapg_video_read_raw_from_ancestors($post_id, $ancestors, $leaf);
}

/**
 * @param list<array<string,mixed>> $ancestors
 */
function aapg_video_read_raw_from_ancestors(int $post_id, array $ancestors, array $leaf) {
    $first = $ancestors[0];
    $rest = array_slice($ancestors, 1);
    $ftype = (string) ($first['type'] ?? '');
    if ($ftype === 'repeater') {
        $rows = get_field((string) ($first['name'] ?? ''), $post_id);
        if (!is_array($rows) || !isset($rows[0])) {
            return null;
        }
        $row = $rows[0];
        if ($rest === []) {
            return $row[$leaf['name'] ?? ''] ?? null;
        }
        return aapg_video_read_raw_from_row($row, $rest, $leaf);
    }
    if ($ftype === 'group') {
        $g = get_field((string) ($first['name'] ?? ''), $post_id);
        if (!is_array($g)) {
            return null;
        }
        if ($rest === []) {
            return $g[$leaf['name'] ?? ''] ?? null;
        }
        return aapg_video_read_raw_from_row($g, $rest, $leaf);
    }
    return null;
}

/**
 * @param list<array<string,mixed>> $ancestors
 */
function aapg_video_read_raw_from_row(array $row, array $ancestors, array $leaf) {
    if ($ancestors === []) {
        return $row[$leaf['name'] ?? ''] ?? null;
    }
    $first = $ancestors[0];
    $rest = array_slice($ancestors, 1);
    $ftype = (string) ($first['type'] ?? '');
    if ($ftype === 'repeater') {
        $rows = $row[$first['name'] ?? ''] ?? null;
        if (!is_array($rows) || !isset($rows[0])) {
            return null;
        }
        if ($rest === []) {
            return $rows[0][$leaf['name'] ?? ''] ?? null;
        }
        return aapg_video_read_raw_from_row($rows[0], $rest, $leaf);
    }
    if ($ftype === 'group') {
        $g = $row[$first['name'] ?? ''] ?? null;
        if (!is_array($g)) {
            return null;
        }
        if ($rest === []) {
            return $g[$leaf['name'] ?? ''] ?? null;
        }
        return aapg_video_read_raw_from_row($g, $rest, $leaf);
    }
    return null;
}

/**
 * Normalize raw ACF value to a URL string for the REST API.
 */
function aapg_video_format_raw_for_api(string $type, $raw): string {
    $type = strtolower($type);
    if ($type === 'url' || $type === 'text' || $type === 'textarea') {
        return is_string($raw) ? $raw : '';
    }
    if ($type === 'link' && is_array($raw)) {
        return isset($raw['url']) ? (string) $raw['url'] : '';
    }
    if ($type === 'image') {
        if (is_string($raw)) {
            $u = trim($raw);
            if ($u !== '') {
                return $u;
            }
        }
        if (is_array($raw) && isset($raw['url']) && is_string($raw['url'])) {
            $u = trim((string) $raw['url']);
            if ($u !== '') {
                return $u;
            }
        }
        $aid = aapg_acf_normalize_attachment_id($raw);
        if ($aid && aapg_validate_attachment_is_image($aid)) {
            return (string) wp_get_attachment_url($aid);
        }
        return '';
    }
    if (is_string($raw)) {
        return $raw;
    }
    if (is_scalar($raw) && $raw !== null) {
        return (string) $raw;
    }
    return '';
}

/**
 * Delete legacy plugin post meta if present (migration off fallback storage).
 */
function aapg_video_delete_legacy_meta(int $post_id, string $name): void {
    delete_post_meta($post_id, 'aapg_' . $name);
}

/**
 * Schema rows for GET content-images/info (empty values).
 *
 * @return list<array<string,mixed>>
 */
function aapg_video_static_info_rows_empty(): array {
    $rows = [];
    foreach (aapg_video_static_field_definitions() as $def) {
        $rows[] = [
            'field_key'     => (string) $def['field_key'],
            'field_name'    => (string) $def['field_name'],
            'field_title'   => (string) $def['field_title'],
            'value'         => '',
            'is_repeater'   => false,
            'repeater_path' => '',
            'example_index' => null,
        ];
    }
    return $rows;
}

/**
 * Same rows with current values for GET ai-content/{id}/images?type=…
 *
 * @return list<array<string,mixed>>
 */
function aapg_video_static_field_rows_for_post(int $post_id): array {
    $rows = aapg_video_static_info_rows_empty();
    foreach ($rows as $i => $row) {
        $identifier = isset($row['field_key']) ? (string) $row['field_key'] : '';
        if ($identifier !== '') {
            $rows[$i]['value'] = aapg_get_video_static_field_value($post_id, $identifier);
        }
    }
    return $rows;
}

/**
 * Minimal field rows (field_key, field_name, field_title, value) for GET ai-content list payloads.
 *
 * @return list<array{field_key:string,field_name:string,field_title:string,value:string}>
 */
function aapg_video_static_field_rows_for_post_compact(int $post_id): array {
    $rows = [];
    foreach (aapg_video_static_field_definitions() as $def) {
        $canonical = (string) ($def['canonical_name'] ?? '');
        if ($canonical === '') {
            continue;
        }
        $rows[] = [
            'field_key'   => (string) $def['field_key'],
            'field_name'  => (string) $def['field_name'],
            'field_title' => (string) $def['field_title'],
            'value'       => aapg_get_video_static_field_value($post_id, $canonical),
        ];
    }
    return $rows;
}

/**
 * Append static video_thumbnail / video_link rows only when no row already uses that field_name (ACF may define the same fields).
 *
 * @param list<array<string,mixed>> $rows
 * @param int|null $post_id When set, values from ACF; when null, empty schema rows (GET content-images/info).
 * @return list<array<string,mixed>>
 */
function aapg_video_merge_static_rows_into_field_list(array $rows, ?int $post_id = null): array {
    $present = [];
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $k = isset($r['field_key']) ? (string) $r['field_key'] : '';
        if ($k !== '') {
            $present[$k] = true;
        }
    }
    $video_rows = $post_id !== null
        ? aapg_video_static_field_rows_for_post_compact($post_id)
        : aapg_video_static_info_rows_empty();
    foreach ($video_rows as $vrow) {
        $k = isset($vrow['field_key']) ? (string) $vrow['field_key'] : '';
        if ($k === '' || !empty($present[$k])) {
            continue;
        }
        $rows[] = $vrow;
        $present[$k] = true;
    }
    return $rows;
}

/**
 * Flat response keys video_thumbnail / video_link only when no ACF entry shares that field name.
 *
 * @param list<array<string,mixed>> $acf_entries
 * @return array<string, string>
 */
function aapg_video_static_field_values_if_missing_by_name(int $post_id, array $acf_entries): array {
    $present = [];
    foreach ($acf_entries as $e) {
        if (!is_array($e)) {
            continue;
        }
        $k = isset($e['field_key']) ? (string) $e['field_key'] : '';
        if ($k !== '') {
            $present[$k] = true;
        }
    }
    $out = [];
    foreach (aapg_video_static_field_definitions() as $def) {
        $k = isset($def['field_key']) ? (string) $def['field_key'] : '';
        $canonical = isset($def['canonical_name']) ? (string) $def['canonical_name'] : '';
        if ($k === '' || $canonical === '' || !empty($present[$k])) {
            continue;
        }
        $out[$k] = aapg_get_video_static_field_value($post_id, $canonical);
    }
    return $out;
}

/**
 * Read value as URL string from ACF only (nested fields supported).
 */
function aapg_get_video_static_field_value(int $post_id, string $key): string {
    $def = aapg_video_static_field_def_by_identifier($key);
    if ($def === null) {
        return '';
    }
    $canonical = (string) ($def['canonical_name'] ?? '');
    if ($canonical === '') {
        return '';
    }
    $resolved = aapg_video_find_field_leaf_for_post($post_id, $canonical);
    if ($resolved === null) {
        return (string) get_post_meta($post_id, $canonical, true);
    }
    $leaf = $resolved['field'];
    $ancestors = $resolved['ancestors'];
    $type = (string) ($leaf['type'] ?? '');
    $raw = aapg_video_read_raw_value($post_id, $ancestors, $leaf);
    $acf_str = aapg_video_format_raw_for_api($type, $raw);
    if ($acf_str !== '') {
        return $acf_str;
    }
    return (string) get_post_meta($post_id, $canonical, true);
}

/**
 * Save URL or arbitrary string (or clear). Uses ACF when a matching field exists; otherwise post meta on $key.
 * Does not fail when ACF is missing, misconfigured, or uses an unexpected field type.
 *
 * @param mixed $value URL string, empty string, or null to clear.
 * @return true|\WP_Error
 */
function aapg_set_video_static_field_value(int $post_id, string $key, $value) {
    $def = aapg_video_static_field_def_by_identifier($key);
    if ($def === null) {
        return true;
    }
    $canonical = (string) ($def['canonical_name'] ?? '');
    if ($canonical === '') {
        return true;
    }
    if ($value === null || $value === false) {
        $clear = true;
    } elseif (is_string($value) || is_int($value) || is_float($value)) {
        $value = trim((string) $value);
        $clear = ($value === '');
    } else {
        return new \WP_Error('aapg_video_invalid', sprintf(/* translators: %s field */ __('Invalid value for %s.', 'aapg'), $canonical), ['status' => 400]);
    }

    $acf_ok = function_exists('update_field') && function_exists('update_sub_field');
    $resolved = ($acf_ok && function_exists('acf_get_field_groups')) ? aapg_video_find_field_leaf_for_post($post_id, $canonical) : null;

    $persist_post_meta = static function (bool $is_clear, string $stored = '') use ($post_id, $canonical): void {
        if ($is_clear) {
            delete_post_meta($post_id, $canonical);
            return;
        }
        update_post_meta($post_id, $canonical, sanitize_text_field($stored));
    };

    if ($clear) {
        $persist_post_meta(true);
        aapg_video_delete_legacy_meta($post_id, $canonical);
        if ($resolved === null || !$acf_ok) {
            return true;
        }
        $leaf = $resolved['field'];
        $ancestors = $resolved['ancestors'];
        $type = strtolower((string) ($leaf['type'] ?? ''));
        $leaf_key = (string) ($leaf['key'] ?? '');
        if ($leaf_key !== '') {
            aapg_video_ensure_first_repeater_row($post_id, $ancestors);
            $selector = aapg_video_build_subfield_selector($ancestors, $leaf);
            if ($ancestors === [] || ($selector !== null && is_array($selector))) {
                aapg_video_apply_clear_value($type, $selector, $leaf_key, $post_id);
            }
        }
        return true;
    }

    $safe = sanitize_text_field($value);

    if ($resolved === null || !$acf_ok) {
        $persist_post_meta(false, $safe);
        aapg_video_delete_legacy_meta($post_id, $canonical);
        return true;
    }

    $leaf = $resolved['field'];
    $ancestors = $resolved['ancestors'];
    $type = strtolower((string) ($leaf['type'] ?? ''));
    $leaf_key = (string) ($leaf['key'] ?? '');

    if ($leaf_key === '') {
        $persist_post_meta(false, $safe);
        aapg_video_delete_legacy_meta($post_id, $canonical);
        return true;
    }

    aapg_video_ensure_first_repeater_row($post_id, $ancestors);
    $selector = aapg_video_build_subfield_selector($ancestors, $leaf);
    if ($ancestors !== [] && ($selector === null || !is_array($selector))) {
        $persist_post_meta(false, $safe);
        aapg_video_delete_legacy_meta($post_id, $canonical);
        return true;
    }

    $acf_saved = false;
    $use_sub = $ancestors !== [] && !empty($selector) && is_array($selector);

    if ($type === 'link') {
        $link_val = ['url' => $safe, 'title' => '', 'target' => ''];
        $acf_saved = $use_sub ? (bool) update_sub_field($selector, $link_val, $post_id) : (bool) update_field($leaf_key, $link_val, $post_id);
    } elseif ($type === 'image') {
        $rid = aapg_resolve_image_url_or_attachment_id($value);
        if (!is_wp_error($rid) && $rid > 0) {
            $acf_saved = $use_sub ? (bool) update_sub_field($selector, $rid, $post_id) : (bool) update_field($leaf_key, $rid, $post_id);
        }
    } elseif ($type === 'url' || $type === 'text' || $type === 'textarea') {
        $text_val = ($type === 'textarea') ? sanitize_textarea_field($value) : $safe;
        $acf_saved = $use_sub ? (bool) update_sub_field($selector, $text_val, $post_id) : (bool) update_field($leaf_key, $text_val, $post_id);
    } else {
        $acf_saved = $use_sub ? (bool) update_sub_field($selector, $safe, $post_id) : (bool) update_field($leaf_key, $safe, $post_id);
    }

    if ($acf_saved) {
        // Do not delete the canonical meta key here: for root-level ACF fields,
        // ACF stores the actual field value under that exact key (e.g. video_link).
        // Only legacy prefixed plugin meta should be cleaned up below.
    } else {
        $persist_post_meta(false, $safe);
    }
    aapg_video_delete_legacy_meta($post_id, $canonical);
    return true;
}

/**
 * @param list<mixed>|null $selector
 */
function aapg_video_apply_clear_value(string $type, ?array $selector, string $leaf_key, int $post_id): bool {
    if ($type === 'link') {
        $v = ['url' => '', 'title' => '', 'target' => ''];
        if (!empty($selector)) {
            return (bool) update_sub_field($selector, $v, $post_id);
        }
        return (bool) update_field($leaf_key, $v, $post_id);
    }
    if ($type === 'image') {
        if (!empty($selector)) {
            return (bool) update_sub_field($selector, false, $post_id);
        }
        return (bool) update_field($leaf_key, false, $post_id);
    }
    if ($type === 'url' || $type === 'text' || $type === 'textarea') {
        if (!empty($selector)) {
            return (bool) update_sub_field($selector, '', $post_id);
        }
        return (bool) update_field($leaf_key, '', $post_id);
    }
    if (!empty($selector)) {
        return (bool) update_sub_field($selector, '', $post_id);
    }
    return (bool) update_field($leaf_key, '', $post_id);
}

/**
 * Apply video_thumbnail / video_link from POST body when keys are present.
 *
 * @param array<string,mixed> $body
 * @return true|\WP_Error
 */
function aapg_apply_video_static_fields_from_body(int $post_id, array $body) {
    foreach (aapg_video_static_field_definitions() as $def) {
        $field_key = isset($def['field_key']) ? (string) $def['field_key'] : '';
        $canonical = isset($def['canonical_name']) ? (string) $def['canonical_name'] : '';
        $field_name = isset($def['field_name']) ? (string) $def['field_name'] : '';
        if ($field_key === '' || $canonical === '') {
            continue;
        }
        $input_key = '';
        if (array_key_exists($field_key, $body)) {
            $input_key = $field_key;
        } elseif (array_key_exists($canonical, $body)) {
            $input_key = $canonical;
        } elseif ($field_name !== '' && array_key_exists($field_name, $body)) {
            $input_key = $field_name;
        }
        if ($input_key === '') {
            continue;
        }
        $r = aapg_set_video_static_field_value($post_id, $canonical, $body[$input_key]);
        if (is_wp_error($r)) {
            return $r;
        }
    }
    return true;
}

/**
 * Resolve media library attachment ID from empty (clear), numeric ID, or attachment URL on this site.
 *
 * @param mixed $value URL string, numeric ID string, or integer.
 * @return int|\WP_Error 0 = clear; positive = attachment ID.
 */
function aapg_resolve_image_url_or_attachment_id($value) {
    if ($value === null) {
        return 0;
    }
    if (is_bool($value)) {
        return new \WP_Error('aapg_invalid_value', __('Invalid image value.', 'aapg'), ['status' => 400]);
    }
    if (is_int($value) || is_float($value)) {
        $id = (int) $value;
        if ($id < 0) {
            return new \WP_Error('aapg_invalid_id', __('Invalid attachment ID.', 'aapg'), ['status' => 400]);
        }
        if ($id === 0) {
            return 0;
        }
        if (!aapg_validate_attachment_is_image($id)) {
            return new \WP_Error('aapg_invalid_attachment', __('Not a valid image attachment.', 'aapg'), ['status' => 400]);
        }
        return $id;
    }
    $s = trim((string) $value);
    if ($s === '') {
        return 0;
    }
    if (ctype_digit($s)) {
        return aapg_resolve_image_url_or_attachment_id((int) $s);
    }
    $id = attachment_url_to_postid($s);
    if ($id > 0) {
        if (!aapg_validate_attachment_is_image($id)) {
            return new \WP_Error('aapg_invalid_attachment', __('URL does not point to a valid image attachment.', 'aapg'), ['status' => 400]);
        }
        return $id;
    }
    return new \WP_Error(
        'aapg_url_resolve',
        __('Could not resolve image URL to a media attachment on this site. Upload the file first or use a full attachment URL.', 'aapg'),
        ['status' => 400]
    );
}

/**
 * Whether an image field with this name exists under an ACF field group (recursive).
 */
function aapg_acf_is_image_field_name_in_group(string $field_group_key, string $name): bool {
    if ($field_group_key === '' || !function_exists('acf_get_fields')) {
        return false;
    }
    $fields = acf_get_fields($field_group_key);
    if (!is_array($fields)) {
        return false;
    }
    return aapg_acf_find_image_field_def_in_tree($fields, $name) !== null;
}

/**
 * @param array<int, array<string,mixed>> $fields
 */
function aapg_acf_find_image_field_def_in_tree(array $fields, string $name): ?array {
    foreach ($fields as $f) {
        if (!is_array($f)) {
            continue;
        }
        if (($f['name'] ?? '') === $name && ($f['type'] ?? '') === 'image') {
            return $f;
        }
        if (($f['type'] ?? '') === 'group' && !empty($f['sub_fields'])) {
            $found = aapg_acf_find_image_field_def_in_tree($f['sub_fields'], $name);
            if ($found !== null) {
                return $found;
            }
        }
        if (($f['type'] ?? '') === 'repeater' && !empty($f['sub_fields'])) {
            $found = aapg_acf_find_image_field_def_in_tree($f['sub_fields'], $name);
            if ($found !== null) {
                return $found;
            }
        }
    }
    return null;
}

/**
 * Set ACF image fields by field name; values are URLs or attachment IDs (same keys as GET field_name for stub/hub).
 *
 * Do not reserve "feature_image" here: stub/hub ACF groups may use that field name (WordPress featured image is only updated when post type meta is blog/research).
 *
 * @param array<string,mixed> $body
 * @param list<string> $reserved_keys Keys to skip (acf, elementor map, featured_attachment_id).
 * @param string $field_group_key API Mode stub/hub group key for validation when acf_get_field_object is unavailable.
 * @return true|\WP_Error
 */
function aapg_acf_apply_image_fields_from_url_body(int $post_id, array $body, array $reserved_keys, string $field_group_key = '') {
    if (!function_exists('update_field')) {
        return new \WP_Error('aapg_acf_unavailable', __('ACF is not available.', 'aapg'), ['status' => 500]);
    }
    $has_any = false;
    foreach ($body as $key => $_v) {
        if (!is_string($key) || in_array($key, $reserved_keys, true)) {
            continue;
        }
        $has_any = true;
        break;
    }
    if (!$has_any) {
        return true;
    }
    foreach ($body as $key => $value) {
        if (!is_string($key) || in_array($key, $reserved_keys, true)) {
            continue;
        }
        $is_image = false;
        if (function_exists('acf_get_field_object')) {
            $fo = acf_get_field_object($key, $post_id);
            if ($fo && ($fo['type'] ?? '') === 'image') {
                $is_image = true;
            }
        }
        if (!$is_image && $field_group_key !== '') {
            $is_image = aapg_acf_is_image_field_name_in_group($field_group_key, $key);
        }
        if (!$is_image) {
            return new \WP_Error(
                'aapg_unknown_field',
                sprintf(/* translators: %s field name */ __('Not an image field on this post: %s', 'aapg'), $key),
                ['status' => 400]
            );
        }
        $rid = aapg_resolve_image_url_or_attachment_id($value);
        if (is_wp_error($rid)) {
            return $rid;
        }
        if ($rid === 0) {
            update_field($key, false, $post_id);
        } else {
            update_field($key, $rid, $post_id);
        }
    }
    return true;
}

function aapg_featured_apply(int $post_id, int $attachment_id) {
    if ($attachment_id < 0) {
        return new \WP_Error('aapg_invalid_id', __('Invalid attachment ID.', 'aapg'), ['status' => 400]);
    }
    if ($attachment_id > 0 && !aapg_validate_attachment_is_image($attachment_id)) {
        return new \WP_Error('aapg_invalid_attachment', __('Featured image must be a valid image attachment.', 'aapg'), ['status' => 400]);
    }
    if ($attachment_id === 0) {
        delete_post_thumbnail($post_id);
    } else {
        set_post_thumbnail($post_id, $attachment_id);
    }
    return true;
}

/**
 * JSON Schema fragment for POST body (image updates).
 */
function aapg_images_post_body_json_schema(): array {
    $video_keys = aapg_video_static_field_keys();
    $video_thumbnail_key = isset($video_keys[0]) ? (string) $video_keys[0] : 'video_thumbnail';
    $video_link_key = isset($video_keys[1]) ? (string) $video_keys[1] : 'video_link';
    return [
        'type'                 => 'object',
        'additionalProperties' => true,
        'properties'           => [
            'elementor_replacements' => [
                'type'        => 'object',
                'description' => 'Map Elementor attachment ID as string to new attachment ID (integer). Same keys as slot_key for elementor slots.',
                'additionalProperties' => [
                    'type' => 'integer',
                ],
            ],
            'acf' => [
                'type'        => 'object',
                'description' => 'Map ACF slot_key (acf:...) to new attachment ID. Use 0 to clear an image field.',
                'additionalProperties' => [
                    'type' => 'integer',
                ],
            ],
            'featured_attachment_id' => [
                'type'        => 'integer',
                'description' => 'Research/blog only. Set featured image; use 0 to remove.',
            ],
            'feature_image' => [
                'type'        => 'string',
                'description' => 'Research/blog: featured image as attachment URL on this site, or attachment ID. Empty string removes.',
            ],
            $video_thumbnail_key => [
                'type'        => 'string',
                'description' => 'Optional video thumbnail or poster URL. Saved to the configured video ACF field.',
            ],
            $video_link_key => [
                'type'        => 'string',
                'description' => 'Optional video page or embed URL. Saved to the configured video ACF field.',
            ],
        ],
    ];
}
