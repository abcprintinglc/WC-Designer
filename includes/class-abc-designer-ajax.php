<?php
if (!defined('ABSPATH')) { exit; }

class ABC_B2B_Designer_AJAX {
    private static $instance = null;
    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_abc_b2b_get_templates', [$this, 'get_templates']);
        add_action('wp_ajax_nopriv_abc_b2b_get_templates', [$this, 'get_templates']);

        add_action('wp_ajax_abc_b2b_get_template', [$this, 'get_template']);
        add_action('wp_ajax_nopriv_abc_b2b_get_template', [$this, 'get_template']);

        add_action('wp_ajax_abc_b2b_save_design', [$this, 'save_design']);
        add_action('wp_ajax_nopriv_abc_b2b_save_design', [$this, 'save_design']);

        // Draft workflow (team)
        add_action('wp_ajax_abc_b2b_create_draft', [$this, 'create_draft']);
        add_action('wp_ajax_abc_b2b_get_draft', [$this, 'get_draft']);
        add_action('wp_ajax_abc_b2b_save_draft', [$this, 'save_draft']);
        add_action('wp_ajax_abc_b2b_update_draft_qty', [$this, 'update_draft_qty']);
        add_action('wp_ajax_abc_b2b_set_employee_ready', [$this, 'set_employee_ready']);
        add_action('wp_ajax_abc_b2b_set_admin_ready', [$this, 'set_admin_ready']);
        add_action('wp_ajax_abc_b2b_set_ready_override', [$this, 'set_ready_override']);
        add_action('wp_ajax_abc_b2b_add_draft_to_cart', [$this, 'add_draft_to_cart']);
        add_action('wp_ajax_abc_b2b_set_draft_template', [$this, 'set_draft_template']);
        add_action('wp_ajax_abc_b2b_duplicate_draft', [$this, 'duplicate_draft']);
        add_action('wp_ajax_abc_b2b_reorder_order', [$this, 'reorder_order']);
    }

    public function get_templates() {
        check_ajax_referer('abc_b2b_designer', 'nonce');

        $product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
        $org_id = abc_b2b_designer_current_user_org_id();
        $can_bypass = current_user_can('manage_options') || current_user_can(ABC_B2B_DESIGNER_BYPASS_CAP);

        // If user is linked to an org but not approved, return a friendly pending payload (no 403)
        if (is_user_logged_in() && $org_id && !abc_b2b_designer_current_user_is_approved() && !$can_bypass) {
            wp_send_json_success(['templates' => [], 'pending' => [
                'org_name' => get_the_title($org_id),
                'organizer_first' => abc_b2b_designer_org_organizer_first_name($org_id),
            ]]);
        }

        // If user has no org and is not bypass, return empty list
        if (is_user_logged_in() && !$org_id && !$can_bypass) {
            wp_send_json_success(['templates' => []]);
        }

        $templates = $this->query_templates_for_product($product_id, (int)$org_id);
        wp_send_json_success(['templates' => $templates]);
    }

    function get_template() {
        check_ajax_referer('abc_b2b_designer', 'nonce');

        $template_id = isset($_GET['template_id']) ? (int)$_GET['template_id'] : 0;
        $post = get_post($template_id);
        if (!$post || $post->post_type !== 'abc_designer_tpl') {
            wp_send_json_error(['message' => 'Template not found.'], 404);
        }

        $org_id_required = (int) get_post_meta($template_id, '_abc_org_id', true);
        $org_id = abc_b2b_designer_current_user_org_id();
        if ($org_id_required && $org_id_required !== $org_id && !(current_user_can('manage_options') || current_user_can(ABC_B2B_DESIGNER_BYPASS_CAP))) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }

        $surfaces = get_post_meta($template_id, '_abc_surfaces', true);
        if (!is_array($surfaces)) $surfaces = [];

        foreach ($surfaces as $k => $s) {
            $bg_id = (int)($s['bg_attachment_id'] ?? 0);
            $surfaces[$k]['bg_url'] = $bg_id ? wp_get_attachment_image_url($bg_id, 'full') : '';
        }

        wp_send_json_success([
            'template' => [
                'id' => $template_id,
                'title' => html_entity_decode(get_the_title($template_id), ENT_QUOTES, 'UTF-8'),
                'surfaces' => $surfaces,
            ]
        ]);
    }

    public function save_design() {
        check_ajax_referer('abc_b2b_designer', 'nonce');

        $template_id = isset($_POST['template_id']) ? (int)$_POST['template_id'] : 0;
        
        $template_label = $template_id ? get_the_title($template_id) : 'Choose Template';
$product_id  = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
        $payload = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
        $previews = isset($_POST['previews']) ? $_POST['previews'] : [];
        $svgs = isset($_POST['svgs']) ? $_POST['svgs'] : [];

        if (!$template_id || !$product_id || !$payload) {
            wp_send_json_error(['message' => 'Missing data.'], 400);
        }

        $post = get_post($template_id);
        if (!$post || $post->post_type !== 'abc_designer_tpl') {
            wp_send_json_error(['message' => 'Template not found.'], 404);
        }

        $org_id_required = (int) get_post_meta($template_id, '_abc_org_id', true);
        $org_id = abc_b2b_designer_current_user_org_id();
        if ($org_id_required && $org_id_required !== $org_id && !(current_user_can('manage_options') || current_user_can(ABC_B2B_DESIGNER_BYPASS_CAP))) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }

        $token = abc_b2b_designer_token(24);
        $base_dir = abc_b2b_designer_upload_base_dir();
        $tmp_dir = trailingslashit($base_dir) . 'tmp/' . $token . '/';
        wp_mkdir_p($tmp_dir);

        file_put_contents($tmp_dir . 'design.json', $payload);

        $saved = [];
        if (is_array($previews)) {
            foreach ($previews as $surface_key => $data_url) {
                $surface_key = sanitize_key($surface_key);
                $data_url = is_string($data_url) ? $data_url : '';
                if (!$surface_key || !$data_url) continue;
                $decoded = abc_b2b_designer_decode_data_url($data_url);
                if (!$decoded || $decoded['mime'] !== 'image/png') continue;
                $path = $tmp_dir . 'surface-' . $surface_key . '.png';
                file_put_contents($path, $decoded['bytes']);
                $saved[$surface_key] = $path;
            }
        }

        
        // Save SVGs (vector text) if provided
        if (is_array($svgs)) {
            foreach ($svgs as $surface_key => $svg_str) {
                $surface_key = sanitize_key($surface_key);
                if (!$surface_key) continue;
                if (!is_string($svg_str) || trim($svg_str) === '') continue;

                // Basic hardening: only accept SVG roots
                if (stripos($svg_str, '<svg') === false) continue;

                $path = $tmp_dir . 'surface-' . $surface_key . '.svg';
                file_put_contents($path, $svg_str);
            }
        }

