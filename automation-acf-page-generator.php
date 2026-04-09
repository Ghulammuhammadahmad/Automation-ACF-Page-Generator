<?php
/**
 * Plugin Name: Automation ACF Page Generator (OpenAI)
 * Description: Generates a child page from an Elementor template category and populates ACF fields using OpenAI JSON Schema.
 * Version: 1.0.46
 * Author: CSC Dallas Workspace
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) { exit; }

define('AAPG_PLUGIN_VERSION', '1.0.46');
define('AAPG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AAPG_PLUGIN_URL', plugin_dir_url(__FILE__));

define('AAPG_DEFAULT_PROMPT_ID', 'pmpt_697732cd233081979612e14e3c8b8f260bc2b578e7052e41');
define('AAPG_DEFAULT_PROMPT_VERSION', '1');

define('AAPG_OPTION_KEY', 'aapg_settings');

define('AAPG_TEMPLATE_CATEGORY_SLUG', 'hubtemplates');

require_once AAPG_PLUGIN_DIR . 'includes/class-aapg-plugin.php';

add_action('plugins_loaded', function () {
    \AAPG\Plugin::instance();
});




function new_clhb_location_cards_shortcode($atts)
{
    if (!function_exists('get_field')) {
        return '';
    }

    // Helper: get first thing inside [ ... ] without brackets
    $extract_bracket_token = function ($text) {
        if (empty($text) || !is_string($text)) return '';
        if (preg_match('/\[([^\]]+)\]/', $text, $m)) {
            return trim($m[1]); // e.g. LINK_FARMERS_BRANCH_MAP
        }
        return '';
    };

    $locations_json = '[
  {
    "title": "Comprehensive Spine Center of Dallas – Farmers Branch",
    "address": "2655 Villa Creek Drive, Suite 105W, West Building, Farmers Branch, Texas 75234",
    "map_link_label": "[LINK_FARMERS_BRANCH_MAP]",
    "map_link": "/locations/farmers-branch-clinic/",
    "image": "https://dallasspine.com/wp-content/uploads/2025/12/farmerbranch-clinic-loc-photo.webp",
    "phone": "214-831-3212"
  },
  {
    "title": "Comprehensive Spine Center of Dallas – Fort Worth",
    "address": "1000 Ninth Avenue, Suite A, Fort Worth, Texas 76104",
    "map_link_label": "[LINK_FORT_WORTH_MAP]",
    "map_link": "/locations/fort-worth-clinic/",
    "image": "https://dallasspine.com/wp-content/uploads/2025/12/fort-worth-clinic-loc-photo.webp",
    "phone": "817-608-7384"
  },
  {
    "title": "Comprehensive Spine Center of Dallas – Allen",
    "address": "1101 Raintree Circle, Suite 200, Allen, Texas 75013",
    "map_link_label": "[LINK_ALLEN_MAP]",
    "map_link": "/locations/allen-clinic/",
    "image": "https://dallasspine.com/wp-content/uploads/2025/12/allen-clinic-loc-photo.webp",
    "phone": "214-831-1682"
  },
  {
    "title": "Comprehensive Spine Center of Dallas – Arlington",
    "address": "2261 Brookhollow Plaza Drive, Suite 111, Arlington, Texas 76006",
    "map_link_label": "[LINK_ARLINGTON_MAP]",
    "map_link": "/locations/arlington-clinic/",
    "image": "https://dallasspine.com/wp-content/uploads/2025/12/arlington-clinic-loc-photo.webp",
    "phone": "817-608-7383"
  },
  {
    "title": "Comprehensive Spine Center of Dallas – Frisco",
    "address": "4577 Ohio Drive, Suite 140, Frisco, Texas 75035",
    "map_link_label": "[LINK_FRISCO_MAP]",
    "map_link": "/locations/frisco-clinic/",
    "image": "https://dallasspine.com/wp-content/uploads/2025/12/frisco-clinic-loc-photo.webp",
    "phone": "214-831-3027"
  },
  {
    "title": "Comprehensive Spine Center of Dallas – Lancaster",
    "address": "2700 West Pleasant Run Road, Suite 200, West Entrance, Lancaster, Texas 75146",
    "map_link_label": "[LINK_LANCASTER_MAP]",
    "map_link": "/locations/lancaster-clinic/",
    "image": "https://dallasspine.com/wp-content/uploads/2025/12/lancaster-clinic-loc-photo.webp",
    "phone": "214-831-1574"
  },
  {
    "title": "Comprehensive Spine Center of Dallas – Mesquite",
    "address": "18601 Lyndon B Johnson Freeway, Suite 618, Mesquite, Texas 75150",
    "map_link_label": "[LINK_MESQUITE_MAP]",
    "map_link": "/locations/mesquite-clinic/",
    "image": "https://dallasspine.com/wp-content/uploads/2025/12/mesquite-clinic-loc-photo.webp",
    "phone": "214-831-1142"
  }
]';

    $all_locations = json_decode($locations_json, true);

    global $post;
    if (empty($post)) return '';

    $repeater = get_field('new_stub_nearby_clinics', $post->ID);
    if (empty($repeater) || !is_array($repeater)) {
        return '';
    }

    // Build quick map of TOKEN => all_locations entry
    $location_map = [];
    foreach ($all_locations as $loc) {
        $token = $extract_bracket_token($loc['map_link_label'] ?? '');
        if (!empty($token)) {
            $location_map[$token] = $loc;
        }
    }

    ob_start();
    ?>
    <div class="clhb-location-cards">
        <?php foreach ($repeater as $row):

            // Try these fields; wherever the [TOKEN] appears, grab it
            $token = '';

            $candidates = [
                $row['map_link_label'] ?? '',
                $row['location_link_label'] ?? '',
                $row['clinicheading'] ?? '',
                $row['clinicdetails'] ?? '',
            ];

            foreach ($candidates as $c) {
                $token = $extract_bracket_token($c);
                if (!empty($token)) break;
            }

            if (empty($token) || empty($location_map[$token])) {
                continue;
            }

            $loc = $location_map[$token];
            ?>
            <div class="clhb-location-card">
                <?php if (!empty($loc['image'])): ?>
                    <div class="clhb-location-card-image">
                        <img src="<?php echo esc_url($loc['image']); ?>"
                             alt="<?php echo esc_attr($loc['title']); ?>"
                             loading="lazy">
                    </div>
                <?php endif; ?>

                <div class="clhb-location-card-title"><h3><?php echo esc_html($loc['title']); ?></h3></div>
				<div class="clhb-location-servingroute">
    <?php echo !empty($row['common_routes_serving_clinic']) ? esc_html($row['common_routes_serving_clinic']) : ''; ?>
</div>
                <div class="clhb-location-card-address">
                    <?php echo esc_html($loc['address']); ?><br>
                    Phone: <a href="<?php echo 'tel:' . $loc['phone']; ?>"><?php echo $loc['phone']; ?></a><br>
                    Email: <a href="mailto:scheduling@dallasspine.com">scheduling@dallasspine.com</a>
                </div>

                <div class="clhb-location-card-maplink">
                    <a href="<?php echo esc_url($loc['map_link']); ?>" target="_blank" rel="noopener">
                        View Location
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<style> .clhb-location-card-title h3{ font-weight:600 !important; } .clhb-location-cards { display: flex; gap: 8rem; flex-wrap: wrap; justify-content: center; align-items: stretch; margin-bottom: 1em; } .clhb-location-card { border-radius: 12px; width: 45%; border: 1px solid #074476; background: #FFF !important; box-shadow: 2px 4px 76px 2px rgba(0, 0, 0, 0.13); padding: 2rem; display: flex; gap: 1.5rem; flex-direction: column; align-items: flex-start; transition: transform 0.25s, box-shadow 0.25s;} .clhb-location-card-image img { width: 100%; } .clhb-location-card-title { font-size: 1.1em; text-align: center; width: 100%; } .clhb-location-card-address { margin-bottom: 10px; color: #333; font-size: 1.8rem; width: 100%; text-align: center; } .clhb-location-card-maplink { border-radius: 8px; background: #2EA3F2; color: #fff; padding: 1.4rem 2.6rem; text-decoration: none; margin: auto;transition: all 0.3s ease; }
	.clhb-location-card:hover{
  transform: translateY(-4px);
  box-shadow: 0 16px 56px rgba(26, 48, 96, 0.17) !important;
}
	.clhb-location-card-maplink:hover {
  box-shadow: 0 4px 18px rgba(30, 136, 229, 0.45);
  transform: translateY(-2px);
} .clhb-location-card-maplink a { color: #fff; } .clhb-location-servingroute{padding:1rem .5rem;text-align:center;font-size:1.3rem;line-height:1.5em;background:#1d1D1D0A;display: flex;justify-content: center;align-items: center;width: 100%;} @media (max-width:1100px){.clhb-location-cards{gap:2rem}} @media (max-width:1024px){ .clhb-location-card{ width:48%; padding: 1rem !important; } .clhb-location-card-address{ font-size:1.6rem; margin-bottom:0; } } @media (max-width:767px){ .clhb-location-card{ width:100%; padding: 1.6rem !important; } } </style>
    <?php
    // (keep your existing <style> block as-is)
    return ob_get_clean();
}
add_shortcode('new_clhb_location_cards', 'new_clhb_location_cards_shortcode');



function custom_acf_youtube_video_shortcode($atts) {
    $atts = shortcode_atts([
        'post_id' => get_the_ID(),
    ], $atts, 'acf_youtube_video');

    $post_id = $atts['post_id'];

    // Get ACF fields
    $video_link      = get_field('video_link', $post_id);
    $video_thumbnail = get_field('video_thumbnail', $post_id);

    // ❌ If no video link → return nothing
    if (empty($video_link)) {
        return '';
    }

    // Extract YouTube video ID
    $video_id = '';
    if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([^&\n?#]+)/', $video_link, $matches)) {
        $video_id = $matches[1];
    }

    if (empty($video_id)) {
        return '';
    }

    $embed_url = 'https://www.youtube.com/embed/' . esc_attr($video_id) . '?rel=0';

    // Check thumbnail
    $thumb_url = '';
    if (!empty($video_thumbnail)) {
        if (is_array($video_thumbnail) && !empty($video_thumbnail['url'])) {
            $thumb_url = $video_thumbnail['url'];
        } else {
            $thumb_url = $video_thumbnail;
        }
    }

    $unique_id = 'acf-video-' . uniqid();

    ob_start();
    ?>

    <div class="acf-youtube-video-wrapper" id="<?php echo esc_attr($unique_id); ?>">

        <?php if (!empty($thumb_url)) : ?>
            <!-- ✅ Show thumbnail -->
            <div class="acf-youtube-video-thumb" data-embed="<?php echo esc_url($embed_url); ?>">
                <img src="<?php echo esc_url($thumb_url); ?>" alt="Video Thumbnail">
                <button type="button" class="acf-youtube-play-btn"><img src="https://dallasspine.com/wp-content/uploads/2026/04/videoplay.webp" /></button>
            </div>

        <?php else : ?>
            <!-- ✅ No thumbnail → show video directly -->
            <iframe 
                src="<?php echo esc_url($embed_url); ?>" 
                allow="autoplay; encrypted-media" 
                allowfullscreen>
            </iframe>
        <?php endif; ?>

    </div>

    <style>
        #<?php echo esc_attr($unique_id); ?> {
            max-width: 1000px;
            margin: 0 auto;
        }

        #<?php echo esc_attr($unique_id); ?> .acf-youtube-video-thumb {
            position: relative;
            cursor: pointer;
            overflow: hidden;
            border-radius: 24px;
            aspect-ratio: 16 / 9;
            background: #0f2354;
        }

        #<?php echo esc_attr($unique_id); ?> .acf-youtube-video-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        #<?php echo esc_attr($unique_id); ?> .acf-youtube-play-btn {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 74px;
            height: 74px;
            border-radius: 50%;
            border: none;
            background: rgba(255,255,255,0.2);
            color: #fff;
            font-size: 28px;
            cursor: pointer;
        }

        #<?php echo esc_attr($unique_id); ?> iframe {
            width: 100%;
            height: 100%;
            border: 0;
            border-radius: 24px;
            aspect-ratio: 16 / 9;
        }
    </style>

    <?php if (!empty($thumb_url)) : ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const wrapper = document.getElementById('<?php echo esc_js($unique_id); ?>');
            if (!wrapper) return;

            const thumb = wrapper.querySelector('.acf-youtube-video-thumb');

            thumb.addEventListener('click', function() {
                const embedUrl = this.getAttribute('data-embed') + '&autoplay=1';
                wrapper.innerHTML = '<iframe src="' + embedUrl + '" allow="autoplay; encrypted-media" allowfullscreen></iframe>';
            });
        });
    </script>
    <?php endif; ?>

    <?php
    return ob_get_clean();
}
add_shortcode('acf_youtube_video', 'custom_acf_youtube_video_shortcode');