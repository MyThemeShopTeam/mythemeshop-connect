<?php
/*
 * Plugin Name: MyThemeShop Theme/Plugin Updater
 * Plugin URI: http://www.mythemeshop.com
 * Description: Update MyThemeShop themes & plugins, get news & exclusive offers right from your WordPress dashboard
 * Version: 1.4
 * Author: MyThemeShop
 * Author URI: http://www.mythemeshop.com
 * License: GPLv2
 */

defined('ABSPATH') or die;

class mts_connection {
    
    private $api_url = "https://deving.mythemeshop.com/mtsapi/v1/";
    
    private $settings_option = "mts_connect_settings";
    private $data_option = "mts_connect_data";
    private $notices_option = "mts_connect_notices";
    private $dismissed_meta = "mts_connect_dismissed_notices";
    
    protected $connect_data = array();
    protected $notices = array();
    protected $sticky_notices = array();
    protected $settings = array();
    protected $default_settings = array('network_notices' => '1', 'update_notices' => '1', 'ui_access_type' => 'role', 'ui_access_role' => 'administrator', 'ui_access_user' => '');
    protected $notice_defaults = array();
    protected $notice_tags = array();
    
    function __construct() {
        
        $this->connect_data = $this->get_data();
        $this->sticky_notices = $this->get_notices();
        $this->settings = $this->get_settings();
        
        // Notices default options
        $this->notice_defaults = array(
            'content' => '', 
            'class' => 'updated', 
            'priority' => 10, 
            'sticky' => false, 
            'date' => time(), 
            'expire' => time() + 7 * DAY_IN_SECONDS,
            'context' => array()
        );
        
        add_action( 'admin_init', array($this, 'admin_init'));
        //add_action( 'admin_print_scripts', array($this, 'admin_inline_js'));        
        
        add_action( 'load-themes.php', array( $this, 'force_check' ), 9);
        add_action( 'load-plugins.php', array( $this, 'force_check' ), 9);
        add_action( 'load-update-core.php', array( $this, 'force_check' ), 9);
        
        // show notices
        if (is_multisite())
            add_action( 'network_admin_notices', array($this, 'show_notices'));
        else
            add_action( 'admin_notices', array($this, 'show_notices'));
        
        // user has dismissed a notice?
        add_action( 'admin_init', array($this, 'dismiss_notices'));
        // add menu item
        if (is_multisite())
            add_action( 'network_admin_menu', array($this, 'admin_menu'));
        else
            add_action( 'admin_menu', array($this, 'admin_menu'));
        // remove old notifications
        add_action( 'after_setup_theme', array($this, 'after_theme') );
        
        add_action('wp_ajax_mts_connect',array($this,'ajax_mts_connect'));
        add_action('wp_ajax_mts_connect_update_settings',array($this,'ajax_update_settings'));
        add_action('wp_ajax_mts_connect_dismiss_notice',array($this,'ajax_mts_connect_dismiss_notices'));
        add_action('wp_ajax_mts_connect_check_themes',array($this,'ajax_mts_connect_check_themes'));
        add_action('wp_ajax_mts_connect_check_plugins',array($this,'ajax_mts_connect_check_plugins'));
        add_action('wp_ajax_mts_connect_reset_notices',array($this,'ajax_mts_connect_reset_notices'));
        
        add_filter( 'pre_set_site_transient_update_themes',  array( $this,'check_theme_updates' ));
        add_filter( 'pre_set_site_transient_update_plugins',  array( $this,'check_plugin_updates' ));

        // Fix false wordpress.org update notifications
        add_filter( 'pre_set_site_transient_update_themes', array($this,'fix_false_wp_org_theme_update_notification') );
        //add_filter( 'pre_set_site_transient_update_plugins', array($this,'fix_false_wp_org_plugin_update_notification') );
        
        register_activation_hook( __FILE__, array($this, 'plugin_activated' ));
        register_deactivation_hook( __FILE__, array($this, 'plugin_deactivated' ));
        
        // localization
        add_action( 'plugins_loaded', array( $this, 'mythemeshop_connect_load_textdomain' ) );
        
        // Override plugin info page with changelog
        add_action('install_plugins_pre_plugin-information', array( $this, 'install_plugin_information' ));

        add_action( 'load-plugins.php', array( $this, 'brand_updates_table' ), 21 );
        //add_action( 'load-themes.php', array( $this, 'brand_updates_table' ), 21 );
        add_action( 'admin_print_scripts-plugins.php', array( $this, 'updates_table_custom_js' ) );

        add_filter( 'wp_prepare_themes_for_js', array( $this, 'brand_theme_updates' ), 21 );

    }

    public function plugin_activated(){
         $this->update_themes_now();
         $this->update_plugins_now();
    }

    public function plugin_deactivated(){
         $this->reset_notices(); // todo: reset for all admins
         $this->disconnect();
    }
    
    function mythemeshop_connect_load_textdomain() {
        load_plugin_textdomain( 'mythemeshop-connect', false, dirname( plugin_basename( __FILE__ ) ) . '/language/' ); 
    }
    
    function ajax_mts_connect_dismiss_notices() {
        if (!empty($_POST['ids']) && is_array($_POST['ids'])) {
            foreach ($_POST['ids'] as $id) {
                $this->dismiss_notice($id);
            }
        }
        exit;
    }
    function ajax_mts_connect_check_themes() {
        $this->update_themes_now();
        $transient = get_site_transient( 'mts_update_themes' );
        if (is_object($transient) && isset($transient->response)) {
            echo count($transient->response);
        } else {
            echo '0';
        }
            
        exit;
    }

    function ajax_mts_connect_reset_notices() {
        $this->reset_notices();

        exit;
    }

    function ajax_mts_connect_check_plugins() {
        $this->update_plugins_now();
        $transient = get_site_transient( 'mts_update_plugins' );
        if (is_object($transient) && isset($transient->response)) {
            echo count($transient->response);
        } else {
            echo '0';
        }
            
        exit;
    }
    function ajax_mts_connect() {
        header("Content-type: application/json");
        $username = isset($_POST['username']) ? $_POST['username'] : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        
        $response = wp_remote_post( 
            $this->api_url . 'get_key', 
            array( 'body' => array( 'user' => $username, 'pass' => $password ), 'timeout' => 10 ) 
        );
        
        if ( is_wp_error( $response ) ) {
           $error_message = $response->get_error_message();
           echo json_encode( array( 'status' => 'fail', 'errors' => array($error_message) ) );
        } else {
            echo $response['body']; // should be JSON already
           
            $data = json_decode($response['body'], true);
            if ( isset( $data['login'] ) ) {
                $this->reset_notices();
                $this->connect_data['username'] = $data['login'];
                $this->connect_data['api_key'] = $data['key'];
                $this->connect_data['connected'] = true;
                $this->update_data();
            }
            // notices
            if (isset($data['notices']) && is_array($data['notices'])) {
                foreach($data['notices'] as $notice) {
                    if (!empty($notice['network_notice'])) {
                        $this->add_network_notice((array) $notice);
                    } else {
                        $this->add_sticky_notice((array) $notice);
                    }
                }
            }
        }
        exit;
    }
        
