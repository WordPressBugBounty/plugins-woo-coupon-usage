<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
global $wcusage_clicks_db_version;
$wcusage_clicks_db_version = "5";
/**
 * CREATE THE TABLES
 *
 */
if ( !function_exists( 'wcusage_install_clicks_tables' ) ) {
    function wcusage_install_clicks_tables() {
        global $wpdb;
        global $wcusage_clicks_db_version;
        $installed_ver = get_option( "wcusage_clicks_db_version" );
        $table_name = $wpdb->prefix . 'wcusage_clicks';
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) === $table_name;
        // Version 5 turned couponid / orderid from text into bigint and added
        // indexes. On an EXISTING table that change is made by
        // wcusage_migrate_clicks_table() (functions-db-indexes.php): it runs from
        // a scheduled event, checks every value is numeric first, and stamps this
        // version only once it has succeeded. It is deliberately NOT left to
        // dbDelta() here - dbDelta does issue the CHANGE COLUMN (it only refuses
        // to narrow within the text/blob families), and that is a full rebuild
        // of the plugin's largest table inside whichever visitor's request came
        // first after the update.
        if ( $table_exists ) {
            return;
        }
        if ( !$installed_ver || $installed_ver != $wcusage_clicks_db_version ) {
            // Fresh install: create the table in its final shape.
            //
            // couponid / orderid are IDs, declared as bigint rather than text.
            // As text they could not be compared to an integer without MySQL
            // casting the whole column, which makes any index on them unusable.
            // This is the fastest-growing table the plugin owns (one row per
            // referral link visit).
            $sql = "CREATE TABLE {$table_name} (\r\n\t\t\tid bigint NOT NULL AUTO_INCREMENT,\r\n\t\t\tcouponid bigint unsigned NOT NULL DEFAULT 0,\r\n\t\t\tcampaign tinytext NOT NULL,\r\n\t\t\tpage tinytext NOT NULL,\r\n\t\t\treferrer tinytext NOT NULL,\r\n\t\t\tipaddress tinytext NOT NULL,\r\n\t\t\torderid bigint unsigned NOT NULL DEFAULT 0,\r\n\t\t\tconverted boolean DEFAULT false,\r\n\t\t\tdate datetime NOT NULL DEFAULT '0000-00-00 00:00:00',\r\n\t\t\tPRIMARY KEY  (id),\r\n\t\t\tKEY couponid_date (couponid,date),\r\n\t\t\tKEY date (date),\r\n\t\t\tKEY orderid (orderid)\r\n\t\t\t);";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta( $sql );
            update_option( "wcusage_clicks_db_version", $wcusage_clicks_db_version );
        }
    }

}
/**
 * CHECK IF TABLE IS UP TO DATE
 *
 */
if ( !function_exists( 'wcusage_update_clicks_db_check' ) ) {
    function wcusage_update_clicks_db_check() {
        global $wcusage_clicks_db_version;
        if ( get_option( 'wcusage_clicks_db_version' ) != $wcusage_clicks_db_version ) {
            wcusage_install_clicks_tables();
        }
    }

}
add_action( 'plugins_loaded', 'wcusage_update_clicks_db_check' );
/**
 * ADD NEW REFERRAL URL CLICK TO TABLE AND RETURN ID OF CLICK
 *
 * @param int $coupon_id
 * @param string $campaign
 * @param int $page
 * @param string $refpage
 * @param bool $converted
 * @param int $ipaddress
 *
 * @return int
 *
 */
