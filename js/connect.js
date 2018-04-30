/*
 * Plugin Name: MyThemeShop Connect
 * Plugin URI: http://www.mythemeshop.com
 * Description: Update MyThemeShop themes & plugins, get news & exclusive offers right from your WordPress dashboard
 * Author: MyThemeShop
 * Author URI: http://www.mythemeshop.com
 */
jQuery(document).ready(function($) {

    // Tabs
    $('.mtsc-nav-tab-wrapper a').click(function(event) {
        event.preventDefault();
        window.location.hash = this.href.substring(this.href.indexOf('#')+1);
    });
    $(window).on('hashchange', function() {
        var tab = window.location.hash.substr(1);
        if ( tab == '' ) {
            tab = 'mtsc-connect';
        }
        $('#mtsc-tabs').children().hide().filter('#'+tab).show();
        $('#mtsc-nav-tab-wrapper').children().removeClass('nav-tab-active').filter('[href="#'+tab+'"]').addClass('nav-tab-active');
    }).trigger('hashchange');

    // Settings form
    $('#mtsc-ui-access-role').focus(function(event) {
        $(this).parent().find('input[type="radio"]').prop('checked', true);
    });
    $('#mtsc-ui-access-user').focus(function(event) {
        $(this).parent().find('input[type="radio"]').prop('checked', true);
    });

    // Connect form
    $('#mts_connect_form').submit(function(e) {
        e.preventDefault();
        var $this = $(this);
        $this.find('.mtsc-error').remove();
        if ( $this.find('#mts_agree').prop('checked') == false ) {
            $this.append('<p class="mtsc-error">'+mtsconnect.l10n_accept_tos+'</p>');
            return false;
        }
        // get_key
        $.ajax({
            url: ajaxurl,
            method: 'post',
            data: $this.serialize(),
            dataType: 'json',
            beforeSend: function( xhr ) {
                $this.addClass('loading');
            },
            success: function( data ) {
                $this.removeClass('loading');
                if (data !== null && data.login !== null) {
                    $this.html(mtsconnect.l10n_ajax_login_success);
                    jQuery('#adminmenu .toplevel_page_mts-connect .dashicons-update').removeClass('disconnected').addClass('connected');
                    // check_themes
                    /*
                    $.get(ajaxurl, 'action=mts_connect_check_themes').done(function() {
                        $this.append(mtsconnect.l10n_ajax_theme_check_done);
                        setTimeout(function() {
                            // check_plugins
                            $.get(ajaxurl, 'action=mts_connect_check_plugins').done(function() {
                                $this.append(mtsconnect.l10n_ajax_plugin_check_done);
                                setTimeout(function() {
                                    window.location.href = mtsconnect.pluginurl+'&updated=1';
                                }, 100);
                            });
                        }, 1000);
                    });
                    */
                } else { // status = fail
                    var errors = '';
                    /* $.each(data.errors, function(i, msg) {
                        errors += '<p class="mtsc-error">'+msg+'</p>';
                    }); */
                    errors = '<p class="mtsc-error">'+data.message+'</p>';
                    $this.find('.mtsc-error').remove();
                    $this.append(errors);
                }
            }
        });
    });
});