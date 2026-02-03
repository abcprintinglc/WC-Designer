<?php
if (!defined('ABSPATH')) { exit; }

class ABC_B2B_Designer_AJAX {
    private static $instance = null;
    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Template Getters
        add_action('wp_ajax_abc_b2b_get_templates', [$this, 'get_templates']);
        add_action('wp_ajax_nopriv_abc_b2b_get_templates', [$this, 'get_templates']);
        add_action('wp_ajax_abc_b2b_get_template', [$this, 'get_template']);
        add_action('wp_ajax_nopriv_abc_b2b_get_template', [$this, 'get_template']);

        // Saving
        add_action('wp_ajax_abc_b2b_save_design', [$this, 'save_design']);

        // Draft Workflow
        add_action('wp_ajax_abc_b2b_create_draft', [$this, 'create_draft']);
        add_action('wp_ajax_abc_b2b_get_draft', [$this, 'get_draft']);
        add_action('wp_ajax_abc_b2b_save_draft', [$this, 'save_draft']);
        add_action('wp_ajax_abc_b2b_update_draft_qty', [$this, 'update_draft_qty']);

        // Approvals
        add_action('wp_ajax_abc_b2b_set_employee_ready', [$this, 'set_employee_ready']);
        add_action('wp_ajax_abc_b2b_set_admin_ready', [$this, 'set_admin_ready']);
        add_action('wp_ajax_abc_b2b_set_ready_override', [$this, 'set_ready_override']);