$meta = [
            'token' => $token,
            'template_id' => $template_id,
            'product_id' => $product_id,
            'user_id' => get_current_user_id(),
            'created' => time(),
            'surfaces' => array_keys($saved),
        ];
        file_put_contents($tmp_dir . 'meta.json', wp_json_encode($meta, JSON_PRETTY_PRINT));

        wp_send_json_success(['token' => $token]);
    }


    private function can_bypass(): bool {
        return current_user_can('manage_options') || current_user_can(ABC_B2B_DESIGNER_BYPASS_CAP);
    }

    private function require_logged_in() {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Please log in.'], 401);
        }
    }

    private function assert_draft_access(int $draft_id) {
        $post = get_post($draft_id);
        if (!$post || $post->post_type !== 'abc_b2b_draft') {
            wp_send_json_error(['message' => 'Draft not found.'], 404);
        }
        $org_id = (int) get_post_meta($draft_id, '_abc_org_id', true);
        $user_org = abc_b2b_designer_current_user_org_id();
        if ($org_id && $org_id !== $user_org && !$this->can_bypass()) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }
        if (!abc_b2b_designer_current_user_is_approved() && !$this->can_bypass()) {
            wp_send_json_error(['message' => 'Organization approval pending.'], 403);
        }
    }

    public function create_draft() {
        check_ajax_referer('abc_b2b_designer', 'nonce');
        $this->require_logged_in();

        if (!abc_b2b_designer_current_user_is_approved() && !$this->can_bypass()) {
            wp_send_json_error(['message' => 'Organization approval pending.'], 403);
        }

        $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $template_id = isset($_POST['template_id']) ? (int) $_POST['template_id'] : 0;
        $qty = isset($_POST['qty']) ? max(1, (int) $_POST['qty']) : 1;

        $variation_id = isset($_POST['variation_id']) ? (int) $_POST['variation_id'] : 0;
        $attributes_json = isset($_POST['attributes']) ? wp_unslash($_POST['attributes']) : '';

        if (!$product_id) {
            wp_send_json_error(['message' => 'Missing product.'], 400);
        }
$tpl = get_post($template_id);
        if (!$tpl || $tpl->post_type !== 'abc_designer_tpl') {
            wp_send_json_error(['message' => 'Template not found.'], 404);
        }

        $org_id = abc_b2b_designer_current_user_org_id();
        $required_org = (int) get_post_meta($template_id, '_abc_org_id', true);
        if ($required_org && $required_org !== $org_id && !$this->can_bypass()) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }

        $attrs = [];
        if ($attributes_json) {
            $decoded = json_decode($attributes_json, true);
            if (is_array($decoded)) $attrs = $decoded;
        }

        $title = 'Draft: ' . $template_label . ' ×' . $qty;
        $draft_id = wp_insert_post([
            'post_type' => 'abc_b2b_draft',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        if (!$draft_id || is_wp_error($draft_id)) {
            wp_send_json_error(['message' => 'Unable to create draft.'], 500);
        }

        update_post_meta($draft_id, '_abc_org_id', (int)$org_id);
        update_post_meta($draft_id, '_abc_product_id', (int)$product_id);
        update_post_meta($draft_id, '_abc_template_id', (int)$template_id);
        update_post_meta($draft_id, '_abc_qty', (int)$qty);
        update_post_meta($draft_id, '_abc_variation_id', (int)$variation_id);
        update_post_meta($draft_id, '_abc_attributes', wp_json_encode($attrs));
        update_post_meta($draft_id, '_abc_created_by', (int)get_current_user_id());
        update_post_meta($draft_id, '_abc_status', 'draft');
        abc_b2b_designer_reset_draft_ready_flags((int)$draft_id);

        $url = abc_b2b_designer_get_draft_editor_url((int)$draft_id);
        wp_send_json_success(['draft_id' => (int)$draft_id, 'url' => $url]);
    }

    public function get_draft() {
        check_ajax_referer('abc_b2b_designer', 'nonce');
        $this->require_logged_in();

        $draft_id = isset($_GET['draft_id']) ? (int) $_GET['draft_id'] : 0;
        if (!$draft_id) wp_send_json_error(['message' => 'Missing draft.'], 400);

        $this->assert_draft_access($draft_id);

        $payload = (string) get_post_meta($draft_id, '_abc_payload', true);
        $product_id = (int) get_post_meta($draft_id, '_abc_product_id', true);
        $template_id = (int) get_post_meta($draft_id, '_abc_template_id', true);
        $qty = (int) get_post_meta($draft_id, '_abc_qty', true);
        $status = (string) get_post_meta($draft_id, '_abc_status', true);
        $variation_id = (int) get_post_meta($draft_id, '_abc_variation_id', true);
        $attrs_raw = (string) get_post_meta($draft_id, '_abc_attributes', true);
        $attrs = json_decode($attrs_raw, true);
        if (!is_array($attrs)) $attrs = [];


        $employee_ready = (int) get_post_meta($draft_id, '_abc_employee_ready', true);
        $admin_ready = (int) get_post_meta($draft_id, '_abc_admin_ready', true);
        $ready_override = (int) get_post_meta($draft_id, '_abc_ready_override', true);

        $org_id = (int) get_post_meta($draft_id, '_abc_org_id', true);

        $draft_dir = trailingslashit(abc_b2b_designer_upload_drafts_dir()) . (int)$draft_id . '/current/';
        $draft_url = trailingslashit(abc_b2b_designer_upload_drafts_url()) . (int)$draft_id . '/current/';

        $previews = [];
        if (file_exists($draft_dir)) {
            foreach (glob($draft_dir . 'surface-*.png') as $p) {
                $bn = basename($p);
                $key = str_replace(['surface-','.png'], '', $bn);
                $previews[$key] = $draft_url . $bn;
            }
        }

        wp_send_json_success([
            'draft' => [
                'id' => (int)$draft_id,
                'org_id' => (int)$org_id,
                'product_id' => (int)$product_id,
                'product_title' => $product_id ? html_entity_decode(get_the_title($product_id), ENT_QUOTES, 'UTF-8') : '',
                'template_id' => (int)$template_id,
                'variation_id' => (int)$variation_id,
                'attributes' => $attrs,
                'qty' => max(1, (int)$qty),
                'status' => $status ?: 'draft',
                'employee_ready' => $employee_ready ? 1 : 0,
                'admin_ready' => $admin_ready ? 1 : 0,
                'ready_override' => $ready_override ? 1 : 0,
                'payload' => $payload ? json_decode($payload, true) : null,
                'previews' => $previews,
                'edit_url' => abc_b2b_designer_get_draft_editor_url((int)$draft_id),
                'is_org_admin' => abc_b2b_designer_current_user_is_org_admin() ? 1 : 0,
                'can_bypass' => $this->can_bypass() ? 1 : 0,
                'cart_url' => function_exists('wc_get_cart_url') ? wc_get_cart_url() : '',
            ]
        ]);
    }

    public function update_draft_qty() {
        check_ajax_referer('abc_b2b_designer', 'nonce');
        $this->require_logged_in();

        $draft_id = isset($_POST['draft_id']) ? (int) $_POST['draft_id'] : 0;
        $qty = isset($_POST['qty']) ? max(1, (int) $_POST['qty']) : 1;
        if (!$draft_id) wp_send_json_error(['message' => 'Missing draft.'], 400);

        $this->assert_draft_access($draft_id);

        update_post_meta($draft_id, '_abc_qty', (int)$qty);
        update_post_meta($draft_id, '_abc_status', 'draft');
        abc_b2b_designer_reset_draft_ready_flags((int)$draft_id);

        wp_send_json_success(['draft_id' => (int)$draft_id, 'qty' => (int)$qty]);
    }

    public function save_draft() {
        check_ajax_referer('abc_b2b_designer', 'nonce');
        $this->require_logged_in();

        $draft_id = isset($_POST['draft_id']) ? (int) $_POST['draft_id'] : 0;
        $payload = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
        $previews = isset($_POST['previews']) ? $_POST['previews'] : [];
        $svgs = isset($_POST['svgs']) ? $_POST['svgs'] : [];

        if (!$draft_id || !$payload) {
            wp_send_json_error(['message' => 'Missing data.'], 400);
        }

        $this->assert_draft_access($draft_id);

        update_post_meta($draft_id, '_abc_payload', wp_slash($payload));
        update_post_meta($draft_id, '_abc_last_saved_by', (int)get_current_user_id());
        update_post_meta($draft_id, '_abc_last_saved_at', current_time('mysql'));
        update_post_meta($draft_id, '_abc_status', 'draft');
        abc_b2b_designer_reset_draft_ready_flags((int)$draft_id);

        // Save proof files
        $draft_dir = trailingslashit(abc_b2b_designer_upload_drafts_dir()) . (int)$draft_id . '/current/';
        wp_mkdir_p($draft_dir);

        file_put_contents($draft_dir . 'design.json', $payload);

        if (is_array($previews)) {
            foreach ($previews as $surface_key => $data_url) {
                $surface_key = sanitize_key($surface_key);
                $data_url = is_string($data_url) ? $data_url : '';
                if (!$surface_key || !$data_url) continue;
                $decoded = abc_b2b_designer_decode_data_url($data_url);
                if (!$decoded || $decoded['mime'] !== 'image/png') continue;
                file_put_contents($draft_dir . 'surface-' . $surface_key . '.png', $decoded['bytes']);
            }
        }

        if (is_array($svgs)) {
            foreach ($svgs as $surface_key => $svg_str) {
                $surface_key = sanitize_key($surface_key);
                if (!$surface_key) continue;
                if (!is_string($svg_str) || trim($svg_str) === '') continue;
                file_put_contents($draft_dir . 'surface-' . $surface_key . '.svg', $svg_str);
            }
        }

        wp_send_json_success(['draft_id' => (int)$draft_id]);
    }

    public function set_employee_ready() {
        check_ajax_referer('abc_b2b_designer', 'nonce');
        $this->require_logged_in();

        $draft_id = isset($_POST['draft_id']) ? (int) $_POST['draft_id'] : 0;
        if (!$draft_id) wp_send_json_error(['message' => 'Missing draft.'], 400);

        $this->assert_draft_access($draft_id);

        update_post_meta($draft_id, '_abc_employee_ready', 1);
        update_post_meta($draft_id, '_abc_employee_ready_by', (int)get_current_user_id());
        update_post_meta($draft_id, '_abc_employee_ready_at', current_time('mysql'));

        wp_send_json_success(['draft_id' => (int)$draft_id, 'employee_ready' => 1]);
    }

    public function set_admin_ready() {
        check_ajax_referer('abc_b2b_designer', 'nonce');
        $this->require_logged_in();

        $draft_id = isset($_POST['draft_id']) ? (int) $_POST['draft_id'] : 0;
        if (!$draft_id) wp_send_json_error(['message' => 'Missing draft.'], 400);

        $this->assert_draft_access($draft_id);

        if (!abc_b2b_designer_current_user_is_org_admin() && !$this->can_bypass()) {
            wp_send_json_error(['message' => 'Only Organization Admins can approve.'], 403);
        }

        update_post_meta($draft_id, '_abc_admin_ready', 1);
        update_post_meta($draft_id, '_abc_admin_ready_by', (int)get_current_user_id());
        update_post_meta($draft_id, '_abc_admin_ready_at', current_time('mysql'));

        wp_send_json_success(['draft_id' => (int)$draft_id, 'admin_ready' => 1]);
    }

    public function set_ready_override() {
        check_ajax_referer('abc_b2b_designer', 'nonce');
        $this->require_logged_in();

        $draft_id = isset($_POST['draft_id']) ? (int) $_POST['draft_id'] : 0;
        $override = !empty($_POST['override']) ? 1 : 0;

        if (!$draft_id) wp_send_json_error(['message' => 'Missing draft.'], 400);

        $this->assert_draft_access($draft_id);

        if (!abc_b2b_designer_current_user_is_org_admin() && !$this->can_bypass()) {
            wp_send_json_error(['message' => 'Only Organization Admins can override.'], 403);
        }

        update_post_meta($draft_id, '_abc_ready_override', $override);
        update_post_meta($draft_id, '_abc_ready_override_by', (int)get_current_user_id());
        update_post_meta($draft_id, '_abc_ready_override_at', current_time('mysql'));

        wp_send_json_success(['draft_id' => (int)$draft_id, 'ready_override' => $override]);
    }

    public function add_draft_to_cart() {
        check_ajax_referer('abc_b2b_designer', 'nonce');
        $this->require_logged_in();

        if (!class_exists('WooCommerce') || !function_exists('WC')) {
            wp_send_json_error(['message' => 'WooCommerce is required.'], 400);
        }

        $draft_id = isset($_POST['draft_id']) ? (int) $_POST['draft_id'] : 0;
        if (!$draft_id) wp_send_json_error(['message' => 'Missing draft.'], 400);

        $this->assert_draft_access($draft_id);

        if (!abc_b2b_designer_current_user_is_org_admin() && !$this->can_bypass()) {
            wp_send_json_error(['message' => 'Only Organization Admins can add drafts to cart.'], 403);
        }

        // Require ready flags unless override/bypass
        $employee_ready = (int) get_post_meta($draft_id, '_abc_employee_ready', true);
        $admin_ready = (int) get_post_meta($draft_id, '_abc_admin_ready', true);
        $ready_override = (int) get_post_meta($draft_id, '_abc_ready_override', true);

        if (!$ready_override && !$this->can_bypass()) {
            if (!$employee_ready || !$admin_ready) {
                wp_send_json_error(['message' => 'Draft must be marked Ready by both Employee and Org Admin (or overridden) before adding to cart.'], 400);
            }
        }


        $employee_ready = (int) get_post_meta($draft_id, '_abc_employee_ready', true);
        $admin_ready = (int) get_post_meta($draft_id, '_abc_admin_ready', true);
        $override = (int) get_post_meta($draft_id, '_abc_ready_override', true);

        if (!$override && (!($employee_ready && $admin_ready))) {
            wp_send_json_error(['message' => 'Draft is not fully approved. Employee Ready and Org Admin Ready are required (or use override).'], 400);
        }

        $product_id = (int) get_post_meta($draft_id, '_abc_product_id', true);
        $template_id = (int) get_post_meta($draft_id, '_abc_template_id', true);
        $qty = (int) get_post_meta($draft_id, '_abc_qty', true);
        if ($qty < 1) $qty = 1;

        $variation_id = (int) get_post_meta($draft_id, '_abc_variation_id', true);
        $attrs_json = (string) get_post_meta($draft_id, '_abc_attributes', true);
        $attrs = [];
        if ($attrs_json) {
            $decoded = json_decode($attrs_json, true);
            if (is_array($decoded)) $attrs = $decoded;
        }

        $draft_current = trailingslashit(abc_b2b_designer_upload_drafts_dir()) . (int)$draft_id . '/current/';
        if (!file_exists($draft_current . 'design.json')) {
            wp_send_json_error(['message' => 'Draft has no saved proof yet. Please Save Proof first.'], 400);
        }

        // Copy into tmp token folder so existing order handling works
        $token = abc_b2b_designer_token(24);
        $tmp_dir = trailingslashit(abc_b2b_designer_upload_base_dir()) . 'tmp/' . $token . '/';
        wp_mkdir_p($tmp_dir);
        foreach (glob($draft_current . '*') as $file) {
            if (is_file($file)) {
                copy($file, $tmp_dir . basename($file));
            }
        }

        $cart_item_data = [
            'abc_design_token' => $token,
            'abc_template_id' => $template_id,
            'abc_draft_id' => $draft_id,
            'unique_key' => md5($token . '|' . $template_id . '|' . microtime(true)),
        ];

        $key = WC()->cart->add_to_cart($product_id, $qty, $variation_id, $attrs, $cart_item_data);
        if (!$key) {
            wp_send_json_error(['message' => 'Could not add to cart.'], 500);
        }

        wp_send_json_success([
            'cart_item_key' => $key,
            'cart_url' => wc_get_cart_url(),
        ]);
    }

    private function query_templates_for_product(int $product_id, int $org_id): array {
        if (!$product_id) return [];

        $all = get_posts([
            'post_type' => 'abc_designer_tpl',
            'numberposts' => 200,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        $out = [];
        foreach ($all as $p) {
            $ids_raw = (string) get_post_meta($p->ID, '_abc_product_ids', true);
            $org_req = (int) get_post_meta($p->ID, '_abc_org_id', true);

            if ($org_req && $org_req !== $org_id && !(current_user_can('manage_options') || current_user_can(ABC_B2B_DESIGNER_BYPASS_CAP))) continue;

            $ids = array_filter(array_map('intval', preg_split('/\s*,\s*/', trim($ids_raw))));
            if (!in_array($product_id, $ids, true)) continue;

            $out[] = [
                'id' => (int)$p->ID,
                'title' => html_entity_decode(get_the_title($p->ID), ENT_QUOTES, 'UTF-8'),
                'org_id' => $org_req,
            ];
        }
        return $out;
    }

    public function set_draft_template() {
        check_ajax_referer('abc_b2b_designer', 'nonce');
        $this->require_logged_in();

        $draft_id = isset($_POST['draft_id']) ? (int)$_POST['draft_id'] : 0;
        $template_id = isset($_POST['template_id']) ? (int)$_POST['template_id'] : 0;

        if (!$draft_id || !$template_id) {
            wp_send_json_error(['message' => 'Missing draft or template.'], 400);
        }

        $this->assert_draft_access($draft_id);

        $tpl = get_post($template_id);
        if (!$tpl || $tpl->post_type !== 'abc_designer_tpl') {
            wp_send_json_error(['message' => 'Template not found.'], 404);
        }

        // Ensure template is allowed for this user/org
        $org_id_required = (int)get_post_meta($template_id, '_abc_org_id', true);
        $org_id = abc_b2b_designer_current_user_org_id();
        if ($org_id_required && $org_id_required !== (int)$org_id && !$this->can_bypass()) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }

        // Ensure template supports this product
        $product_id = (int)get_post_meta($draft_id, '_abc_product_id', true);
        $pids = get_post_meta($template_id, '_abc_product_ids', true);
        $allowed = true;
        if (is_string($pids) && trim($pids) !== '') {
            $list = array_filter(array_map('intval', preg_split('/\s*,\s*/', $pids)));
            if (!empty($list) && !in_array($product_id, $list, true)) {
                $allowed = false;
            }
        }
        if (!$allowed && !$this->can_bypass()) {
            wp_send_json_error(['message' => 'Template not available for this product.'], 403);
        }

        // Keep overlapping fields from existing payload
        $payload_raw = (string)get_post_meta($draft_id, '_abc_payload', true);
        $old = json_decode($payload_raw, true);
        if (!is_array($old)) $old = [];

        $surfaces = get_post_meta($template_id, '_abc_surfaces', true);
        if (!is_array($surfaces)) $surfaces = [];

        $new_payload = $old;
        if (!is_array($new_payload)) $new_payload = [];
        if (!isset($new_payload['surfaces']) || !is_array($new_payload['surfaces'])) $new_payload['surfaces'] = [];

        $rebuilt = [];
        foreach ($surfaces as $skey => $cfg) {
            $skey = sanitize_key($skey);
            if (!$skey) continue;

            $rebuilt[$skey] = ['fields' => []];
            $fields_cfg = isset($cfg['fields']) && is_array($cfg['fields']) ? $cfg['fields'] : [];

            // old values for same surface
            $old_fields = [];
            if (isset($old['surfaces'][$skey]['fields']) && is_array($old['surfaces'][$skey]['fields'])) {
                $old_fields = $old['surfaces'][$skey]['fields'];
            }

            foreach ($fields_cfg as $f) {
                $k = isset($f['key']) ? sanitize_key($f['key']) : '';
                if (!$k) continue;
                if (isset($old_fields[$k])) {
                    $rebuilt[$skey]['fields'][$k] = (string)$old_fields[$k];
                } else {
                    $rebuilt[$skey]['fields'][$k] = '';
                }
            }
        }

        $new_payload['surfaces'] = $rebuilt;
        $new_payload_json = wp_json_encode($new_payload);

        update_post_meta($draft_id, '_abc_template_id', (int)$template_id);
        update_post_meta($draft_id, '_abc_payload', wp_slash($new_payload_json));
        update_post_meta($draft_id, '_abc_status', 'draft');
        abc_b2b_designer_reset_draft_ready_flags((int)$draft_id);

        // Clear proof files (force re-proof)
        $draft_dir = trailingslashit(abc_b2b_designer_upload_drafts_dir()) . (int)$draft_id . '/current/';
        if (is_dir($draft_dir)) {
            foreach (glob($draft_dir . '*') as $f) {
                if (is_file($f)) @unlink($f);
            }
        }
        wp_mkdir_p($draft_dir);
        file_put_contents($draft_dir . 'design.json', $new_payload_json);

        wp_send_json_success([
            'draft_id' => (int)$draft_id,
            'template_id' => (int)$template_id,
        ]);
    }

    public function duplicate_draft() {
        check_ajax_referer('abc_b2b_designer', 'nonce');
        $this->require_logged_in();

        $draft_id = isset($_POST['draft_id']) ? (int)$_POST['draft_id'] : 0;
        if (!$draft_id) wp_send_json_error(['message' => 'Missing draft.'], 400);

        $this->assert_draft_access($draft_id);

        $org_id = (int)get_post_meta($draft_id, '_abc_org_id', true);
        $product_id = (int)get_post_meta($draft_id, '_abc_product_id', true);
        $template_id = (int)get_post_meta($draft_id, '_abc_template_id', true);
        $qty = (int)get_post_meta($draft_id, '_abc_qty', true);
        $variation_id = (int)get_post_meta($draft_id, '_abc_variation_id', true);
        $attrs = (string)get_post_meta($draft_id, '_abc_attributes', true);
        $payload = (string)get_post_meta($draft_id, '_abc_payload', true);

        $new_id = wp_insert_post([
            'post_type' => 'abc_b2b_draft',
            'post_status' => 'publish',
            'post_title' => 'Draft: Copy ×' . max(1, $qty),
        ]);
        if (!$new_id || is_wp_error($new_id)) wp_send_json_error(['message' => 'Unable to duplicate draft.'], 500);

        update_post_meta($new_id, '_abc_org_id', $org_id);
        update_post_meta($new_id, '_abc_product_id', $product_id);
        update_post_meta($new_id, '_abc_template_id', $template_id);
        update_post_meta($new_id, '_abc_qty', max(1, $qty));
        update_post_meta($new_id, '_abc_variation_id', $variation_id);
        update_post_meta($new_id, '_abc_attributes', $attrs);
        update_post_meta($new_id, '_abc_payload', $payload);
        update_post_meta($new_id, '_abc_created_by', (int)get_current_user_id());
        update_post_meta($new_id, '_abc_status', 'draft');
        abc_b2b_designer_reset_draft_ready_flags((int)$new_id);

        // Copy current proof files
        $src = trailingslashit(abc_b2b_designer_upload_drafts_dir()) . (int)$draft_id . '/current/';
        $dst = trailingslashit(abc_b2b_designer_upload_drafts_dir()) . (int)$new_id . '/current/';
        wp_mkdir_p($dst);
        if (is_dir($src)) {
            foreach (glob($src . '*') as $f) {
                if (is_file($f)) {
                    @copy($f, $dst . basename($f));
                }
            }
        }

        wp_send_json_success([
            'draft_id' => (int)$new_id,
            'edit_url' => abc_b2b_designer_get_draft_editor_url((int)$new_id),
        ]);
    }

    public function reorder_order() {
        check_ajax_referer('abc_b2b_designer', 'nonce');
        $this->require_logged_in();

        if (!class_exists('WooCommerce') || !function_exists('WC')) {
            wp_send_json_error(['message' => 'WooCommerce is required.'], 400);
        }

        if (!abc_b2b_designer_current_user_is_org_admin() && !$this->can_bypass()) {
            wp_send_json_error(['message' => 'Only Organization Admins can reorder.'], 403);
        }

        $order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
        $mode = isset($_POST['mode']) ? sanitize_key($_POST['mode']) : 'reorder'; // reorder|changes
        if (!$order_id) wp_send_json_error(['message' => 'Missing order.'], 400);

        $order = wc_get_order($order_id);
        if (!$order) wp_send_json_error(['message' => 'Order not found.'], 404);

        // Validate org membership: placed by must be in same org
        $org_id = abc_b2b_designer_current_user_org_id();
        $cust_id = (int)$order->get_customer_id();
        $cust_org = (int)get_user_meta($cust_id, 'abc_b2b_org_id', true);
        if ($org_id && $cust_org && $org_id !== $cust_org && !$this->can_bypass()) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }

        $first_draft_url = '';
        $cart_url = wc_get_cart_url();

        foreach ($order->get_items() as $item_id => $item) {
            if (!($item instanceof WC_Order_Item_Product)) continue;

            $token = (string)$item->get_meta('_abc_design_token', true);
            $folder_url = (string)$item->get_meta('_abc_design_folder_url', true);
            $template_id = (int)$item->get_meta('_abc_template_id', true);

            $product_id = (int)$item->get_product_id();
            $variation_id = (int)$item->get_variation_id();
            $qty = (int)$item->get_quantity();
            $attrs = method_exists($item, 'get_variation_attributes') ? $item->get_variation_attributes() : [];

            // Create a draft so the cart can link to "Edit design"
            $draft_id = wp_insert_post([
                'post_type' => 'abc_b2b_draft',
                'post_status' => 'publish',
                'post_title' => 'Draft: Reorder ×' . max(1, $qty),
            ]);
            if (!$draft_id || is_wp_error($draft_id)) continue;

            update_post_meta($draft_id, '_abc_org_id', (int)$org_id);
            update_post_meta($draft_id, '_abc_product_id', $product_id);
            update_post_meta($draft_id, '_abc_template_id', $template_id);
            update_post_meta($draft_id, '_abc_qty', max(1, $qty));
            update_post_meta($draft_id, '_abc_variation_id', $variation_id);
            update_post_meta($draft_id, '_abc_attributes', wp_json_encode($attrs));
            update_post_meta($draft_id, '_abc_created_by', (int)get_current_user_id());
            update_post_meta($draft_id, '_abc_status', 'draft');
            abc_b2b_designer_reset_draft_ready_flags((int)$draft_id);

            // Copy files from order folder to draft current + tmp token
            $base_dir = abc_b2b_designer_upload_base_dir();
            $order_dir = trailingslashit($base_dir) . 'orders/' . (int)$order_id . '/' . $token . '/';
            $draft_dir = trailingslashit(abc_b2b_designer_upload_drafts_dir()) . (int)$draft_id . '/current/';
            wp_mkdir_p($draft_dir);

            if (is_dir($order_dir)) {
                foreach (glob($order_dir . '*') as $f) {
                    if (is_file($f)) {
                        @copy($f, $draft_dir . basename($f));
                    }
                }
                // payload
                if (file_exists($order_dir . 'design.json')) {
                    $payload = file_get_contents($order_dir . 'design.json');
                    if (is_string($payload) && trim($payload) !== '') {
                        update_post_meta($draft_id, '_abc_payload', wp_slash($payload));
                    }
                }
            }

            if (!$first_draft_url) {
                $first_draft_url = abc_b2b_designer_get_draft_editor_url((int)$draft_id);
            }

            if ($mode === 'reorder') {
                $new_token = wp_generate_uuid4();
                $tmp_dir = trailingslashit($base_dir) . 'tmp/' . $new_token . '/';
                wp_mkdir_p($tmp_dir);

                if (is_dir($draft_dir)) {
                    foreach (glob($draft_dir . '*') as $f) {
                        if (is_file($f)) {
                            @copy($f, $tmp_dir . basename($f));
                        }
                    }
                }

                $cart_item_data = [
                    'abc_design_token' => $new_token,
                    'abc_template_id' => $template_id,
                    'abc_draft_id' => (int)$draft_id,
                    'unique_key' => md5($new_token . '|' . microtime(true)),
                ];

                WC()->cart->add_to_cart($product_id, max(1,$qty), $variation_id, $attrs, $cart_item_data);
            }
        }

        if ($mode === 'reorder') {
            wp_send_json_success(['redirect' => $cart_url]);
        } else {
            wp_send_json_success(['redirect' => $first_draft_url ? $first_draft_url : $cart_url]);
        }
    }

}