<?php
if (!defined('ABSPATH')) { exit; }

class ABC_B2B_Designer_CPT {
    private static $instance = null;
    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        add_action('init', [$this, 'register_cpts']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post_abc_designer_tpl', [$this, 'save_template_meta'], 10, 2);

        add_filter('manage_abc_designer_tpl_posts_columns', [$this, 'columns']);
        add_action('manage_abc_designer_tpl_posts_custom_column', [$this, 'column_content'], 10, 2);
    }

    public function register_cpts() {
        if (post_type_exists('abc_designer_tpl')) { return; }

        register_post_type('abc_designer_tpl', [
            'labels' => [
                'name' => 'Designer Templates',
                'singular_name' => 'Designer Template',
                'add_new_item' => 'Add New Template',
                'edit_item' => 'Edit Template',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'edit.php?post_type=abc_b2b_org',
            'menu_icon' => 'dashicons-art',
            'supports' => ['title'],
            'capability_type' => 'post',
        ]);

        // Drafts (team workflow)
        register_post_type('abc_b2b_draft', [
            'labels' => [
                'name' => 'Design Drafts',
                'singular_name' => 'Design Draft',
                'add_new_item' => 'Add New Draft',
                'edit_item' => 'Edit Draft',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'edit.php?post_type=abc_b2b_org',
            'menu_icon' => 'dashicons-clipboard',
            'supports' => ['title'],
            'capability_type' => 'post',
        ]);

    }

    public function columns($cols) {
        $cols['abc_org'] = 'Organization';
        $cols['abc_products'] = 'Products';
        $cols['abc_surfaces'] = 'Surfaces';
        return $cols;
    }

    public function column_content($col, $post_id) {
        if ($col === 'abc_org') {
            $org_id = (int) get_post_meta($post_id, '_abc_org_id', true);
            echo $org_id ? esc_html(get_the_title($org_id)) . ' (#' . (int)$org_id . ')' : 'All';
        }
        if ($col === 'abc_products') {
            $ids = get_post_meta($post_id, '_abc_product_ids', true);
            echo esc_html($ids ?: '');
        }
        if ($col === 'abc_surfaces') {
            $surfaces = get_post_meta($post_id, '_abc_surfaces', true);
            if (is_array($surfaces)) {
                echo esc_html(implode(', ', array_keys($surfaces)));
            } else {
                echo 'front';
            }
        }
    }

    public function add_meta_boxes() {
        add_meta_box(
            'abc_template_meta',
            'Template Settings',
            [$this, 'render_meta_box'],
            'abc_designer_tpl',
            'normal',
            'high'
        );
    }

    public function render_meta_box($post) {
        wp_nonce_field('abc_template_meta_save', 'abc_template_meta_nonce');
        $org_id = (int) get_post_meta($post->ID, '_abc_org_id', true);
        $product_ids = (string) get_post_meta($post->ID, '_abc_product_ids', true);

        $surfaces = get_post_meta($post->ID, '_abc_surfaces', true);
        if (!is_array($surfaces) || empty($surfaces)) {
            $surfaces = [
                'front' => [
                    'trim_w_in' => 3.5,
                    'trim_h_in' => 2.0,
                    'bleed_in' => 0.125,
                    'safe_in'  => 0.125,
                    'dpi'      => 300,
                    'bg_attachment_id' => 0,
                    'fields' => [
                        [
                            'key' => 'name',
                            'label' => 'Name',
                            'type' => 'text',
                            'required' => 1,
                            'left_in' => 0.25,
                            'top_in' => 1.2,
                            'width_in' => 3.0,
                            'height_in' => 0.35,
                            'font_family' => 'Arial',
                            'font_size' => 24,
                            'color' => '#000000',
                            'lock_style' => 1,
                            'max_chars' => 40,
                        ],
                        [
                            'key' => 'phone',
                            'label' => 'Phone',
                            'type' => 'text',
                            'required' => 0,
                            'left_in' => 0.25,
                            'top_in' => 1.55,
                            'width_in' => 3.0,
                            'height_in' => 0.30,
                            'font_family' => 'Arial',
                            'font_size' => 16,
                            'color' => '#000000',
                            'lock_style' => 1,
                            'max_chars' => 40,
                        ],
                    ],
                ],
                'back' => [
                    'trim_w_in' => 3.5,
                    'trim_h_in' => 2.0,
                    'bleed_in' => 0.125,
                    'safe_in'  => 0.125,
                    'dpi'      => 300,
                    'bg_attachment_id' => 0,
                    'fields' => [],
                ],
            ];
        }

        $orgs = get_posts([
            'post_type' => 'abc_b2b_org',
            'numberposts' => 200,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        wp_enqueue_media();
        wp_enqueue_style('abc-b2b-designer-admin', ABC_B2B_DESIGNER_URL . 'assets/css/admin.css', [], ABC_B2B_DESIGNER_VERSION);
        // Optional external font stylesheet (Adobe Fonts / Typekit)
        if (class_exists('ABC_B2B_Designer_Settings')) {
            $font_css = ABC_B2B_Designer_Settings::font_css_url();
            if ($font_css) {
                wp_enqueue_style('abc-b2b-designer-fonts', $font_css, [], null);
            }
        }
                wp_enqueue_script('abc-b2b-designer-admin', ABC_B2B_DESIGNER_URL . 'assets/js/admin-template.js', ['jquery'], ABC_B2B_DESIGNER_VERSION, true);
        wp_enqueue_script('abc-b2b-designer-interact', 'https://cdn.jsdelivr.net/npm/interactjs/dist/interact.min.js', [], null, true);
        wp_enqueue_script('abc-b2b-designer-admin-builder', ABC_B2B_DESIGNER_URL . 'assets/js/admin-builder.js', ['jquery', 'abc-b2b-designer-interact'], ABC_B2B_DESIGNER_VERSION, true);
        wp_enqueue_style('abc-b2b-designer-admin-builder', ABC_B2B_DESIGNER_URL . 'assets/css/admin-builder.css', [], ABC_B2B_DESIGNER_VERSION);
        ?>
        <div class="abc-admin-grid">
            <div class="abc-admin-row">
                <label><strong>Organization</strong></label>
                <select name="abc_org_id">
                    <option value="0" <?php selected($org_id, 0); ?>>All (no restriction)</option>
                    <?php foreach ($orgs as $o): ?>
                        <option value="<?php echo (int)$o->ID; ?>" <?php selected($org_id, (int)$o->ID); ?>>
                            <?php echo esc_html($o->post_title) . ' (#' . (int)$o->ID . ')'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">If set, only users assigned to this organization can see this template.</p>
            </div>

            <div class="abc-admin-row">
                <label><strong>WooCommerce Product IDs</strong></label>
                <input type="text" name="abc_product_ids" value="<?php echo esc_attr($product_ids); ?>" placeholder="e.g. 123, 456">
                <p class="description">Comma-separated WooCommerce product IDs this template applies to.</p>
            </div>
        </div>

        <hr>

        <h3>Surfaces</h3>
        <p class="description">Phase 1 supports Front/Back (or Inside/Outside). Each surface can have a background and editable fields.</p>

        <div id="abc-surfaces">
            <?php foreach ($surfaces as $surface_key => $surface): ?>
                <?php
                $trim_w = abc_b2b_designer_safe_float($surface['trim_w_in'] ?? 3.5, 3.5);
                $trim_h = abc_b2b_designer_safe_float($surface['trim_h_in'] ?? 2.0, 2.0);
                $bleed  = abc_b2b_designer_safe_float($surface['bleed_in'] ?? 0.125, 0.125);
                $safe   = abc_b2b_designer_safe_float($surface['safe_in'] ?? 0.125, 0.125);
                $dpi    = (int)($surface['dpi'] ?? 300);
                $bg_id  = (int)($surface['bg_attachment_id'] ?? 0);
                $bg_url = $bg_id ? wp_get_attachment_image_url($bg_id, 'large') : '';
                $fields = is_array($surface['fields'] ?? null) ? $surface['fields'] : [];
                ?>
                <div class="abc-surface" data-surface="<?php echo esc_attr($surface_key); ?>">
                    <h4><?php echo esc_html(ucfirst($surface_key)); ?></h4>
                    <div class="abc-admin-grid">
                        <div class="abc-admin-row">
                            <label>Trim W (in)</label>
                            <input type="number" step="0.001" name="abc_surfaces[<?php echo esc_attr($surface_key); ?>][trim_w_in]" value="<?php echo esc_attr($trim_w); ?>">
                        </div>
                        <div class="abc-admin-row">
                            <label>Trim H (in)</label>
                            <input type="number" step="0.001" name="abc_surfaces[<?php echo esc_attr($surface_key); ?>][trim_h_in]" value="<?php echo esc_attr($trim_h); ?>">
                        </div>
                        <div class="abc-admin-row">
                            <label>Bleed (in)</label>
                            <input type="number" step="0.001" name="abc_surfaces[<?php echo esc_attr($surface_key); ?>][bleed_in]" value="<?php echo esc_attr($bleed); ?>">
                        </div>
                        <div class="abc-admin-row">
                            <label>Safe (in)</label>
                            <input type="number" step="0.001" name="abc_surfaces[<?php echo esc_attr($surface_key); ?>][safe_in]" value="<?php echo esc_attr($safe); ?>">
                        </div>
                        <div class="abc-admin-row">
                            <label>DPI</label>
                            <input type="number" step="1" min="72" max="1200" name="abc_surfaces[<?php echo esc_attr($surface_key); ?>][dpi]" value="<?php echo esc_attr($dpi); ?>">
                        </div>
                    </div>

                    <div class="abc-bg">
                        <input type="hidden" class="abc-bg-id" name="abc_surfaces[<?php echo esc_attr($surface_key); ?>][bg_attachment_id]" value="<?php echo (int)$bg_id; ?>">
                        <button type="button" class="button abc-pick-bg">Choose Background Image</button>
                        <button type="button" class="button abc-clear-bg">Clear</button>
                        <div class="abc-bg-preview">
                            <?php if ($bg_url): ?>
                                <img src="<?php echo esc_url($bg_url); ?>" alt="">
                            <?php else: ?>
                                <em>No background selected</em>
                            <?php endif; ?>
                        </div>
                        <p class="description">Use a flattened background (PNG/JPG). Phase 2 will support better PDF/SVG workflows.</p>
                    </div>

                    <div class="abc-builder-actions">
                        <button type="button" class="button abc-open-builder" data-surface="<?php echo esc_attr($surface_key); ?>">Visual Builder</button>
                        <button type="button" class="button abc-refresh-builder" data-surface="<?php echo esc_attr($surface_key); ?>">Refresh</button>
                        <span class="description">Drag & resize field boxes visually (no measuring). Use Refresh after adding/removing fields.</span>
                    </div>
                    <div class="abc-visual-builder" id="abc_builder_<?php echo esc_attr($surface_key); ?>" style="display:none;"></div>

                    <h4>Editable Fields (<?php echo esc_html($surface_key); ?>)</h4>
                    <p class="description">Define bounding boxes in inches from the top-left of the trim area (not including bleed).</p>

                    <table class="widefat abc-fields-table">
                        <thead>
                            <tr>
                                <th>Key</th>
                                <th>Label</th>
                                <th>Req</th>
                                <th>Left (in)</th>
                                <th>Top (in)</th>
                                <th>W (in)</th>
                                <th>H (in)</th>
                                <th>Font</th>
                                <th>Size</th>
                                <th>Color</th>
                                <th>Align</th>
                                <th>Bold</th>
                                <th>Italic</th>
                                <th>Max</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody class="abc-fields-body">
                            <?php foreach ($fields as $idx => $f): ?>
                                <?php $this->render_field_row($surface_key, $idx, $f); ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p>
                        <button type="button" class="button abc-add-field" data-surface="<?php echo esc_attr($surface_key); ?>">+ Add Field</button>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>

        <script type="text/template" id="abc-field-row-template">
            <?php $this->render_field_row('__SURFACE__', '__INDEX__', [
                'key' => '',
                'label' => '',
                'required' => 0,
                'left_in' => 0.25,
                'top_in' => 0.25,
                'width_in' => 3.0,
                'height_in' => 0.35,
                'font_family' => 'Arial',
                'font_size' => 16,
                'color' => '#000000',
                'align' => 'left',
                'bold' => 0,
                'italic' => 0,
                'lock_style' => 1,
                'max_chars' => 60,
            ], true); ?>
        </script>
        <?php
    }

    private function render_field_row($surface_key, $idx, $f, $is_template = false) {
        $k = esc_attr($f['key'] ?? '');
        $label = esc_attr($f['label'] ?? '');
        $req = !empty($f['required']) ? 1 : 0;
        $left = esc_attr($f['left_in'] ?? 0);
        $top = esc_attr($f['top_in'] ?? 0);
        $w = esc_attr($f['width_in'] ?? 1);
        $h = esc_attr($f['height_in'] ?? 0.25);
        $font = esc_attr($f['font_family'] ?? 'Arial');
        $size = esc_attr($f['font_size'] ?? 16);
        $color = esc_attr($f['color'] ?? '#000000');
        $align = esc_attr($f['align'] ?? 'left');
        $bold = !empty($f['bold']) ? 1 : 0;
        $italic = !empty($f['italic']) ? 1 : 0;
        $max = esc_attr($f['max_chars'] ?? 60);

        $surface_key_out = $is_template ? '__SURFACE__' : $surface_key;
        $idx_out = $is_template ? '__INDEX__' : (string)$idx;
        ?>
        <tr class="abc-field-row">
            <td><input type="text" name="abc_surfaces[<?php echo esc_attr($surface_key_out); ?>][fields][<?php echo esc_attr($idx_out); ?>][key]" value="<?php echo $k; ?>" placeholder="name"></td>
            <td><input type="text" name="abc_surfaces[<?php echo esc_attr($surface_key_out); ?>][fields][<?php echo esc_attr($idx_out); ?>][label]" value="<?php echo $label; ?>" placeholder="Name"></td>
            <td style="text-align:center;"><input type="checkbox" name="abc_surfaces[<?php echo esc_attr($surface_key_out); ?>][fields][<?php echo esc_attr($idx_out); ?>][required]" value="1" <?php checked($req, 1); ?>></td>
            <td><input type="number" step="0.001" name="abc_surfaces[<?php echo esc_attr($surface_key_out); ?>][fields][<?php echo esc_attr($idx_out); ?>][left_in]" value="<?php echo $left; ?>"></td>
            <td><input type="number" step="0.001" name="abc_surfaces[<?php echo esc_attr($surface_key_out); ?>][fields][<?php echo esc_attr($idx_out); ?>][top_in]" value="<?php echo $top; ?>"></td>
            <td><input type="number" step="0.001" name="abc_surfaces[<?php echo esc_attr($surface_key_out); ?>][fields][<?php echo esc_attr($idx_out); ?>][width_in]" value="<?php echo $w; ?>"></td>
            <td><input type="number" step="0.001" name="abc_surfaces[<?php echo esc_attr($surface_key_out); ?>][fields][<?php echo esc_attr($idx_out); ?>][height_in]" value="<?php echo $h; ?>"></td>
            <td><input type="text" name="abc_surfaces[<?php echo esc_attr($surface_key_out); ?>][fields][<?php echo esc_attr($idx_out); ?>][font_family]" value="<?php echo $font; ?>"></td>
            <td><input type="number" step="1" min="6" max="200" name="abc_surfaces[<?php echo esc_attr($surface_key_out); ?>][fields][<?php echo esc_attr($idx_out); ?>][font_size]" value="<?php echo $size; ?>"></td>
            <td><input type="text" name="abc_surfaces[<?php echo esc_attr($surface_key_out); ?>][fields][<?php echo esc_attr($idx_out); ?>][color]" value="<?php echo $color; ?>" placeholder="#000000"></td>
            <td>
                <select name="abc_surfaces[<?php echo esc_attr($surface_key_out); ?>][fields][<?php echo esc_attr($idx_out); ?>][align]">
                    <option value="left" <?php selected($align, 'left'); ?>>Left</option>
                    <option value="center" <?php selected($align, 'center'); ?>>Center</option>
                    <option value="right" <?php selected($align, 'right'); ?>>Right</option>
                </select>
            </td>
            <td style="text-align:center;"><input type="checkbox" name="abc_surfaces[<?php echo esc_attr($surface_key_out); ?>][fields][<?php echo esc_attr($idx_out); ?>][bold]" value="1" <?php checked($bold, 1); ?>></td>
            <td style="text-align:center;"><input type="checkbox" name="abc_surfaces[<?php echo esc_attr($surface_key_out); ?>][fields][<?php echo esc_attr($idx_out); ?>][italic]" value="1" <?php checked($italic, 1); ?>></td>
            <td><input type="number" step="1" min="1" max="500" name="abc_surfaces[<?php echo esc_attr($surface_key_out); ?>][fields][<?php echo esc_attr($idx_out); ?>][max_chars]" value="<?php echo $max; ?>"></td>
            <td><button type="button" class="button-link-delete abc-remove-field">Remove</button></td>
        </tr>
        <?php
    }

    public function save_template_meta($post_id, $post) {
        if (!isset($_POST['abc_template_meta_nonce']) || !wp_verify_nonce($_POST['abc_template_meta_nonce'], 'abc_template_meta_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $org_id = isset($_POST['abc_org_id']) ? (int)$_POST['abc_org_id'] : 0;
        update_post_meta($post_id, '_abc_org_id', $org_id);

        $product_ids = isset($_POST['abc_product_ids']) ? sanitize_text_field($_POST['abc_product_ids']) : '';
        $product_ids = preg_replace('/[^0-9,\s]/', '', $product_ids);
        update_post_meta($post_id, '_abc_product_ids', $product_ids);

        $surfaces = $_POST['abc_surfaces'] ?? [];
        if (!is_array($surfaces)) $surfaces = [];

        $clean = [];
        foreach ($surfaces as $key => $s) {
            $key = sanitize_key($key);
            if (!$key) continue;

            $trim_w = abc_b2b_designer_safe_float($s['trim_w_in'] ?? 0, 0);
            $trim_h = abc_b2b_designer_safe_float($s['trim_h_in'] ?? 0, 0);
            $bleed  = abc_b2b_designer_safe_float($s['bleed_in'] ?? 0.125, 0.125);
            $safe   = abc_b2b_designer_safe_float($s['safe_in'] ?? 0.125, 0.125);
            $dpi    = (int)($s['dpi'] ?? 300);
            $bg_id  = (int)($s['bg_attachment_id'] ?? 0);

            $fields_in = $s['fields'] ?? [];
            $fields = [];
            if (is_array($fields_in)) {
                foreach ($fields_in as $f) {
                    if (!is_array($f)) continue;
                    $fkey = sanitize_key($f['key'] ?? '');
                    if (!$fkey) continue;
                    $fields[] = [
                        'key' => $fkey,
                        'label' => sanitize_text_field($f['label'] ?? $fkey),
                        'type' => 'text',
                        'required' => !empty($f['required']) ? 1 : 0,
                        'left_in' => abc_b2b_designer_safe_float($f['left_in'] ?? 0, 0),
                        'top_in' => abc_b2b_designer_safe_float($f['top_in'] ?? 0, 0),
                        'width_in' => abc_b2b_designer_safe_float($f['width_in'] ?? 1, 1),
                        'height_in' => abc_b2b_designer_safe_float($f['height_in'] ?? 0.25, 0.25),
                        'font_family' => sanitize_text_field($f['font_family'] ?? 'Arial'),
                        'font_size' => (int)($f['font_size'] ?? 16),
                        'color' => sanitize_text_field($f['color'] ?? '#000000'),
                        'lock_style' => 1,
                        'max_chars' => (int)($f['max_chars'] ?? 60),
                    ];
                }
            }

            $clean[$key] = [
                'trim_w_in' => $trim_w,
                'trim_h_in' => $trim_h,
                'bleed_in' => $bleed,
                'safe_in' => $safe,
                'dpi' => max(72, min(1200, $dpi)),
                'bg_attachment_id' => $bg_id,
                'fields' => $fields,
            ];
        }

        update_post_meta($post_id, '_abc_surfaces', $clean);
    }
}
