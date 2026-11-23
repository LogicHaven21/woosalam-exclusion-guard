<?php

// Standalone logic tests. No WordPress, WooCommerce, WooSalam, or network is used.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

define('ABSPATH', __DIR__ . '/');

$wseg_actions = array();
$wseg_filters = array();
$wseg_options = array();
$wseg_posts = array();
$wseg_terms = array();
$wseg_category_parents = array();

function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
{
    global $wseg_actions;
    $wseg_actions[$hook] = array($callback, $priority, $accepted_args);
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
{
    global $wseg_filters;
    $wseg_filters[$hook] = array($callback, $priority, $accepted_args);
}

function is_admin()
{
    return false;
}

function plugin_basename($file)
{
    return basename($file);
}

function absint($value)
{
    return abs((int) $value);
}

function get_option($name, $default = false)
{
    global $wseg_options;
    return array_key_exists($name, $wseg_options) ? $wseg_options[$name] : $default;
}

function get_post($post_id)
{
    global $wseg_posts;
    return isset($wseg_posts[(int) $post_id]) ? $wseg_posts[(int) $post_id] : null;
}

function wp_get_post_terms($post_id, $taxonomy, $args = array())
{
    global $wseg_terms;
    unset($args);
    return isset($wseg_terms[(int) $post_id][$taxonomy]) ? $wseg_terms[(int) $post_id][$taxonomy] : array();
}

function is_wp_error($value)
{
    return false;
}

function term_is_ancestor_of($ancestor_id, $term_id, $taxonomy)
{
    global $wseg_category_parents;
    unset($taxonomy);
    $current = (int) $term_id;

    while (isset($wseg_category_parents[$current])) {
        $current = (int) $wseg_category_parents[$current];
        if ($current === (int) $ancestor_id) {
            return true;
        }
    }

    return false;
}

require dirname(__DIR__) . '/woosalam-exclusion-guard.php';

function wseg_product($id)
{
    return (object) array(
        'ID' => (int) $id,
        'post_type' => 'product',
        'post_parent' => 0,
    );
}

function wseg_set_rules($category_ids, $tag_ids)
{
    global $wseg_options;
    $wseg_options[WooSalam_Exclusion_Guard::OPTION_NAME] = array(
        'product_cat_ids' => $category_ids,
        'product_tag_ids' => $tag_ids,
    );
}

function wseg_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wseg_expect_block($callback, $message)
{
    try {
        $callback();
    } catch (RuntimeException $exception) {
        return;
    }

    throw new RuntimeException($message);
}

global $wseg_posts, $wseg_terms, $wseg_category_parents, $wseg_actions, $wseg_filters;

$wseg_posts = array(
    101 => wseg_product(101),
    102 => wseg_product(102),
    103 => wseg_product(103),
    104 => wseg_product(104),
    105 => wseg_product(105),
    106 => wseg_product(106),
    107 => wseg_product(107),
);

$wseg_terms = array(
    101 => array('product_cat' => array(10), 'product_tag' => array()),
    102 => array('product_cat' => array(), 'product_tag' => array(20)),
    103 => array('product_cat' => array(30), 'product_tag' => array(20)),
    104 => array('product_cat' => array(30), 'product_tag' => array(40)),
    105 => array('product_cat' => array(10), 'product_tag' => array()),
    106 => array('product_cat' => array(30), 'product_tag' => array(40)),
    107 => array('product_cat' => array(11), 'product_tag' => array()),
);

$wseg_category_parents = array(11 => 10);

$tests = array();

$tests['category-only product is excluded'] = function () {
    wseg_set_rules(array(10), array());
    wseg_assert(WooSalam_Exclusion_Guard::is_product_excluded(101), 'Category rule did not match.');
};

$tests['tag-only product is excluded'] = function () {
    wseg_set_rules(array(), array(20));
    wseg_assert(WooSalam_Exclusion_Guard::is_product_excluded(102), 'Tag rule did not match.');
};

$tests['normal category plus excluded tag is excluded'] = function () {
    wseg_set_rules(array(10), array(20));
    wseg_assert(WooSalam_Exclusion_Guard::is_product_excluded(103), 'OR behavior did not match the tag.');
};

$tests['ordinary product continues through create and update hooks'] = function () {
    wseg_set_rules(array(10), array(20));
    wseg_assert(!WooSalam_Exclusion_Guard::is_product_excluded(104), 'Ordinary product was excluded.');
    WooSalam_Exclusion_Guard::before_create(104);
    WooSalam_Exclusion_Guard::before_update(104);
};

$tests['already connected excluded product has queued update blocked'] = function () {
    wseg_set_rules(array(10), array());
    wseg_expect_block(function () {
        WooSalam_Exclusion_Guard::before_update(105);
    }, 'Queued update was not blocked.');
};

$tests['excluded product cannot receive a WooSalam mapping'] = function () {
    wseg_set_rules(array(10), array());
    wseg_expect_block(function () {
        WooSalam_Exclusion_Guard::guard_product_mapping(null, 101, 'sync_basalam_product_id_7', 900001, false);
    }, 'Mapping was not blocked.');
};

$tests['selected category includes descendants'] = function () {
    wseg_set_rules(array(10), array());
    wseg_assert(WooSalam_Exclusion_Guard::is_product_excluded(107), 'Descendant category was not excluded.');
};

$tests['no configured rules and absent dependencies are safe'] = function () {
    wseg_set_rules(array(), array());
    wseg_assert(!class_exists('WooCommerce'), 'WooCommerce unexpectedly exists in the standalone test.');
    wseg_assert(!WooSalam_Exclusion_Guard::is_product_excluded(106), 'Empty rules excluded a product.');
    WooSalam_Exclusion_Guard::before_create(106);
    WooSalam_Exclusion_Guard::before_update(106);
    $result = WooSalam_Exclusion_Guard::guard_product_mapping(null, 106, 'sync_basalam_product_id_7', 900002, false);
    wseg_assert($result === null, 'Empty rules blocked mapping.');
};

$tests['expected WooSalam hooks are registered'] = function () use (&$wseg_actions, &$wseg_filters) {
    wseg_assert(isset($wseg_actions['sync_basalam_before_create_product_api']), 'Create hook is missing.');
    wseg_assert(isset($wseg_actions['sync_basalam_before_update_product_api']), 'Update hook is missing.');
    wseg_assert(isset($wseg_filters['add_post_metadata']), 'Add-mapping filter is missing.');
    wseg_assert(isset($wseg_filters['update_post_metadata']), 'Update-mapping filter is missing.');
};

$failures = 0;

foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS: {$name}\n";
    } catch (Throwable $throwable) {
        $failures++;
        echo "FAIL: {$name}\n";
        echo '  ' . $throwable->getMessage() . "\n";
    }
}

echo sprintf("\n%d tests, %d failures.\n", count($tests), $failures);
exit($failures === 0 ? 0 : 1);
