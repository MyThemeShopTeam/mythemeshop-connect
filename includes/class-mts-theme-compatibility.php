<?php
/**
 * Compatibility code for MTS themes & plugins.
 *
 * @since      3.0
 * @package    MyThemeShop_Connect
 * @author     MyThemeShop <support-team@mythemeshop.com>
 */

namespace MyThemeShop_Connect;

defined( 'ABSPATH' ) || exit;

/**
 * MTS_Theme_Compatibility class.
 */
class MTS_Theme_Compatibility {
	protected $mts_theme_in_use      = false;
	protected $mts_plugins_in_use    = 0;
	protected $custom_admin_messages = array();
	protected $ngmsg                 = '';
	protected $plugin_file           = '';
	function __construct() {
		$this->plugin_file = 'mythemeshop-connect/mythemeshop-connect.php';

		add_action( 'admin_menu', array( $this, 'replace_admin_pages' ), 99 );

		$connected = ( ! empty( $this->connect_data['connected'] ) );
		add_filter( 'nhp-opts-sections', '__return_empty_array', 9, 1 );

		if ( $connected ) {
			remove_filter( 'nhp-opts-sections', '__return_empty_array', 9 );
			remove_action( 'admin_menu', array( $this, 'replace_admin_pages' ), 99 );
		}

		add_filter( 'plugins_loaded', array( $this, 'check_for_mts_plugins' ), 11 );
		add_filter( 'after_setup_theme', array( $this, 'check_for_mts_theme' ), 11 );
		add_filter( 'after_switch_theme', array( $this, 'clear_theme_check' ), 11 );

		add_action( 'plugins_loaded', array( $this, 'load_connector' ), 9 );

		if ( empty( $this->connect_data['connected'] ) ) {
			add_filter( 'nhp-opts-sections', array( $this, 'nhp_sections' ), 10, 1 );
			add_filter( 'nhp-opts-args', array( $this, 'nhp_opts' ), 10, 1 );
			add_filter( 'nhp-opts-extra-tabs', '__return_empty_array', 11, 1 );
		}

		add_action( 'init', array( $this, 'set_theme_defaults' ), -11, 1 );
	}

	public function is_free_plan() {
		$themes = wp_get_themes();
		return true;
		// print_r($themes);die();
	}

	public function set_theme_defaults() {
		if ( defined( 'MTS_THEME_NAME' ) ) {
			if ( ! get_option( MTS_THEME_NAME, false ) ) {
				remove_filter( 'nhp-opts-sections', '__return_empty_array', 9 );
				remove_filter( 'nhp-opts-sections', array( $this, 'nhp_sections' ), 10 );
			}
		}
	}

	function init() {
		define( 'MTS_THEME_T', 'mts' . 'the' );
	}

	function after_theme() {
		add_action( 'admin_menu', array( $this, 'remove_themeupdates_page' ) );
	}
	function remove_themeupdates_page() {
		remove_submenu_page( 'index.php', 'mythemeshop-updates' );
	}

	function check_for_mts_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active_plugins = get_option( 'active_plugins', array() );
		$all_plugins    = get_plugins( '' );

		$hash = substr( md5( serialize( $active_plugins ) ), 0, 8 );
		$opt  = get_option( 'mts_plugins_active', false );
		if ( $opt !== false ) {
			$stored_hash = substr( $opt, 0, 8 );
			if ( $hash == $stored_hash ) {
				// No change in the list of plugins
				$this->mts_plugins_in_use = (int) substr( $opt, 9 );
				return;
			}
		}

		foreach ( $active_plugins as $plugin_file ) {
			if ( $plugin_file == $this->plugin_file ) {
				continue;
			}
			if ( isset( $all_plugins[ $plugin_file ] ) && isset( $all_plugins[ $plugin_file ]['Author'] ) && stripos( $all_plugins[ $plugin_file ]['Author'], 'MyThemeShop' ) !== false ) {
				$this->mts_plugins_in_use++;
			}
		}