if ( !function_exists( 'wcusage_install_clicks_data' ) ) {
    function wcusage_install_clicks_data(
        $coupon_id,
        $campaign,
        $page,
        $refpage,
        $converted,
        $ipaddress
    ) {
        global $wpdb;
        // Check the table exists, if not, create it
        wcusage_install_clicks_tables();
        // Sanitize each value according to its expected type.
        $coupon_id = absint( $coupon_id );
        $campaign = sanitize_text_field( wp_unslash( (string) $campaign ) );
        $page = absint( $page );
        $refpage = sanitize_text_field( wp_unslash( (string) $refpage ) );
        $converted = (int) (bool) $converted;
        // IP/ID: accept a valid IP address or an alphanumeric random ID (cookie-based tracking).
        $ipaddress = sanitize_text_field( wp_unslash( (string) $ipaddress ) );
        if ( filter_var( $ipaddress, FILTER_VALIDATE_IP ) === false ) {
            // Not a valid IP — only allow the alphanumeric random-ID format used by cookie tracking.
            if ( !preg_match( '/^[a-zA-Z0-9_\\-]{1,64}$/', $ipaddress ) ) {
                $ipaddress = '';
            }
        }
        // A coupon ID is required; an empty ipaddress is allowed (tracking may be disabled).
        if ( empty( $coupon_id ) ) {
            return false;
        }
        $table_name = $wpdb->prefix . 'wcusage_clicks';
        $insert_data = [
            'couponid'  => $coupon_id,
            'campaign'  => $campaign,
            'page'      => $page,
            'referrer'  => $refpage,
            'converted' => $converted,
            'ipaddress' => $ipaddress,
            'orderid'   => '',
            'date'      => current_time( 'mysql' ),
        ];
        $result = $wpdb->insert( $table_name, $insert_data );
        if ( $result === false ) {
            // Handle the error as needed.
            return false;
        }
        return $wpdb->insert_id;
    }

}
/**
 * RECORD A REFERRAL CLICK FOR A COUPON
 *
 * The public way to record a click from outside the plugin - a headless or
 * decoupled front end that handles the referral link itself, for example, and
 * needs the click to reach the affiliate's URL statistics and conversion figures
 * anyway. It takes a coupon code, fills in the details the plugin would normally
 * read from the request, and returns the click ID.
 *
 * Unlike the plugin's own tracking this does no throttling, so a caller that can
 * be hit more than once for the same visit (bots, prefetching, a page refresh)
 * should decide for itself whether a visit is a new click before calling.
 *
 * Keep hold of the returned click ID: it is what marks the click as converted
 * when the visitor orders. See the "wcusage_conversion_click_id" filter.
 *
 * @param string|int $coupon Coupon code, or coupon ID.
 * @param array      $args {
 *     Optional. Details of the click.
 *
 *     @type string     $campaign  Campaign / "src" value. Default empty.
 *     @type int        $page      Post ID of the landing page, for the click
 *                                 history table. 0 shows as the homepage.
 *                                 Default 0.
 *     @type string     $referrer  Where the visitor came from. A full URL or a
 *                                 domain; only the domain is stored, without any
 *                                 "www." prefix. Default empty.
 *     @type string|null $ip       Visitor IP, or an ID of up to 64 letters,
 *                                 numbers, "-" or "_" for a site not storing IPs.
 *                                 Null detects it from the current request the
 *                                 way the plugin's own tracking does, which is
 *                                 only meaningful when the visitor's browser is
 *                                 what made the request. Default null.
 *     @type bool       $converted Whether the click already led to an order.
 *                                 Default false.
 * }
 *
 * @return int|false Click ID, or false if the coupon was not found, the referrer
 *                   is one of the site's blocked domains, or the click could not
 *                   be saved.
 *
 */
if ( !function_exists( 'wcusage_record_click' ) ) {
    function wcusage_record_click(  $coupon, $args = array()  ) {
        $coupon_object = wcusage_get_coupon_object_safe( $coupon );
        if ( !$coupon_object ) {
            return false;
        }
        $coupon_id = $coupon_object->get_id();
        if ( !$coupon_id ) {
            return false;
        }
        $args = wp_parse_args( $args, array(
            'campaign'  => '',
            'page'      => 0,
            'referrer'  => '',
            'ip'        => null,
            'converted' => false,
        ) );
        // Referrers are stored as a bare domain, so accept a full URL and reduce it
        // to one, matching what the plugin records for its own clicks.
        $referrer = trim( (string) $args['referrer'] );
        if ( $referrer ) {
            if ( strpos( $referrer, '//' ) !== false ) {
                // A full URL. wp_parse_url() gives back null for one it cannot read.
                $referrer = (string) wp_parse_url( $referrer, PHP_URL_HOST );
            }
            // Otherwise a domain, possibly with a path or query string after it.
            $referrer = (string) strtok( $referrer, '/' );
            $referrer = (string) strtok( $referrer, '?' );
            $referrer = preg_replace( '/^www\\./i', '', $referrer );
        }
        // The site's blocked referrer domains apply here too - the plugin's own
        // tracking drops these clicks rather than recording them, and a click
        // arriving through this function is no more trustworthy.
        if ( $referrer && function_exists( 'wcusage_is_domain_blacklisted' ) && wcusage_is_domain_blacklisted( $referrer ) ) {
            return false;
        }
        // Null means "work it out from this request", which is what the plugin does
        // when the visitor's own browser is the one being tracked.
        $ipaddress = $args['ip'];
        if ( $ipaddress === null ) {
            $ipaddress = wcusage_get_visitor_ip();
        }
        // Put the tidied values back, so anything listening below is told what was
        // actually saved rather than what was passed in.
        $args['referrer'] = $referrer;
        $args['ip'] = $ipaddress;
        $click_id = wcusage_install_clicks_data(
            $coupon_id,
            $args['campaign'],
            $args['page'],
            $args['referrer'],
            $args['converted'],
            $args['ip']
        );
        if ( $click_id ) {
            /**
             * Fires after a referral click has been recorded.
             *
             * @param int    $click_id  ID of the click that was saved.
             * @param int    $coupon_id Coupon the click was recorded against.
             * @param array  $args      Details the click was recorded with.
             */
            do_action(
                'wcusage_click_recorded',
                $click_id,
                $coupon_id,
                $args
            );
        }
        return $click_id;
    }

}
/**
 * HOOK TO DISPLAY CLICKS FOR COUPON & CAMPAIGN ON AFFILIATE DASHBOARD
 *
 * @param int $postid
 * @param string $campaign
 *
 * @return mixed
 *
 */
