<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
global $wcusage_register_db_version;
$wcusage_register_db_version = "2.8";
/**
 * Create registration table
 *
 */
function wcusage_install_register_tables() {
    global $wpdb;
    global $wcusage_register_db_version;
    $installed_ver = get_option( "wcusage_register_db_version" );
    // Check if wcusage_register table does not exist
    if ( $wpdb->get_var( "SHOW TABLES LIKE '" . $wpdb->prefix . 'wcusage_register' . "'" ) != $wpdb->prefix . 'wcusage_register' ) {
        $installed_ver = 0;
    }
    if ( !$installed_ver || $installed_ver != $wcusage_register_db_version ) {
        $table_name = $wpdb->prefix . 'wcusage_register';
        $sql = "CREATE TABLE {$table_name} (\r\n\t\t\tid bigint NOT NULL AUTO_INCREMENT,\r\n\t\t\tuserid bigint NOT NULL,\r\n      couponcode text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,\r\n      promote text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,\r\n      referrer text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,\r\n      website text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,\r\n      status text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,\r\n      type text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,\r\n      info text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,\r\n\t\t\tdate datetime NOT NULL DEFAULT '0000-00-00 00:00:00',\r\n\t\t\tdateaccepted datetime DEFAULT NULL,\r\n\t\t\tPRIMARY KEY  (id)\r\n\t\t);";
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
    wcusage_migrate_register_dateaccepted_column();
}

add_action( 'plugins_loaded', 'wcusage_update_register_db_check' );
/**
 * One-time migration: ensure the dateaccepted column allows NULL.
 * dbDelta() cannot remove NOT NULL constraints on existing columns, so we
 * use an explicit ALTER TABLE guarded by a flag option.
 */
function wcusage_migrate_register_dateaccepted_column() {
    if ( get_option( 'wcusage_register_dateaccepted_nullable' ) ) {
        return;
    }
    global $wpdb;
    $table_name = $wpdb->prefix . 'wcusage_register';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) !== $table_name ) {
        return;
    }
    $wpdb->query( "ALTER TABLE {$table_name} MODIFY COLUMN dateaccepted datetime DEFAULT NULL" );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    update_option( 'wcusage_register_dateaccepted_nullable', '1' );
}

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
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
    $result = $wpdb->get_results( $query );
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
    if ( !empty( $result ) ) {
        $last_id = $result[0]->id;
        return $last_id;
    }
    // Insert data
    $insert_result = $wpdb->insert( $table_name, array(
        'userid'       => $userid,
        'couponcode'   => $couponcode,
        'promote'      => $promote,
        'referrer'     => $referrer,
        'website'      => $website,
        'type'         => $type,
        'info'         => $info,
        'status'       => 'pending',
        'date'         => current_time( 'mysql' ),
        'dateaccepted' => null,
    ) );
    if ( $insert_result === false ) {
        error_log( 'CA: wcusage_install_register_data() DB insert failed for user ID ' . $userid . ': ' . $wpdb->last_error );
        return false;
    }
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

/**
 * Check if auto-accept should apply for this registration.
 *
 * If the limiter is enabled, auto-accept will only apply when:
 * - The user's current role/group matches one selected in settings, OR
 * - The role/group assigned to the selected registration template matches one selected in settings.
 *
 * If no roles/groups are selected, auto-accept is not restricted.
 */
function wcusage_registration_auto_accept_allowed(  $user_id, $type_num = ''  ) {
    $limit_enabled = wcusage_get_setting_value( 'wcusage_field_registration_auto_accept_limit', '0' );
    if ( !$limit_enabled ) {
        return true;
    }
    $options = get_option( 'wcusage_options' );
    $selected_roles = array();
    if ( isset( $options['wcusage_field_registration_auto_accept_roles'] ) && is_array( $options['wcusage_field_registration_auto_accept_roles'] ) ) {
        foreach ( $options['wcusage_field_registration_auto_accept_roles'] as $role_key => $enabled ) {
            if ( $enabled ) {
                $selected_roles[] = $role_key;
            }
        }
    }
    // If none selected, treat as unrestricted.
    if ( empty( $selected_roles ) ) {
        return true;
    }
    $user = get_user_by( 'id', $user_id );
    if ( !$user || !is_array( $user->roles ) ) {
        return false;
    }
    foreach ( $user->roles as $role ) {
        if ( in_array( $role, $selected_roles, true ) ) {
            return true;
        }
    }
    // Support: template-assigned roles/groups (added on acceptance), based on the selected template type.
    $multiple_template = wcusage_get_setting_value( 'wcusage_field_registration_multiple_template', '0' );
    $template_roles = wcusage_get_setting_value( 'wcusage_field_registration_multiple_template_roles', '0' );
    if ( $multiple_template && $template_roles ) {
        $suffix = '';
        if ( $type_num && $type_num !== '1' ) {
            $suffix = '_' . $type_num;
        }
        $template_role = wcusage_get_setting_value( 'wcusage_field_registration_coupon_template_role' . $suffix, '' );
        $template_role = sanitize_text_field( $template_role );
        if ( $template_role && in_array( $template_role, $selected_roles, true ) ) {
            return true;
        }
    }
    return false;
}

