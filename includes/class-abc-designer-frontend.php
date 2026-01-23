<?php
if (!defined('ABSPATH')) { exit; }

class ABC_B2B_Designer_Frontend {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('woocommerce_after_add_to_cart_form', [$this, 'render_product_draft_launcher'], 20);

        add_shortcode('abc_b2b_draft_editor', [$this, 'shortcode_draft_editor']);

        // Prevent direct add-to-cart when templates exist (teams should use drafts)
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_add_to_cart'], 10, 3);

        add_filter('woocommerce_add_cart_item_data', [$this, 'add_cart_item_data'], 10, 3);
        add_filter('woocommerce_get_item_data', [$this, 'display_cart_item_data'], 10, 2);
        add_action('woocommerce_check_cart_items', [$this, 'validate_cart_drafts_ready']);
    }

    public function enqueue() {
        if (!is_user_logged_in()) return;

        // Optional external font stylesheet (Adobe Fonts / Typekit)
        if (class_exists('ABC_B2B_Designer_Settings')) {
            $font_css = ABC_B2B_Designer_Settings::font_css_url();
            if ($font_css) {
                wp_enqueue_style('abc-b2b-designer-fonts', $font_css, [], null);
            }
        }

        // CSS used on product + draft editor
        wp_enqueue_style('abc-b2b-designer', ABC_B2B_DESIGNER_URL . 'assets/css/designer.css', [], ABC_B2B_DESIGNER_VERSION);

        global $post;
        $is_draft_editor_page = false;
        if ($post && is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'abc_b2b_draft_editor')) {
            $is_draft_editor_page = true;
        }

        if ($is_draft_editor_page) {
            // Determine draft id from query args (supports aliases)
            $draft_id = 0;
            if (isset($_GET['draft_id'])) $draft_id = (int) $_GET['draft_id'];
            if (!$draft_id && isset($_GET['draft'])) $draft_id = (int) $_GET['draft'];
            if (!$draft_id && isset($_GET['draftId'])) $draft_id = (int) $_GET['draftId'];

            // If no draft selected, skip heavy editor scripts (the shortcode will show a helpful draft list)
            if (!$draft_id) {
                return;
            }

            // Heavy editor assets only on Draft Editor page
            wp_enqueue_script('fabric-js', 'https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.0/fabric.min.js', [], '5.3.0', true);
            wp_enqueue_script('abc-b2b-draft-editor', ABC_B2B_DESIGNER_URL . 'assets/js/draft-editor.js', ['jquery', 'fabric-js'], ABC_B2B_DESIGNER_VERSION, true);

            wp_localize_script('abc-b2b-draft-editor', 'ABCDRAFTED', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('abc_b2b_designer'),
                'draft_id' => $draft_id,
            ]);
            return;
        }


        if (function_exists('is_product') && is_product()) {
            wp_enqueue_script('abc-b2b-product-draft', ABC_B2B_DESIGNER_URL . 'assets/js/product-draft.js', ['jquery'], ABC_B2B_DESIGNER_VERSION, true);
            wp_localize_script('abc-b2b-product-draft', 'ABCDRAFT', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('abc_b2b_designer'),
                'product_id' => get_the_ID(),
            ]);
        }
    }

    public function render_product_draft_launcher() {
        if (!class_exists('WooCommerce')) return;
        if (!function_exists('is_product') || !is_product()) return;

        global $product;
        if (!$product || !is_a($product, 'WC_Product')) return;

        if (!is_user_logged_in()) return;

        $can_bypass = current_user_can('manage_options') || current_user_can(ABC_B2B_DESIGNER_BYPASS_CAP);

        $org_id = abc_b2b_designer_current_user_org_id();
        $approved = abc_b2b_designer_current_user_is_approved();

        // If user has no org assigned, still show launcher for admins/bypass (can see all templates)
        if (!$org_id && !$can_bypass) {
            echo '<div class="abc-b2b-no-org">';
            echo '<strong>No Organization assigned.</strong> Your account is not linked to a customer Organization yet. ';
            echo 'Please contact ABC Printing to connect your account so templates can be enabled.';
            echo '</div>';
            return;
        }


        // If pending approval, show message only (no templates)
        if (!$approved && !$can_bypass) {
            $org_name = get_the_title($org_id);
            $organizer_first = abc_b2b_designer_org_organizer_first_name($org_id);
            echo '<div class="abc-b2b-pending-approval">';
            echo '<strong>Access pending:</strong> Your account is linked to <strong>' . esc_html($org_name) . '</strong>. ';
            echo 'Please contact <strong>' . esc_html($organizer_first) . '</strong> to approve your Organization access.';
            echo '</div>';
            return;
        }

        $product_id = $product->get_id();
        $templates = $this->templates_for_product((int)$product_id, (int)$org_id);

        // Hide launcher if no templates available for this product/org
        if (empty($templates) && !$can_bypass) {
            return;
        }

        ?>
        <div class="abc-designer-wrap" id="abc-draft-launcher" data-product-id="<?php echo (int)$product_id; ?>">
            <h3 class="abc-designer-title">Personalize with your brand template</h3>
            <div class="abc-designer-row">
                <div id="abc_template_notice" class="abc-template-notice" style="display:none;"></div>
                <p class="abc-designer-help" style="margin:0;">
                    Team workflow: Create a Draft, choose your template on the next page, customize it, mark ready, then your Organization Admin checks out.
                </p>
            </div>
                <p class="abc-designer-help">Team workflow: Create a Draft, customize it, mark ready, then your Organization Admin checks out.</p>
            </div>

            <div class="abc-designer-actions">
                <button type="button" class="button button-primary" id="abc_create_draft">Create Draft &amp; Customize</button>
                <span class="abc-save-status" id="abc_draft_status"></span>
            </div>
        </div>
        <?php
    }

    public function shortcode_draft_editor($atts = []) {
        if (!is_user_logged_in()) {
            return '<div class="abc-org-portal-notice">Please log in to customize your draft.</div>';
        }

        $draft_id = 0;
        if (isset($_GET['draft_id'])) $draft_id = (int) $_GET['draft_id'];
        if (!$draft_id && isset($_GET['draft'])) $draft_id = (int) $_GET['draft'];
        if (!$draft_id && isset($_GET['draftId'])) $draft_id = (int) $_GET['draftId'];

        if (!$draft_id) {
            // Show a helpful picker instead of a dead-end message
            $org_id = abc_b2b_designer_current_user_org_id();
            $can_bypass = current_user_can('manage_options') || current_user_can(ABC_B2B_DESIGNER_BYPASS_CAP);
            $args = [
                'post_type' => 'abc_b2b_draft',
                'post_status' => 'publish',
                'numberposts' => 25,
                'orderby' => 'date',
                'order' => 'DESC',
            ];
            $drafts = get_posts($args);
            if (!$can_bypass && $org_id) {
                $drafts = array_values(array_filter($drafts, function($d) use ($org_id){
                    return (int)get_post_meta($d->ID, '_abc_org_id', true) === (int)$org_id;
                }));
            }

            ob_start();
            echo '<div class="abc-org-portal-notice">';
            echo '<strong>No draft selected.</strong> Create a draft from a product page (Template → Create Draft & Customize), or open a recent draft below.';
            echo '</div>';

            if (!empty($drafts)) {
                echo '<div class="abc-designer-wrap" style="margin-top:14px;">';
                echo '<h3 class="abc-designer-title" style="margin-top:0;">Recent Drafts</h3>';
                echo '<table class="shop_table" style="width:100%;">';
                echo '<thead><tr><th>Draft</th><th>Status</th><th>Qty</th><th>Open</th></tr></thead><tbody>';
                foreach ($drafts as $d) {
                    $status = (string)get_post_meta($d->ID, '_abc_status', true);
                    $qty = (int)get_post_meta($d->ID, '_abc_qty', true);
                    if ($qty < 1) $qty = 1;
                    $url = abc_b2b_designer_get_draft_editor_url((int)$d->ID);
                    echo '<tr>';
                    echo '<td>' . esc_html(get_the_title($d->ID)) . '</td>';
                    echo '<td>' . esc_html($status ?: 'draft') . '</td>';
                    echo '<td>' . esc_html((string)$qty) . '</td>';
                    echo '<td><a class="button" href="' . esc_url($url) . '">Open</a></td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
                echo '</div>';
            }

            return ob_get_clean();
        }

        ob_start();
        ?>
        <div class="abc-designer-wrap abc-draft-editor" id="abc-draft-editor" data-draft-id="<?php echo (int)$draft_id; ?>">
            <div class="abc-draft-topbar">
                <div class="abc-draft-topbar-left">
                    <h3 class="abc-designer-title" style="margin:0;">Customize Draft</h3>
                    <div class="abc-draft-template-picker" id="abc_draft_template_picker" style="margin-top:8px;display:none;">
                        <label style="display:flex;gap:10px;align-items:center;margin:0;">
                            <strong style="min-width:70px;">Template</strong>
                            <select id="abc_draft_template_select" style="min-width:260px;max-width:420px;">
                                <option value="">Loading…</option>
                            </select>
                            <button type="button" class="button button-primary" id="abc_template_apply" style="display:none;">Apply</button>
                            <button type="button" class="button" id="abc_template_switch" style="display:none;">Switch</button>
                        </label>
                        <div class="abc-muted" style="margin-top:4px;">Switching templates keeps overlapping field values and resets approvals &amp; proofs.</div>
                    </div>

                    <div class="abc-draft-meta">
                        <label>Qty
                            <input type="number" min="1" step="1" id="abc_draft_qty" value="1" style="width:90px;">
                        </label>
                        <span class="abc-muted" id="abc_ready_hint">Changing quantity or saving proof resets approvals.</span>
                    </div>
                </div>
                <div class="abc-draft-topbar-right">
                    <button type="button" class="button" id="abc_employee_ready">Employee Ready</button>
                    <button type="button" class="button" id="abc_admin_ready" style="display:none;">Org Admin Ready</button>
                    <label class="abc-override-wrap" style="display:none;">
                        <input type="checkbox" id="abc_ready_override"> Override
                    </label>
                    <button type="button" class="button button-primary" id="abc_add_draft_to_cart" style="display:none;">Add to Cart</button>
                    <a href="#" class="button" id="abc_view_cart" style="display:none;">View Cart</a>
                </div>
            </div>

            <div class="abc-designer-grid">
                <div class="abc-designer-left">
                    <div id="abc_surface_tabs" class="abc-surface-tabs" style="display:none;"></div>
                    <div class="abc-canvas-wrap">
                        <canvas id="abc_canvas" width="900" height="600"></canvas>
                    </div>
                    <div class="abc-designer-actions">
                        <button type="button" class="button" id="abc_save_draft">Save Proof</button>
                        <span class="abc-save-status" id="abc_save_status"></span>
                    </div>
                    <p class="abc-muted" style="margin-top:8px;">
                        Share link: <input type="text" id="abc_share_link" value="<?php echo esc_attr(esc_url(abc_b2b_designer_get_draft_editor_url((int)$draft_id))); ?>" readonly style="width:100%;max-width:680px;">
                    </p>
                </div>

                <div class="abc-designer-right">
                    <div class="abc-order-details" id="abc_order_details" style="margin-bottom:10px;"></div>
                    <h4>Fields</h4>
                    <div id="abc_fields_form">
                        <em>Loading…</em>
                    </div>
                    <hr>
                    <h4>Proof Previews</h4>
                    <div id="abc_preview_list"><em>No previews saved yet.</em></div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function validate_add_to_cart($passed, $product_id, $qty) {
        if (!$passed) return false;

        // Allow admins to bypass
        $can_bypass = current_user_can('manage_options') || current_user_can(ABC_B2B_DESIGNER_BYPASS_CAP);
        if ($can_bypass) return $passed;

        if (!is_user_logged_in()) return $passed;

        $org_id = abc_b2b_designer_current_user_org_id();
        if (!$org_id) return $passed;

        // If user is part of an org and templates exist for this product, require draft workflow
        $templates = $this->templates_for_product((int)$product_id, (int)$org_id);
        if (!empty($templates)) {
            // Block direct add-to-cart (draft adds through AJAX)
            wc_add_notice('Please create a Draft and customize it first (use the Template section below). Your Organization Admin will check out.', 'error');
            return false;
        }

        return $passed;
    }

    public function add_cart_item_data($cart_item_data, $product_id, $variation_id) {
        // Legacy flow support (if used)
        if (!empty($_POST['abc_design_token'])) {
            $cart_item_data['abc_design_token'] = sanitize_text_field(wp_unslash($_POST['abc_design_token']));
        }
        if (!empty($_POST['abc_template_id'])) {
            $cart_item_data['abc_template_id'] = (int) wp_unslash($_POST['abc_template_id']);
        }
        if (!empty($cart_item_data['abc_design_token'])) {
            $cart_item_data['unique_key'] = md5($cart_item_data['abc_design_token'] . '|' . microtime(true));
        }
        return $cart_item_data;
    }

    public function display_cart_item_data($item_data, $cart_item) {
        if (!empty($cart_item['abc_template_id'])) {
            $item_data[] = [
                'key' => 'Template',
                'value' => esc_html(get_the_title((int)$cart_item['abc_template_id'])),
            ];
        }

        if (!empty($cart_item['abc_draft_id'])) {
            $url = abc_b2b_designer_get_draft_editor_url((int)$cart_item['abc_draft_id']);
            $item_data[] = [
                'key' => 'Design',
                'value' => wp_kses_post('<a href="' . esc_url($url) . '">Edit design</a>'),
            ];
        }

        return $item_data;
    }

    public function templates_for_product(int $product_id, int $org_id): array {
        // Reuse AJAX query function by instantiating AJAX class method? Keep local query:
        $args = [
            'post_type' => 'abc_designer_tpl',
            'post_status' => 'publish',
            'numberposts' => 200,
            'orderby' => 'title',
            'order' => 'ASC',
            'meta_query' => [],
        ];

        // Product match
        $args['meta_query'][] = [
            'key' => '_abc_product_ids',
            'value' => (string)$product_id,
            'compare' => 'LIKE',
        ];

        // Organization match or All (0)
        $args['meta_query'][] = [
            'relation' => 'OR',
            [
                'key' => '_abc_org_id',
                'compare' => 'NOT EXISTS',
            ],
            [
                'key' => '_abc_org_id',
                'value' => 0,
                'compare' => '=',
            ],
            [
                'key' => '_abc_org_id',
                'value' => $org_id,
                'compare' => '=',
            ]
        ];

        return get_posts($args);
    }

    public function validate_cart_drafts_ready() {
        if (!function_exists('WC') || !WC()->cart) return;
        $can_bypass = current_user_can('manage_options') || current_user_can(ABC_B2B_DESIGNER_BYPASS_CAP);
        foreach (WC()->cart->get_cart() as $key => $item) {
            if (empty($item['abc_draft_id'])) continue;
            $draft_id = (int)$item['abc_draft_id'];
            if (!$draft_id) continue;

            $employee_ready = (int)get_post_meta($draft_id, '_abc_employee_ready', true);
            $admin_ready = (int)get_post_meta($draft_id, '_abc_admin_ready', true);
            $override = (int)get_post_meta($draft_id, '_abc_ready_override', true);

            if ($override || $can_bypass) continue;

            if (!$employee_ready || !$admin_ready) {
                wc_add_notice('A cart item design is not marked Ready by both Employee and Org Admin. Please open "Edit design" and complete approvals.', 'error');
                break;
            }
        }
    }

}
