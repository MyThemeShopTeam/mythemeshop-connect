<?php
/*
 * Plugin Name: ⟳ MyThemeShop Theme & Plugin Updater
 * Plugin URI: https://mythemeshop.com
 * Description: Update MyThemeShop themes & plugins right from your WordPress dashboard.
 * Version: 3.0
 * Author: MyThemeShop
 * Author URI: https://mythemeshop.com
 * License: GPLv2
 */

use MyThemeShop_Connect\Core;

defined( 'ABSPATH' ) || die;

/* Sets the path to the plugin directory. */
define( 'MTS_CONNECT_DIR', trailingslashit( plugin_dir_path( __FILE__ ) ) );

/* Sets the path to the plugin directory URI. */
define( 'MTS_CONNECT_URI', trailingslashit( plugin_dir_url( __FILE__ ) ) );

/* Sets the path to the `includes` directory. */
define( 'MTS_CONNECT_INCLUDES', WP_REVIEW_DIR . trailingslashit( 'includes' ) );

/* Sets the path to the `assets` directory. */
define( 'MTS_CONNECT_ASSETS', WP_REVIEW_URI . 'public/' );

/* We're here. */
define( 'MTS_CONNECT_ACTIVE', true );

/* Require main class */
requre_once( MTS_CONNECT_INCLUDES . 'class-core.php' );

/* Run plugin */
$mts_connection = new Core();
