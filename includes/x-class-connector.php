<?php
/**
 * Code responsible to connect to mythemeshop.com.
 *
 * @since      3.0
 * @package    MyThemeShop_Connect
 * @author     MyThemeShop <support-team@mythemeshop.com>
 */

namespace MyThemeShop_Connect;

defined( 'ABSPATH' ) || exit;

/**
 * Connector class.
 */
class Connector {

	public function connect() {
		header( 'Content-type: application/json' );
		$username = isset( $_POST['username'] ) ? $_POST['username'] : '';
		$password = isset( $_POST['password'] ) ? $_POST['password'] : '';

		$response = wp_remote_post(
			$this->api_url . 'get_key',
			array(
				'body'    => array(
					'user' => $username,
					'pass' => $password,
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			echo json_encode(
				array(
					'status' => 'fail',
					'errors' => array( $error_message ),
				)
			);
		} else {
			echo $response['body']; // should be JSON already

			$data = json_decode( $response['body'], true );
			if ( isset( $data['login'] ) ) {
				$this->reset_notices();
				$this->connect_data['username']  = $data['login'];
				$this->connect_data['api_key']   = $data['key'];
				$this->connect_data['connected'] = true;
				$this->update_data();
			}
			// notices
			if ( isset( $data['notices'] ) && is_array( $data['notices'] ) ) {
				foreach ( $data['notices'] as $notice ) {
					if ( ! empty( $notice['network_notice'] ) ) {
						Core::get( 'notifications' )->add_network_notice( (array) $notice );
					} else {
						Core::get( 'notifications' )->add_sticky_notice( (array) $notice );
					}
				}
			}
		}
		exit;
	}

	public function disconnect() {
		$this->connect_data['username']  = '';
		$this->connect_data['api_key']   = '';
		$this->connect_data['connected'] = false;
		$this->update_data();

		// remove theme updates for mts themes in transient by searching through 'packages' properties for 'mythemeshop'
		$transient = get_site_transient( 'update_themes' );
		delete_site_transient( 'mts_update_themes' );
		delete_site_transient( 'mts_update_themes_no_access' );
		if ( $transient && ! empty( $transient->response ) ) {
			foreach ( $transient->response as $theme => $data ) {
				if ( strstr( $data['package'], 'mythemeshop' ) !== false ) {
					unset( $transient->response[ $theme ] );
				}
			}
			set_site_transient( 'update_themes', $transient );
		}
		$transient = get_site_transient( 'update_plugins' );
		delete_site_transient( 'mts_update_plugins' );
		delete_site_transient( 'mts_update_plugins_no_access' );
		if ( $transient && ! empty( $transient->response ) ) {
			foreach ( $transient->response as $plugin => $data ) {
				if ( strstr( $data->package, 'mythemeshop' ) !== false ) {
					unset( $transient->response[ $plugin ] );
				}
			}
			set_site_transient( 'update_plugins', $transient );
		}
		$this->reset_notices();
	}


	public function ajax_mts_connect() {
		header( 'Content-type: application/json' );
		$username = isset( $_POST['username'] ) ? $_POST['username'] : '';
		$password = isset( $_POST['password'] ) ? $_POST['password'] : '';

		$response = wp_remote_post(
			$this->api_url . 'get_key',
			array(
				'body'    => array(
					'user' => $username,
					'pass' => $password,
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			echo json_encode(
				array(
					'status' => 'fail',
					'errors' => array( $error_message ),
				)
			);
		} else {
			echo $response['body']; // should be JSON already

			$data = json_decode( $response['body'], true );
			if ( isset( $data['login'] ) ) {
				$this->reset_notices();
				$this->connect_data['username']  = $data['login'];
				$this->connect_data['api_key']   = $data['key'];
				$this->connect_data['connected'] = true;
				$this->update_data();
			}
			// notices
			if ( isset( $data['notices'] ) && is_array( $data['notices'] ) ) {
				foreach ( $data['notices'] as $notice ) {
					if ( ! empty( $notice['network_notice'] ) ) {
						Core::get( 'notifications' )->add_network_notice( (array) $notice );
					} else {
						Core::get( 'notifications' )->add_sticky_notice( (array) $notice );
					}
				}
			}
		}
	}
}