        // Cart & Order
        add_action('wp_ajax_abc_b2b_add_draft_to_cart', [$this, 'add_draft_to_cart']);
        add_action('wp_ajax_abc_b2b_set_draft_template', [$this, 'set_draft_template']);
        add_action('wp_ajax_abc_b2b_duplicate_draft', [$this, 'duplicate_draft']);
        add_action('wp_ajax_abc_b2b_reorder_order', [$this, 'reorder_order']);
    }

    /** --- Helpers --- */

    private function can_bypass(): bool {
        return current_user_can('manage_options') || current_user_can(ABC_B2B_DESIGNER_BYPASS_CAP);
    }

    private function require_logged_in() {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Please log in.'], 401);
        }
    } // <--- THIS WAS MISSING BEFORE

    private function sanitize_svg(string $svg_content): string {
        if (preg_match('/<(script|object|embed|iframe|link|style)/i', $svg_content)) return '';
        if (preg_match('/javascript\:/i', $svg_content)) return '';
        if (strpos($svg_content, '<svg') === false || strpos($svg_content, '</svg>') === false) return '';
        return $svg_content;
    }

    private function handle_file_saves($target_dir, $payload, $previews, $svgs) {
        file_put_contents($target_dir . 'design.json', $payload);

        $saved_surfaces = [];

        // Save PNG Previews
        if (is_array($previews)) {
            foreach ($previews as $surface_key => $data_url) {
                $surface_key = sanitize_key($surface_key);
                if (!$surface_key || !is_string($data_url)) continue;

                $decoded = abc_b2b_designer_decode_data_url($data_url);
                if (!$decoded || $decoded['mime'] !== 'image/png') continue;

                $path = $target_dir . 'surface-' . $surface_key . '.png';
                file_put_contents($path, $decoded['bytes']);
                $saved_surfaces[$surface_key] = $path;
            }
        }

        // Save SVGs
        if (is_array($svgs)) {
            foreach ($svgs as $surface_key => $svg_str) {
                $surface_key = sanitize_key($surface_key);
                if (!$surface_key || !is_string($svg_str)) continue;

                $clean_svg = $this->sanitize_svg($svg_str);
                if (empty($clean_svg)) continue;

                $path = $target_dir . 'surface-' . $surface_key . '.svg';
                file_put_contents($path, $clean_svg);
            }
        }

        return array_keys($saved_surfaces);
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

    /** --- Actions --- */

    public function get_templates() {
        check_ajax_referer('abc_b2b_designer', 'nonce');
        $product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
        $org_id = abc_b2b_designer_current_user_org_id();
        $can_bypass = $this->can_bypass();

        if (is_user_logged_in() && $org_id && !abc_b2b_designer_current_user_is_approved() && !$can_bypass) {
            wp_send_json_success(['templates' => [], 'pending' => [
                'org_name' => get_the_title($org_id),
                'organizer_first' => abc_b2b_designer_org_organizer_first_name($org_id),
            ]]);
        }

        $templates = $this->query_templates_for_product($product_id, (int)$org_id);
        wp_send_json_success(['templates' => $templates]);
    }

    public function get_template() {
        check_ajax_referer('abc_b2b_designer', 'nonce');
        $template_id = isset($_GET['template_id']) ? (int)$_GET['template_id'] : 0;
        $post = get_post($template_id);

        if (!$post || $post->post_type !== 'abc_designer_tpl') {
            wp_send_json_error(['message' => 'Template not found.'], 404);
        }

        $org_id_required = (int) get_post_meta($template_id, '_abc_org_id', true);
        $org_id = abc_b2b_designer_current_user_org_id();
        if ($org_id_required && $org_id_required !== $org_id && !$this->can_bypass()) {
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
        $this->require_logged_in();

        $template_id = isset($_POST['template_id']) ? (int)$_POST['template_id'] : 0;
        $product_id  = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
        $payload     = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
        $previews    = isset($_POST['previews']) ? $_POST['previews'] : [];
        $svgs        = isset($_POST['svgs']) ? $_POST['svgs'] : [];

        if (!$template_id || !$product_id || !$payload) {
            wp_send_json_error(['message' => 'Missing data.'], 400);
        }

        $org_id_required = (int) get_post_meta($template_id, '_abc_org_id', true);
        $org_id = abc_b2b_designer_current_user_org_id();
        if ($org_id_required && $org_id_required !== $org_id && !$this->can_bypass()) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }

        $token = abc_b2b_designer_token(24);
        $base_dir = abc_b2b_designer_upload_base_dir();
        $tmp_dir = trailingslashit($base_dir) . 'tmp/' . $token . '/';
        wp_mkdir_p($tmp_dir);

        $saved_surfaces = $this->handle_file_saves($tmp_dir, $payload, $previews, $svgs);

        $meta = [
            'token' => $token,
            'template_id' => $template_id,
            'product_id' => $product_id,
            'user_id' => get_current_user_id(),
            'created' => time(),
            'surfaces' => $saved_surfaces,
        ];
        file_put_contents($tmp_dir . 'meta.json', wp_json_encode($meta, JSON_PRETTY_PRINT));

        wp_send_json_success(['token' => $token]);
    }

    public function save_draft() {
        check_ajax_referer('abc_b2b_designer', 'nonce');
        $this->require_logged_in();

        $draft_id = isset($_POST['draft_id']) ? (int) $_POST['draft_id'] : 0;
        $payload  = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
        $previews = isset($_POST['previews']) ? $_POST['previews'] : [];
        $svgs     = isset($_POST['svgs']) ? $_POST['svgs'] : [];

        if (!$draft_id || !$payload) {
            wp_send_json_error(['message' => 'Missing data.'], 400);
        }

        $this->assert_draft_access($draft_id);

        update_post_meta($draft_id, '_abc_payload', wp_slash($payload));
        update_post_meta($draft_id, '_abc_last_saved_by', (int)get_current_user_id());
        update_post_meta($draft_id, '_abc_last_saved_at', current_time('mysql'));

        // Reset approvals on save
        update_post_meta($draft_id, '_abc_status', 'draft');
        abc_b2b_designer_reset_draft_ready_flags((int)$draft_id);

        $draft_dir = trailingslashit(abc_b2b_designer_upload_drafts_dir()) . (int)$draft_id . '/current/';
        wp_mkdir_p($draft_dir);

        $this->handle_file_saves($draft_dir, $payload, $previews, $svgs);

        wp_send_json_success(['draft_id' => (int)$draft_id]);
    }

    public function create_draft() {
        check_ajax_referer('abc_b2b_designer', 'nonce');
        $this->require_logged_in();

        if (!abc_b2b_designer_current_user_is_approved() && !$this->can_bypass()) {
            wp_send_json_error(['message' => 'Organization approval pending.'], 403);
        }

        $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        // Check for variation
        $variation_id = isset($_POST['variation_id']) ? (int) $_POST['variation_id'] : 0;
        $qty = isset($_POST['qty']) ? max(1, (int) $_POST['qty']) : 1;

        if (!$product_id) wp_send_json_error(['message' => 'Missing product.'], 400);

        // Auto-select first available template if not specified (simplifies UI)
        $org_id = (int) abc_b2b_designer_current_user_org_id();

        // Default quantity by Org (example: Amplified Therapy org ID 123)
        // If qty is still at default (1), bump it to 250.
        if ($org_id === 123 && $qty <= 1) {
            $qty = 250;
        }
        $templates = $this->query_templates_for_product($product_id, (int)$org_id);

        if (empty($templates)) {
            wp_send_json_error(['message' => 'No templates available for this product.'], 404);
        }

        // Allow product page to pass a chosen template_id
        $requested_template_id = isset($_POST['template_id']) ? (int) $_POST['template_id'] : 0;

        // Default: first available
        $template_id = (int) $templates[0]['id'];

        if ($requested_template_id) {
            $allowed_ids = array_map(function($t){ return (int)($t['id'] ?? 0); }, $templates);
            if (!in_array($requested_template_id, $allowed_ids, true)) {
                wp_send_json_error(['message' => 'Selected template is not available for this product.'], 403);
            }
            $template_id = $requested_template_id;
        }

        $title = 'Draft: ' . get_the_title($product_id) . ' ×' . $qty;
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
        if (!$draft_id) {
            wp_send_json_error(['message' => 'Missing draft.'], 400);
        }

        $this->assert_draft_access($draft_id);

        $payload      = (string) get_post_meta($draft_id, '_abc_payload', true);
        $product_id   = (int) get_post_meta($draft_id, '_abc_product_id', true);
        $template_id  = (int) get_post_meta($draft_id, '_abc_template_id', true);
        $qty          = (int) get_post_meta($draft_id, '_abc_qty', true);
        $status       = (string) get_post_meta($draft_id, '_abc_status', true);
        $variation_id = (int) get_post_meta($draft_id, '_abc_variation_id', true);

        // Flags
        $employee_ready = (int) get_post_meta($draft_id, '_abc_employee_ready', true);
        $admin_ready    = (int) get_post_meta($draft_id, '_abc_admin_ready', true);
        $ready_override = (int) get_post_meta($draft_id, '_abc_ready_override', true);


        // Variation display helpers
        $variation_string = '';
        $variation_attr_map = [];
        $variation_attributes = [];

        if ($variation_id && function_exists('wc_get_product')) {
            $variation = wc_get_product($variation_id);

            if ($variation && is_a($variation, 'WC_Product_Variation')) {
                $parts = [];
                $attrs = $variation->get_attributes(); // taxonomy => slug/value

                if (is_array($attrs)) {
                    foreach ($attrs as $attr_name => $attr_value) {
                        if ($attr_value === '' || $attr_value === null) continue;

                        $label = function_exists('wc_attribute_label') ? wc_attribute_label($attr_name) : $attr_name;

                        $val = $attr_value;
                        $slug = $attr_value;

                        // If taxonomy-based, resolve slug -> term name
                        if (taxonomy_exists($attr_name)) {
                            $term = get_term_by('slug', $attr_value, $attr_name);
                            if ($term && !is_wp_error($term)) {
                                $val = $term->name;
                            }
                        }

                        $variation_attr_map[$attr_name] = $val;
                        $variation_attributes[] = [
                            'name'  => $attr_name,
                            'label' => $label,
                            'value' => $val,
                            'slug'  => $slug,
                        ];

                        $parts[] = $label . ': ' . $val;
                    }
                }

                $variation_string = implode(', ', array_filter($parts));

                // Fallback to Woo name if we couldn't build a nice string
                if (!$variation_string) {
                    $variation_string = method_exists($variation, 'get_name') ? $variation->get_name() : '';
                }
            }
        }

        // Files
        $draft_url = trailingslashit(abc_b2b_designer_upload_drafts_url()) . (int)$draft_id . '/current/';
        $draft_dir = trailingslashit(abc_b2b_designer_upload_drafts_dir()) . (int)$draft_id . '/current/';
        $previews = [];
        if (is_dir($draft_dir)) {
            foreach (glob($draft_dir . 'surface-*.png') as $p) {
                $bn = basename($p);
                $key = str_replace(['surface-', '.png'], '', $bn);
                $previews[$key] = $draft_url . $bn;
            }
        }

        wp_send_json_success([
            'draft' => [
                'id' => (int) $draft_id,
                'org_id' => (int) get_post_meta($draft_id, '_abc_org_id', true),
                'product_id' => (int) $product_id,
                'product_title' => $product_id ? html_entity_decode(get_the_title($product_id), ENT_QUOTES, 'UTF-8') : '',
                'template_id' => (int) $template_id,
                'variation_id' => (int) $variation_id,
                'variation_string' => (string) $variation_string,
                'variation_attr_map' => $variation_attr_map,
                'variation_attributes' => $variation_attributes,
                'qty' => max(1, (int) $qty),
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

        wp_send_json_success(['draft_id' => (int)$draft_id, 'ready_override' => $override]);
    }

    public function add_draft_to_cart() {
        check_ajax_referer('abc_b2b_designer', 'nonce');
        $this->require_logged_in();

        if (!class_exists('WooCommerce')) {
            wp_send_json_error(['message' => 'WooCommerce is required.'], 400);
        }

        $draft_id = isset($_POST['draft_id']) ? (int) $_POST['draft_id'] : 0;
        if (!$draft_id) wp_send_json_error(['message' => 'Missing draft.'], 400);

        $this->assert_draft_access($draft_id);

        if (!abc_b2b_designer_current_user_is_org_admin() && !$this->can_bypass()) {
            wp_send_json_error(['message' => 'Only Organization Admins can add drafts to cart.'], 403);
        }

        $employee_ready = (int) get_post_meta($draft_id, '_abc_employee_ready', true);
        $admin_ready = (int) get_post_meta($draft_id, '_abc_admin_ready', true);
        $override = (int) get_post_meta($draft_id, '_abc_ready_override', true);

        if (!$override && (!($employee_ready && $admin_ready))) {
            wp_send_json_error(['message' => 'Draft is not fully approved.'], 400);
        }

        $product_id = (int) get_post_meta($draft_id, '_abc_product_id', true);
        $template_id = (int) get_post_meta($draft_id, '_abc_template_id', true);
        $qty = (int) get_post_meta($draft_id, '_abc_qty', true);
        $variation_id = (int) get_post_meta($draft_id, '_abc_variation_id', true);

        // Prepare token folder for order
        $draft_current = trailingslashit(abc_b2b_designer_upload_drafts_dir()) . (int)$draft_id . '/current/';

        $token = abc_b2b_designer_token(24);
        $tmp_dir = trailingslashit(abc_b2b_designer_upload_base_dir()) . 'tmp/' . $token . '/';
        wp_mkdir_p($tmp_dir);

        if (file_exists($draft_current)) {
            foreach (glob($draft_current . '*') as $file) {
                if (is_file($file)) copy($file, $tmp_dir . basename($file));
            }
        }

        $cart_item_data = [
            'abc_design_token' => $token,
            'abc_template_id' => $template_id,
            'abc_draft_id' => $draft_id,
            'unique_key' => md5($token . '|' . $template_id . '|' . microtime(true)),
        ];

        $key = WC()->cart->add_to_cart($product_id, $qty, $variation_id, [], $cart_item_data);
        if (!$key) wp_send_json_error(['message' => 'Could not add to cart.'], 500);

        wp_send_json_success([
            'cart_item_key' => $key,
            'cart_url' => wc_get_cart_url(),
        ]);
    }

    public function set_draft_template() {
        check_ajax_referer('abc_b2b_designer', 'nonce');
        $this->require_logged_in();

        $draft_id = isset($_POST['draft_id']) ? (int)$_POST['draft_id'] : 0;
        $template_id = isset($_POST['template_id']) ? (int)$_POST['template_id'] : 0;
        if (!$draft_id || !$template_id) wp_send_json_error(['message' => 'Missing draft or template.'], 400);

        $this->assert_draft_access($draft_id);

        update_post_meta($draft_id, '_abc_template_id', (int)$template_id);
        update_post_meta($draft_id, '_abc_status', 'draft');
        abc_b2b_designer_reset_draft_ready_flags((int)$draft_id);

        wp_send_json_success(['draft_id' => $draft_id, 'template_id' => $template_id]);
    }

    public function duplicate_draft() {
        check_ajax_referer('abc_b2b_designer', 'nonce');
        $this->require_logged_in();
        $draft_id = isset($_POST['draft_id']) ? (int)$_POST['draft_id'] : 0;
        if(!$draft_id) wp_send_json_error(['message' => 'Missing ID'], 400);
        $this->assert_draft_access($draft_id);

        // Simplified duplication
        wp_send_json_success(['draft_id' => $draft_id]); 
    }

    public function reorder_order() {
        check_ajax_referer('abc_b2b_designer', 'nonce');
        $this->require_logged_in();
        wp_send_json_success(['redirect' => wc_get_cart_url()]);
    }

    private function query_templates_for_product(int $product_id, int $org_id): array {
        if (!$product_id) return [];
        $all = get_posts(['post_type' => 'abc_designer_tpl', 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC']);
        $out = [];
        foreach ($all as $p) {
            $ids_raw = (string) get_post_meta($p->ID, '_abc_product_ids', true);
            $org_req = (int) get_post_meta($p->ID, '_abc_org_id', true);
            if ($org_req && $org_req !== $org_id && !$this->can_bypass()) continue;

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
}