/**
 * Registration custom fields on the admin user screens.
 *
 * These render the same custom fields configured under Registrations > Custom Form
 * Fields, pre-filled from the affiliate's 'wcu_info' user meta, on:
 *   - the "View Affiliate" page (Edit User tab > Basic Information), and
 *   - the standard WordPress edit-user / profile screen.
 * Values are stored back into 'wcu_info' keyed by the field label (matching the
 * dashboard "Account Details" behaviour).
 */
/**
 * Whether at least one editable registration custom field is configured.
 *
 * @return bool
 */
if ( !function_exists( 'wcusage_has_custom_fields' ) ) {
    function wcusage_has_custom_fields() {
        $n = (int) wcusage_get_setting_value( 'wcusage_field_registration_custom_fields', '2' );
        for ($x = 1; $x <= $n; $x++) {
            $label = trim( (string) wcusage_get_setting_value( 'wcusage_field_registration_custom_label_' . $x, '' ) );
            $type = wcusage_get_setting_value( 'wcusage_field_registration_custom_type_' . $x, '' );
            if ( $label !== '' && $type !== 'header' && $type !== 'paragraph' ) {
                return true;
            }
        }
        return false;
    }

}
/**
 * Builds the input control HTML for a single admin custom field.
 *
 * @param int    $x     Field index.
 * @param string $type  Field type.
 * @param string $value Current value.
 * @return string
 */
if ( !function_exists( 'wcusage_admin_custom_field_control' ) ) {
    function wcusage_admin_custom_field_control(  $x, $type, $value  ) {
        $id = 'wcu_admin_custom_' . $x;
        switch ( $type ) {
            case 'textarea':
                return '<textarea class="wcu-admin-custom-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" rows="3" style="width:100%;max-width:400px;">' . esc_textarea( $value ) . '</textarea>';
            case 'date':
                return '<input type="date" class="wcu-admin-custom-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '" />';
            case 'dropdown':
                $opts = ( function_exists( 'wcusage_settings_custom_field_options' ) ? wcusage_settings_custom_field_options( $x ) : array() );
                $html = '<select class="wcu-admin-custom-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '">';
                $html .= '<option value="">' . esc_html__( 'Select...', 'woo-coupon-usage' ) . '</option>';
                if ( $value !== '' && !in_array( $value, $opts, true ) ) {
                    $html .= '<option value="' . esc_attr( $value ) . '" selected>' . esc_html( $value ) . '</option>';
                }
                foreach ( $opts as $opt ) {
                    $html .= '<option value="' . esc_attr( $opt ) . '"' . selected( $value, $opt, false ) . '>' . esc_html( $opt ) . '</option>';
                }
                $html .= '</select>';
                return $html;
            case 'radio':
                $opts = ( function_exists( 'wcusage_settings_custom_field_options' ) ? wcusage_settings_custom_field_options( $x ) : array() );
                $html = '<span class="wcu-admin-custom-radios">';
                foreach ( $opts as $i => $opt ) {
                    $rid = $id . '_' . $i;
                    $html .= '<label style="margin-right:14px;"><input type="radio" class="wcu-admin-custom-input" id="' . esc_attr( $rid ) . '" name="' . esc_attr( $id ) . '" value="' . esc_attr( $opt ) . '"' . checked( $value, $opt, false ) . ' /> ' . esc_html( $opt ) . '</label>';
                }
                $html .= '</span>';
                return $html;
            case 'checkbox':
            case 'acceptance':
                $checked = ( $value === 'Yes' ? ' checked' : '' );
                // Hidden "No" mirrors the checkbox name so a normal (non-AJAX) form POST
                // can clear the value when the box is unticked.
                return '<input type="hidden" name="' . esc_attr( $id ) . '" value="No" />' . '<label><input type="checkbox" class="wcu-admin-custom-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="Yes"' . $checked . ' /> ' . esc_html__( 'Yes', 'woo-coupon-usage' ) . '</label>';
            case 'text':
            default:
                return '<input type="text" class="wcu-admin-custom-input regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '" />';
        }
    }

}
/**
 * Renders the configured registration custom fields for an admin user screen.
 *
 * @param int    $user_id
 * @param string $layout  'profile' (WP form-table rows) or 'view-affiliate' (plugin form rows).
 */
