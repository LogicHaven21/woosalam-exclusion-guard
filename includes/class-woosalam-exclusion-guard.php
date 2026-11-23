<?php

defined('ABSPATH') || exit;

final class WooSalam_Exclusion_Guard
{
    const OPTION_NAME = 'woosalam_exclusion_guard_rules';
    const SETTINGS_PAGE = 'woosalam-exclusion-guard';
    const SETTINGS_GROUP = 'woosalam_exclusion_guard';
    const CAPABILITY = 'manage_woocommerce';

    /** @var string */
    private static $plugin_file = '';

    public static function init($plugin_file)
    {
        self::$plugin_file = (string) $plugin_file;

        // These hook names are retained from the locally supplied WooSalam integration.
        add_action('sync_basalam_before_create_product_api', array(__CLASS__, 'before_create'), 1, 2);
        add_action('sync_basalam_before_update_product_api', array(__CLASS__, 'before_update'), 1, 2);
        add_filter('add_post_metadata', array(__CLASS__, 'guard_product_mapping'), 1, 5);
        add_filter('update_post_metadata', array(__CLASS__, 'guard_product_mapping'), 1, 5);

        if (is_admin()) {
            add_action('admin_init', array(__CLASS__, 'register_settings'));
            add_action('admin_menu', array(__CLASS__, 'add_settings_page'));
            add_action('admin_notices', array(__CLASS__, 'dependency_notice'));
            add_filter('plugin_action_links_' . plugin_basename(self::$plugin_file), array(__CLASS__, 'settings_link'));
        }
    }

    public static function before_create($product_id, $product_data = null)
    {
        unset($product_data);

        if (self::is_product_excluded($product_id)) {
            self::block('This product is excluded from WooSalam creation.');
        }
    }

    public static function before_update($product_id, $product_data = null)
    {
        unset($product_data);

        if (self::is_product_excluded($product_id)) {
            self::block('This product is excluded from WooSalam updates.');
        }
    }

    public static function guard_product_mapping($check, $object_id, $meta_key, $meta_value, $extra)
    {
        unset($meta_value, $extra);

        if ($check !== null || !preg_match('/^sync_basalam_product_id_[0-9]+$/D', (string) $meta_key)) {
            return $check;
        }

        if (self::is_product_excluded($object_id)) {
            self::block('This product is excluded from WooSalam connections.');
        }

        return $check;
    }

    public static function is_product_excluded($product_id)
    {
        $rules = self::get_rules();
        if (empty($rules['product_cat_ids']) && empty($rules['product_tag_ids'])) {
            return false;
        }

        $post = get_post(absint($product_id));
        if (!$post) {
            return false;
        }

        if ($post->post_type === 'product_variation') {
            $post = get_post((int) $post->post_parent);
        }

        if (!$post || $post->post_type !== 'product') {
            return false;
        }

        $product_id = (int) $post->ID;

        if (!empty($rules['product_cat_ids'])) {
            $category_ids = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
            if (!is_wp_error($category_ids) && self::category_matches($category_ids, $rules['product_cat_ids'])) {
                return true;
            }
        }

        if (!empty($rules['product_tag_ids'])) {
            $tag_ids = wp_get_post_terms($product_id, 'product_tag', array('fields' => 'ids'));
            if (!is_wp_error($tag_ids) && array_intersect($rules['product_tag_ids'], array_map('intval', $tag_ids))) {
                return true;
            }
        }

        return false;
    }