    function disconnect() {
        $this->connect_data['username'] = '';
        $this->connect_data['api_key'] = '';
        $this->connect_data['connected'] = false;
        $this->update_data();
        
        // remove theme updates for mts themes in transient by searching through 'packages' properties for 'mythemeshop'
        $transient = get_site_transient( 'update_themes' );
        delete_site_transient( 'mts_update_themes' );
        if ( $transient && !empty($transient->response) ) {
            foreach ($transient->response as $theme => $data) {
                if (strstr($data['package'], 'mythemeshop') !== false) {
                    unset($transient->response[$theme]);
                }
            }
            set_site_transient('update_themes', $transient);
        }
        $transient = get_site_transient( 'update_plugins' );
        delete_site_transient( 'mts_update_plugins' );
        if ( $transient && !empty($transient->response) ) {
            foreach ($transient->response as $plugin => $data) {
                if (strstr($data->package, 'mythemeshop') !== false) {
                    unset($transient->response[$plugin]);
                }
            }
            set_site_transient('update_plugins', $transient);
        }
        $this->reset_notices();
    }
    
    function reset_notices() {
        $notices = $this->notices + $this->sticky_notices;
        foreach ($notices as $id => $notice) {
            $this->remove_notice( $id );
            $this->undismiss_notice( $id );
        }
    }
    
    function admin_menu() {
        $ui_access_type = $this->settings['ui_access_type'];
        $ui_access_role = $this->settings['ui_access_role'];
        $ui_access_user = $this->settings['ui_access_user'];

        $admin_page_role = 'manage_options';
        $allow_admin_access = false;
        if ( $ui_access_type == 'role' ) {
            $admin_page_role = $ui_access_role;
        } else { // ui_access_type = user (IDs)
            $allow_admin_access = in_array( get_current_user_id(), array_map('absint', explode( ',', $ui_access_user ) ) );
        }

        $allow_admin_access = apply_filters( 'mts_connect_admin_access', $allow_admin_access );

        // Add the new admin menu and page and save the returned hook suffix
        if ( $ui_access_type == 'role' || $allow_admin_access ) {
            $this->menu_hook_suffix = add_menu_page('MyThemeShop Connect', 'MyThemeShop', $admin_page_role, 'mts-connect', array( $this, 'show_ui' ), 'dashicons-update', 66 );
            // Use the hook suffix to compose the hook and register an action executed when plugin's options page is loaded
            add_action( 'load-' . $this->menu_hook_suffix , array( $this, 'ui_onload' ) );
        }
    }
    function admin_init() {
        wp_register_script( 'mts-connect', plugins_url('/js/admin.js', __FILE__), array('jquery') );
        wp_register_script( 'mts-connect-form', plugins_url('/js/connect.js', __FILE__), array('jquery') );
        wp_register_style( 'mts-connect', plugins_url('/css/admin.css', __FILE__) );
        wp_register_style( 'mts-connect-form', plugins_url('/css/form.css', __FILE__) );
        
        wp_localize_script('mts-connect', 'mtsconnect', array(
            'pluginurl' => network_admin_url('admin.php?page=mts-connect'),
            'connected_class_attr' => (!empty($this->connect_data['connected']) && empty($_GET['disconnect']) ? 'connected' : 'disconnected'),
            'check_themes_url' => network_admin_url('themes.php?force-check=1'),
            'check_plugins_url' => network_admin_url('plugins.php?force-check=1'),
            'l10n_ajax_login_success' => __('<p>Login successful! Checking for theme updates...</p>', 'mythemeshop-connect'),
            'l10n_ajax_theme_check_done' => __('<p>Theme check done. Checking plugins...</p>', 'mythemeshop-connect'),
            'l10n_ajax_plugin_check_done' => __('<p>Plugin check done. Refreshing page...</p>', 'mythemeshop-connect'),
            'l10n_check_themes_button' => __('Check for updates now', 'mythemeshop-connect'),
            'l10n_check_plugins_button' => __('Check for updates now', 'mythemeshop-connect'),
            'l10n_insert_username' => __('Please insert your MyThemeShop <strong>username</strong> instead of the email address you registered with.', 'mythemeshop-connect'),
            'l10n_accept_tos' => __('You have to accept the terms.', 'mythemeshop-connect'),
        ) );
        
        // Enqueue on all admin pages because notice may appear anywhere
        wp_enqueue_script( 'mts-connect' );
        wp_enqueue_style( 'mts-connect' );
        
        $current_user = wp_get_current_user();
        // Tags to use in notifications
        $this->notice_tags = array(
            '[logo_url]' => plugins_url( 'img/mythemeshop-logo.png' , __FILE__ ),
            '[plugin_url]' => network_admin_url('admin.php?page=mts-connect'),
            '[themes_url]' => network_admin_url('themes.php'),
            '[plugins_url]' => network_admin_url('plugins.php'),
            '[updates_url]' => network_admin_url('update-core.php'),
            '[site_url]' => site_url(),
            '[user_firstname]' => $current_user->first_name
        );

        // Fix for false wordpress.org update notifications
        // If wrong updates are already shown, delete transients
        if ( false === get_option( 'mts_wp_org_updates_disabled' ) ) { // check only once
            update_option( 'mts_wp_org_updates_disabled', 'disabled' );

            delete_site_transient( 'update_themes' );
            delete_site_transient( 'update_plugins' );
        }
    }
    
