<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ============================================================================
 *  Coupon Affiliates — index migrations
 * ----------------------------------------------------------------------------
 *  Indexes that the plugin needs but does not own the schema for.
 *
 *  The stats queries find a coupon's orders by matching
 *  wp_woocommerce_order_items on order_item_type + order_item_name. WooCommerce
 *  ships that table with only PRIMARY KEY (order_item_id) and KEY (order_id), so
 *  neither filtered column is indexed and MySQL reads the whole table on every
 *  call:
 *
 *      EXPLAIN ... type: ALL   key: NULL            rows: <every row>
 *      with index   type: ref   key: wcusage_...    rows: <this coupon only>
 *
 *  That makes the cost of a dashboard scale with the size of the STORE rather
 *  than with the affiliate's own order count, which is why the dashboard slows
 *  down on large merchants even for affiliates with few orders.
 *
 *  Adding it is deliberately NOT done inline on a page load. On a table with
 *  millions of rows the ALTER takes real time, so it runs from a one-shot
 *  scheduled event, guarded by a flag, and re-checked rather than assumed.
 * ============================================================================
 */

/**
 * Whether a table already has an index of this name.
 *
 * @param string $table Full table name.
 * @param string $index Index name.
 * @return bool
 */
if ( ! function_exists( 'wcusage_table_has_index' ) ) {
    function wcusage_table_has_index( $table, $index ) {
        global $wpdb;

        $found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->prepare(
                "SHOW INDEX FROM `" . esc_sql( $table ) . "` WHERE Key_name = %s",
                $index
            )
        );

        return ! empty( $found );
    }
}

/**
 * Whether a table exists.
 *
 * @param string $table Full table name.
 * @return bool
 */
if ( ! function_exists( 'wcusage_table_exists' ) ) {
    function wcusage_table_exists( $table ) {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }
}

/**
 * Add an index to a table if it is not already there.
 *
 * Returns true when the index exists afterwards, whether this call created it
 * or found it already present. A false return means the ALTER did not take -
 * usually insufficient privileges on managed hosting - and the caller should
 * retry later rather than record the migration as done.
 *
 * @param string $table      Full table name.
 * @param string $index      Index name.
 * @param string $definition Column list, e.g. "order_item_type(20), order_item_name(64)".
 * @return bool
 */
if ( ! function_exists( 'wcusage_add_index_if_missing' ) ) {
    function wcusage_add_index_if_missing( $table, $index, $definition ) {
        global $wpdb;

        if ( ! wcusage_table_exists( $table ) ) {
            return false;
        }

        if ( wcusage_table_has_index( $table, $index ) ) {
            return true;
        }

        // ALGORITHM/LOCK are not specified: MySQL 8 and MariaDB both pick an
        // online plan for a secondary index by default, and naming an algorithm
        // the server cannot use turns a slow-but-working migration into a hard
        // error.
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            "ALTER TABLE `" . esc_sql( $table ) . "` ADD INDEX `" . esc_sql( $index ) . "` ( {$definition} )"
        );

        if ( ! wcusage_table_has_index( $table, $index ) ) {
            error_log( 'CA: could not add index ' . $index . ' to ' . $table . ': ' . $wpdb->last_error );
            return false;
        }

        return true;
    }
}

/**
 * Convert the clicks table's ID columns to integers and index it.
 *
 * couponid and orderid were created as text. Comparing a text column to an
 * integer makes MySQL cast the entire column, so no index on it can be used -
 * which is why "SELECT ... WHERE couponid IN (...) AND date >= ..." was a full
 * table scan plus a filesort even after an index was added.
 *
 * The conversion is only attempted once every existing value is confirmed to be
 * a plain integer (or empty). If a site somehow has other text in there, the
 * columns are left alone and only the prefix indexes are added, so no data is
 * silently turned into 0.
 *
 * @return bool True when the table is in its target shape (or absent).
 */
