<?php
if (!defined('ABSPATH')) { exit; }

class ABC_B2B_Designer_Woo {
    private static $instance = null;
    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        if (!class_exists('WooCommerce')) return;

        add_action('woocommerce_checkout_order_processed', [$this, 'move_designs_to_order_folder'], 10, 3);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'add_order_item_meta'], 10, 4);
        add_action('woocommerce_after_order_itemmeta', [$this, 'render_admin_order_item_links'], 10, 3);
    }

    public function add_order_item_meta($item, $cart_item_key, $values, $order) {
        if (!empty($values['abc_design_token'])) {
            $item->add_meta_data('_abc_design_token', sanitize_text_field($values['abc_design_token']), true);
        }
        if (!empty($values['abc_template_id'])) {
            $item->add_meta_data('_abc_template_id', (int)$values['abc_template_id'], true);
        }
        if (!empty($values['abc_draft_id'])) {
            $item->add_meta_data('_abc_draft_id', (int)$values['abc_draft_id'], true);
        }
    }

    public function move_designs_to_order_folder($order_id, $posted_data, $order) {
        if (!$order_id) return;

        $base_dir = abc_b2b_designer_upload_base_dir();
        $tmp_base = trailingslashit($base_dir) . 'tmp/';
        $order_base = trailingslashit($base_dir) . 'orders/' . (int)$order_id . '/';
        wp_mkdir_p($order_base);

        foreach ($order->get_items() as $item_id => $item) {
            $token = $item->get_meta('_abc_design_token');
            if (!$token) continue;

            $token = sanitize_text_field((string)$token);
            $src = $tmp_base . $token . '/';
            $dst = $order_base . $token . '/';

            if (!is_dir($src)) continue;
            if (!file_exists($dst)) wp_mkdir_p($dst);

            $this->recurse_copy($src, $dst);
            $this->recurse_delete($src);

            $base_url = abc_b2b_designer_upload_base_url();
            $token_url = $base_url . 'orders/' . (int)$order_id . '/' . $token . '/';

            $item->update_meta_data('_abc_design_folder_url', esc_url_raw($token_url));
            $item->save();
        }
    }

    public function render_admin_order_item_links($item_id, $item, $product) {
        if (!is_admin()) return;
        $token_url = $item->get_meta('_abc_design_folder_url');
        if (!$token_url) return;

        $links = [];
        $svg_links = [];
        $possible = ['front', 'back', 'inside', 'outside'];
        foreach ($possible as $s) {
            $url = trailingslashit($token_url) . 'surface-' . $s . '.png';
            $links[] = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">Preview ' . esc_html(ucfirst($s)) . ' (PNG)</a>';
            $svg_url = trailingslashit($token_url) . 'surface-' . $s . '.svg';
            $svg_links[] = '<a href="' . esc_url($svg_url) . '" target="_blank" rel="noopener">Vector ' . esc_html(ucfirst($s)) . ' (SVG)</a>';
        }

        echo '<div class="abc-order-item-design">';
        echo '<strong>ABC Design Files:</strong><br>';
        echo '<a href="' . esc_url($token_url) . '" target="_blank" rel="noopener">Open design folder</a><br>';
        echo implode(' | ', $links);
        echo '<br>' . implode(' | ', $svg_links);
        echo '</div>';
    }

    private function recurse_copy($src, $dst) {
        $dir = opendir($src);
        @mkdir($dst, 0755, true);
        while(false !== ($file = readdir($dir))) {
            if (($file !== '.') && ($file !== '..')) {
                if (is_dir($src . $file)) {
                    $this->recurse_copy($src . $file . '/', $dst . $file . '/');
                } else {
                    copy($src . $file, $dst . $file);
                }
            }
        }
        closedir($dir);
    }

    private function recurse_delete($dir) {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . $file;
            if (is_dir($path)) {
                $this->recurse_delete($path . '/');
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
