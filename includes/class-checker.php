<?php
/**
 * Checker base class for theme and plugin checkers.
 *
 * @since      3.0
 * @package    MyThemeShop_Connect
 * @author     MyThemeShop <support-team@mythemeshop.com>
 */

namespace MyThemeShop_Connect;

defined( 'ABSPATH' ) || exit;

/**
 * Checker class.
 */
class Checker {
	/**
	 * API endpoint URL.
	 *
	 * @var object
	 */
	public $api_url = 'https://mtssta-5756.bolt72.servebolt.com/mtsapi/v1/';

	public function __construct() {

	}

	public function needs_check_now( $updates_data ) {
		return apply_filters( 'mts_connect_needs_check', true, $updates_data );
	}
}
