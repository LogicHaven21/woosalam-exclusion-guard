<?php
/**
 * Plugin Name: WooSalam Exclusion Guard
 * Description: Prevents selected WooCommerce product categories and tags from being created, connected, or updated by WooSalam.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Text Domain: woosalam-exclusion-guard
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/includes/class-woosalam-exclusion-guard.php';

WooSalam_Exclusion_Guard::init(__FILE__);
