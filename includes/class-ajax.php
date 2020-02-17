<?php
/**
 * Code responsible for AJAX functions.
 *
 * @since      3.0
 * @package    MyThemeShop_Connect
 * @author     MyThemeShop <support-team@mythemeshop.com>
 */

namespace MyThemeShop_Connect;

defined( 'ABSPATH' ) || exit;

class Ajax {

	public function __construct() {
		add_action( 'wp_ajax_mts_connect', array( $this, 'connect' ) );
		add_action( 'wp_ajax_mts_connect_update_settings', array( $this, 'update_settings' ) );
		add_action( 'wp_ajax_mts_connect_dismiss_notice', array( $this, 'dismiss_notices' ) );
		add_action( 'wp_ajax_mts_connect_check_themes', array( $this, 'check_themes' ) );
		add_action( 'wp_ajax_mts_connect_check_plugins', array( $this, 'check_plugins' ) );
		add_action( 'wp_ajax_mts_connect_reset_notices', array( $this, 'reset_notices' ) );
	}

	/**
	 * AJAX handler for theme check.
	 *
	 * @return void
	 */
	public function check_themes() {
		$this->update_themes_now();
		$transient = get_site_transient( 'mts_update_themes' );
		if ( is_object( $transient ) && isset( $transient->response ) ) {
			echo count( $transient->response );
		} else {
			echo '0';
		}

		exit;
	}


	public function check_plugins() {
		$this->update_plugins_now();
		$transient = get_site_transient( 'mts_update_plugins' );
		if ( is_object( $transient ) && isset( $transient->response ) ) {
			echo count( $transient->response );
		} else {
			echo '0';
		}

		exit;
	}

	public function connect() {

		exit;
	}

	public function update_settings() {
		$this->set_settings( $_POST );
		$this->update_settings();

		exit;
	}
}
