<?php
if (!defined('ABSPATH')) { exit; }

class ABC_B2B_Designer_User_Meta {
    private static $instance = null;
    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('show_user_profile', [$this, 'render']);
        add_action('edit_user_profile', [$this, 'render']);
        add_action('personal_options_update', [$this, 'save']);
        add_action('edit_user_profile_update', [$this, 'save']);
    }

    public function render($user) {
        if (!current_user_can('list_users')) return;

        $org_id = (int) get_user_meta($user->ID, 'abc_b2b_org_id', true);
        $approved = (int) get_user_meta($user->ID, 'abc_b2b_org_approved', true);
        $role = get_user_meta($user->ID, 'abc_b2b_org_role', true);
        if (!$role) $role = 'member';

        $orgs = get_posts([
            'post_type' => 'abc_org',
            'posts_per_page' => 200,
            'post_status' => ['publish','draft','private'],
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        ?>
        <h2>ABC B2B Organization</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="abc_b2b_org_id">Organization</label></th>
                <td>
                    <select name="abc_b2b_org_id" id="abc_b2b_org_id">
                        <option value="0">— None —</option>
                        <?php foreach ($orgs as $org): ?>
                            <option value="<?php echo (int)$org->ID; ?>" <?php selected($org_id, (int)$org->ID); ?>>
                                <?php echo esc_html($org->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Assign this user to a customer Organization to limit which templates they can see.</p>
                </td>
            </tr>
            <tr>
                <th><label for="abc_b2b_org_approved">Organization Approval</label></th>
                <td>
                    <label>
                        <input type="checkbox" name="abc_b2b_org_approved" id="abc_b2b_org_approved" value="1" <?php checked($approved, 1); ?>>
                        Approved to access templates
                    </label>
                    <p class="description">If unchecked, the user will see an approval-pending message and cannot use templates.</p>
                </td>
            </tr>
            <tr>
                <th><label for="abc_b2b_org_role">Organization Role</label></th>
                <td>
                    <select name="abc_b2b_org_role" id="abc_b2b_org_role">
                        <option value="member" <?php selected($role, 'member'); ?>>Member</option>
                        <option value="admin" <?php selected($role, 'admin'); ?>>Organization Admin</option>
                    </select>
                    <p class="description">Org Admins can approve members and view Organization order history in the front-end portal.</p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save($user_id) {
        if (!current_user_can('edit_user', $user_id)) return;

        if (isset($_POST['abc_b2b_org_id'])) {
            update_user_meta($user_id, 'abc_b2b_org_id', (int) $_POST['abc_b2b_org_id']);
        }

        $approved = !empty($_POST['abc_b2b_org_approved']) ? 1 : 0;
        update_user_meta($user_id, 'abc_b2b_org_approved', $approved);

        if (isset($_POST['abc_b2b_org_role'])) {
            $role = sanitize_text_field($_POST['abc_b2b_org_role']);
            if (!in_array($role, ['member','admin'], true)) $role = 'member';
            update_user_meta($user_id, 'abc_b2b_org_role', $role);
        }
    }
}
