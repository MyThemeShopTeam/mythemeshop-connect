<?php
/**
 * Code responsible for the settings admin screen.
 *
 * @since      3.0
 * @package    MyThemeShop_Connect
 * @author     MyThemeShop <support-team@mythemeshop.com>
 */

namespace MyThemeShop_Connect;

defined( 'ABSPATH' ) || exit;

/**
 * Settings class.
 */
class Settings {

	function __construct() {

		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

		// add menu item
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'admin_menu' ) );
		} else {
			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		}

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
