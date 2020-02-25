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
		header( 'Content-type: application/json' );
		$output = array();
		$output['login'] = true;
		$output['auth_url'] = Core::get_instance()->auth_url;
		$output['auth_url'] = add_query_arg( array(
			'site' => urlencode( site_url() ),
			'r'    => urlencode( network_admin_url( 'admin.php?page=mts-connect' ) ),
		), $output['auth_url'] );

		echo wp_json_encode( $output );
		exit;
	}

	/**
	 * Get current page URL.
	 *
	 * @param  bool $ignore_qs Ignore query string.
	 * @return string
	 */
	public static function get_current_page_url( $ignore_qs = false ) {
		$link = '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		$link = ( is_ssl() ? 'https' : 'http' ) . $link;

		if ( $ignore_qs ) {
			$link = explode( '?', $link );
			$link = $link[0];
		}

		return $link;
	}

	public function update_settings() {
		$this->set_settings( $_POST );
		$this->update_settings();

		exit;
	}
}