    private static function category_matches($assigned_ids, $excluded_ids)
    {
        $assigned_ids = array_map('intval', $assigned_ids);

        foreach ($excluded_ids as $excluded_id) {
            $excluded_id = (int) $excluded_id;
            foreach ($assigned_ids as $assigned_id) {
                if ($assigned_id === $excluded_id || term_is_ancestor_of($excluded_id, $assigned_id, 'product_cat')) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function get_rules()
    {
        $rules = get_option(self::OPTION_NAME, array());
        if (!is_array($rules)) {
            $rules = array();
        }

        return array(
            'product_cat_ids' => self::sanitize_ids(isset($rules['product_cat_ids']) ? $rules['product_cat_ids'] : array()),
            'product_tag_ids' => self::sanitize_ids(isset($rules['product_tag_ids']) ? $rules['product_tag_ids'] : array()),
        );
    }

    private static function sanitize_ids($ids)
    {
        if (!is_array($ids)) {
            return array();
        }

        $ids = array_filter(array_map('absint', $ids));
        return array_values(array_unique($ids));
    }

    private static function block($message)
    {
        $exception = '\\SyncBasalam\\Jobs\\Exceptions\\NonRetryableException';

        if (class_exists($exception) && method_exists($exception, 'permanent')) {
            throw $exception::permanent($message);
        }

        throw new RuntimeException($message);
    }

    public static function register_settings()
    {
        register_setting(
            self::SETTINGS_GROUP,
            self::OPTION_NAME,
            array(
                'type' => 'array',
                'default' => array(),
                'sanitize_callback' => array(__CLASS__, 'sanitize_settings'),
            )
        );
    }

    public static function sanitize_settings($input)
    {
        if (!is_array($input)) {
            $input = array();
        }

        return array(
            'product_cat_ids' => self::sanitize_ids(isset($input['product_cat_ids']) ? $input['product_cat_ids'] : array()),
            'product_tag_ids' => self::sanitize_ids(isset($input['product_tag_ids']) ? $input['product_tag_ids'] : array()),
        );
    }

    public static function add_settings_page()
    {
        add_submenu_page(
            'woocommerce',
            'محافظ استثناهای WooSalam',
            'استثناهای WooSalam',
            self::CAPABILITY,
            self::SETTINGS_PAGE,
            array(__CLASS__, 'render_settings_page')
        );
    }

    public static function render_settings_page()
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'woosalam-exclusion-guard'));
        }

        $rules = self::get_rules();
        $categories = self::get_terms_for_settings('product_cat');
        $tags = self::get_terms_for_settings('product_tag');
        ?>
        <div class="wrap">
            <h1>محافظ استثناهای WooSalam</h1>
            <p>هر محصولی که در یکی از دسته‌ها یا برچسب‌های انتخاب‌شده باشد، از ایجاد، اتصال و به‌روزرسانی توسط WooSalam مستثنا می‌شود. انتخاب یک دسته، زیردسته‌های آن را هم شامل می‌شود.</p>
            <p><strong>اگر هیچ موردی انتخاب نشود، افزونه هیچ محصولی را مسدود نمی‌کند.</strong></p>

            <form method="post" action="options.php">
                <?php settings_fields(self::SETTINGS_GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wseg-product-categories">دسته‌های مستثنا</label></th>
                        <td>
                            <?php self::render_term_select('wseg-product-categories', 'product_cat_ids', $categories, $rules['product_cat_ids']); ?>
                            <p class="description">برای انتخاب چند مورد از Ctrl (در ویندوز/لینوکس) یا Command (در macOS) استفاده کنید.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wseg-product-tags">برچسب‌های مستثنا</label></th>
                        <td>
                            <?php self::render_term_select('wseg-product-tags', 'product_tag_ids', $tags, $rules['product_tag_ids']); ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button('ذخیرهٔ استثناها'); ?>
            </form>
        </div>
        <?php
    }

    private static function get_terms_for_settings($taxonomy)
    {
        if (!taxonomy_exists($taxonomy)) {
            return array();
        }

        $terms = get_terms(
            array(
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'orderby' => 'name',
                'order' => 'ASC',
            )
        );

        return is_wp_error($terms) ? array() : $terms;
    }

    private static function render_term_select($id, $rule_key, $terms, $selected_ids)
    {
        printf(
            '<input type="hidden" name="%1$s[%2$s][]" value="">',
            esc_attr(self::OPTION_NAME),
            esc_attr($rule_key)
        );

        printf(
            '<select id="%1$s" name="%2$s[%3$s][]" multiple="multiple" size="10" style="min-width: 320px; max-width: 100%%;">',
            esc_attr($id),
            esc_attr(self::OPTION_NAME),
            esc_attr($rule_key)
        );

        foreach ($terms as $term) {
            printf(
                '<option value="%1$d"%2$s>%3$s</option>',
                (int) $term->term_id,
                selected(in_array((int) $term->term_id, $selected_ids, true), true, false),
                esc_html($term->name)
            );
        }

        echo '</select>';

        if (!$terms) {
            echo '<p class="description">موردی برای انتخاب پیدا نشد.</p>';
        }
    }

    public static function settings_link($links)
    {
        $url = admin_url('admin.php?page=' . self::SETTINGS_PAGE);
        array_unshift($links, '<a href="' . esc_url($url) . '">تنظیمات</a>');
        return $links;
    }

    public static function dependency_notice()
    {
        if (class_exists('WooCommerce') || defined('WC_VERSION')) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('WooSalam Exclusion Guard requires WooCommerce. Until WooCommerce is active, the guard remains inactive and does not stop the site.', 'woosalam-exclusion-guard');
        echo '</p></div>';
    }
}
