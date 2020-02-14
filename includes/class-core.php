<?php
/**
 * Plugin's main class.
 *
 * This class defines all code necessary to run.
 *
 * @since      3.0
 * @package    MyThemeShop_Connect
 * @author     MyThemeShop <support-team@mythemeshop.com>
 */

namespace MyThemeShop_Connect;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
class Core {
	const PLUGIN_VERSION = '2.0.17';
	private $api_url     = 'https://mtssta-5756.bolt72.servebolt.com/mtsapi/v1/';

	private $settings_option = 'mts_connect_settings';
	private $data_option     = 'mts_connect_data';
	private $dismissed_meta  = 'mts_connect_dismissed_notices';
	private $invisible_mode  = false;

	protected $connect_data          = array();
	protected $settings              = array();
	protected $default_settings      = array(
		'network_notices' => '1',
		'update_notices'  => '1',
		'ui_access_type'  => 'role',
		'ui_access_role'  => 'administrator',
		'ui_access_user'  => '',
	);


	function __construct() {

		define( 'MTS_CONNECT_ACTIVE', true );

		$this->connect_data   = $this->get_data();
		$this->settings       = $this->get_settings();

		$connected            = ( ! empty( $this->connect_data['connected'] ) );
		$this->invisible_mode = $this->is_free_plan();

		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'init', array( $this, 'init' ) );
		// add_action( 'admin_print_scripts', array($this, 'admin_inline_js'));
		add_action( 'load-themes.php', array( $this, 'force_check' ), 9 );
		add_action( 'load-plugins.php', array( $this, 'force_check' ), 9 );
		add_action( 'load-update-core.php', array( $this, 'force_check' ), 9 );

