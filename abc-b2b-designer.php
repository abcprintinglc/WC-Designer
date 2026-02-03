<?php
/**
 * Plugin Name: ABC B2B Template Designer (Phase 1)
 * Description: B2B brand templates for WooCommerce products. Customers fill locked templates with editable fields; designs attach to orders.
 * Version: 0.4.0
 * Author: ABC Printing Co. LLC (generated)
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: abc-b2b-designer
 */

if (!defined('ABSPATH')) { exit; }

define('ABC_B2B_DESIGNER_VERSION', '0.4.0');
// Users with this capability can bypass Organization filtering (in addition to Administrators).
define('ABC_B2B_DESIGNER_BYPASS_CAP', 'abc_b2b_designer_bypass');

register_activation_hook(__FILE__, function () {
    // Grant bypass capability to Administrators by default.
    $admin = get_role('administrator');
    if ($admin && method_exists($admin, 'add_cap')) {
        $admin->add_cap(ABC_B2B_DESIGNER_BYPASS_CAP);
    }

    // Create Draft Editor page (dedicated editor for team workflow)
    $page_id = (int) get_option('abc_b2b_draft_editor_page_id', 0);
    if ($page_id <= 0 || !get_post($page_id)) {
        $existing = get_posts([
            'post_type' => 'page',
            'post_status' => ['publish','private','draft'],
            'numberposts' => 1,
            'meta_key' => '_abc_b2b_draft_editor_page',
            'meta_value' => '1',
        ]);
        if (!empty($existing)) {
            $page_id = (int) $existing[0]->ID;
        } else {
            $page_id = wp_insert_post([
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => 'Customize Draft',
                'post_content' => '[abc_b2b_draft_editor]',
            ]);
            if ($page_id && !is_wp_error($page_id)) {
                update_post_meta($page_id, '_abc_b2b_draft_editor_page', '1');
            }
        }
        if ($page_id && !is_wp_error($page_id)) {
            update_option('abc_b2b_draft_editor_page_id', (int)$page_id);
        }
    }
});
define('ABC_B2B_DESIGNER_FILE', __FILE__);
define('ABC_B2B_DESIGNER_DIR', plugin_dir_path(__FILE__));
define('ABC_B2B_DESIGNER_URL', plugin_dir_url(__FILE__));

require_once ABC_B2B_DESIGNER_DIR . 'includes/helpers.php';
require_once ABC_B2B_DESIGNER_DIR . 'includes/class-abc-designer-cpt.php';
require_once ABC_B2B_DESIGNER_DIR . 'includes/class-abc-designer-org.php';
require_once ABC_B2B_DESIGNER_DIR . 'includes/class-abc-designer-settings.php';
require_once ABC_B2B_DESIGNER_DIR . 'includes/class-abc-designer-health.php';
require_once ABC_B2B_DESIGNER_DIR . 'includes/class-abc-designer-ajax.php';
require_once ABC_B2B_DESIGNER_DIR . 'includes/class-abc-designer-user-meta.php';
require_once ABC_B2B_DESIGNER_DIR . 'includes/class-abc-designer-portal.php';
require_once ABC_B2B_DESIGNER_DIR . 'includes/class-abc-designer-frontend.php';
require_once ABC_B2B_DESIGNER_DIR . 'includes/class-abc-designer-woocommerce.php';

final class ABC_B2B_Designer_Plugin {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Bootstrap immediately at plugin load so CPTs are registered reliably.
        $this->bootstrap();
        // Keep soft-checks and other late hooks.
        add_action('plugins_loaded', [$this, 'plugins_loaded'], 30);
    }

    public function bootstrap() {
        static $booted = false;
        if ($booted) return;
        $booted = true;

        // These classes add their own hooks (including init hooks). They must be instantiated before init runs.
        ABC_B2B_Designer_CPT::instance();
        ABC_B2B_Designer_Org::instance();
        ABC_B2B_Designer_Settings::instance();
        ABC_B2B_Designer_Health::instance();
        ABC_B2B_Designer_AJAX::instance();
        ABC_B2B_Designer_User_Meta::instance();
        ABC_B2B_Designer_Portal::instance();
        ABC_B2B_Designer_Frontend::instance();
        ABC_B2B_Designer_Woo::instance();
    }

    public function plugins_loaded() {

        // Soft-check WooCommerce
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function () {
                if (!current_user_can('activate_plugins')) { return; }
                echo '<div class="notice notice-warning"><p><strong>ABC B2B Template Designer</strong> requires WooCommerce to attach designs to cart/orders. You can still create templates, but storefront features will be limited until WooCommerce is active.</p></div>';
            });
        }
    }
}

ABC_B2B_Designer_Plugin::instance();