    function force_check() {
        $screen = get_current_screen();
        if (isset($_GET['force-check']) && $_GET['force-check'] == 1) {
            switch ($screen->id) {
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
            set_site_transient('update_themes', $transient);
        }
    }
    function update_plugins_now() {
        if ( $transient = get_site_transient( 'update_plugins' ) ) {
            delete_site_transient( 'mts_update_plugins' );
            set_site_transient('update_plugins', $transient);
        }
    }
    
    function plugin_get_version() {
        $plugin_data = get_plugin_data( __FILE__ );
        $plugin_version = $plugin_data['Version'];
        return $plugin_version;
    }
    
    function check_theme_updates( $update_transient ){
        global $wp_version;
        
        if ( !isset($update_transient->checked) )
            return $update_transient;
        else
            $themes = $update_transient->checked;
        
        // New 'mts_' folder structure
        $folders_fix = array();
        foreach ($themes as $theme => $version) {
            if (stripos($theme, 'mts_') === 0) {
                $themes[str_replace('mts_', '', $theme)] = $version;
                $folders_fix[] = str_replace('mts_', '', $theme);
                unset($themes[$theme]);
            }
        }

        $mts_updates = get_site_transient('mts_update_themes');
        if ( ! $this->needs_check_now( $mts_updates ) ) {
            return $update_transient;
        }

        if (empty($_GET['disconnect'])) {
            $r = 'check_themes';
            $send_to_api = array(
                'themes' => $themes,
                'prefixed'      => $folders_fix,
                'info'          => array( 
                    'url' => home_url(), 
                    'php_version' => phpversion(), 
                    'wp_version' => $wp_version,
                    'plugin_version' => $this->plugin_get_version() 
                )
            );

            // is connected
            if ( $this->is_connected() ) {
                $send_to_api['user'] = $this->connect_data['username'];
                $send_to_api['key'] = $this->connect_data['api_key'];
            } else {
                $r = 'guest/'.$r;
            }
    
            $options = array(
                'timeout' => ( ( defined('DOING_CRON') && DOING_CRON ) ? 30 : 10),
                'body'          => $send_to_api
            );
    
            $last_update = new stdClass();
            $no_access = new stdClass();
    
            $theme_request = wp_remote_post( $this->api_url.$r, $options );
            
            if ( ! is_wp_error( $theme_request ) && wp_remote_retrieve_response_code( $theme_request ) == 200 ) {
                $theme_response = json_decode( wp_remote_retrieve_body( $theme_request ), true );
    
                if ( ! empty( $theme_response ) ) {
                    if ( ! empty( $theme_response['themes'] )) {
                        if ( empty( $update_transient->response ) ) $update_transient->response = array();
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
                    
                    if (!empty($theme_response['notices'])) {
                        foreach ($theme_response['notices'] as $notice) {
                            if (!empty($notice['network_notice'])) {
                                $this->add_network_notice((array) $notice);
                            } else {
                                $this->add_sticky_notice((array) $notice);
                            }
                        }
                    }
                    
                    if (!empty($theme_response['disconnect'])) $this->disconnect();
                }
            }
            
            $last_update->last_checked = time();
            set_site_transient( 'mts_update_themes', $last_update );
            set_site_transient( 'mts_update_themes_no_access', $no_access );
        }

        return $update_transient;
    }
    
    function check_plugin_updates( $update_transient ){
        global $wp_version;
        
        if ( !isset($update_transient->checked) )
            return $update_transient;
        else
            $plugins = $update_transient->checked;
        
        $mts_updates = get_site_transient('mts_update_plugins');
        if ( ! $this->needs_check_now( $mts_updates ) ) {
            return $update_transient;
        }

        if ( ! empty( $_GET['disconnect'] ) ) {
            return $update_transient;
        }

        $r = 'check_plugins';
        $send_to_api = array(
            'plugins' => $plugins,
            'info'          => array( 
                'url' => home_url(), 
                'php_version' => phpversion(), 
                'wp_version' => $wp_version,
                'plugin_version' => $this->plugin_get_version() 
            )
        );
        // is connected
        if ($this->is_connected()) {
            $send_to_api['user'] = $this->connect_data['username'];
            $send_to_api['key'] = $this->connect_data['api_key'];
        } else {
            $r = 'guest/'.$r;
        }

        $options = array(
            'timeout' => ( ( defined('DOING_CRON') && DOING_CRON ) ? 30 : 10),
            'body'          => $send_to_api
        );

        $last_update = new stdClass();
        $no_access = new stdClass();

        $plugin_request = wp_remote_post( $this->api_url.$r, $options );
        
        if ( ! is_wp_error( $plugin_request ) && wp_remote_retrieve_response_code( $plugin_request ) == 200 ){
            $plugin_response = json_decode( wp_remote_retrieve_body( $plugin_request ), true );

            if ( ! empty( $plugin_response ) ) {
                if ( ! empty( $plugin_response['plugins'] )) {
                    if ( empty( $update_transient->response ) ) $update_transient->response = array();
                    
                    // array to object
                    $new_arr = array();
                    foreach ($plugin_response['plugins'] as $pluginname => $plugindata) {
                        $object = new stdClass();
                        foreach ($plugindata as $k => $v) {
                            $object->$k = $v;
                        }
                        $new_arr[$pluginname] = $object;
                    }
                    $plugin_response['plugins'] = $new_arr;

                    $update_transient->response = array_merge( (array) $update_transient->response, (array) $plugin_response['plugins'] );
                }

                $last_update->checked = $plugins;
                
                if (!empty($plugin_response['plugins'])) {
                    $last_update->response = $plugin_response['plugins'];
                } else {
                    $last_update->response = array();
                }

                if ( ! empty( $plugin_response['plugins_no_access'] ) ) {
                    $no_access->response = $plugin_response['plugins_no_access'];
                } else {
                    $no_access->response = array();
                }
                
                if (!empty($plugin_response['notices'])) {
                    foreach ($plugin_response['notices'] as $notice) {
                        if (!empty($notice['network_notice'])) {
                            $this->add_network_notice((array) $notice);
                        } else {
                            $this->add_sticky_notice((array) $notice);
                        }
                    }
                }
                
                if (!empty($plugin_response['disconnect'])) $this->disconnect();
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
        if (isset($_GET['disconnect']) && $_GET['disconnect'] == 1) {
            $this->disconnect();
            $this->add_notice(array('content' => __('Disconnected.', 'mythemeshop-connect'), 'class' => 'error'));
        }
        if (isset($_GET['reset_notices']) && $_GET['reset_notices'] == 1)  {
            $this->reset_notices();
            $this->add_notice(array('content' => __('Notices reset.', 'mythemeshop-connect')));
        }
        if ( isset( $_GET['mts_changelog'] ) ) {
            $mts_changelog = $_GET['mts_changelog'];
            $transient = get_site_transient( 'mts_update_plugins' );
            if (is_object($transient) && !empty($transient->response)) {
                foreach ($transient->response as $plugin_path => $data) {
                    if (stristr($plugin_path, $mts_changelog) !== false) {
                        $content = wp_remote_get( $data->changelog );
                        echo $content['body'];
                        die();
                    }
                }
            }
            $ttransient = get_site_transient( 'mts_update_themes' );
            if (is_object($ttransient) && !empty($ttransient->response)) {
                foreach ($ttransient->response as $slug => $data) {
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
        wp_enqueue_script('mts-connect-form');
        wp_enqueue_style('mts-connect-form');
        /* 
        echo '<div class="mts_connect_ui">';
        // echo '<h2>'.__('MyThemeShop Connect', 'mythemeshop-connect').'</h2>';
        echo '<div class="mts_connect_ui_content">';
        echo '<nav class="nav-tab-wrapper mtsc-nav-tab-wrapper">';
        echo '<a href="#" class="nav-tab nav-tab-active" data-tabcontent="connect">'.__('Connect', 'mythemeshop-connect').'</a>';
        echo '<a href="#" class="nav-tab" data-tabcontent="settings">'.__('Settings', 'mythemeshop-connect').'</a>';
        echo '</nav>';
        echo '<div id="mtsc-tabs">';

        echo '<div id="mtsc-tab-connect">';

        if ( $this->is_connected() ) {
            
            echo '<p>'.__('Connected!', 'mythemeshop-connect').'</br>';
            echo __('MyThemeShop username:', 'mythemeshop-connect').' <strong>'.$this->connect_data['username'].'</strong></p>';
            echo '<a href="'.esc_url(add_query_arg('disconnect', '1')).'">'.__('Disconnect', 'mythemeshop-connect').'</a>';
            
        } else {
            // connect form
            $form = '<form action="'.admin_url('admin-ajax.php').'" method="post" id="mts_connect_form">';
            $form .= '<input type="hidden" name="action" value="mts_connect" />';
            $form .= '<p class="description">'.__('Enter your MyThemeShop username or the email address you registered with and your password to get instant updates for all your MyThemeShop products.', 'mythemeshop-connect').'</p>';
            $form .= '<label>'.__('MyThemeShop Username', 'mythemeshop-connect').'</label>';
            $form .= '<input type="text" val="" name="username" id="mts_username" />';
            $form .= '<label>'.__('Password', 'mythemeshop-connect').'</label>';
            $form .= '<input type="password" val="" name="password" id="mts_password" />';
            
            $form .= '<input type="submit" class="button button-primary" value="'.__('Connect', 'mythemeshop-connect').'" />';
            
            $form .= '</form>';
            
            echo $form;
            
        }

        echo '</div>'; // #mtsc-tab-connect
        
        echo '<div id="mtsc-tab-settings">';
            // settings form
            $form = '<form action="'.admin_url('admin-ajax.php').'" method="post" id="mts_connect_form">';
            $form .= '<input type="hidden" name="action" value="mts_connect" />';
            $form .= '<p>'.__('Enter your MyThemeShop email/username and password to get instant updates for all your MyThemeShop products.', 'mythemeshop-connect').'</p>';
            $form .= '<label>'.__('Email address or Username', 'mythemeshop-connect').'</label>';
            $form .= '<input type="text" val="" name="username" id="mts_username" />';
            $form .= '<label>'.__('Password', 'mythemeshop-connect').'</label>';
            $form .= '<input type="password" val="" name="password" id="mts_password" />';
            
            $form .= '<input type="submit" class="button button-primary" value="'.__('Connect', 'mythemeshop-connect').'" />';
            
            $form .= '</form>';
        echo '</div>'; // #mtsc-tab-settings
        
        echo '</div>'; // #mtsc-tabs
        echo '</div>'; // .mts_connect_ui_content
        echo '</div>'; // .mts_connect_ui
        */

        $updates_required = false;
        $theme_updates_required = false;
        $plugin_updates_required = false;

        $themes_transient = get_site_transient( 'mts_update_themes' );
        $plugins_transient = get_site_transient( 'mts_update_plugins' );

        $themes_noaccess_transient = get_site_transient( 'mts_update_themes_no_access' );
        $plugins_noaccess_transient = get_site_transient( 'mts_update_plugins_no_access' );
        
        $available_theme_updates = $this->new_updates_available( $themes_transient );
        $available_plugins_updates = $this->new_updates_available( $plugins_transient );

        $inaccessible_theme_updates = $this->new_updates_available( $themes_noaccess_transient );
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

        // Check for updates which user cannot access -- either because their membership is expired or because they ran out of domains
        // @@todo

        ?>
        <div class="mts_connect_ui_content">
            <nav class="nav-tab-wrapper" id="mtsc-nav-tab-wrapper">
                <a href="#mtsc-connect" class="nav-tab nav-tab-active">Connect</a>
                <a href="#mtsc-settings" class="nav-tab">Settings</a>
            </nav>
            <div id="mtsc-tabs">
                <div id="mtsc-connect">
                    <?php if ( ! $this->is_connected() ) { ?>
                        <form action="<?php echo admin_url('admin-ajax.php'); ?>" method="post" id="mts_connect_form">
                            <input type="hidden" name="action" value="mts_connect">
                            
                            <!-- <p class="description"><?php _e('Enter your MyThemeShop email/username and password to get instant updates for all your MyThemeShop products.', 'mythemeshop-connect'); ?></p> -->
                            <img src="<?php echo plugins_url( 'img/mythemeshop-logo.png' , __FILE__ ); ?>" id="mts_logo">
                            
                            <label for="mts_username"><?php _e('MyThemeShop Username', 'mythemeshop-connect'); ?></label>
                            <input type="text" val="" name="username" id="mts_username">

                            <label for="mts_password"><?php _e('Password', 'mythemeshop-connect'); ?></label>
                            <input type="password" val="" name="password" id="mts_password">

                            <label for="mts_agree" id="mtsc-label-agree">
                                <input type="checkbox" name="tos_agree" id="mts_agree" value="1"> 
                                <?php _e('I accept the <a href="#">Terms and Conditions</a>', 'mythemeshop-connect'); ?>
                            </label>

                            <input type="submit" class="button button-primary" value="<?php esc_attr_e('Connect', 'mythemeshop-connect'); ?>">
                        </form>
                    <?php } else { ?>
                        <div id="mtsc-connected">
                            <img src="<?php echo plugins_url( 'img/mythemeshop-logo.png' , __FILE__ ); ?>" id="mts_logo">
                            
                            <div class="mtsc-updates-status">
                                <?php if ( $updates_required ) { ?>
                                    <div class="mtsc-status-icon mtsc-icon-updates-required">
                                        <span class="dashicons dashicons-no-alt"></span>
                                    </div>
                                    <div class="mtsc-status-text">
                                        <?php if ( $theme_updates_required && $plugin_updates_required ) { ?>
                                            <p><?php printf( __('Your themes and plugins are outdated. Please navigate to %s to get the latest versions.', 'mythemeshop-connect'), '<a href="'.network_admin_url( 'update-core.php' ).'">'.__( 'the Updates page', 'mythemeshop-connect' ).'</a>' ); ?></p>
                                        <?php } elseif ( $theme_updates_required ) { ?>
                                            <p><?php printf( __('One or more themes are outdated. Please navigate to %s to get the latest versions.', 'mythemeshop-connect'), '<a href="'.network_admin_url( 'update-core.php' ).'">'.__( 'the Updates page', 'mythemeshop-connect' ).'</a>' ); ?></p>
                                        <?php } elseif ( $plugin_updates_required ) { ?>
                                            <p><?php printf( __('One or more plugins are outdated. Please navigate to %s to get the latest versions.', 'mythemeshop-connect'), '<a href="'.network_admin_url( 'update-core.php' ).'">'.__( 'the Updates page', 'mythemeshop-connect' ).'</a>' ); ?></p>
                                        <?php } ?>
                                    </div>
                                <?php } else { ?>
                                    <div class="mtsc-status-icon mtsc-icon-no-updates-required">
                                        <span class="dashicons dashicons-yes"></span>
                                    </div>
                                    <div class="mtsc-status-text">
                                        <p><?php _e('Your themes and plugins are up to date.', 'mythemeshop-connect'); ?></p>
                                    </div>
                                <?php } ?>
                            </div>

                            <div class="mtsc-connected-msg">
                                <span class="mtsc-connected-msg-connected">
                                    <?php _e('Connected', 'mythemeshop-connect'); ?>
                                </span>
                                <span class="mtsc-connected-msg-username">
                                    <?php printf( __( 'MyThemeShop username: %s', 'mythemeshop-connect' ), '<span class="mtsc-username">'.$this->connect_data['username'].'</span>' ); ?>
                                </span>
                                <a href="<?php echo esc_url(add_query_arg('disconnect', '1')); ?>" class="mtsc-connected-msg-disconnect">
                                    <?php _e('Disconnect', 'mythemeshop-connect'); ?>
                                </a>
                            </div>
                        </div>
                    <?php } ?>
                </div>
                <div id="mtsc-settings" style="display: none;">
                    <form action="<?php echo admin_url('admin-ajax.php'); ?>" method="post" id="mts_connect_settings_form">
                        <input type="hidden" name="action" value="mts_connect_update_settings">
                        
                        <span class="mtsc-option-heading mtsc-label-adminaccess"><?php _e('Admin page access &amp; notice visibility', 'mythemeshop-connect'); ?></span>
                        <p class="description mtsc-description-uiaccess">
                            <?php _e('Control who can see this page and the admin notices.', 'mythemeshop-connect'); ?> 
                            <?php printf( __('Pay attention when using this option because you may end up losing access to this page. In case that happens, you can use the following filter hook to give yourself access again: %1$s. More information at %2$s', 'mythemeshop-connect'), '<code>mts_connect_admin_access</code>', '<a href="https://www.mythemeshop.com/" target("_blank">'.__('mythemeshop.com', 'mythemeshop-connect' ).'</a>' ); ?>
                        </p>
                        <div class="mtsc-option-uiaccess mtsc-option-uiaccess-role">
                            <label><input type="radio" name="ui_access_type" value="role" <?php checked( $this->settings['ui_access_type'], 'role' ); ?>><?php _e('User role: ', 'mythemeshop-connect'); ?></label>
                            <select name="ui_access_role" id="mtsc-ui-access-role"><?php wp_dropdown_roles( $this->settings['ui_access_role'] ); ?></select>
                        </div>

                        <div class="mtsc-option-uiaccess mtsc-option-uiaccess-user">
                            <label><input type="radio" name="ui_access_type" value="userid" <?php checked( $this->settings['ui_access_type'], 'userid' ); ?>><?php _e('User IDs: ', 'mythemeshop-connect'); ?></label>
                            <input type="text" value="<?php echo esc_attr( $this->settings['ui_access_user'] ); ?>" name="ui_access_user" id="mtsc-ui-access-user">
                            <span class="mtsc-label-yourid"><?php printf( __('Your User ID: %d. ', 'mythemeshop-connect'), get_current_user_id() ); _e('You can insert multiple IDs separated by comma.', 'mythemeshop-connect'); ?></span>
                        </div>

                        <span class="mtsc-option-heading"><?php _e('Admin notices', 'mythemeshop-connect'); ?></span>
                        <p class="description mtsc-description-notices"><?php _e('Control which notices to show.', 'mythemeshop-connect'); ?></p>
                        <input type="hidden" name="update_notices" value="0"> 
                        <label class="mtsc-label" id="mtsc-label-updatenotices">
                            <input type="checkbox" name="update_notices" value="1" <?php checked( $this->settings['update_notices'] ); ?>> 
                            <?php _e('Show update notices', 'mythemeshop-connect'); ?>
                        </label>

                        <input type="hidden" name="network_notices" value="0"> 
                        <label class="mtsc-label" id="mtsc-label-networknotices">
                            <input type="checkbox" name="network_notices" value="1" <?php checked( $this->settings['network_notices'] ); ?>> 
                            <?php _e('Show network notices', 'mythemeshop-connect'); ?>
                        </label>
                        <p class="description mtsc-description-networknotices mtsc-description-networknotices-2"><?php _e('Network notices may include news related to the products you are using, special offers, and other useful information.', 'mythemeshop-connect'); ?></p>
                        <div class="mtsc-clear-notices-wrapper">
                            <input type="button" class="button button-secondary" name="" value="<?php esc_attr_e('Clear All Admin Notices', 'mythemeshop-connect'); ?>" id="mtsc-clear-notices">
                            <span id="mtsc-clear-notices-success"><span class="dashicons dashicons-yes"></span> <?php _e('Notices cleared', 'mythemeshop-connect'); ?></span>
                        </div>

                        <input type="submit" class="button button-primary" value="<?php esc_attr_e('Save Settings', 'mythemeshop-connect'); ?>" data-savedmsg="<?php esc_attr_e('Settings Saved', 'mythemeshop-connect'); ?>">
                    </form>
                </div>
            </div>
        </div>
        <?php

    }

    function new_updates_available( $transient ) {
        if ( is_object( $transient ) && isset( $transient->response ) ) {
            return count( $transient->response );
        }
        return 0;
    }
    
    function after_theme() {
        add_action('admin_menu', array($this, 'remove_themeupdates_page'));
    }
    function remove_themeupdates_page() {
        remove_submenu_page( 'index.php', 'mythemeshop-updates' );
    }
    
    function is_connected() {
        return ( ! empty( $this->connect_data['connected'] ) );
    }
    
    function get_data() {
        $options = get_option( $this->data_option );
        if (empty($options)) $options = array();
        return $options;
    }
    function get_settings() {
        $settings = get_option( $this->settings_option );
        
        if (empty($settings)) {
            $settings = $this->default_settings;
            update_option( $this->settings_option, $settings );
        } else {
            // Set defaults if not set
            $update_settings = false;
            foreach ( $this->default_settings as $option => $default ) {
                if ( ! isset( $settings[$option] ) ) {
                    $settings[$option] = $default;
                    $update_settings = true;
                }
            }
            if ( $update_settings ) {
                update_option( $this->settings_option, $settings );
            }
        }
        return $settings;
    }

    function set_settings( $new_settings ) {
        foreach ( $this->default_settings as $setting_key => $setting_value ) {
            if ( isset( $new_settings[$setting_key] ) ) {
                $this->settings[$setting_key] = $new_settings[$setting_key];
            }
        }
    }

    function ajax_update_settings() {
        $this->set_settings( $_POST );
        $this->update_settings();
 
        exit;
    }

    function get_notices() {
        $notices = get_option( $this->notices_option );
        if (empty($notices)) $notices = array();
        return $notices;
    }
    
    /**
     * add_notice() 
     * $args:
     * - content: notice content text or HTML
     * - class: notice element class attribute, possible values are 'updated' (default), 'error', 'update-nag', 'mts-network-notice'
     * - priority:  default 10
     * - date: date of notice as UNIX timestamp
     * - expire: expiry date as UNIX timestamp. Notice is removed and "undissmissed" ater expiring
     * - (array) context: 
     *      - screen: admin page id where the notice should appear, eg. array('themes', 'themes-network')
     *      - connected (bool): check if plugin have this setting
     *      - themes (array): list of themes in format: array('name' => 'magxp', 'version' => '1.0', 'compare' => '='), array(...)
     * 
     * @return
     */
    public function add_notice( $args ) {
        if (empty($args)) return;
        
        if (is_string( $args ) && ! strstr($args, 'content=')) $args = array('content' => $args); // $this->add_notice('instant content!');
        
        $args = wp_parse_args( $args, $this->notice_defaults );
        
        if (empty($args['content'])) return;
        
        $id = ( empty( $args['id'] ) ? md5($args['content']) : $args['id'] );
        unset( $args['id'] );
        
        if ($args['sticky']) {
            if (!empty($args['overwrite']) || (empty($args['overwrite']) && empty($this->sticky_notices[$id]))) {
                $this->sticky_notices[$id] = $args;
                $this->update_notices();
            }
        } else {
            $this->notices[$id] = $args;
        }
    }
    
    
    public function add_sticky_notice( $args ) {
        $args = wp_parse_args( $args, array() );
        $args['sticky'] = 1;
        $this->add_notice( $args );
    }
    
    // Network notices are additional API messages (news and offers) that can be switched off with an option
    public function add_network_notice( $args ) {
        if (!empty($this->settings['network_notices'])) {
            $args['network_notice'] = 1;
            $this->add_sticky_notice( $args );
        }
    }
    
    public function error_message( $msg ) {
        $this->add_notice( array('content' => $msg, 'class' => 'error') );
    }
    
    public function remove_notice( $id ) {
        unset( $this->notices[$id], $this->sticky_notices[$id] );
        $this->update_notices();
    }
     
    protected function update_data() {
        update_option( $this->data_option, $this->connect_data );
    }
    protected function update_settings() {
        update_option( $this->settings_option, $this->settings );
    }
    protected function update_notices() {
        update_option( $this->notices_option, $this->sticky_notices );
    }
    public function show_notices() {
        global $current_user;
        $user_id = $current_user->ID;

        $ui_access_type = $this->settings['ui_access_type'];
        $ui_access_role = $this->settings['ui_access_role'];
        $ui_access_user = $this->settings['ui_access_user'];

        $admin_page_role = 'manage_options';
        $allow_admin_access = false;
        if ( $ui_access_type == 'role' ) {
            $admin_page_role = $ui_access_role;
        } else { // ui_access_type = user (IDs)
            $allow_admin_access = in_array( $user_id, array_map('absint', explode( ',', $ui_access_user ) ) );
        }

        $allow_admin_access = apply_filters( 'mts_connect_admin_access', $allow_admin_access );

        if ( ( $ui_access_type == 'role' && ! current_user_can( $ui_access_role ) ) && ! $allow_admin_access ) {
            return;
        }

        $notices = $this->notices + $this->sticky_notices;
        uasort($notices, array($this, 'sort_by_priority'));
        $multiple_notices = false;
        $thickbox = 0;

        // update-themes class notice: show only the latest
        $update_notice = array();
        $unset_notices = array();
        foreach ($notices as $id => $notice) {
            if (strpos($notice['class'], 'update-themes') !== false) {
                if (empty($update_notice)) {
                    $update_notice = array('id' => $id, 'date' => $notice['date']);
                } else {
                    // check if newer
                    if ($notice['date'] < $update_notice['date']) {
                        $unset_notices[] = $id; // unset this one, there's a newer
                    } else {
                        // newer: store this one
                        $unset_notices[] = $update_notice['id'];
                        $update_notice = array('id' => $id, 'date' => $notice['date']);
                    }
                }
            }
        }

        // update-plugins class notice: show only the latest
        $update_notice = array();
        foreach ($notices as $id => $notice) {
            if (strpos($notice['class'], 'update-plugins') !== false) {
                if (empty($update_notice)) {
                    $update_notice = array('id' => $id, 'date' => $notice['date']);
                } else {
                    // check if newer
                    if ($notice['date'] < $update_notice['date']) {
                        $unset_notices[] = $id; // unset this one, there's a newer
                    } else {
                        // newer: store this one
                        $unset_notices[] = $update_notice['id'];
                        $update_notice = array('id' => $id, 'date' => $notice['date']);
                    }
                }
            }
        }

        foreach ($notices as $id => $notice) {
            // expired
            if ( $notice['expire'] < time() ) {
                $this->remove_notice( $id );
                $this->undismiss_notice( $id );
                continue;
            }
            
            // scheduled
            if ( $notice['date'] > time() ) { // ['date'] is in the future
                continue;
            }
            
            // sticky & dismissed
            if ( $notice['sticky'] ) {
                $dismissed = get_user_meta( $user_id, $this->dismissed_meta, true );
                if ( empty( $dismissed ) ) $dismissed = array();
                if (in_array( $id, $dismissed ))
                    continue;
            }
            
            // network notice and disabled
            if ( ! empty($notice['network_notice'] ) && empty( $this->settings['network_notices'] )) {
                continue;
            }

            // update notice and disabled
            $is_update_notice = ( strpos( $notice['class'], 'update-themes' ) !== false || strpos( $notice['class'], 'update-plugins' ) !== false );
            if ( empty( $this->settings['update_notices'] ) && $is_update_notice ) {
                continue;
            }
            
            // context: connected
            if ( isset( $notice['context']['connected'] )) {
                if ( ( ! $notice['context']['connected'] && $this->connect_data['connected'] )
                    || ( $notice['context']['connected'] && ! $this->connect_data['connected'] ) ) {
                    continue; // skip this
                }
            }
            
            // context: screen
            if (isset($notice['context']['screen'])) {
                if (!is_array($notice['context']['screen'])) {
                    $notice['context']['screen'] = array($notice['context']['screen']);
                }
                $is_targeted_page = false;
                $screen = get_current_screen();
                foreach ($notice['context']['screen'] as $page) {
                    if ($screen->id == $page) $is_targeted_page = true;
                }
                if ( ! $is_targeted_page ) continue; // skip if not targeted
            }

            // context: themes
            if (isset($notice['context']['themes'])) {
                if (is_string($notice['context']['themes'])) {
                    $notice['context']['themes'] = array(array('name' => $notice['context']['themes']));
                }

                $themes = wp_get_themes();
                $wp_themes = array();
                foreach ( $themes as $theme ) {
                    $name = $theme->get_stylesheet();
                    $wp_themes[ $name ] = $theme->get('Version');
                }

                $required_themes_present = true;
                foreach ( $notice['context']['themes'] as $theme ) {
                    // 1. check if theme exists
                    if ( ! array_key_exists($theme['name'], $wp_themes )) {
                        // Check for mts_ version of theme folder
                        if ( array_key_exists('mts_'.$theme['name'], $wp_themes )) {
                            $theme['name'] = 'mts_'.$theme['name'];
                        } else {
                            $required_themes_present = false;
                            break; // theme doesn't exist - skip notice   
                        }
                    }
                    // 2. compare theme version
                    if ( isset( $theme['version'] )) {
                        if ( empty( $theme['compare'] )) $theme['compare'] = '='; // compare with EQUALS by default

                        if ( ! version_compare( $wp_themes[$theme['name']], $theme['version'], $theme['compare'] )) {
                            $required_themes_present = false;
                            break; // theme version check fails - skip
                        }
                    }
                }
                if ( ! $required_themes_present ) continue;
            }

            // context: plugins
            if (isset($notice['context']['plugins'])) {
                if (is_string($notice['context']['plugins'])) {
                    $notice['context']['plugins'] = array(array('name' => $notice['context']['plugins']));
                }

                $plugins = get_plugins();
                $wp_plugins = array();
                foreach ( $plugins as $plugin_name => $plugin_info ) {
                    $name = explode('/', $plugin_name);
                    $wp_plugins[ $name[0] ] = $plugin_info['Version'];
                }

                $required_plugins_present = true;
                foreach ( $notice['context']['plugins'] as $plugin ) {
                    // 1. check if plugin exists
                    if ( ! array_key_exists($plugin['name'], $wp_plugins )) {
                        $required_plugins_present = false;
                        break; // plugin doesn't exist - skip notice
                    }
                    // 2. compare plugin version
                    if ( isset( $plugin['version'] )) {
                        if ( empty( $plugin['compare'] )) $plugin['compare'] = '='; // compare with EQUALS by default

                        if ( ! version_compare( $wp_plugins[$plugin['name']], $plugin['version'], $plugin['compare'] )) {
                            $required_plugins_present = false;
                            break; // plugin version check fails - skip
                        }
                    }
                }
                if ( ! $required_plugins_present ) continue;
            }

            // skip $unset_notices
            if (in_array($id, $unset_notices)) continue;
            
            if ( ! $thickbox ) { add_thickbox(); $thickbox = 1; }
            
            // wrap plaintext content in <p>
            // assumes text if first char != '<'
            if (substr(trim($notice['content']), 0 , 1) != '<') $notice['content'] = '<p>'.$notice['content'].'</p>';   
            
            // insert notice tags
            foreach ($this->notice_tags as $tag => $value) {
                $notice['content'] = str_replace($tag, $value, $notice['content']);
            }
            
            echo '<div class="'.$notice['class'].($notice['sticky'] ? ' mts-connect-sticky' : '').' mts-connect-notice" id="notice_'.$id.'">';
            echo $notice['content'];
            echo '<a href="' . esc_url(add_query_arg( 'mts_dismiss_notice', $id )) . '" class="dashicons dashicons-dismiss mts-notice-dismiss-icon" title="'.__('Dissmiss Notice').'"></a>';
            echo '<a href="' . esc_url(add_query_arg( 'mts_dismiss_notice', 'dismiss_all' )) . '" class="dashicons dashicons-dismiss mts-notice-dismiss-all-icon" title="'.__('Dissmiss All Notices').'"></a>';
            echo '</div>';
            $multiple_notices = true;
        }
        
    }
    
    public function dismiss_notices() {
        if ( !empty($_GET['mts_dismiss_notice']) && is_string( $_GET['mts_dismiss_notice'] ) ) {
            if ( $_GET['mts_dismiss_notice'] == 'dismiss_all' ) {
                foreach ( $this->sticky_notices as $id => $notice ) {
                    $this->dismiss_notice( $id );
                }
            } else {
                $this->dismiss_notice( $_GET['mts_dismiss_notice'] );
            }
            
        }
    }
    private function dismiss_notice( $id ) {
        global $current_user;
        $user_id = $current_user->ID;
        $dismissed = get_user_meta($user_id, $this->dismissed_meta, true );
        if (is_string($dismissed)) $dismissed = array($dismissed);
        if ( ! in_array( $id, $dismissed ) ) {
            $dismissed[] = $id;
            update_user_meta($user_id, $this->dismissed_meta, $dismissed);
        }
    }
    
    private function undismiss_notice( $id ) {
        global $current_user;
        $user_id = $current_user->ID;
        $dismissed = get_user_meta($user_id, $this->dismissed_meta, true );
        if (is_string($dismissed)) $dismissed = array($dismissed);
        if ( $key = array_search( $id, $dismissed ) ) {
            unset( $dismissed[$key] );
            update_user_meta($user_id, $this->dismissed_meta, $dismissed);
        }
    }
    
    public function sort_by_priority($a, $b) {
        if ($a['priority'] == $b['priority']) return 1;
        return $a['priority'] - $b['priority'];
    }

    public function fix_false_wp_org_theme_update_notification( $val ) {
        $allow_update = array( 'point', 'ribbon-lite' );
        if ( property_exists( $val, 'response' ) && is_array( $val->response ) ) {
            foreach ( $val->response as $key => $value ) {
                if ( isset( $value['theme'] ) ) {// added by WordPress
                    if ( in_array( $value['theme'], $allow_update ) ) {
                        continue;
                    }
                    $url = $value['url'];// maybe wrong url for MyThemeShop theme
                    $theme = wp_get_theme( $value['theme'] );//real theme object
                    $theme_uri = $theme->get( 'ThemeURI' );//theme url
                    // If it is MyThemeShop theme but wordpress.org have the theme with same name, remove it from update response
                    if ( false !== strpos( $theme_uri, 'mythemeshop.com' ) && false !== strpos( $url, 'wordpress.org' ) ) {
                        unset( $val->response[$key] );
                    }
                }
            }
        }
        return $val;
    }

    public function fix_false_wp_org_plugin_update_notification( $val ) {

        if ( property_exists( $val, 'response' ) && is_array( $val->response ) ) {
            foreach ( $val->response as $key => $value ) {
                $url = $value->url;
                $plugin = get_plugin_data( WP_PLUGIN_DIR.'/'.$key, false, false );
                $plugin_uri = $plugin['PluginURI'];
                if ( 0 !== strpos( $plugin_uri, 'mythemeshop.com' && 0 !== strpos( $url, 'wordpress.org' ) ) ) {
                    unset( $val->response[$key] );
                }
            }
        }
        return $val;
    }

    function install_plugin_information() {
        $plugin = $_GET['plugin'];
        $transient = get_site_transient( 'mts_update_plugins' );
        if (is_object($transient) && !empty($transient->response)) {
            foreach ($transient->response as $plugin_path => $data) {
                if (stristr($plugin_path, $plugin) !== false) {
                    $content = wp_remote_get( $data->changelog );
                    echo $content['body'];

                    // short circuit
                    iframe_footer();
                    exit;
                }
            }
        }
    }

    function brand_updates_table() {
        if ( ! current_user_can( 'update_plugins' ) ) {
            return;
        }

        //don't show on per site plugins list, just like core
        if ( is_multisite() && ! is_network_admin() ) {
            return;
        }

        // Get plugin updates which user has no access to
        $plugins_noaccess_transient = get_site_transient( 'mts_update_plugins_no_access' );
        if ( is_object( $plugins_noaccess_transient ) && !empty( $plugins_noaccess_transient->response ) ) {
            foreach ( $plugins_noaccess_transient->response as $plugin_slug => $plugin_data ) { 
                add_action( 'after_plugin_row_'.$plugin_slug, array( $this, 'brand_updates_plugin_row' ), 9, 3 );
            }
        }
    }

    function brand_updates_plugin_row( $file, $plugin_data, $status ) {
        if ( ! current_user_can( 'update_plugins' ) ) {
            return;
        }

        // @@todo: add changelog link in notice
        $row_text   = __( 'There is a new version of %1$s available. Automatic update for this product is unavailable.', 'wpmudev' );
        $active_class = '';
        if ( is_network_admin() ) {
            $active_class = is_plugin_active_for_network( $file ) ? ' active' : '';
        } else {
            $active_class = is_plugin_active( $file ) ? ' active' : '';
        }
        $filename = $file;
        $plugins_allowedtags = array(
            'a'       => array( 'href' => array(), 'title' => array(), 'class' => array(), 'target' => array() ),
            'abbr'    => array( 'title' => array() ),
            'acronym' => array( 'title' => array() ),
            'code'    => array(),
            'em'      => array(),
            'strong'  => array(),
        );
        $plugin_name = wp_kses( $plugin_data['Name'], $plugins_allowedtags );

        ?>

        <tr class="plugin-update-tr<?php echo $active_class; ?>"
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
            });
        </script>
        <?php
    }

    function brand_theme_updates( $themes ) {

        $html = '<p><strong>' . __( 'There is a new version of %1$s available. <a href="%2$s" %3$s>View version %4$s details</a>. <em>Automatic update is unavailable for this theme.</em>' ) . '</strong></p>';

        $themes_noaccess_transient = get_site_transient( 'mts_update_themes_no_access' );
        if ( is_object( $themes_noaccess_transient ) && !empty( $themes_noaccess_transient->response ) ) {
            foreach ( $themes_noaccess_transient->response as $theme_slug => $theme_data ) {
                if ( isset( $themes[$theme_slug] ) ) {
                    $themes[$theme_slug]['hasUpdate'] = 1;
                    $themes[$theme_slug]['hasPackage'] = 1;

                    // Get theme
                    $theme = wp_get_theme( $theme_slug );
                    $theme_name = $theme->display('Name');
                    $details_url = $theme_data['changelog'];
                    $new_version = $theme_data['version'];
                    $themes[$theme_slug]['update'] = sprintf( $html,
                        $theme_name,
                        esc_url( $details_url ),
                        sprintf(
                            'class="thickbox open-plugin-details-modal" aria-label="%s"',
                            /* translators: 1: theme name, 2: version number */
                            esc_attr( sprintf( __( 'View %1$s version %2$s details' ), $theme_name, $update['new_version'] ) )
                        ),
                        $update['new_version']
                    );
                }
            }
        }

        return $themes;
    }
    
}

$mts_connection = new mts_connection();

?>