		update_option( 'mts_plugins_active', $hash . '-' . $this->mts_plugins_in_use );
		return;

	}

	function check_for_mts_theme() {
		// Check for mts_theme once.
		if ( ( $stored = get_option( 'mts_theme_active', false ) ) !== false ) {
			$this->mts_theme_in_use = ( $stored === '1' );
			return;
		}

		$theme  = wp_get_theme();
		$author = $theme->get( 'Author' );
		if ( stripos( $author, 'MyThemeShop' ) !== false ) {
			$this->mts_theme_in_use = true;
			update_option( 'mts_theme_active', '1' );
			return;
		}

		// Also check parent
		if ( $theme->parent() ) {
			$parent_author = $theme->parent()->get( 'Author' );
			if ( stripos( $parent_author, 'MyThemeShop' ) !== false ) {
				$this->mts_theme_in_use = true;
				update_option( 'mts_theme_active', '1' );
				return;
			}
		}

		update_option( 'mts_theme_active', '0' );
		return;
	}

	function clear_theme_check() {
		delete_option( 'mts_theme_active' );
	}


	function add_overlay() {
		add_thickbox();
		add_action( 'admin_footer', array( $this, 'show_overlay' ), 10, 1 );
	}

	function show_overlay() {
		?>
		<div
		<?php
		$this->str_convert(
			'6964 3D226D74732D636F 6E 6E656374 2D6D6F64 616C2220636C6173 73 3D 22 6D74 73 2D636F6E 6E6563742D74 68 65 6D652D6D6F64616C222073 74796C653D22646973706C6179 3A6E6F6E653B 22
',
			1
		);
		?>
				>
			<div></div>
			<div>
				<p><?php echo strip_tags( $this->ngmsg ); ?></p>
				<?php $this->connect_form_html(); ?>
				<p><a class="button button-secondary" href="#"><?php $this->str_convert( '436F6E6E656374204C61746572', 1 ); ?></a></p>
			</div>
		</div>
		<?php
	}


	function add_reminder() {
		$exclude_pages = array( 'toplevel_page_mts-connect', 'toplevel_page_mts-connect-network', 'toplevel_page_mts-install-plugins' );
		$connected     = ( ! empty( $this->connect_data['connected'] ) && empty( $_GET['disconnect'] ) );

		$screen = get_current_screen();
		// Never show on excluded pages
		if ( in_array( $screen->id, $exclude_pages ) ) {
			return;
		}
		// Multisite: show only on network admin
		if ( is_multisite() && ! is_network_admin() ) {
			return;
		}
		if ( ! $connected ) {
			if ( $this->mts_theme_in_use || $this->mts_plugins_in_use ) {
				$this->add_notice(
					array(
						'content' => $this->ngmsg,
						'class'   => 'error',
					)
				);
				$this->add_overlay();
			}
		}
	}

	/**
	 * Load legacy class for backwards compatibility.
	 *
	 * @return void
	 */
	public function load_connector() {
		require_once MTS_CONNECT_INCLUDES . 'class-mts_connector.php';
	}

	function nhp_opts( $opts ) {
		$opts['show_import_export']    = false;
		$opts['show_typography']       = false;
		$opts['show_translate']        = false;
		$opts['show_child_theme_opts'] = false;
		$opts['last_tab']              = 0;

		return $opts;
	}

	function nhp_sections( $sections ) {
		$url        = network_admin_url( 'admin.php?page=mts-connect' );
		$sections[] = array(
			'icon'   => 'fa fa-cogs',
			'title'  => __( 'Not Connected', 'mythemeshop-connect' ),
			'desc'   => '<p class="description">' . __( 'You will find all the theme options here after <a href="' . $url . '">connecting with your MyThemeShop account</a>.', 'mythemeshop-connect' ) . '</p>',
			'fields' => array(
				/*
				array(
					'id' => 'mts_logo',
					'type' => 'upload',
					'title' => __('Logo Image', 'mythemeshop-connect' ),
					'sub_desc' => __('Upload your logo using the Upload Button or insert image URL. Preferable Size 120px X 28px', 'mythemeshop-connect' ),
					'return' => 'id'
					),*/
			),
		);
		return $sections;
	}

	function replace_admin_pages() {
		$default_title = __( 'Settings', 'mythemeshop-connect' );
		/* Translators: 1 is opening tag for link to admin page, 2 is closing tag for the same */
		$default_message = sprintf( __( 'Plugin settings will appear here after you %1$sconnect with your MyThemeShop account.%2$s', 'mythemeshop-connect' ), '<a href="' . network_admin_url( 'admin.php?page=mts-connect' ) . '">', '</a>' );
		$replace         = array(
			array(
				'parent_slug' => 'options-general.php',
				'menu_slug'   => 'wp-review-pro',
				'title'       => __( 'WP Review Settings', 'mythemeshop-connect' ),
				/* Translators: 1 is opening tag for link to admin page, 2 is closing tag for the same */
				'message'     => sprintf( __( 'Review settings will appear here after you %1$sconnect with your MyThemeShop account.%2$s', 'mythemeshop-connect' ), '<a href="' . network_admin_url( 'admin.php?page=mts-connect' ) . '">', '</a>' ),
			),
			array(
				'parent_slug' => 'admin.php',
				'menu_slug'   => 'url_shortener_settings',
			),
			array(
				'parent_slug' => 'edit.php?post_type=wp_quiz',
				'menu_slug'   => 'wp_quiz_config',
			),
			array(
				'parent_slug' => 'admin.php',
				'menu_slug'   => 'wp-shortcode-options-general',
			),
			array(
				'parent_slug' => 'edit.php?post_type=listing',
				'menu_slug'   => 'wre_options',
			),
			array(
				'parent_slug' => 'edit.php?post_type=mts_notification_bar',
				'menu_slug'   => 'mts-notification-bar',
			),
			array(
				'parent_slug' => 'options-general.php',
				'menu_slug'   => 'wps-subscribe',
			),
		);

		$hide_items = array(
			array(
				'parent_slug' => 'edit.php?post_type=wp_quiz',
				'menu_slug'   => 'edit.php?post_type=wp_quiz',
			),
			array(
				'parent_slug' => 'edit.php?post_type=wp_quiz',
				'menu_slug'   => 'post-new.php?post_type=wp_quiz',
			),
		);

		foreach ( $replace as $menu_data ) {
			$parent_slug = $menu_data['parent_slug'];
			$menu_slug   = $menu_data['menu_slug'];
			$hookname    = get_plugin_page_hookname( $menu_slug, $parent_slug );

			$title   = ! empty( $menu_data['title'] ) ? $menu_data['title'] : $default_title;
			$message = ! empty( $menu_data['message'] ) ? $menu_data['message'] : $default_message;

			$this->custom_admin_messages[ $hookname ] = array(
				'title'   => $title,
				'message' => $message,
			);

			remove_all_actions( $hookname );
			add_action( $hookname, array( $this, 'replace_settings_page' ) );
		}

		foreach ( $hide_items as $i => $item ) {
			remove_submenu_page( $item['parent_slug'], $item['menu_slug'] );
		}

		add_action( 'add_meta_boxes', array( $this, 'remove_meta_boxes' ), 99, 2 );
	}

	function remove_meta_boxes( $post_type, $post ) {
		$remove_meta_boxes = array(
			'wp-review-metabox-review',
			'wp-review-metabox-item',
			'wp-review-metabox-reviewLinks',
			'wp-review-metabox-desc',
			'wp-review-metabox-userReview',
		);
		$post_types        = get_post_types( array( 'public' => true ), 'names' );
		foreach ( $post_types as $post_type ) {
			foreach ( $remove_meta_boxes as $box ) {
				remove_meta_box( $box, $post_type, 'normal' );
			}
		}
	}

	function replace_settings_page() {
		$hookname = current_filter();
		$data     = $this->custom_admin_messages[ $hookname ];

		?>
		<div class="wrap wp-review">
			<h1><?php echo $data['title']; ?></h1>

			<p><?php echo $data['message']; ?></p>
		</div>
		<script type="text/javascript">var mts_connect_refresh = true;</script>
		<?php
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
}
