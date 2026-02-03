<?php
if (!defined('ABSPATH')) { exit; }

class ABC_B2B_Designer_Health {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'register_page']);
    }

    public function register_page() {
        add_submenu_page(
            'abc-b2b-designer-settings',
            'System Health',
            'System Health',
            'manage_options',
            'abc-b2b-designer-health',
            [$this, 'render']
        );
    }

    public function render() {
        $upload_dir  = abc_b2b_designer_upload_base_dir();
        $is_writable = wp_is_writable($upload_dir);
        $gd_installed = extension_loaded('gd');
        $upload_max = ini_get('upload_max_filesize');
        $post_max   = ini_get('post_max_size');

        echo '<div class="wrap"><h1>ABC Designer Health Check (Phase 0)</h1>';
        echo '<table class="widefat fixed striped" style="max-width:760px; margin-top:20px;">';

        echo '<tr><td style="width:220px;"><strong>Storage Folder</strong></td>';
        echo '<td><code>' . esc_html($upload_dir) . '</code></td>';
        echo '<td style="width:160px;">' . ($is_writable ? '<span style="color:green">&#10004; Writable</span>' : '<span style="color:red">&#10008; Not Writable</span>') . '</td></tr>';

        echo '<tr><td><strong>GD Library</strong></td>';
        echo '<td>Used for generating proof PNGs / raster tasks</td>';
        echo '<td>' . ($gd_installed ? '<span style="color:green">&#10004; Installed</span>' : '<span style="color:red">&#10008; Missing</span>') . '</td></tr>';

        echo '<tr><td><strong>Upload Limits</strong></td>';
        echo '<td>upload_max_filesize: <code>' . esc_html($upload_max) . '</code> &nbsp; post_max_size: <code>' . esc_html($post_max) . '</code></td>';
        echo '<td><span style="color:gray">Info</span></td></tr>';

        echo '<tr><td><strong>AJAX Endpoint</strong></td>';
        echo '<td><code>' . esc_html(admin_url('admin-ajax.php')) . '</code></td>';
        echo '<td><span style="color:gray">Check browser console for 403/401</span></td></tr>';

        echo '</table>';

        echo '<h2 style="margin-top:24px;">Workflow Config</h2>';
        echo '<p><strong>Draft Page ID:</strong> ' . (int)get_option('abc_b2b_draft_editor_page_id', 0) . '</p>';

        echo '</div>';
    }
}