if ( !function_exists( 'wcusage_display_coupon_url_clicks' ) ) {
    function wcusage_display_coupon_url_clicks(
        $postid,
        $campaign,
        $page = 0,
        $converted = 0
    ) {
        $wcusage_field_show_click_history = wcusage_get_setting_value( 'wcusage_field_show_click_history', 1 );
        $wcusage_field_show_click_history_amount = wcusage_get_setting_value( 'wcusage_field_show_click_history_amount', 15 );
        $wcusage_field_show_click_history_amount = absint( $wcusage_field_show_click_history_amount );
        $wcusage_field_show_campaigns = wcusage_get_setting_value( 'wcusage_field_show_campaigns', 1 );
        $wcusage_field_load_ajax = wcusage_get_setting_value( 'wcusage_field_load_ajax', 1 );
        $show_converted = wcusage_get_setting_value( 'wcusage_field_show_click_history_converted', 1 );
        $show_converted_col = 1;
        $wcusage_store_cookies = wcusage_get_setting_value( 'wcusage_field_store_cookies', '1' );
        if ( !$wcusage_store_cookies ) {
            $show_converted = 0;
            $show_converted_col = 0;
        }
        // Cast here too: this is a public action, so the page number is only as
        // trustworthy as whoever fired the hook.
        $page = absint( $page );
        $offset = $page * $wcusage_field_show_click_history_amount;
        if ( $campaign && $campaign != "all" ) {
            $campaignline = " AND campaign = %s";
        } else {
            $campaignline = "";
        }
        $convertedline = "";
        if ( $converted ) {
            $convertedline = " AND converted = '1'";
        }
        $postid = absint( $postid );
        global $wpdb;
        $table_name = $wpdb->prefix . 'wcusage_clicks';
        $query = "SELECT * FROM {$table_name} WHERE couponid = %d {$campaignline} {$convertedline} ORDER BY id DESC LIMIT %d OFFSET %d";
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
        $prepare_args = array($postid);
        if ( $campaign && $campaign != "all" ) {
            $prepare_args[] = $campaign;
        }
        // matches the %s placeholder in $campaignline
        $prepare_args[] = $wcusage_field_show_click_history_amount;
        $prepare_args[] = $offset;
        $query = $wpdb->prepare( $query, $prepare_args );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
        $result2 = $wpdb->get_results( $query );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
        $totalresults = count( $result2 );
        ?>

		<!-- Table Mobile Labels -->
		<style>
		@media only screen and (max-width: 760px) {
			.wcu-table-clicks td:nth-of-type(1):before { content: "ID"; }
			.wcu-table-clicks td:nth-of-type(2):before { content: "Landing Page"; }
			.wcu-table-clicks td:nth-of-type(3):before { content: "Referring URL"; }
			.wcu-table-clicks td:nth-of-type(4):before { content: "Converted"; }
			<?php 
        ?>
			.wcu-table-clicks td:nth-of-type(6):before { content: "Date"; }
		}
		</style>

	  <div style="clear: both;"></div>

    <!-- Heading -->
	<p class="wcu-tab-title wcusage-subheader wcusage-title-referral-clicks" style='font-size: 22px; float: left; margin-top: 20px; margin-bottom: 10px;' id='wcu-recent-clicks-section'><?php 
        echo esc_html__( 'Recent Clicks', 'woo-coupon-usage' );
        ?>:</p>

    <!-- Converted only toggle -->
    <?php 
        if ( $wcusage_field_load_ajax && $show_converted ) {
            ?>
    <p style="float: right; font-size: 17px; margin-top: 25px; margin-bottom: 10px;">
      <label for="wcu-checkbox-clicks-converted" style="vertical-align: baseline;">
        <input type="checkbox" id="wcu-checkbox-clicks-converted" name="wcu-checkbox-clicks-converted" value="1" style=""> <?php 
            echo esc_html__( 'Converted Only', 'woo-coupon-usage' );
            ?>
      </label>
    </p>
    <?php 
        }
        ?>

    <div style="clear: both;"></div>

    <!-- Show the clicks table -->
    <?php 
        if ( $totalresults > 0 ) {
            echo "<table class='wcuTable wcu-table-clicks'>";
            echo "<tr class='wcu-thetitlerow'>";
            echo "<td class='wcuTableHead'>#</td>";
            if ( $show_converted_col ) {
                echo "<td class='wcuTableHead'><i class='fas fa-cart-plus' title='" . esc_attr( ucfirst( esc_html__( 'Converted?', 'woo-coupon-usage' ) ) ) . "'></i></td>";
            }
            echo "<td class='wcuTableHead' style='max-width: 300px;'>" . esc_html( ucfirst( esc_html__( 'Landing Page', 'woo-coupon-usage' ) ) ) . "</td>";
            echo "<td class='wcuTableHead' style='max-width: 350px;'>" . esc_html( ucfirst( esc_html__( 'Referring URL', 'woo-coupon-usage' ) ) ) . "</td>";
            echo "<td class='wcuTableHead'>" . ucfirst( esc_html__( 'Date', 'woo-coupon-usage' ) ) . "</td>";
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe internal output; verified in manual audit.
            echo "</tr>";
            foreach ( $result2 as $result ) {
                echo "<tr class='wcuTableRow'>";
                echo "<td class='wcuTableCell' style='max-width: 100%;'>" . esc_html( $result->id ) . "</td>";
                if ( $result->converted == 1 ) {
                    $convertedicon = '<i class="fas fa-check" style="color: green;" title="' . esc_html__( "Converted", "woo-coupon-usage" ) . '"></i>';
                } else {
                    $convertedicon = '<i class="fas fa-times" title="' . esc_html__( "Not Converted", "woo-coupon-usage" ) . '"></i>';
                }
                echo "<td class='wcuTableCell' style='max-width: 100%;'>" . wp_kses_post( $convertedicon ) . "</td>";
                // Display landing page with proper handling for homepage/empty values
                if ( $result->page && $result->page != '0' && $result->page != 0 ) {
                    // Regular page/post
                    $page_title = get_the_title( $result->page );
                    $page_url = get_permalink( $result->page );
                    if ( $page_title && $page_url ) {
                        echo "<td class='wcuTableCell wcuTableCell-ref-landing'><a href='" . esc_url( $page_url ) . "'>" . esc_html( $page_title ) . "</a></td>";
                    } else {
                        echo "<td class='wcuTableCell wcuTableCell-ref-landing'>-</td>";
                    }
                } elseif ( $result->page === '0' || $result->page === 0 || $result->page == '0' ) {
                    // Homepage (blog index)
                    echo "<td class='wcuTableCell wcuTableCell-ref-landing'><a href='" . esc_url( home_url( '/' ) ) . "'>" . esc_html__( 'Homepage', 'woo-coupon-usage' ) . "</a></td>";
                } else {
                    // Empty/not set
                    echo "<td class='wcuTableCell wcuTableCell-ref-landing'>-</td>";
                }
                if ( $result->referrer ) {
                    $referrerurl = "<span style='word-wrap: break-word !important;'>" . esc_html( $result->referrer ) . "</span>";
                } else {
                    $referrerurl = 'Direct Traffic';
                }
                echo "<td class='wcuTableCell wcuTableCell-ref-website'>" . wp_kses_post( $referrerurl ) . "</td>";
                $thedatetime = strtotime( $result->date );
                echo "<td class='wcuTableCell' style='max-width: 100%;'>" . ucfirst( date_i18n( get_option( 'date_format' ) . " " . "(" . get_option( 'time_format' ) . ")", $thedatetime ) ) . "</td>";
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe internal output; verified in manual audit.
                echo "</tr>";
            }
            echo "</table>";
        } else {
            if ( $page == 0 ) {
                ?>
				<p><?php 
                echo esc_html__( 'There have been no clicks for this campaign yet.', 'woo-coupon-usage' );
                ?></p>
				<script>
				jQuery(document).ready(function(){
					jQuery( ".wcu-clicks-pagination" ).css({
						"display": "none !important"
					});
				});
				</script>
				<?php 
            } else {
                ?>
				<p><?php 
                echo esc_html__( 'No clicks available.', 'woo-coupon-usage' );
                ?></p>
				<?php 
            }
        }
    }

}
add_action(
    'wcusage_hook_display_coupon_url_clicks',
    'wcusage_display_coupon_url_clicks',
    10,
    4
);