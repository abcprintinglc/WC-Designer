<?php
if (!defined('ABSPATH')) { exit; }

class ABC_B2B_Designer_Org {
    private static $instance = null;
    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'register_org_cpt']);
        add_action('show_user_profile', [$this, 'user_profile_field']);
        add_action('edit_user_profile', [$this, 'user_profile_field']);
        add_action('personal_options_update', [$this, 'save_user_profile_field']);
        add_action('edit_user_profile_update', [$this, 'save_user_profile_field']);
    }

    public function register_org_cpt() {
        if (post_type_exists('abc_b2b_org')) { return; }

        register_post_type('abc_b2b_org', [
            'labels' => [
                'name' => 'Organizations',
                'singular_name' => 'Organization',
                'add_new_item' => 'Add New Organization',
                'edit_item' => 'Edit Organization',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-building',
            'menu_position' => 25,
            'supports' => ['title'],
        ]);
    }

    public function user_profile_field($user) {
        if (!current_user_can('edit_users')) return;
        $org_id = (int) get_user_meta($user->ID, 'abc_b2b_org_id', true);
        $orgs = get_posts([
            'post_type' => 'abc_b2b_org',
            'numberposts' => 200,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        ?>
        <h3>ABC B2B Organization</h3>
        <table class="form-table">
            <tr>
                <th><label for="abc_b2b_org_id">Organization</label></th>
                <td>
                    <select name="abc_b2b_org_id" id="abc_b2b_org_id">
                        <option value="0" <?php selected($org_id, 0); ?>>None</option>
                        <?php foreach ($orgs as $o): ?>
                            <option value="<?php echo (int)$o->ID; ?>" <?php selected($org_id, (int)$o->ID); ?>>
                                <?php echo esc_html($o->post_title) . ' (#' . (int)$o->ID . ')'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Used to show brand-specific templates on WooCommerce product pages.</p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_user_profile_field($user_id) {
        if (!current_user_can('edit_users')) return;
        $org_id = isset($_POST['abc_b2b_org_id']) ? (int)$_POST['abc_b2b_org_id'] : 0;
        update_user_meta($user_id, 'abc_b2b_org_id', $org_id);
    }
}
