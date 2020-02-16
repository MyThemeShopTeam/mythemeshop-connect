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
	private $api_url     = 'https://mtssta-5756.bolt72.servebolt.com/mtsapi/v1/';

	private $settings_option = 'mts_connect_settings';
	private $data_option     = 'mts_connect_data';
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

		$this->connect_data   = $this->get_data();
		$this->settings       = $this->get_settings();

		$connected            = ( ! empty( $this->connect_data['connected'] ) );
		$this->invisible_mode = $this->is_free_plan();

		add_action( 'init', array( $this, 'init' ) );
		// add_action( 'admin_print_scripts', array($this, 'admin_inline_js'));
		add_action( 'load-themes.php', array( $this, 'force_check' ), 9 );
		add_action( 'load-plugins.php', array( $this, 'force_check' ), 9 );
		add_action( 'load-update-core.php', array( $this, 'force_check' ), 9 );

		add_action( 'wp_ajax_mts_connect', array( $this, 'ajax_mts_connect' ) );
		add_action( 'wp_ajax_mts_connect_update_settings', array( $this, 'ajax_update_settings' ) );
		add_action( 'wp_ajax_mts_connect_dismiss_notice', array( $this, 'ajax_mts_connect_dismiss_notices' ) );
		add_action( 'wp_ajax_mts_connect_check_themes', array( $this, 'ajax_mts_connect_check_themes' ) );
		add_action( 'wp_ajax_mts_connect_check_plugins', array( $this, 'ajax_mts_connect_check_plugins' ) );
		add_action( 'wp_ajax_mts_connect_reset_notices', array( $this, 'ajax_mts_connect_reset_notices' ) );

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

		}

	public function plugin_activated() {
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

	function plugin_get_version() {
		return MTS_CONNECT_VERSION;
	}

	function needs_check_now( $updates_data ) {
		return apply_filters( 'mts_connect_needs_check', true, $updates_data );
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


}
