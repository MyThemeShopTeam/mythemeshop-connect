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

	/**
	 * Singleton object instance.
	 *
	 * @var object
	 */
	protected static $instance = null;

	/**
	 * Settings option name.
	 *
	 * @var string
	 */
	private $settings_option = 'mts_connect_settings';

	/**
	 * Connect data option name.
	 *
	 * @var string
	 */
	private $data_option = 'mts_connect_data';

	/**
	 * No-UI invisible mode is enabled by default for free products.
	 *
	 * @var boolean
	 */
	public $invisible_mode = false;

	/**
	 * Connect data.
	 *
	 * @var array
	 */
	public $connect_data = array();

	/**
	 * Settings array.
	 *
	 * @var array
	 */
	protected $settings = array();

	/**
	 * Default settings.
	 *
	 * @var array
	 */
	protected $default_settings = array(
		'network_notices' => '1',
		'update_notices'  => '1',
		'ui_access_type'  => 'role',
		'ui_access_role'  => 'administrator',
		'ui_access_user'  => '',
	);

	/**
	 * Controller objects.
	 *
	 * @var array
	 */
	public $controllers = array();

	/**
	 * Auth URL.
	 *
	 * @var array
	 */
	public $auth_url = 'https://mtssta-5756.bolt72.servebolt.com/auth/';

	/**
	 * Constructor.
	 */
	private function __construct() {
		$files = array(
			'class-ajax.php',
			'class-checker.php',
			'class-compatibility.php',
			'class-notifications.php',
			'class-plugin-checker.php',
			'class-settings.php',
			'class-theme-checker.php',
		);

		foreach ( $files as $file ) {
			require_once MTS_CONNECT_INCLUDES . $file;
		}

		$this->init_controllers();

		$this->connect_data   = $this->get_data();
		$this->settings       = $this->get_settings();
		$this->invisible_mode = apply_filters( 'mts_connect_invisible_mode', $this->is_free_plan() );
		$connected            = ( ! empty( $this->connect_data['connected'] ) );
		add_action( 'load-themes.php', array( $this, 'maybe_force_check' ), 9 );
		add_action( 'load-plugins.php', array( $this, 'maybe_force_check' ), 9 );
		add_action( 'load-update-core.php', array( $this, 'maybe_force_check' ), 9 );
		register_activation_hook( __FILE__, array( $this, 'plugin_activated' ) );
		register_deactivation_hook( __FILE__, array( $this, 'plugin_deactivated' ) );
		// Localization.
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		// Override plugin info page with changelog.
		add_action( 'install_plugins_pre_plugin-information', array( $this, 'install_plugin_information' ) );
		add_action( 'load-plugins.php', array( $this, 'brand_updates_table' ), 21 );
		add_action( 'core_upgrade_preamble', array( $this, 'brand_updates_page' ), 21 );
		add_action( 'admin_print_scripts-plugins.php', array( $this, 'updates_table_custom_js' ) );
		add_filter( 'wp_prepare_themes_for_js', array( $this, 'brand_theme_updates' ), 21 );
		add_action( 'after_plugin_row_' . MTS_CONNECT_PLUGIN_FILE, array( $this, 'plugin_row_deactivate_notice' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'handle_connect' ), 10, 2 );
		add_action( 'activated_plugin', array( $this, 'check_free_plan' ), 10, 1 );
		add_action( 'after_switch_theme', array( $this, 'check_free_plan' ), 10, 1 );
	}

	/**
	 * Singleton getter.
	 *
	 * @return object This object.
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new Core();
		}
		return self::$instance;
	}

	/**
	 * Init controllers.
	 *
	 * @return void
	 */
	public function init_controllers() {
		$this->controllers['ajax']           = new Ajax();
		$this->controllers['compatibility']  = new Compatibility();
		$this->controllers['notifications']  = new Notifications();
		$this->controllers['plugin_checker'] = new Plugin_Checker();
		$this->controllers['theme_checker']  = new Theme_Checker();
		$this->controllers['settings']       = new Settings();
	}

	/**
	 * Shorthand function to get specific controller object.
	 *
	 * @param  string $controller Controller name.
	 * @return object             Controller object.
	 */
	public static function get( $controller ) {
		if ( isset( self::$instance->controllers[ $controller ] ) ) {
			return self::$instance->controllers[ $controller ];
		}
		return new stdClass();
	}

	/**
	 * Shorthand function to get specific setting.
	 *
	 * @param  string $setting Setting name.
	 * @return object          Setting value.
	 */
	public static function get_setting( $setting ) {
		if ( isset( self::$instance->settings[ $setting ] ) ) {
			return self::$instance->settings[ $setting ];
		}
		return '';
	}

	/**
	 * Localization.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'mythemeshop-connect', false, dirname( plugin_basename( __FILE__ ) ) . '/language/' );
	}

	/**
	 * Hook after plugin activation.
	 *
	 * @return void
	 */
	public function plugin_activated() {
		self::get( 'theme_checker' )->update_themes_now();
		self::get( 'plugin_checker' )->update_plugins_now();
	}

	/**
	 * Hook after plugin deactivation.
	 *
	 * @return void
	 */
	public function plugin_deactivated() {
		self::get( 'notifications' )->reset_notices(); // Todo: reset for all admins.
		$this->disconnect();
		do_action( 'mts_connect_deactivate' );
	}


	public function maybe_force_check() {
		if ( isset( $_GET['force-check'] ) && $_GET['force-check'] == 1 ) {
			$screen = get_current_screen();
			switch ( $screen->id ) {
				case 'themes':
				case 'themes-network':
					self::get( 'theme_checker' )->update_themes_now();
					break;

				case 'plugins':
				case 'plugins-network':
					self::get( 'plugin_checker' )->update_plugins_now();
					break;

				case 'update-core':
				case 'network-update-core':
					self::get( 'theme_checker' )->update_themes_now();
					self::get( 'plugin_checker' )->update_plugins_now();
					break;
			}
		}
	}

	public static function has_new_updates( $transient = null ) {
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

	public static function is_connected() {
		return ( ! empty( self::$instance->connect_data['connected'] ) );
	}

	public function get_data() {
		$options = get_site_option( $this->data_option );
		if ( empty( $options ) ) {
			$options = array( 'connected' => false );
		}
		return $options;
	}
	public function get_settings() {
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

	public function set_settings( $new_settings ) {
		foreach ( $this->default_settings as $setting_key => $setting_value ) {
			if ( isset( $new_settings[ $setting_key ] ) ) {
				$this->settings[ $setting_key ] = $new_settings[ $setting_key ];
			}
		}
	}


	protected function update_data() {
		update_site_option( $this->data_option, $this->connect_data );
	}

	protected function update_settings() {
		update_site_option( $this->settings_option, $this->settings );
	}

	public function install_plugin_information() {
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

	public function brand_updates_page() {
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
							<img src="<?php echo esc_attr( MTS_CONNECT_ASSETS . 'img/mythemeshop-logo-2.png' ); ?>" width="64" height="64" class="updates-table-screenshot mts-connect-default-plugin-icon" style="float:left;">
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
				$screenshot = ( ! empty( $theme_obj->screenshot ) ? get_theme_root_uri() . '/' . $theme_slug . '/' . $theme_obj->screenshot : MTS_CONNECT_ASSETS . 'img/mythemeshop-logo-2.png' );
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

	public function reason_string( $reason ) {
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

	public function brand_updates_table() {
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

	public function brand_updates_plugin_row( $file, $plugin_data, $status ) {
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

	public function updates_table_custom_js() {
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
						if ( ! updater_selected && item.name == 'checked[]' && item.value.indexOf( '<?php echo esc_js( MTS_CONNECT_PLUGIN_FILE ); ?>') !== -1 ) {
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

	public function brand_theme_updates( $themes ) {

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

	public function plugin_row_deactivate_notice( $file, $plugin_data ) {
		if ( is_multisite() && ! is_network_admin() && is_plugin_active_for_network( $file ) ) {
			return;
		}

		if ( ! self::get( 'compatibility' )->mts_plugins_in_use && ! self::get( 'compatibility' )->mts_theme_in_use ) {
			return;
		}

		$wp_list_table = _get_list_table( 'WP_Plugins_List_Table' );

		echo '<tr class="plugin-update-tr active mts-deactivate-notice-row" id="' . '" data-slug="" data-plugin="' . esc_attr( $file ) . '"><td colspan="' . esc_attr( $wp_list_table->get_column_count() ) . '" class="plugin-update colspanchange"><div class="notice inline notice-inline-mts-message notice-alt"><p>';
		echo '<strong>' . __( 'Important Notice:' ) . '</strong> ' . __( 'You have a currently active MyThemeShop theme or plugin on this site. If you deactivate this required plugin, other MyThemeShop products may not function correctly and they may be automatically deactivated.', 'mythemeshop-connect' );
		echo '</p></div></td></tr>';
	}

	public function mts_product_type_extra_header( $headers ) {
		$headers[] = 'MTS Product Type';
		return $headers;
	}

	public function check_free_plan( $theme_or_plugin ) {
		$is_free = true;
		add_filter( 'extra_theme_headers', array( $this, 'mts_product_type_extra_header' ) );
		$themes = \wp_get_themes();
		remove_filter( 'extra_theme_headers', array( $this, 'mts_product_type_extra_header' ) );
		foreach ( $themes as $slug => $theme ) {
			$product_type = $theme->get('MTS Product Type');
			if ( mb_strtolower( $product_type ) == 'premium' ) {
				$is_free = false;
				break;
			}
		}

		if ( $is_free ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			add_filter( 'extra_plugin_headers', array( $this, 'mts_product_type_extra_header' ) );
			$plugins = \get_plugins();
			remove_filter( 'extra_plugin_headers', array( $this, 'mts_product_type_extra_header' ) );
			foreach ( $plugins as $slug => $plugin ) {
				$product_type = isset( $plugin['MTS Product Type'] ) ? $plugin['MTS Product Type'] : '';
				if ( mb_strtolower( $product_type ) == 'premium' ) {
					$is_free = false;
					break;
				}
			}
		}
		update_option( 'mts_free_plan', (int) $is_free );

		return $is_free;
	}

	public function is_free_plan() {
		$stored = get_option( 'mts_free_plan', false );
		if ( $stored !== false ) {
			return (bool) $stored;
		}

		$is_free = $this->check_free_plan();
		return $is_free;
	}

	public function handle_connect() {
		if ( isset( $_GET['mythemeshop_connect'] ) ) {
			switch ( $_GET['mythemeshop_connect'] ) {
				case 'ok':
					$this->connect( json_decode( base64_decode( $_GET['mythemeshop_auth'] ), true ) );
					break;

				case 'banned':
					// TODO: Handle banned & cancel
					break;

				case 'cancel':

					break;
			}
		}
	}

	public function connect( $data ) {
		$this->connect_data['username']  = $data['username'];
		$this->connect_data['email']     = $data['email'];
		$this->connect_data['api_key']   = $data['api_key'];
		$this->connect_data['connected'] = true;
		$this->update_data();
		wp_safe_redirect( admin_url( 'admin.php?page=mts-connect&mythemeshop_connect_status=success' ), 302 );
		die();
	}

	public function disconnect() {
		$this->connect_data['username']  = '';
		$this->connect_data['email']     = '';
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
		self::get( 'notifications' )->reset_notices();
	}

}
