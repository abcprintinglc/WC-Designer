<?php
if (!defined('ABSPATH')) { exit; }

class ABC_B2B_Designer_Portal {
    private static $instance = null;
    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('abc_b2b_org_portal', [$this, 'shortcode']);
        add_action('wp_ajax_abc_b2b_org_approve_user', [$this, 'ajax_approve_user']);
        add_action('wp_ajax_abc_b2b_org_unapprove_user', [$this, 'ajax_unapprove_user']);
    }

    private function current_org_id(): int {
        return (int) get_user_meta(get_current_user_id(), 'abc_b2b_org_id', true);
    }

    private function can_manage_org(): bool {
        $uid = get_current_user_id();
        if (!$uid) return false;
        if (current_user_can('manage_options')) return true;
        return get_user_meta($uid, 'abc_b2b_org_role', true) === 'admin';
    }

    public function shortcode($atts = []) {
        if (!is_user_logged_in()) {
            return '<div class="abc-org-portal-notice">Please log in to access your Organization portal.</div>';
        }
        if (!$this->can_manage_org()) {
            return '<div class="abc-org-portal-notice">You do not have permission to access the Organization portal.</div>';
        }

        $org_id = $this->current_org_id();
        if (!$org_id) {
            return '<div class="abc-org-portal-notice">No Organization is assigned to your account.</div>';
        }

        $org_name = get_the_title($org_id);

        $users = get_users([
            'meta_key' => 'abc_b2b_org_id',
            'meta_value' => (int) $org_id,
            'number' => 500,
        ]);

        ob_start();
        ?>
        <div class="abc-org-portal" style="max-width:1100px;">
            <h2>Organization Portal: <?php echo esc_html($org_name); ?></h2>

            <h3>Members</h3>
            <p>Approve members so they can access templates. Unapproved users will see an “Approval pending” message.</p>

            <table class="widefat striped" style="margin:10px 0 20px;">
                <thead>
                    <tr><th>User</th><th>Email</th><th>Status</th><th>Role</th><th>Action</th></tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): 
                    $approved = (int) get_user_meta($u->ID, 'abc_b2b_org_approved', true) === 1;
                    $role = get_user_meta($u->ID, 'abc_b2b_org_role', true) ?: 'member';
                ?>
                    <tr>
                        <td><?php echo esc_html($u->display_name); ?></td>
                        <td><?php echo esc_html($u->user_email); ?></td>
                        <td><?php echo $approved ? '<span style="color:#027a48;font-weight:600;">Approved</span>' : '<span style="color:#b42318;font-weight:600;">Pending</span>'; ?></td>
                        <td><?php echo esc_html($role === 'admin' ? 'Org Admin' : 'Member'); ?></td>
                        <td>
                            <?php if (!$approved): ?>
                                <button class="button button-primary abc-approve-user" data-user="<?php echo (int)$u->ID; ?>">Approve</button>
                            <?php else: ?>
                                <button class="button abc-unapprove-user" data-user="<?php echo (int)$u->ID; ?>">Remove access</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h3>Organization Orders</h3>
            <p>Orders placed by any approved member in this Organization.</p>
            <?php
            if (!class_exists('WooCommerce')) {
                echo '<div class="abc-org-portal-notice">WooCommerce is required to view order history.</div>';
            } else {
                $user_ids = array_map(function($u){ return (int)$u->ID; }, $users);
                $orders = [];
                if (!empty($user_ids)) {
                    $orders = wc_get_orders([
                        'limit' => 50,
                        'orderby' => 'date',
                        'order' => 'DESC',
                        'customer_id' => $user_ids,
                        'return' => 'objects',
                    ]);
                }
                if (empty($orders)) {
                    echo '<div class="abc-org-portal-notice">No orders found yet.</div>';
                } else {
                    echo '<table class="widefat striped"><thead><tr><th>Order</th><th>Date</th><th>Status</th><th>Total</th><th>Placed by</th><th>Tracking</th><th>Actions</th></tr></thead><tbody>';
                    foreach ($orders as $o) {
                        $cust = $o->get_user();
                        $tracking = '';
                        // Try common tracking meta keys
                        $keys = ['_tracking_number','tracking_number','_wc_shipment_tracking_items','_aftership_tracking_number'];
                        foreach ($keys as $k) {
                            $v = $o->get_meta($k, true);
                            if (is_string($v) && trim($v) !== '') { $tracking = $v; break; }
                            if (is_array($v) && !empty($v)) { $tracking = 'Available'; break; }
                        }
                        echo '<tr>';
                        echo '<td>#'.esc_html($o->get_order_number()).'</td>';
                        echo '<td>'.esc_html($o->get_date_created() ? $o->get_date_created()->date('Y-m-d') : '').'</td>';
                        echo '<td>'.esc_html(wc_get_order_status_name($o->get_status())).'</td>';
                        echo '<td>'.wp_kses_post($o->get_formatted_order_total()).'</td>';
                        echo '<td>'.esc_html($cust ? $cust->display_name : '').'</td>';
                        echo '<td>'.esc_html($tracking).'</td>';
                        echo '<td>';
                        $oid = (int)$o->get_id();
                        echo '<button type="button" class="button abc-reorder" data-order-id="'.esc_attr($oid).'">Reorder</button> ';
                        echo '<button type="button" class="button abc-reorder-changes" data-order-id="'.esc_attr($oid).'">Reorder + Changes</button>';
                        echo '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                }
            }
            ?>

            <script>
            (function(){
                function post(action, userId){
                    const data = new FormData();
                    data.append('action', action);
                    data.append('user_id', userId);
                    data.append('_wpnonce', '<?php echo esc_js(wp_create_nonce('abc_b2b_org_portal')); ?>');
                    return fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', { method:'POST', credentials:'same-origin', body:data })
                      .then(r=>r.json());
                }
                document.addEventListener('click', function(e){
                    const btn = e.target.closest('.abc-approve-user, .abc-unapprove-user');
                    if(!btn) return;
                    const uid = btn.getAttribute('data-user');
                    const action = btn.classList.contains('abc-approve-user') ? 'abc_b2b_org_approve_user' : 'abc_b2b_org_unapprove_user';
                    btn.disabled = true;
                    post(action, uid).then(res=>{
                        if(res && res.success){
                            location.reload();
                        }else{
                            alert((res && res.data && res.data.message) ? res.data.message : 'Could not update user.');
                            btn.disabled = false;
                        }
                    }).catch(()=>{ alert('Could not update user.'); btn.disabled=false; });
                });
            
                // Reorder buttons
                document.querySelectorAll('.abc-reorder, .abc-reorder-changes').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        const orderId = btn.getAttribute('data-order-id');
                        const mode = btn.classList.contains('abc-reorder-changes') ? 'changes' : 'reorder';
                        btn.disabled = true;
                        const fd = new FormData();
                        fd.append('action', 'abc_b2b_reorder_order');
                        fd.append('nonce', '<?php echo esc_js(wp_create_nonce('abc_b2b_designer')); ?>');
                        fd.append('order_id', orderId);
                        fd.append('mode', mode);
                        fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', { method: 'POST', credentials: 'same-origin', body: fd })
                            .then(r=>r.json())
                            .then(res=>{
                                if(res && res.success && res.data && res.data.redirect){
                                    window.location.href = res.data.redirect;
                                }else{
                                    alert((res && res.data && res.data.message) ? res.data.message : 'Could not reorder.');
                                    btn.disabled = false;
                                }
                            })
                            .catch(()=>{ alert('Could not reorder.'); btn.disabled=false; });
                    });
                });

})();
            </script>
        </div>
        <?php
        return ob_get_clean();
    }

    public function ajax_approve_user(){ $this->ajax_set_user_approval(true); }
    public function ajax_unapprove_user(){ $this->ajax_set_user_approval(false); }

    private function ajax_set_user_approval(bool $approved){
        if (!is_user_logged_in()) wp_send_json_error(['message' => 'Not logged in.'], 403);
        if (!check_ajax_referer('abc_b2b_org_portal', '_wpnonce', false)) wp_send_json_error(['message' => 'Invalid nonce.'], 403);

        $current = get_current_user_id();
        if (!$this->can_manage_org()) wp_send_json_error(['message' => 'No permission.'], 403);

        $target_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        if (!$target_id) wp_send_json_error(['message' => 'Missing user_id.'], 400);

        $org_id = $this->current_org_id();
        $target_org = (int) get_user_meta($target_id, 'abc_b2b_org_id', true);

        // Site admins can approve across orgs; org admins only within their org.
        if (!current_user_can('manage_options') && $target_org !== $org_id) {
            wp_send_json_error(['message' => 'User is not in your Organization.'], 403);
        }

        update_user_meta($target_id, 'abc_b2b_org_approved', $approved ? 1 : 0);
        wp_send_json_success(['ok' => true]);
    }
}