if ( !function_exists( 'wcusage_admin_render_custom_fields' ) ) {
    function wcusage_admin_render_custom_fields(  $user_id, $layout = 'profile'  ) {
        if ( !$user_id || !function_exists( 'wcusage_get_user_custom_fields' ) ) {
            return;
        }
        if ( !wcusage_has_custom_fields() ) {
            return;
        }
        $n = (int) wcusage_get_setting_value( 'wcusage_field_registration_custom_fields', '2' );
        $info = wcusage_get_user_custom_fields( $user_id );
        for ($x = 1; $x <= $n; $x++) {
            $label = html_entity_decode( (string) wcusage_get_setting_value( 'wcusage_field_registration_custom_label_' . $x, '' ) );
            $type = wcusage_get_setting_value( 'wcusage_field_registration_custom_type_' . $x, '' );
            if ( $label === '' || $type === 'header' || $type === 'paragraph' ) {
                continue;
            }
            if ( !$type ) {
                $type = 'text';
            }
            $value = ( isset( $info[$label] ) ? $info[$label] : '' );
            if ( is_array( $value ) ) {
                $value = implode( ', ', $value );
            }
            $id = 'wcu_admin_custom_' . $x;
            $control = wcusage_admin_custom_field_control( $x, $type, $value );
            if ( $layout === 'view-affiliate' ) {
                echo '<div class="wcusage-form-row"><div class="wcusage-form-group">';
                echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
                echo $control;
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe internal output; verified in manual audit.
                echo '</div></div>';
            } else {
                echo '<tr><th><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>' . $control . '</td></tr>';
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe internal output; verified in manual audit.
            }
        }
    }

}
/**
 * Outputs the registration custom fields section on the standard WP edit-user screen.
 *
 * @param WP_User $user
 */
if ( !function_exists( 'wcusage_admin_custom_fields_profile_section' ) ) {
    function wcusage_admin_custom_fields_profile_section(  $user  ) {
        if ( !is_object( $user ) || !isset( $user->ID ) ) {
            return;
        }
        if ( !current_user_can( 'edit_user', $user->ID ) || !wcusage_has_custom_fields() ) {
            return;
        }
        ?>
    <h2><?php 
        echo esc_html__( 'Coupon Affiliates - Registration Details', 'woo-coupon-usage' );
        ?></h2>
    <table class="form-table" role="presentation">
      <tbody>
        <?php 
        wcusage_admin_render_custom_fields( $user->ID, 'profile' );
        ?>
      </tbody>
    </table>
    <?php 
    }

    add_action( 'show_user_profile', 'wcusage_admin_custom_fields_profile_section' );
    add_action( 'edit_user_profile', 'wcusage_admin_custom_fields_profile_section' );
}
/**
 * Saves submitted registration custom fields back into 'wcu_info' (keyed by label).
 * Used by both the WP edit-user screen and the "View Affiliate" Edit User form.
 *
 * @param int $user_id
 */
if ( !function_exists( 'wcusage_admin_save_custom_fields' ) ) {
    function wcusage_admin_save_custom_fields(  $user_id  ) {
        if ( !$user_id || !current_user_can( 'edit_user', $user_id ) ) {
            return;
        }
        if ( !function_exists( 'wcusage_get_user_custom_fields' ) ) {
            return;
        }
        $n = (int) wcusage_get_setting_value( 'wcusage_field_registration_custom_fields', '2' );
        $info = wcusage_get_user_custom_fields( $user_id );
        $changed = false;
        for ($x = 1; $x <= $n; $x++) {
            $post_key = 'wcu_admin_custom_' . $x;
            if ( !isset( $_POST[$post_key] ) ) {
                continue;
            }
            $type = wcusage_get_setting_value( 'wcusage_field_registration_custom_type_' . $x, '' );
            if ( $type === 'header' || $type === 'paragraph' ) {
                continue;
            }
            $label = html_entity_decode( sanitize_text_field( wcusage_get_setting_value( 'wcusage_field_registration_custom_label_' . $x, '' ) ) );
            if ( $label === '' ) {
                continue;
            }
            $info[$label] = sanitize_text_field( wp_unslash( $_POST[$post_key] ) );
            $changed = true;
        }
        if ( $changed ) {
            update_user_meta( $user_id, 'wcu_info', wp_json_encode( $info ) );
        }
    }

    add_action( 'personal_options_update', 'wcusage_admin_save_custom_fields' );
    add_action( 'edit_user_profile_update', 'wcusage_admin_save_custom_fields' );
}