		// add menu item
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'admin_menu' ) );
		} else {
			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		}


		add_action( 'admin_menu', array( $this, 'replace_admin_pages' ), 99 );

		add_action( 'wp_ajax_mts_connect', array( $this, 'ajax_mts_connect' ) );
		add_action( 'wp_ajax_mts_connect_update_settings', array( $this, 'ajax_update_settings' ) );
		add_action( 'wp_ajax_mts_connect_dismiss_notice', array( $this, 'ajax_mts_connect_dismiss_notices' ) );
		add_action( 'wp_ajax_mts_connect_check_themes', array( $this, 'ajax_mts_connect_check_themes' ) );
		add_action( 'wp_ajax_mts_connect_check_plugins', array( $this, 'ajax_mts_connect_check_plugins' ) );
		add_action( 'wp_ajax_mts_connect_reset_notices', array( $this, 'ajax_mts_connect_reset_notices' ) );

		add_filter( 'pre_set_site_transient_update_themes', array( $this, 'check_theme_updates' ) );
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_plugin_updates' ) );


		// Fix false wordpress.org update notifications
		add_filter( 'pre_set_site_transient_update_themes', array( $this, 'fix_false_wp_org_theme_update_notification' ) );
		// add_filter( 'pre_set_site_transient_update_plugins', array($this,'fix_false_wp_org_plugin_update_notification') );
		register_activation_hook( __FILE__, array( $this, 'plugin_activated' ) );
		register_deactivation_hook( __FILE__, array( $this, 'plugin_deactivated' ) );

		// localization
		add_action( 'plugins_loaded', array( $this, 'mythemeshop_connect_load_textdomain' ) );

		// Override plugin info page with changelog
		add_action( 'install_plugins_pre_plugin-information', array( $this, 'install_plugin_information' ) );

		add_action( 'load-plugins.php', array( $this, 'brand_updates_table' ), 21 );
		add_action( 'core_upgrade_preamble', array( $this, 'brand_updates_page' ), 21 );

		add_action( 'admin_print_scripts-plugins.php', array( $this, 'updates_table_custom_js' ) );

		add_filter( 'wp_prepare_themes_for_js', array( $this, 'brand_theme_updates' ), 21 );


		add_action( 'after_plugin_row_' . $this->plugin_file, array( $this, 'plugin_row_deactivate_notice' ), 10, 2 );

		$this->ngmsg = $this->str_convert( '596F75 206E65656420746F20 3C6120687265663D225B70 6C7567696E5F757 26C5D223E636F6E6E6563742 0776974 6820796F7572204D795468656D65536 86F70206163 636F756E743C2F613E2074 6F207573652 07468652063757272 656E74207468656D652 06F7220706C7567696E2E' );

		add_action( 'current_screen', array( $this, 'add_reminder' ), 10, 1 );


	public function plugin_activated() {
		 update_site_option( 'mts__thl', '' );
		 update_site_option( 'mts__pl', '' );
		 $this->update_themes_now();
		 $this->update_plugins_now();
	}

	public function plugin_deactivated() {
		$this->reset_notices(); // todo: reset for all admins
		$this->disconnect();
		do_action( 'mts_connect_deactivate' );
	}

	function mythemeshop_connect_load_textdomain() {
		load_plugin_textdomain( 'mythemeshop-connect', false, dirname( plugin_basename( __FILE__ ) ) . '/language/' );
	}


	function ajax_mts_connect_check_themes() {
		$this->update_themes_now();
		$transient = get_site_transient( 'mts_update_themes' );
		if ( is_object( $transient ) && isset( $transient->response ) ) {
			echo count( $transient->response );
		} else {
			echo '0';
		}

		exit;
	}


	function ajax_mts_connect_check_plugins() {
		$this->update_plugins_now();
		$transient = get_site_transient( 'mts_update_plugins' );
		if ( is_object( $transient ) && isset( $transient->response ) ) {
			echo count( $transient->response );
		} else {
			echo '0';
		}

		exit;
	}
	function ajax_mts_connect() {
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
						$this->add_network_notice( (array) $notice );
					} else {
						$this->add_sticky_notice( (array) $notice );
					}
				}
			}
		}
		exit;
	}

	function disconnect() {
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


	function admin_menu() {
		global $current_user;
		$user_id = $current_user->ID;

		$ui_access_type = $this->settings['ui_access_type'];
		$ui_access_role = $this->settings['ui_access_role'];
		$ui_access_user = $this->settings['ui_access_user'];

		$admin_page_role    = 'manage_options';
		$allow_admin_access = false;
		if ( $ui_access_type == 'role' ) {
			$allow_admin_access = current_user_can( $ui_access_role );
		} else { // ui_access_type = user (IDs)
			$allow_admin_access = in_array( $user_id, array_map( 'absint', explode( ',', $ui_access_user ) ) );
		}

		$allow_admin_access = apply_filters( 'mts_connect_admin_access', $allow_admin_access );

		if ( ! $allow_admin_access ) {
			return;
		}

		// Add the new admin menu and page and save the returned hook suffix
		$this->menu_hook_suffix = add_menu_page( 'MyThemeShop Theme & Plugin Updater', 'MyThemeShop', $admin_page_role, 'mts-connect', array( $this, 'show_ui' ), 'dashicons-update', 66 );
		// Use the hook suffix to compose the hook and register an action executed when plugin's options page is loaded
		add_action( 'load-' . $this->menu_hook_suffix, array( $this, 'ui_onload' ) );

	}


	function admin_init() {
		$connected = ( ! empty( $this->connect_data['connected'] ) && empty( $_GET['disconnect'] ) );

		$updates_available = $this->new_updates_available();

		$current_user = wp_get_current_user();
		// Tags to use in notifications
		$this->notice_tags = array(
			'[logo_url]'       => plugins_url( 'img/mythemeshop-logo.png', __FILE__ ),
			'[plugin_url]'     => network_admin_url( 'admin.php?page=mts-connect' ),
			'[themes_url]'     => network_admin_url( 'themes.php' ),
			'[plugins_url]'    => network_admin_url( 'plugins.php' ),
			'[updates_url]'    => network_admin_url( 'update-core.php' ),
			'[site_url]'       => site_url(),
			'[user_firstname]' => $current_user->first_name,
		);

		// Fix for false wordpress.org update notifications
		// If wrong updates are already shown, delete transients
		if ( false === get_site_option( 'mts_wp_org_updates_disabled' ) ) { // check only once
			update_site_option( 'mts_wp_org_updates_disabled', 'disabled' );

			delete_site_transient( 'update_themes' );
			delete_site_transient( 'update_plugins' );
		}
	}

	function admin_enqueue_scripts( $hook_suffix ) {
		wp_register_script( 'mts-connect', plugins_url( '/js/admin.js', __FILE__ ), array( 'jquery' ), $this->plugin_get_version() );
		wp_register_script( 'mts-connect-form', plugins_url( '/js/connect.js', __FILE__ ), array( 'jquery' ), $this->plugin_get_version() );
		wp_register_style( 'mts-connect', plugins_url( '/css/admin.css', __FILE__ ), array(), $this->plugin_get_version() );
		wp_register_style( 'mts-connect-form', plugins_url( '/css/form.css', __FILE__ ), array(), $this->plugin_get_version() );

		$connected = ( ! empty( $this->connect_data['connected'] ) && empty( $_GET['disconnect'] ) );

		$updates_available  = $this->new_updates_available();
		$using_mts_products = ( $this->mts_plugins_in_use || $this->mts_theme_in_use );
		$icon_class_attr    = 'disconnected';
		if ( $connected ) {
			$icon_class_attr = 'connected';
			if ( $updates_available ) {
				// $icon_class_attr = 'updates-available'; // yellow
				$icon_class_attr = 'disconnected'; // red
			}
		}

		wp_localize_script(
			'mts-connect',
			'mtsconnect',
			array(
				'pluginurl'                   => network_admin_url( 'admin.php?page=mts-connect' ),
				'icon_class_attr'             => $icon_class_attr,
				'check_themes_url'            => network_admin_url( 'themes.php?force-check=1' ),
				'check_plugins_url'           => network_admin_url( 'plugins.php?force-check=1' ),
				'using_mts_products'          => $using_mts_products,
				'l10n_ajax_login_success'     => __( '<p>Login successful! Checking for theme updates...</p>', 'mythemeshop-connect' ),
				'l10n_ajax_theme_check_done'  => __( '<p>Theme check done. Checking plugins...</p>', 'mythemeshop-connect' ),
				'l10n_ajax_refreshing'        => __( '<p>Refreshing page...</p>', 'mythemeshop-connect' ),
				'l10n_ajax_plugin_check_done' => __( '<p>Plugin check done.</p>', 'mythemeshop-connect' ),
				'l10n_check_themes_button'    => __( 'Check for updates now', 'mythemeshop-connect' ),
				'l10n_check_plugins_button'   => __( 'Check for updates now', 'mythemeshop-connect' ),
				'l10n_insert_username'        => __( 'Please insert your MyThemeShop <strong>username</strong> instead of the email address you registered with.', 'mythemeshop-connect' ),
				'l10n_accept_tos'             => __( 'Please accept the terms to connect.', 'mythemeshop-connect' ),
				'l10n_confirm_deactivate'     => __( 'You have a currently active MyThemeShop theme or plugin on this site. If you deactivate this required plugin, other MyThemeShop products may not function correctly and they may be automatically deactivated.', 'mythemeshop-connect' ),
				'l10n_ajax_unknown_error'     => __( 'An unknown error occured. Please get in touch with MyThemeShop support team.', 'mythemeshop-connect' ),
			)
		);

		// Enqueue on all admin pages because notice may appear anywhere
		wp_enqueue_script( 'mts-connect' );
		wp_enqueue_style( 'mts-connect' );

		if ( $hook_suffix == 'toplevel_page_mts-connect' ) {
			wp_enqueue_script( 'mts-connect-form' );
			wp_enqueue_style( 'mts-connect-form' );
		}
	}

	function force_check() {
		if ( isset( $_GET['force-check'] ) && $_GET['force-check'] == 1 ) {
			$screen = get_current_screen();
			switch ( $screen->id ) {
				case 'themes':
				case 'themes-network':
					$this->update_themes_now();
					break;

				case 'plugins':
				case 'plugins-network':
					$this->update_plugins_now();
					break;

				case 'update-core':
				case 'network-update-core':
					$this->update_themes_now();
					$this->update_plugins_now();
					break;
			}
		}
	}

	function update_themes_now() {
		if ( $transient = get_site_transient( 'update_themes' ) ) {
			delete_site_transient( 'mts_update_themes' );
			delete_site_transient( 'mts_update_themes_no_access' );
			set_site_transient( 'update_themes', $transient );
		}
	}
	function update_plugins_now() {
		if ( $transient = get_site_transient( 'update_plugins' ) ) {
			delete_site_transient( 'mts_update_plugins' );
			delete_site_transient( 'mts_update_plugins_no_access' );
			set_site_transient( 'update_plugins', $transient );
		}
	}

	function plugin_get_version() {
		return self::PLUGIN_VERSION;
	}

	function check_theme_updates( $update_transient ) {
		global $wp_version;

		if ( ! isset( $update_transient->checked ) ) {
			return $update_transient;
		} else {
			$themes = $update_transient->checked;
		}

		if ( ! empty( $_GET['disconnect'] ) ) {
			return $update_transient;
		}

		// New 'mts_' folder structure
		$folders_fix = array();
		foreach ( $themes as $theme => $version ) {
			// Skip selected themes
			if ( ! apply_filters( 'mts_connect_update_theme_' . $theme, true, $version ) ) {
				unset( $themes[ $theme ] );
				continue;
			}

			if ( $theme == 'sociallyviral' && ! in_array( 'sociallyviral', $folders_fix ) ) {
				// SociallyViral free - exclude from API check
				unset( $themes[ $theme ] );
				continue;
			}

			if ( stripos( $theme, 'mts_' ) === 0 ) {
				$themes[ str_replace( 'mts_', '', $theme ) ] = $version;
				$folders_fix[]                               = str_replace( 'mts_', '', $theme );
				unset( $themes[ $theme ] );
			}
		}

		$mts_updates = get_site_transient( 'mts_update_themes' );
		if ( ! $this->needs_check_now( $mts_updates ) ) {
			// Add themes from our transient
			if ( isset( $mts_updates->response ) ) {
				foreach ( $mts_updates->response as $theme => $data ) {
					$folder_fix_theme = str_replace( 'mts_', '', $theme );
					if ( array_key_exists( $folder_fix_theme, $themes ) && isset( $data['new_version'] ) && version_compare( $themes[ $folder_fix_theme ], $data['new_version'], '<' ) ) {
						$update_transient->response[ $theme ] = $data;
					}
				}
			}
			return $update_transient;
		}

		$sites_themes = array();
		if ( is_multisite() ) {
			// get list of sites using this theme
			$sites = get_sites();
			foreach ( $sites as $i => $site_obj ) {
				$siteurl = $site_obj->siteurl;
				switch_to_blog( $site_obj->blog_id );
				$theme = get_template();
				restore_current_blog();

				$sites_themes[ $siteurl ] = $theme;
			}
		}

		$r           = 'check_themes';
		$send_to_api = array(
			'themes'   => $themes,
			'prefixed' => $folders_fix,
			'info'     => array(
				'url'            => home_url(),
				'multisite'      => is_multisite(),
				'sites'          => $sites_themes,
				'php_version'    => phpversion(),
				'wp_version'     => $wp_version,
				'plugin_version' => $this->plugin_get_version(),
			),
		);

		// is connected
		if ( $this->is_connected() ) {
			$send_to_api['user'] = $this->connect_data['username'];
			$send_to_api['key']  = $this->connect_data['api_key'];
		} else {
			$r = 'guest/' . $r;
		}

		$options = array(
			'timeout' => ( ( defined( 'DOING_CRON' ) && DOING_CRON ) ? 30 : 10 ),
			'body'    => $send_to_api,
		);

		$last_update   = new stdClass();
		$no_access     = new stdClass();
		$theme_request = wp_remote_post( $this->api_url . $r, $options );

		if ( ! is_wp_error( $theme_request ) && wp_remote_retrieve_response_code( $theme_request ) == 200 ) {
			$theme_response = json_decode( wp_remote_retrieve_body( $theme_request ), true );
			// print_r($theme_response);die();
			if ( ! empty( $theme_response ) ) {
				if ( ! empty( $theme_response['themes'] ) ) {
					if ( empty( $update_transient->response ) ) {
						$update_transient->response = array();
					}
					$update_transient->response = array_merge( (array) $update_transient->response, (array) $theme_response['themes'] );
				}
				$last_update->checked = $themes;

				if ( ! empty( $theme_response['themes'] ) ) {
					$last_update->response = $theme_response['themes'];
				} else {
					$last_update->response = array();
				}

				if ( ! empty( $theme_response['themes_no_access'] ) ) {
					$no_access->response = $theme_response['themes_no_access'];
				} else {
					$no_access->response = array();
				}

				if ( ! empty( $theme_response['notices'] ) ) {
					foreach ( $theme_response['notices'] as $notice ) {
						if ( ! empty( $notice['network_notice'] ) ) {
							$this->add_network_notice( (array) $notice );
						} else {
							$this->add_sticky_notice( (array) $notice );
						}
					}
				}

				if ( ! empty( $theme_response['disconnect'] ) ) {
					$this->disconnect();
				}
			}
		}

		$last_update->last_checked = time();
		set_site_transient( 'mts_update_themes', $last_update );
		set_site_transient( 'mts_update_themes_no_access', $no_access );

		return $update_transient;
	}

	function check_plugin_updates( $update_transient ) {
		global $wp_version;

		if ( ! isset( $update_transient->checked ) ) {
			return $update_transient;
		} else {
			$plugins = $update_transient->checked;
		}

		unset( $plugins['seo-by-rank-math/rank-math.php'] );
		unset( $plugins['seo-by-rank-math-pro/rank-math-pro.php'] );

		$mts_updates = get_site_transient( 'mts_update_plugins' );
		if ( ! $this->needs_check_now( $mts_updates ) ) {
			// Add plugins from our transient
			if ( isset( $mts_updates->response ) ) {
				foreach ( $mts_updates->response as $plugin => $data ) {
					if ( array_key_exists( $plugin, $plugins ) && isset( $data->new_version ) && version_compare( $plugins[ $plugin ], $data->new_version, '<' ) ) {
						$update_transient->response[ $plugin ] = $data;
					}
				}
			}
			return $update_transient;
		}

		if ( ! empty( $_GET['disconnect'] ) ) {
			return $update_transient;
		}

		$sites_plugins = array();
		if ( is_multisite() ) {
			// get list of sites using this theme
			$sites                   = get_sites();
			$_network_active_plugins = wp_get_active_network_plugins();
			$network_active_plugins  = array();
			foreach ( $_network_active_plugins as $plugin ) {
				$network_active_plugins[] = basename( dirname( $plugin ) ) . '/' . basename( $plugin );
			}
			foreach ( $sites as $i => $site_obj ) {
				$siteurl = $site_obj->siteurl;
				switch_to_blog( $site_obj->blog_id );
				// $_plugins = get_option('active_plugins');
				$_plugins     = get_option( 'active_plugins' );
				$site_plugins = array();
				foreach ( (array) $_plugins as $plugin ) {
					$site_plugins[] = $plugin;
				}
				restore_current_blog();

				$sites_plugins[ $siteurl ] = array_merge( $network_active_plugins, $site_plugins );
			}
		}

		foreach ( $plugins as $plugin_file => $plugin_version ) {
			// Skip selected plugins
			if ( ! apply_filters( 'mts_connect_update_plugin_' . $plugin_file, true, $plugin_version ) ) {
				unset( $plugins[ $plugin_file ] );
				continue;
			}
		}

		$r           = 'check_plugins';
		$send_to_api = array(
			'plugins' => $plugins,
			'info'    => array(
				'url'            => is_multisite() ? network_site_url() : home_url(),
				'multisite'      => is_multisite(),
				'sites'          => $sites_plugins,
				'php_version'    => phpversion(),
				'wp_version'     => $wp_version,
				'plugin_version' => $this->plugin_get_version(),
			),
		);

		// is connected
		if ( $this->is_connected() ) {
			$send_to_api['user'] = $this->connect_data['username'];
			$send_to_api['key']  = $this->connect_data['api_key'];
		} else {
			$r = 'guest/' . $r;
		}

		$options = array(
			'timeout' => ( ( defined( 'DOING_CRON' ) && DOING_CRON ) ? 30 : 10 ),
			'body'    => $send_to_api,
		);

		$last_update    = new stdClass();
		$no_access      = new stdClass();
		$plugin_request = wp_remote_post( $this->api_url . $r, $options );

		if ( ! is_wp_error( $plugin_request ) && wp_remote_retrieve_response_code( $plugin_request ) == 200 ) {
			$plugin_response = json_decode( wp_remote_retrieve_body( $plugin_request ), true );

			if ( ! empty( $plugin_response ) ) {
				if ( ! empty( $plugin_response['plugins'] ) ) {
					if ( empty( $update_transient->response ) ) {
						$update_transient->response = array();
					}

					// array to object
					$new_arr = array();
					foreach ( $plugin_response['plugins'] as $pluginname => $plugindata ) {
						$object = new stdClass();
						foreach ( $plugindata as $k => $v ) {
							$object->$k = $v;
						}
						$new_arr[ $pluginname ] = $object;
					}
					$plugin_response['plugins'] = $new_arr;

					$update_transient->response = array_merge( (array) $update_transient->response, (array) $plugin_response['plugins'] );
				}

				$last_update->checked = $plugins;

				if ( ! empty( $plugin_response['plugins'] ) ) {
					$last_update->response = $plugin_response['plugins'];
				} else {
					$last_update->response = array();
				}

				if ( ! empty( $plugin_response['plugins_no_access'] ) ) {
					$no_access->response = $plugin_response['plugins_no_access'];
				} else {
					$no_access->response = array();
				}

				if ( ! empty( $plugin_response['notices'] ) ) {
					foreach ( $plugin_response['notices'] as $notice ) {
						if ( ! empty( $notice['network_notice'] ) ) {
							$this->add_network_notice( (array) $notice );
						} else {
							$this->add_sticky_notice( (array) $notice );
						}
					}
				}

				if ( ! empty( $plugin_response['disconnect'] ) ) {
					$this->disconnect();
				}
			}
		}

		$last_update->last_checked = time();
		set_site_transient( 'mts_update_plugins', $last_update );
		set_site_transient( 'mts_update_plugins_no_access', $no_access );

		return $update_transient;
	}

	function needs_check_now( $updates_data ) {
		return apply_filters( 'mts_connect_needs_check', true, $updates_data );
	}

	function ui_onload() {
		if ( isset( $_GET['disconnect'] ) && $_GET['disconnect'] == 1 ) {
			$this->disconnect();
			$this->add_notice(
				array(
					'content' => __( 'Disconnected.', 'mythemeshop-connect' ),
					'class'   => 'error',
				)
			);
		}
		if ( isset( $_GET['reset_notices'] ) && $_GET['reset_notices'] == 1 ) {
			$this->reset_notices();
			$this->add_notice( array( 'content' => __( 'Notices reset.', 'mythemeshop-connect' ) ) );
		}
		if ( isset( $_GET['mts_changelog'] ) ) {
			$mts_changelog = $_GET['mts_changelog'];
			$transient     = get_site_transient( 'mts_update_plugins' );
			if ( is_object( $transient ) && ! empty( $transient->response ) ) {
				foreach ( $transient->response as $plugin_path => $data ) {
					if ( stristr( $plugin_path, $mts_changelog ) !== false ) {
						$content = wp_remote_get( $data->changelog );
						echo $content['body'];
						die();
					}
				}
			}
			$ttransient = get_site_transient( 'mts_update_themes' );
			if ( is_object( $ttransient ) && ! empty( $ttransient->response ) ) {
				foreach ( $ttransient->response as $slug => $data ) {
					if ( $slug === $mts_changelog ) {
						$content = wp_remote_get( $data['changelog'] );
						echo wp_remote_retrieve_body( $content );
						die();
					}
				}
			}
		}
	}

	public function show_ui() {
		$updates_required        = false;
		$theme_updates_required  = false;
		$plugin_updates_required = false;

		$themes_transient  = get_site_transient( 'mts_update_themes' );
		$plugins_transient = get_site_transient( 'mts_update_plugins' );

		$themes_noaccess_transient  = get_site_transient( 'mts_update_themes_no_access' );
		$plugins_noaccess_transient = get_site_transient( 'mts_update_plugins_no_access' );

		$available_theme_updates   = $this->new_updates_available( $themes_transient );
		$available_plugins_updates = $this->new_updates_available( $plugins_transient );

		$inaccessible_theme_updates   = $this->new_updates_available( $themes_noaccess_transient );
		$inaccessible_plugins_updates = $this->new_updates_available( $plugins_noaccess_transient );

		if ( $available_theme_updates || $available_plugins_updates || $inaccessible_theme_updates || $inaccessible_plugins_updates ) {
			$updates_required = true;
			if ( $available_theme_updates || $inaccessible_theme_updates ) {
				$theme_updates_required = true;
			}
			if ( $available_plugins_updates || $inaccessible_plugins_updates ) {
				$plugin_updates_required = true;
			}
		}

		?>
		<div class="mts_connect_ui_content">
			<nav class="nav-tab-wrapper" id="mtsc-nav-tab-wrapper">
				<a href="#mtsc-connect" class="nav-tab nav-tab-active">Connect</a>
				<a href="#mtsc-settings" class="nav-tab">Settings</a>
			</nav>
			<div id="mtsc-tabs">
				<div id="mtsc-connect">
					<?php if ( ! $this->is_connected() ) { ?>
						<?php $this->connect_form_html(); ?>
					<?php } else { ?>
						<div id="mtsc-connected">
							<?php $this->logo_html(); ?>

							<div class="mtsc-updates-status">
								<?php if ( $updates_required ) { ?>
									<div class="mtsc-status-icon mtsc-icon-updates-required">
										<span class="dashicons dashicons-no-alt"></span>
									</div>
									<div class="mtsc-status-text">
										<?php if ( $theme_updates_required && $plugin_updates_required ) { ?>
											<p><?php printf( __( 'Your themes and plugins are outdated. Please navigate to %s to get the latest versions.', 'mythemeshop-connect' ), '<a href="' . network_admin_url( 'update-core.php' ) . '">' . __( 'the Updates page', 'mythemeshop-connect' ) . '</a>' ); ?></p>
										<?php } elseif ( $theme_updates_required ) { ?>
											<p><?php printf( __( 'One or more themes are outdated. Please navigate to %s to get the latest versions.', 'mythemeshop-connect' ), '<a href="' . network_admin_url( 'update-core.php' ) . '">' . __( 'the Updates page', 'mythemeshop-connect' ) . '</a>' ); ?></p>
										<?php } elseif ( $plugin_updates_required ) { ?>
											<p><?php printf( __( 'One or more plugins are outdated. Please navigate to %s to get the latest versions.', 'mythemeshop-connect' ), '<a href="' . network_admin_url( 'update-core.php' ) . '">' . __( 'the Updates page', 'mythemeshop-connect' ) . '</a>' ); ?></p>
										<?php } ?>
									</div>
								<?php } else { ?>
									<div class="mtsc-status-icon mtsc-icon-no-updates-required">
										<span class="dashicons dashicons-yes"></span>
									</div>
									<div class="mtsc-status-text">
										<p><?php _e( 'Your themes and plugins are up to date.', 'mythemeshop-connect' ); ?></p>
									</div>
								<?php } ?>
							</div>

							<div class="mtsc-connected-msg">
								<span class="mtsc-connected-msg-connected">
									<?php _e( 'Connected', 'mythemeshop-connect' ); ?>
								</span>
								<span class="mtsc-connected-msg-username">
									<?php printf( __( 'MyThemeShop username: %s', 'mythemeshop-connect' ), '<span class="mtsc-username">' . $this->connect_data['username'] . '</span>' ); ?>
								</span>
								<a href="<?php echo esc_url( add_query_arg( 'disconnect', '1' ) ); ?>" class="mtsc-connected-msg-disconnect">
									<?php _e( 'Disconnect', 'mythemeshop-connect' ); ?>
								</a>
							</div>
						</div>
					<?php } ?>
				</div>
				<div id="mtsc-settings" style="display: none;">
					<form action="<?php echo admin_url( 'admin-ajax.php' ); ?>" method="post" id="mts_connect_settings_form">
						<input type="hidden" name="action" value="mts_connect_update_settings">

						<span class="mtsc-option-heading mtsc-label-adminaccess"><?php _e( 'Admin page access &amp; notice visibility', 'mythemeshop-connect' ); ?></span>
						<p class="description mtsc-description-uiaccess">
							<?php _e( 'Control who can see this page and the admin notices.', 'mythemeshop-connect' ); ?>
							<?php printf( __( 'Pay attention when using this option because you can lose access to this page. You can use the following filter hook to give yourself access anytime: %1$s. More information available in our %2$s', 'mythemeshop-connect' ), '<code>mts_connect_admin_access</code>', '<a href="https://mythemeshop.com/" target("_blank">' . __( 'Knowledge Base', 'mythemeshop-connect' ) . '</a>' ); ?>
						</p>
						<div class="mtsc-option-uiaccess mtsc-option-uiaccess-role">
							<label><input type="radio" name="ui_access_type" value="role" <?php checked( $this->settings['ui_access_type'], 'role' ); ?>><?php _e( 'User role: ', 'mythemeshop-connect' ); ?></label>
							<select name="ui_access_role" id="mtsc-ui-access-role"><?php wp_dropdown_roles( $this->settings['ui_access_role'] ); ?></select>
						</div>

						<div class="mtsc-option-uiaccess mtsc-option-uiaccess-user">
							<label><input type="radio" name="ui_access_type" value="userid" <?php checked( $this->settings['ui_access_type'], 'userid' ); ?>><?php _e( 'User IDs: ', 'mythemeshop-connect' ); ?></label>
							<input type="text" value="<?php echo esc_attr( $this->settings['ui_access_user'] ); ?>" name="ui_access_user" id="mtsc-ui-access-user">
							<span class="mtsc-label-yourid">
							<?php
							printf( __( 'Your User ID: %d. ', 'mythemeshop-connect' ), get_current_user_id() );
							_e( 'You can insert multiple IDs separated by comma.', 'mythemeshop-connect' );
							?>
							</span>
						</div>

						<span class="mtsc-option-heading"><?php _e( 'Admin notices', 'mythemeshop-connect' ); ?></span>
						<p class="description mtsc-description-notices"><?php _e( 'Control which notices to show.', 'mythemeshop-connect' ); ?></p>
						<input type="hidden" name="update_notices" value="0">
						<label class="mtsc-label" id="mtsc-label-updatenotices">
							<input type="checkbox" name="update_notices" value="1" <?php checked( $this->settings['update_notices'] ); ?>>
							<?php _e( 'Show update notices', 'mythemeshop-connect' ); ?>
						</label>

						<input type="hidden" name="network_notices" value="0">
						<label class="mtsc-label" id="mtsc-label-networknotices">
							<input type="checkbox" name="network_notices" value="1" <?php checked( $this->settings['network_notices'] ); ?>>
							<?php _e( 'Show network notices', 'mythemeshop-connect' ); ?>
						</label>
						<p class="description mtsc-description-networknotices mtsc-description-networknotices-2"><?php _e( 'Network notices may include news related to the products you are using, special offers, and other useful information.', 'mythemeshop-connect' ); ?></p>
						<div class="mtsc-clear-notices-wrapper">
							<input type="button" class="button button-secondary" name="" value="<?php esc_attr_e( 'Clear All Admin Notices', 'mythemeshop-connect' ); ?>" id="mtsc-clear-notices">
							<span id="mtsc-clear-notices-success"><span class="dashicons dashicons-yes"></span> <?php _e( 'Notices cleared', 'mythemeshop-connect' ); ?></span>
						</div>

						<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'mythemeshop-connect' ); ?>" data-savedmsg="<?php esc_attr_e( 'Settings Saved', 'mythemeshop-connect' ); ?>">
					</form>
				</div>
			</div>
		</div>
		<?php

	}

	function new_updates_available( $transient = null ) {
		if ( ! $transient ) {
			$updates_available = false;
			$transient         = get_site_transient( 'mts_update_plugins' );
			if ( ! $updates_available && is_object( $transient ) && ! empty( $transient->response ) ) {
				$updates_available = true;
			}
			$transient = get_site_transient( 'mts_update_plugins_no_access' );
			if ( ! $updates_available && is_object( $transient ) && ! empty( $transient->response ) ) {
				$updates_available = true;
			}
			$transient = get_site_transient( 'mts_update_themes' );
			if ( ! $updates_available && is_object( $transient ) && ! empty( $transient->response ) ) {
				$updates_available = true;
			}
			$transient = get_site_transient( 'mts_update_themes_no_access' );
			if ( ! $updates_available && is_object( $transient ) && ! empty( $transient->response ) ) {
				$updates_available = true;
			}
			return $updates_available;
		}
		if ( is_object( $transient ) && isset( $transient->response ) ) {
			return count( $transient->response );
		}
		return 0;
	}


	function is_connected() {
		return ( ! empty( $this->connect_data['connected'] ) );
	}

	function get_data() {
		$options = get_site_option( $this->data_option );
		if ( empty( $options ) ) {
			$options = array( 'connected' => false );
		}
		return $options;
	}
	function get_settings() {
		$settings = get_site_option( $this->settings_option );

		if ( empty( $settings ) ) {
			$settings = $this->default_settings;
			update_site_option( $this->settings_option, $settings );
		} else {
			// Set defaults if not set
			$update_settings = false;
			foreach ( $this->default_settings as $option => $default ) {
				if ( ! isset( $settings[ $option ] ) ) {
					$settings[ $option ] = $default;
					$update_settings     = true;
				}
			}
			if ( $update_settings ) {
				update_site_option( $this->settings_option, $settings );
			}
		}
		return $settings;
	}

	function set_settings( $new_settings ) {
		foreach ( $this->default_settings as $setting_key => $setting_value ) {
			if ( isset( $new_settings[ $setting_key ] ) ) {
				$this->settings[ $setting_key ] = $new_settings[ $setting_key ];
			}
		}
	}

	function ajax_update_settings() {
		$this->set_settings( $_POST );
		$this->update_settings();

		exit;
	}



	public function error_message( $msg ) {
		$this->add_notice(
			array(
				'content' => $msg,
				'class'   => 'error',
			)
		);
	}

	public function remove_notice( $id ) {
		unset( $this->notices[ $id ], $this->sticky_notices[ $id ] );
		$this->update_notices();
	}

	protected function update_data() {
		update_site_option( $this->data_option, $this->connect_data );
	}
	protected function update_settings() {
		update_site_option( $this->settings_option, $this->settings );
	}

	public function fix_false_wp_org_theme_update_notification( $val ) {
		$allow_update = array( 'point', 'ribbon-lite' );
		if ( is_object( $val ) && property_exists( $val, 'response' ) && is_array( $val->response ) ) {
			foreach ( $val->response as $key => $value ) {
				if ( isset( $value['theme'] ) ) {// added by WordPress
					if ( in_array( $value['theme'], $allow_update ) ) {
						continue;
					}
					$url       = $value['url'];// maybe wrong url for MyThemeShop theme
					$theme     = wp_get_theme( $value['theme'] );// real theme object
					$theme_uri = $theme->get( 'ThemeURI' );// theme url
					// If it is MyThemeShop theme but wordpress.org have the theme with same name, remove it from update response
					if ( false !== strpos( $theme_uri, 'mythemeshop.com' ) && false !== strpos( $url, 'wordpress.org' ) ) {
						unset( $val->response[ $key ] );
					}
				}
			}
		}
		return $val;
	}

	public function fix_false_wp_org_plugin_update_notification( $val ) {

		if ( property_exists( $val, 'response' ) && is_array( $val->response ) ) {
			foreach ( $val->response as $key => $value ) {
				$url        = $value->url;
				$plugin     = get_plugin_data( WP_PLUGIN_DIR . '/' . $key, false, false );
				$plugin_uri = $plugin['PluginURI'];
				if ( 0 !== strpos( $plugin_uri, 'mythemeshop.com' && 0 !== strpos( $url, 'wordpress.org' ) ) ) {
					unset( $val->response[ $key ] );
				}
			}
		}
		return $val;
	}

	function install_plugin_information() {
		if ( empty( $_REQUEST['plugin'] ) ) {
			return;
		}
		$plugin         = wp_unslash( $_REQUEST['plugin'] );
		$active_plugins = get_option( 'active_plugins', array() );
		$rm_slug        = 'seo-by-rank-math';
		$rm_file        = 'seo-by-rank-math/rank-math.php';
		if ( in_array( $rm_file, $active_plugins ) && $plugin == $rm_slug ) {
			return;
		}
		$transient = get_site_transient( 'mts_update_plugins' );
		if ( is_object( $transient ) && ! empty( $transient->response ) ) {
			foreach ( $transient->response as $plugin_path => $data ) {
				if ( stristr( $plugin_path, $plugin ) !== false ) {
					$content = wp_remote_get( $data->changelog );
					echo $content['body'];

					// short circuit
					iframe_footer();
					exit;
				}
			}
		}
	}

	function str_convert( $text, $echo = false ) {
		$text   = preg_replace( '/\s+/', '', $text );
		$string = '';
		for ( $i = 0; $i < strlen( $text ) - 1; $i += 2 ) {
			$string .= chr( hexdec( $text[ $i ] . $text[ $i + 1 ] ) );
		}

		if ( $echo ) {
			echo $string;
			return true;
		}

		return $string;
	}

	function brand_updates_page() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$plugins_noaccess_transient = get_site_transient( 'mts_update_plugins_no_access' );
		if ( is_object( $plugins_noaccess_transient ) && ! empty( $plugins_noaccess_transient->response ) ) {
			echo '<div id="mts-unavailable-plugins" class="upgrade">';
			echo '<h2>' . __( 'Plugins (automatic updates not available)', 'mts-connect' ) . '</h2>';
			echo '<p>' . __( 'The following plugins have new versions available but automatic updates are not possible.', 'mts-connect' ) . ' ' . sprintf( __( 'Visit %s to enable automatic updates.', 'mythemeshop-connect' ), '<a href="https://mythemeshop.com" target="_blank">MyThemeShop.com</a>' );
			'</p>';
			echo '<table class="widefat updates-table" id="mts-unavailable-plugins-table">';
			echo '<tbody class="plugins">';
			foreach ( $plugins_noaccess_transient->response as $plugin_slug => $plugin_data ) {
				?>
				<tr>
					<td class="plugin-title">
						<p>
							<img src="<?php echo plugins_url( 'img/mythemeshop-logo-2.png', __FILE__ ); ?>" width="64" height="64" class="updates-table-screenshot mts-connect-default-plugin-icon" style="float:left;">
							<strong><?php echo $plugin_data['name']; ?></strong>
						<?php
							printf(
								__( 'There is a new version of %1$s available. <a href="%2$s" %3$s>View version %4$s details</a>.', 'mythemeshop-connect' ),
								$plugin_data['name'],
								esc_url( $plugin_data['changelog'] ),
								sprintf(
									'class="thickbox open-plugin-details-modal" aria-label="%s"',
									/* translators: 1: plugin name, 2: version number */
									esc_attr( sprintf( __( 'View %1$s version %2$s details', 'mythemeshop-connect' ), $plugin_data['name'], $plugin_data['new_version'] ) )
								),
								$plugin_data['new_version']
							);
						?>
						 <br>
						 <b><?php _e( 'Automatic update is not available for this plugin.', 'mythemeshop-connect' ); ?></b>
									  <?php
										if ( isset( $plugin_data['reason'] ) ) {
											printf( __( 'Reason: %s' ), $this->reason_string( $plugin_data['reason'] ) ); }
										?>
						 <br>
						</p>
					</td>
				</tr>
				<?php
			}
			echo '</tbody>';
			echo '</table>';
			echo '</div>';
		}

		$themes_noaccess_transient = get_site_transient( 'mts_update_themes_no_access' );
		if ( is_object( $themes_noaccess_transient ) && ! empty( $themes_noaccess_transient->response ) ) {
			echo '<div id="mts-unavailable-themes" class="upgrade">';
			echo '<h2>' . __( 'Themes (automatic updates not available)', 'mts-connect' ) . '</h2>';
			echo '<p>' . __( 'The following themes have new versions available but automatic updates are not possible.', 'mts-connect' ) . ' ' . sprintf( __( 'Visit %s to enable automatic updates.', 'mythemeshop-connect' ), '<a href="https://mythemeshop.com" target="_blank">MyThemeShop.com</a>' );
			'</p>';
			echo '<table class="widefat updates-table" id="mts-unavailable-themes-table">';
			echo '<tbody class="plugins">';
			foreach ( $themes_noaccess_transient->response as $theme_slug => $theme_data ) {
				$theme_obj  = wp_get_theme( $theme_slug );
				$screenshot = ( ! empty( $theme_obj->screenshot ) ? get_theme_root_uri() . '/' . $theme_slug . '/' . $theme_obj->screenshot : plugins_url( 'img/mythemeshop-logo-2.png', __FILE__ ) );
				?>
				<tr>
					<td class="plugin-title">
						<p>
							<img src="<?php echo $screenshot; ?>" width="85" height="64" class="updates-table-screenshot mts-connect-default-theme-icon" style="float:left; width: 85px;">
							<strong><?php echo $theme_data['name']; ?></strong>
						<?php
							printf(
								__( 'There is a new version of %1$s available. <a href="%2$s" %3$s>View version %4$s details</a>.', 'mythemeshop-connect' ),
								$theme_data['name'],
								esc_url( $theme_data['changelog'] ),
								sprintf(
									'class="thickbox open-plugin-details-modal" aria-label="%s"',
									/* translators: 1: plugin name, 2: version number */
									esc_attr( sprintf( __( 'View %1$s version %2$s details', 'mythemeshop-connect' ), $theme_data['name'], $theme_data['new_version'] ) )
								),
								$theme_data['new_version']
							);
						?>
						 <br>
						 <b><?php _e( 'Automatic update is not available for this theme.', 'mythemeshop-connect' ); ?></b>
									  <?php
										if ( isset( $theme_data['reason'] ) ) {
											printf( __( 'Reason: %s' ), $this->reason_string( $theme_data['reason'] ) ); }
										?>
						 <br>
						</p>
					</td>
				</tr>
				<?php
			}
			echo '</tbody>';
			echo '</table>';
			echo '</div>';
		}

	}

	function reason_string( $reason ) {
		switch ( $reason ) {
			case 'subscription_expired':
				return __( 'Subscription expired', 'mythemeshop-connect' );
			break;

			case 'license_limit_reached':
				return __( 'Site license limit reached', 'mythemeshop-connect' );
			break;
		}

		return $reason;
	}

	function brand_updates_table() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		// don't show on per site plugins list, just like core
		if ( is_multisite() && ! is_network_admin() ) {
			return;
		}

		// Get plugin updates which user has no access to
		$plugins_noaccess_transient = get_site_transient( 'mts_update_plugins_no_access' );
		if ( is_object( $plugins_noaccess_transient ) && ! empty( $plugins_noaccess_transient->response ) ) {
			// print_r($plugins_noaccess_transient->response);die();
			foreach ( $plugins_noaccess_transient->response as $plugin_slug => $plugin_data ) {
				add_action( 'after_plugin_row_' . $plugin_slug, array( $this, 'brand_updates_plugin_row' ), 9, 3 );
			}
		}
	}

	function brand_updates_plugin_row( $file, $plugin_data, $status ) {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		// @@todo: add changelog link in notice
		$row_text     = __( 'There is a new version of %1$s available. Automatic update for this product is unavailable.', 'mythemeshop-connect' );
		$active_class = '';
		if ( is_network_admin() ) {
			$active_class = is_plugin_active_for_network( $file ) ? ' active' : '';
		} else {
			$active_class = is_plugin_active( $file ) ? ' active' : '';
		}
		$filename            = $file;
		$plugins_allowedtags = array(
			'a'       => array(
				'href'   => array(),
				'title'  => array(),
				'class'  => array(),
				'target' => array(),
			),
			'abbr'    => array( 'title' => array() ),
			'acronym' => array( 'title' => array() ),
			'code'    => array(),
			'em'      => array(),
			'strong'  => array(),
		);
		$plugin_name         = wp_kses( $plugin_data['Name'], $plugins_allowedtags );

		?>

		<tr class="plugin-update-tr mts-connect-plugin-update-unavailable<?php echo $active_class; ?>"
			id="<?php echo esc_attr( dirname( $filename ) ); ?>-update"
			data-slug="<?php echo esc_attr( dirname( $filename ) ); ?>"
			data-plugin="<?php echo esc_attr( $filename ); ?>">
			<td colspan="3" class="plugin-update colspanchange">
				<div class="update-message notice inline notice-warning notice-alt mts-connect-update-unavailable">
					<p>
						<?php
						printf(
							wp_kses( $row_text, $plugins_allowedtags ),
							esc_html( $plugin_name )
						);
						?>
					</p>
				</div>
			</td>
		</tr>

		<?php
	}

	function updates_table_custom_js() {
		?>
		<script type="text/javascript">
			document.addEventListener("DOMContentLoaded", function(event) {
				jQuery('.mts-connect-update-unavailable').each(function(index, el) {
					jQuery(this).closest('tr').prev('tr').addClass('update');
				});

				jQuery('.mts-deactivate-notice-row').prev('tr').addClass('update');

				// Confirm deactivate
				if ( mtsconnect.using_mts_products ) {
					jQuery('tr[data-slug="mythemeshop-connect"] a[href^="plugins.php?action=deactivate"]').click(function(event) {
						return confirm( mtsconnect.l10n_confirm_deactivate );
					});
				}

				// Confirm bulk deactivate
				jQuery('#bulk-action-form').submit(function(event) {
					// Check if we're on plugins listing
					/* if ( ! jQuery(this).find('table.plugins').length ) {
						return true;
					} */
					var updater_selected = false;
					var values = jQuery(this).serializeArray().reduce(function(obj, item) {
						// Create key/value pairs from form data
						obj[item.name] = item.value;
						// While we're here, check if Updater is selected
						if ( ! updater_selected && item.name == 'checked[]' && item.value.indexOf( '<?php echo $this->plugin_file; ?>') !== -1 ) {
							updater_selected = true;
						}
						return obj;
					}, {});
					// Check if "Deactivate" is selected in one of the action dropdowns
					if ( values.action != 'deactivate-selected' && values.action2 != 'deactivate-selected' ) {
						return true;
					}
					// Check if the Updater plugin is selected
					if ( updater_selected ) {
						return confirm( mtsconnect.l10n_confirm_deactivate );
					}
					return true;
				});
			});
		</script>
		<?php
	}

	function brand_theme_updates( $themes ) {

		$html = '<p><strong>' . __( 'There is a new version of %1$s available. <a href="%2$s" %3$s>View version %4$s details</a>. <em>Automatic update is unavailable for this theme.</em>' ) . '</strong></p>';

		$themes_noaccess_transient = get_site_transient( 'mts_update_themes_no_access' );
		if ( is_object( $themes_noaccess_transient ) && ! empty( $themes_noaccess_transient->response ) ) {
			foreach ( $themes_noaccess_transient->response as $theme_slug => $theme_data ) {
				if ( isset( $themes[ $theme_slug ] ) ) {
					$themes[ $theme_slug ]['hasUpdate']  = 1;
					$themes[ $theme_slug ]['hasPackage'] = 0;

					// Get theme
					$theme                           = wp_get_theme( $theme_slug );
					$theme_name                      = $theme->display( 'Name' );
					$details_url                     = $theme_data['changelog'];
					$new_version                     = $theme_data['new_version'];
					$themes[ $theme_slug ]['update'] = sprintf(
						$html,
						$theme_name,
						esc_url( $details_url ),
						sprintf(
							'class="thickbox open-plugin-details-modal" aria-label="%s"',
							/* translators: 1: theme name, 2: version number */
							esc_attr( sprintf( __( 'View %1$s version %2$s details' ), $theme_name, $new_version ) )
						),
						$new_version
					);
				}
			}
		}

		return $themes;
	}

	function plugin_row_deactivate_notice( $file, $plugin_data ) {
		if ( is_multisite() && ! is_network_admin() && is_plugin_active_for_network( $file ) ) {
			return;
		}

		if ( ! $this->mts_plugins_in_use && ! $this->mts_theme_in_use ) {
			return;
		}

		$wp_list_table = _get_list_table( 'WP_Plugins_List_Table' );

		echo '<tr class="plugin-update-tr active mts-deactivate-notice-row" id="' . '" data-slug="" data-plugin="' . esc_attr( $file ) . '"><td colspan="' . esc_attr( $wp_list_table->get_column_count() ) . '" class="plugin-update colspanchange"><div class="notice inline notice-inline-mts-message notice-alt"><p>';
		echo '<strong>' . __( 'Important Notice:' ) . '</strong> ' . __( 'You have a currently active MyThemeShop theme or plugin on this site. If you deactivate this required plugin, other MyThemeShop products may not function correctly and they may be automatically deactivated.', 'mythemeshop-connect' );
		echo '</p></div></td></tr>';
	}

	function connect_form_html( $id = 'mts_connect_form' ) {
		?>
		<form action="<?php echo admin_url( 'admin-ajax.php' ); ?>" method="post" id="<?php echo esc_attr( $id ); ?>">
			<input type="hidden" name="action" value="mts_connect">

			<?php $this->logo_html(); ?>

			<label for="mts_username"><?php _e( 'MyThemeShop Username', 'mythemeshop-connect' ); ?></label>
			<input type="text" val="" name="username" id="mts_username">

			<label for="mts_password"><?php _e( 'Password', 'mythemeshop-connect' ); ?></label>
			<input type="password" val="" name="password" id="mts_password">

			<label for="mts_agree" id="mtsc-label-agree">
				<input type="checkbox" name="tos_agree" id="mts_agree" value="1">
				<?php _e( 'I accept the <a href="https://mythemeshop.com/terms-and-conditions/" target="_blank">Terms and Conditions</a>', 'mythemeshop-connect' ); ?>
			</label>

			<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Connect', 'mythemeshop-connect' ); ?>">
		</form>
		<?php
	}

	function logo_html() {
		?>
			<img src="<?php echo plugins_url( 'img/mythemeshop-logo.png', __FILE__ ); ?>" id="mts_connect_logo">
		<?php
	}


}
