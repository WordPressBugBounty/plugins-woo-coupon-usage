<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
global $wcusage_register_db_version;
$wcusage_register_db_version = "2.7";
/**
 * Create registration table
 *
 */
function wcusage_install_register_tables() {
    global $wpdb;
    global $wcusage_register_db_version;
    $installed_ver = get_option( "wcusage_register_db_version" );
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wcusage_register'" ) != $wpdb->prefix . 'wcusage_register' ) {
        $installed_ver = null;
    }
    if ( $installed_ver != $wcusage_register_db_version ) {
        $table_name = $wpdb->prefix . 'wcusage_register';
        $sql = "CREATE TABLE {$table_name} (\r\n\t\t\tid bigint NOT NULL AUTO_INCREMENT,\r\n\t\t\tuserid bigint NOT NULL,\r\n      couponcode text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,\r\n      promote text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,\r\n      referrer text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,\r\n      website text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,\r\n      status text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,\r\n      type text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,\r\n      info text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,\r\n\t\t\tdate datetime NOT NULL DEFAULT '0000-00-00 00:00:00',\r\n\t\t\tdateaccepted datetime NOT NULL DEFAULT '0000-00-00 00:00:00',\r\n\t\t\tPRIMARY KEY  (id)\r\n\t\t);";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( "wcusage_register_db_version", $wcusage_register_db_version );
    }
}

/**
 * Check database update
 *
 */
function wcusage_update_register_db_check() {
    global $wcusage_register_db_version;
    if ( get_site_option( 'wcusage_register_db_version' ) != $wcusage_register_db_version ) {
        wcusage_install_register_tables();
    }
}

add_action( 'plugins_loaded', 'wcusage_update_register_db_check' );
/**
 * Install data into registration table
 *
 */
function wcusage_install_register_data(
    $couponcode,
    $userid,
    $referrer,
    $promote,
    $website,
    $type = "",
    $info = ""
) {
    if ( $type ) {
        if ( $type == "1" || !$type ) {
            $type = wcusage_get_setting_value( 'wcusage_field_registration_coupon_template', '' );
        } else {
            $type = wcusage_get_setting_value( 'wcusage_field_registration_coupon_template' . "_" . $type, '' );
        }
    }
    global $wpdb;
    $table_name = $wpdb->prefix . 'wcusage_register';
    // Check the table exists, if not, create it
    wcusage_install_register_tables();
    // Encode emoji
    $couponcode = wp_encode_emoji( $couponcode );
    $promote = wp_encode_emoji( $promote );
    $referrer = wp_encode_emoji( $referrer );
    $website = wp_encode_emoji( $website );
    $type = wp_encode_emoji( $type );
    $info = wp_encode_emoji( $info );
    // Sanitize data
    $couponcode = sanitize_text_field( $couponcode );
    $promote = sanitize_text_field( $promote );
    $referrer = sanitize_text_field( $referrer );
    $website = sanitize_text_field( $website );
    $type = sanitize_text_field( $type );
    $info = sanitize_text_field( $info );
    // Check already submission for user id within the last 10 seconds
    $query = $wpdb->prepare( "SELECT id FROM {$table_name} WHERE userid = %d AND date > DATE_SUB(NOW(), INTERVAL 10 SECOND) LIMIT 1", $userid );
    $result = $wpdb->get_results( $query );
    if ( !empty( $result ) ) {
        $last_id = $result[0]->id;
        return $last_id;
    }
    // Insert data
    $wpdb->insert( $table_name, array(
        'userid'       => $userid,
        'couponcode'   => $couponcode,
        'promote'      => $promote,
        'referrer'     => $referrer,
        'website'      => $website,
        'type'         => $type,
        'info'         => $info,
        'status'       => 'pending',
        'date'         => current_time( 'mysql' ),
        'dateaccepted' => '',
    ) );
    $last_id = $wpdb->insert_id;
    // Activity Log
    $user_info = get_userdata( $userid );
    $username = $user_info->user_login;
    $activity_log = wcusage_add_activity( $last_id, 'registration', $username );
    // Custom Action
    do_action(
        'wcusage_hook_registration_new',
        $last_id,
        $userid,
        $couponcode
    );
    return $last_id;
}
