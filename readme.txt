=== Hub ACF Page Generator (OpenAI) ===
Contributors: csc
Tags: acf, elementor, openai
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later

Generates a child page from an Elementor template category and populates ACF fields using OpenAI JSON Schema.

== Setup ==
1. Install and activate Advanced Custom Fields (ACF) and Elementor.
2. Ensure you have:
   - An ACF Field Group you want to populate.
   - An Elementor template under Templates (elementor_library) assigned to category slug: hubtemplates.
3. Go to Tools -> Hub ACF Page Generator.
4. Save your OpenAI API key and model in Settings section.
5. Fill the form and click Generate.

== Notes ==
- This plugin copies Elementor data from the chosen template to the newly created page.
- It builds a JSON Schema from the selected ACF field group, calls OpenAI Responses API using a Prompt ID, and maps the returned JSON to ACF fields.