if ( ! function_exists( 'wcusage_migrate_clicks_table' ) ) {
    function wcusage_migrate_clicks_table() {
        global $wpdb;

        $table = $wpdb->prefix . 'wcusage_clicks';
        if ( ! wcusage_table_exists( $table ) ) {
            return true;
        }

        $ok = true;

        foreach ( array( 'couponid', 'orderid' ) as $column ) {

            $current = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->prepare( "SHOW COLUMNS FROM `" . esc_sql( $table ) . "` LIKE %s", $column )
            );

            if ( ! $current || ! isset( $current->Type ) ) {
                continue;
            }

            // Already numeric - nothing to convert.
            if ( stripos( $current->Type, 'int' ) !== false ) {
                continue;
            }

            $non_numeric = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
                "SELECT COUNT(*) FROM `" . esc_sql( $table ) . "`
                 WHERE `" . esc_sql( $column ) . "` <> '' AND `" . esc_sql( $column ) . "` NOT REGEXP '^[0-9]+$'"
            );

            if ( $non_numeric > 0 ) {
                error_log( 'CA: leaving ' . $table . '.' . $column . ' as text, ' . $non_numeric . ' rows are not numeric.' );
                $ok = false;
                continue;
            }

            // A prefix index left by an earlier run, when the column was still
            // text, blocks the type change - drop it; it is recreated below.
            $prefix_index = ( 'couponid' === $column ) ? 'couponid_date' : 'orderid';
            if ( wcusage_table_has_index( $table, $prefix_index ) ) {
                $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
                    "ALTER TABLE `" . esc_sql( $table ) . "` DROP INDEX `" . esc_sql( $prefix_index ) . "`"
                );
            }

            $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
                "ALTER TABLE `" . esc_sql( $table ) . "`
                 MODIFY COLUMN `" . esc_sql( $column ) . "` bigint unsigned NOT NULL DEFAULT 0"
            );

            // Only believe the conversion once the server agrees; otherwise the
            // "done" flag would be written with the column still text.
            $after = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->prepare( "SHOW COLUMNS FROM `" . esc_sql( $table ) . "` LIKE %s", $column )
            );
            if ( ! $after || ! isset( $after->Type ) || stripos( $after->Type, 'int' ) === false ) {
                error_log( 'CA: could not convert ' . $table . '.' . $column . ' to bigint: ' . $wpdb->last_error );
                $ok = false;
            }
        }

        // Index whether or not the conversion happened. On a table still holding
        // text the prefix length keeps the index legal; it only pays off once the
        // queries compare like with like, which the conversion above ensures.
        $couponid_type = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            "SHOW COLUMNS FROM `" . esc_sql( $table ) . "` LIKE 'couponid'"
        );
        $couponid_is_int = ( $couponid_type && isset( $couponid_type->Type ) && stripos( $couponid_type->Type, 'int' ) !== false );

        $ok = wcusage_add_index_if_missing(
            $table,
            'couponid_date',
            $couponid_is_int ? 'couponid, date' : 'couponid(20), date'
        ) && $ok;

        $ok = wcusage_add_index_if_missing( $table, 'date', 'date' ) && $ok;

        $orderid_type = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            "SHOW COLUMNS FROM `" . esc_sql( $table ) . "` LIKE 'orderid'"
        );
        $orderid_is_int = ( $orderid_type && isset( $orderid_type->Type ) && stripos( $orderid_type->Type, 'int' ) !== false );
        $ok = wcusage_add_index_if_missing( $table, 'orderid', $orderid_is_int ? 'orderid' : 'orderid(20)' ) && $ok;

        // The clicks schema version is stamped here, not by dbDelta() in
        // wcusage_install_clicks_tables(): an existing table only counts as
        // current once this guarded conversion has actually happened.
        global $wcusage_clicks_db_version;
        if ( $ok && ! empty( $wcusage_clicks_db_version ) ) {
            update_option( 'wcusage_clicks_db_version', $wcusage_clicks_db_version );
        }

        return $ok;
    }
}

/**
 * Schedule the index migration once, shortly after load.
 *
 * A scheduled event rather than an inline call so that no visitor waits for the
 * ALTER, and so a site with a very large order_items table cannot have the
 * migration killed halfway by a request timeout on an ordinary page view.
 *
 * @return void
 */
if ( ! function_exists( 'wcusage_maybe_schedule_index_migrations' ) ) {
    function wcusage_maybe_schedule_index_migrations() {

        if ( get_option( 'wcusage_index_migrations_done' ) ) {
            return;
        }

        if ( wp_next_scheduled( 'wcusage_run_index_migrations' ) ) {
            return;
        }

        // Throttle retries so a permanently failing migration (no ALTER
        // privilege, for instance) is not rescheduled on every page load.
        if ( get_transient( 'wcusage_index_migrations_retry' ) ) {
            return;
        }

        wp_schedule_single_event( time() + 30, 'wcusage_run_index_migrations' );
    }
}
add_action( 'plugins_loaded', 'wcusage_maybe_schedule_index_migrations', 20 );

/**
 * Run the index migrations.
 *
 * @return void
 */
if ( ! function_exists( 'wcusage_run_index_migrations' ) ) {
    function wcusage_run_index_migrations() {
        global $wpdb;

        // The ALTER can take a while on a large table. The DDL continues at the
        // server even if PHP is cut off, and the SHOW INDEX check on the next
        // run picks it up, but give it room to finish in one go where the host
        // allows it.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }

        $all_ok = true;

        // Coupon -> orders lookup (the stats queries).
        $all_ok = wcusage_add_index_if_missing(
            $wpdb->prefix . 'woocommerce_order_items',
            'wcusage_coupon_lookup',
            'order_item_type(20), order_item_name(64)'
        ) && $all_ok;

        // The plugin's own tables. These were all created with nothing but a
        // primary key, so every lookup by coupon, user or date was a full scan.
        $all_ok = wcusage_migrate_clicks_table() && $all_ok;

        $activity_table = $wpdb->prefix . 'wcusage_activity';
        if ( wcusage_table_exists( $activity_table ) ) {
            $all_ok = wcusage_add_index_if_missing( $activity_table, 'event_date', 'event(32), date' ) && $all_ok;
            $all_ok = wcusage_add_index_if_missing( $activity_table, 'user_id', 'user_id' ) && $all_ok;
            $all_ok = wcusage_add_index_if_missing( $activity_table, 'event_id', 'event_id' ) && $all_ok;
            $all_ok = wcusage_add_index_if_missing( $activity_table, 'date', 'date' ) && $all_ok;
        }

        if ( $all_ok ) {
            update_option( 'wcusage_index_migrations_done', '1', false );
            delete_transient( 'wcusage_index_migrations_retry' );
        } else {
            // Retry on a later load rather than recording it as done.
            set_transient( 'wcusage_index_migrations_retry', 1, DAY_IN_SECONDS );
        }
    }
}
add_action( 'wcusage_run_index_migrations', 'wcusage_run_index_migrations' );
