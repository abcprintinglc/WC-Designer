<?php
if (!defined('ABSPATH')) { exit; }

class ABC_B2B_Designer_Settings {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function admin_menu() {
        add_options_page(
            'ABC B2B Designer',
            'ABC B2B Designer',
            'manage_options',
            'abc-b2b-designer-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('abc_b2b_designer', 'abc_b2b_designer_font_css_url', [
            'type' => 'string',
            'sanitize_callback' => function($val){
                $val = trim((string)$val);
                if ($val === '') return '';
                return esc_url_raw($val);
            },
            'default' => ''
        ]);

        add_settings_section(
            'abc_b2b_designer_section_assets',
            'Assets',
            function(){
                echo '<p>Load external CSS for fonts (Adobe Fonts / Typekit). This makes the font available inside the product-page designer and in exported proofs.</p>';
            },
            'abc-b2b-designer-settings'
        );

        add_settings_field(
            'abc_b2b_designer_font_css_url',
            'External Font Stylesheet URL',
            [$this, 'field_font_css_url'],
            'abc-b2b-designer-settings',
            'abc_b2b_designer_section_assets'
        );
    }

    public function field_font_css_url() {
        $val = (string) get_option('abc_b2b_designer_font_css_url', '');
        echo '<input type="url" style="width:520px;max-width:100%;" name="abc_b2b_designer_font_css_url" value="' . esc_attr($val) . '" placeholder="https://use.typekit.net/xxxxxxx.css">';
        echo '<p class="description">Example: <code>https://use.typekit.net/dpz1pxr.css</code></p>';
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap">';
        echo '<h1>ABC B2B Designer Settings</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('abc_b2b_designer');
        do_settings_sections('abc-b2b-designer-settings');
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    public static function font_css_url(): string {
        $val = (string) get_option('abc_b2b_designer_font_css_url', '');
        return $val ? esc_url_raw($val) : '';
    }
}
