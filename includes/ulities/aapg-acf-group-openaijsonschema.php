<?php
/**
 * AAPG ACF Group to JSON Schema Utility
 * Converts ACF field groups to JSON Schema for OpenAI API
 */

namespace AAPG\Utilities;

if (!defined('ABSPATH')) {
    exit;
}

class AAPG_ACF_Group_OpenAIJSONSchema {

    /**
     * Convert ACF field group to JSON Schema
     * 
     * @param string $field_group_key The ACF field group key
     * @return array JSON Schema for the field group
     */
    public static function acf_schema_from_group(string $field_group_key) {
        $fields = acf_get_fields($field_group_key);

        $schema = [
            "type" => "object",
            "properties" => [],
            "additionalProperties" => false,
        ];

        // ACF fields
        if (is_array($fields)) {
            foreach ($fields as $field) {
                $name = isset($field['name']) ? trim((string)$field['name']) : '';
                if ($name === '' || $field['type'] === 'image') {
                    continue; // avoid invalid keys or images
                }

                $schema["properties"][$name] = self::acf_schema_for_field($field);
            }
        }

        // SEO fields
        $schema["properties"]["meta_title"] = [
            "type" => "string",
            "description" => "Meta Title for SEO",
        ];

        $schema["properties"]["meta_description"] = [
            "type" => "string",
            "description" => "Meta Description for SEO",
        ];

        // URL Resolution Table (array of objects)
        $schema["properties"]["URL_RESOLUTION_TABLE"] = [
            "type" => "array",
            "description" => "Array of link label to URL mappings. Include mappings for: [LINK_ALL_LOCATIONS], [LINK_AREAS_WE_SERVE], [LINK_APPOINTMENT_FORM], [LINK_DIRECTIONS_PAGE], [LINK_REVIEWS_PAGE], [LINK_TEAM_PAGE], [LINK_RESEARCH_CENTER]. Do NOT include map links like [LINK_FARMERS_BRANCH_MAP], etc.",
            "items" => [
                "type" => "object",
                "properties" => [
                    "link_label" => [
                        "type" => "string",
                        "description" => "The link label (e.g., [LINK_ALL_LOCATIONS])"
                    ],
                    "link" => [
                        "type" => "string", 
                        "description" => "The URL for this link label"
                    ]
                ],
                "required" => ["link_label", "link"],
                "additionalProperties" => false
            ]
        ];

        // REQUIRED MUST MATCH PROPERTIES EXACTLY (OpenAI schema rule)
        $schema["required"] = array_values(array_keys($schema["properties"]));

        return $schema;
    }

    /**
     * Convert individual ACF field to JSON Schema property
     * 
     * @param array $field ACF field configuration
     * @return array JSON Schema property definition
     */
    private static function acf_schema_for_field(array $f) {
        $type = $f['type'];
        $base = ["description" => $f['label']];

        switch ($type) {
            case 'text':
            case 'textarea':
            case 'wysiwyg':
            case 'email':
            case 'url':
                $base['type'] = 'string';
                break;

            case 'number':
                $base['type'] = 'number';
                break;

            case 'true_false':
                $base['type'] = 'boolean';
                break;

            case 'select':
                $base['type'] = 'string';
                break;

            case 'checkbox':
                $base['type'] = 'array';
                $base['items'] = ['type' => 'string'];
                break;

            case 'repeater':
                $sub_props = [];
                $sub_req = [];

                if (!empty($f['sub_fields'])) {
                    foreach ($f['sub_fields'] as $sub) {
                        if ($sub['type'] === 'image') {
                            continue; // Skip image sub-fields
                        }
                        $sub_props[$sub['name']] = self::acf_schema_for_field($sub);
                        $sub_req[] = $sub['name'];
                    }
                }

                $base['type'] = 'array';
                $base['items'] = [
                    "type" => "object",
                    "properties" => $sub_props,
                    "required" => $sub_req,
                    "additionalProperties" => false
                ];
                break;

            case 'group':
                $sub_props = [];
                $sub_req = [];

                if (!empty($f['sub_fields'])) {
                    foreach ($f['sub_fields'] as $sub) {
                        if ($sub['type'] === 'image') {
                            continue;
                        }
                        $sub_props[$sub['name']] = self::acf_schema_for_field($sub);
                        $sub_req[] = $sub['name'];
                    }
                }

                $base['type'] = 'object';
                $base['properties'] = $sub_props;
                $base['required'] = $sub_req;
                $base['additionalProperties'] = false;
                break;

            default:
                $base['type'] = 'string';
                break;
        }

        return $base;
    }
}