# UI Guide: `GET /wp-json/aapg/v1/content-images/info`

This endpoint gives image field definitions by `type` without requiring a `post_id`.

## Endpoint

- `GET /wp-json/aapg/v1/content-images/info?type=stub`
- Auth:
  - Header: `X-AAPG-API-Key: <key>`
  - or query param: `api_key=<key>`
- Allowed `type`: `researchcenter`, `research`, `blog`, `stub`, `hub`

## Response shape

Returns an array of rows:

- `field_key`: API key expected by backend conventions
- `field_name`: ACF field name (or `feature_image` for blog/researchcenter)
- `field_title`: label for UI
- `value`: always empty string in this endpoint (schema-only)
- `is_repeater`: `true` if field is inside repeater context
- `repeater_path`: dot path for display/grouping (example: `hero.sections.image_block`)
- `example_index`: sample row index (always `0` for schema-only repeater rows)

Example:

```json
[
  {
    "field_key": "field_abc123_0",
    "field_name": "section_image",
    "field_title": "Section Image",
    "value": "",
    "is_repeater": true,
    "repeater_path": "sections.section_image",
    "example_index": 0
  }
]
```

## How UI should handle repeater image fields

1. Treat rows with `is_repeater=true` as **template rows**.
2. Initial row uses `example_index=0`.
3. When user adds rows, clone UI row and generate keys for index `1`, `2`, `3`, ...
4. Keep original row order from API response for stable rendering.

## Practical mapping strategy

- Show label from `field_title`.
- Group optional by `repeater_path`.
- Internal UI model:
  - `field_key_template` = server `field_key`
  - `field_name`
  - `row_index`
- For extra rows, replace trailing row index in key:
  - from `field_xxx_0` to `field_xxx_1`, `field_xxx_2`, ...

## Notes

- This endpoint is for building forms and contracts.
- To fetch real values for a post, use:
  - `GET /wp-json/aapg/v1/ai-content/{POST_ID}/images?type=...`

## Updating repeater images (important)

To update actual images (including repeater rows), use the existing post-based endpoint:

- `POST /wp-json/aapg/v1/ai-content/{POST_ID}/images?type=stub`

### Which key should UI send?

- For updates, backend `acf` map expects **`slot_key`** (not `field_key`).
- `slot_key` format includes repeater row index in path form:
  - `acf:repeater_name:0:image_field`
  - `acf:sections:2:card:image`

Recommended flow:

1. Call `GET /ai-content/{POST_ID}/images?type=stub` to get real rows and their `slot_key`.
2. Let user pick/replace image in any repeater row.
3. Send `POST` with `acf` object mapping `slot_key -> attachment_id`.

### Example payload (replace repeater row images)

```json
{
  "acf": {
    "acf:sections:0:section_image": 3451,
    "acf:sections:1:section_image": 3452
  }
}
```

You can also send attachment URLs (same-site media URLs) instead of IDs:

```json
{
  "acf": {
    "acf:sections:0:section_image": "https://example.com/wp-content/uploads/2026/03/img-a.webp",
    "acf:sections:1:section_image": "https://example.com/wp-content/uploads/2026/03/img-b.webp"
  }
}
```

### Clearing an image

- Send `0` as attachment ID:

```json
{
  "acf": {
    "acf:sections:1:section_image": 0
  }
}
```

### If UI starts from schema-only endpoint

Schema endpoint does not include real `slot_key` per existing post row.  
So for update forms, UI should do both:

1. Build layout from `GET /content-images/info?type=stub` (generic template).
2. Fetch actual post values + row keys from `GET /ai-content/{POST_ID}/images?type=stub`.
3. Use returned `slot_key` values when submitting `POST`.

### Do not send for repeater updates

- Do not send `field_key` in `acf` update map.
- Do not send external/non-media URLs; use same-site media attachment IDs or same-site media URLs.
