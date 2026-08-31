<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tool: Restore Registration Field Answers.
 *
 * Copies custom registration field answers from the applications table onto the
 * affiliates' profiles. Needed only for applications submitted by someone who was
 * already logged in, which before version 8.2.0 were stored on the application and
 * nowhere else.
 *
 * Everything for this one-off recovery is kept together in this file - the
 * detection, the copy itself and the screen that drives it - so it can be removed
 * in one piece once it has outlived its usefulness. The copy relies on
 * wcusage_sync_custom_fields_to_user(), which lives in inc/registration/
 * functions-registration.php because the live registration path uses it too.
 *
 * This file is only loaded in an admin context, so every caller outside it guards
 * with function_exists().
 */

/**
 * One-off backfill of registration custom fields onto affiliate profiles.
 *
 * Until this release the values were only written to 'wcu_info' when the
 * registration form created the account, so an applicant who was already logged
 * in has their answers on the application row and nowhere else. The data was
 * never lost, so it can be copied across from wp_wcusage_register.
 *
 * This is deliberately not automatic. An application keeps the field label as it
 * was worded when it was submitted, so on a site that has since renamed or
 * reused a custom field the copy would put labels on profiles that no longer
 * match any configured field - and the "Other Information" panels list every
 * stored label, not only the configured ones. It is offered as a tool under
 * Admin Tools instead, and only on the sites that have something to copy.
 */

/**
 * How many affiliates have application answers that never reached their profile.
 *
 * Counts applicants holding custom-field answers whose profile has no custom
 * fields stored at all - the case this release fixes. An affiliate missing only
 * some of their fields is not counted, as that cannot be told apart from a
 * partly filled application without decoding every row.
 *
 * @param bool $refresh Recount rather than using the cached figure.
 * @return int
 */
if ( ! function_exists( 'wcusage_custom_fields_backfill_pending_count' ) ) {
  function wcusage_custom_fields_backfill_pending_count( $refresh = false ) {

    global $wpdb;

    $cache_key = 'wcusage_custom_fields_backfill_count';

    if ( ! $refresh ) {
      $cached = get_transient( $cache_key );
      if ( $cached !== false ) {
        return (int) $cached;
      }
    }

    $table_name = $wpdb->prefix . 'wcusage_register';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '" . $table_name . "'" ) !== $table_name ) { // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
      set_transient( $cache_key, 0, DAY_IN_SECONDS );
      return 0;
    }

    $count = (int) $wpdb->get_var( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
      "SELECT COUNT(DISTINCT r.userid)
       FROM $table_name r
       LEFT JOIN {$wpdb->usermeta} um ON um.user_id = r.userid AND um.meta_key = 'wcu_info'
       WHERE r.userid > 0
         AND r.info != '' AND r.info != '[]' AND r.info != '{}'
         AND ( um.umeta_id IS NULL OR um.meta_value = '' OR um.meta_value = '[]' OR um.meta_value = '{}' )"
    );

    set_transient( $cache_key, $count, DAY_IN_SECONDS );

    return $count;

  }
}

/**
 * Whether the backfill tool should be offered at all.
 *
 * @return bool
 */
if ( ! function_exists( 'wcusage_custom_fields_backfill_needed' ) ) {
  function wcusage_custom_fields_backfill_needed() {

    // Autoloaded, so the usual answer costs nothing.
    if ( get_option( 'wcusage_custom_fields_backfill_done' ) ) {
      return false;
    }

    if ( wcusage_custom_fields_backfill_pending_count() > 0 ) {
      return true;
    }

    // Nothing to copy. Only code from before 8.2.0 could leave answers stranded,
    // so this cannot become true again - settle it now rather than repeating the
    // count for the life of the install.
    wcusage_finish_custom_fields_backfill();

    return false;

  }
}

/**
 * Mark the backfill complete and clear up after it.
 *
 * The flag is autoloaded because it is read whenever the Admin Tools page is
 * built; the cursor is not, since only a part-finished run reads it.
 *
 * @return void
 */
if ( ! function_exists( 'wcusage_finish_custom_fields_backfill' ) ) {
  function wcusage_finish_custom_fields_backfill() {
    update_option( 'wcusage_custom_fields_backfill_done', '1' );
    delete_option( 'wcusage_custom_fields_backfill_cursor' );
    delete_transient( 'wcusage_custom_fields_backfill_count' );
  }
}

/**
 * Copy application custom fields onto the affiliates' profiles.
 *
 * Works newest application first, so where an affiliate applied more than once
 * the most recent answer is the one that lands on the profile. Stops when the
 * time budget runs out and remembers where it got to, so a very large table can
 * be finished by running the tool again rather than timing out mid-way.
 *
 * @param int $time_budget Seconds to keep working for.
 * @return array {
 *     @type int  $updated   Affiliates whose profile was written to.
 *     @type int  $processed Application rows examined.
 *     @type bool $complete  Whether the whole table has now been covered.
 * }
 */
if ( ! function_exists( 'wcusage_run_custom_fields_backfill' ) ) {
  function wcusage_run_custom_fields_backfill( $time_budget = 20 ) {

    global $wpdb;

    $result = array( 'updated' => 0, 'processed' => 0, 'complete' => false );

    $table_name = $wpdb->prefix . 'wcusage_register';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '" . $table_name . "'" ) !== $table_name ) { // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
      wcusage_finish_custom_fields_backfill();
      $result['complete'] = true;
      return $result;
    }

    $batch_size = 200;
    $started    = time();

    // Descending, with the cursor holding the lowest id handled so far. Combined
    // with the "do not overwrite" flag below this makes the newest application
    // for a given affiliate the one that wins.
    $cursor = (int) get_option( 'wcusage_custom_fields_backfill_cursor', 0 );
    if ( $cursor <= 0 ) {
      $cursor = (int) $wpdb->get_var( "SELECT MAX(id) FROM $table_name" ) + 1; // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    do {

      $rows = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT id, userid, info FROM $table_name WHERE id < %d AND userid > 0 AND info != '' ORDER BY id DESC LIMIT %d", // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
          $cursor,
          $batch_size
        ),
        ARRAY_A
      ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter

      if ( ! $rows ) {
        wcusage_finish_custom_fields_backfill();
        $result['complete'] = true;
        return $result;
      }

      foreach ( $rows as $row ) {
        // Never replace a value already on the profile: the affiliate may have
        // corrected it from their dashboard since applying.
        if ( wcusage_sync_custom_fields_to_user( $row['userid'], $row['info'], false ) ) {
          $result['updated']++;
        }
        $result['processed']++;
        $cursor = (int) $row['id'];
      }

      update_option( 'wcusage_custom_fields_backfill_cursor', $cursor, false );

      if ( count( $rows ) < $batch_size ) {
        wcusage_finish_custom_fields_backfill();
        $result['complete'] = true;
        return $result;
      }

    } while ( ( time() - $started ) < $time_budget );

    // Out of time with rows still to go. The cursor is stored, so running the
    // tool again picks up from here.
    delete_transient( 'wcusage_custom_fields_backfill_count' );

    return $result;

  }
}

/**
 * Renders the tool page.
 *
 * @return void
 */
function wcusage_restore_registration_fields_page() {

    if ( ! wcusage_check_admin_access() ) {
        wp_die( esc_html__( 'Error: Permission denied.', 'woo-coupon-usage' ) );
    }

    $result = null;

    if ( isset( $_POST['wcusage_restore_registration_fields'] ) ) {

        check_admin_referer( 'wcusage_restore_registration_fields' );

        $result = wcusage_run_custom_fields_backfill();

    }

    // Recount after a run, so the figure shown is the one that is left.
    $pending = wcusage_custom_fields_backfill_pending_count( true );
    $done    = (bool) get_option( 'wcusage_custom_fields_backfill_done' );

    ?>

    <div class="wrap wcusage-admin-page">
        <?php do_action( 'wcusage_hook_dashboard_page_header', '' ); ?>
    </div>

    <div class="wrap wcusage-tools">

        <h2><?php echo esc_html__( 'Restore Registration Field Answers', 'woo-coupon-usage' ); ?></h2>

        <?php if ( $result !== null ) : ?>
            <div class="notice notice-success">
                <p>
                    <?php
                    printf(
                        /* translators: 1: number of affiliates updated, 2: number of applications checked. */
                        esc_html__( 'Finished. %1$s affiliate profiles were updated, from %2$s applications checked.', 'woo-coupon-usage' ),
                        '<strong>' . esc_html( number_format_i18n( $result['updated'] ) ) . '</strong>',
                        esc_html( number_format_i18n( $result['processed'] ) )
                    );
                    ?>
                </p>
                <?php if ( ! $result['complete'] ) : ?>
                    <p><?php echo esc_html__( 'There are still applications left to check. Run the tool again to carry on from where it stopped.', 'woo-coupon-usage' ); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <p>
            <?php
            echo esc_html__( 'Custom registration fields are shown on an affiliate\'s "Account Details" and on their user profile in the admin. Before version 8.2.0 those answers were only saved to the profile when the application created a brand new account, so anyone who applied while already logged in had their answers saved to the application only.', 'woo-coupon-usage' );
            ?>
        </p>
        <p>
            <?php echo esc_html__( 'Nothing was lost. This tool copies those answers from the applications onto the matching affiliate profiles. New applications no longer need it.', 'woo-coupon-usage' ); ?>
        </p>

        <?php if ( $done || $pending < 1 ) : ?>

            <div class="notice notice-info inline" style="margin: 20px 0;">
                <p><?php echo esc_html__( 'There is nothing left to copy. Every affiliate with answers on an application already has them on their profile.', 'woo-coupon-usage' ); ?></p>
            </div>

            <p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcusage_tools' ) ); ?>" class="button">
                    <?php echo esc_html__( 'Back to Admin Tools', 'woo-coupon-usage' ); ?>
                </a>
            </p>

        <?php else : ?>

            <p>
                <strong>
                    <?php
                    printf(
                        /* translators: %s: number of affiliates. */
                        esc_html( _n( '%s affiliate has answers that are not on their profile.', '%s affiliates have answers that are not on their profile.', $pending, 'woo-coupon-usage' ) ),
                        esc_html( number_format_i18n( $pending ) )
                    );
                    ?>
                </strong>
            </p>

            <p>
                <?php echo esc_html__( 'An answer already on a profile is never replaced, so anything you or the affiliate has since corrected is kept. Where somebody applied more than once, their most recent answer is used.', 'woo-coupon-usage' ); ?>
            </p>
            <p>
                <?php echo esc_html__( 'Applications store the field label as it was worded at the time. If you have renamed or reused a custom field since, older answers will be copied across under their original label.', 'woo-coupon-usage' ); ?>
            </p>

            <form method="POST">
                <?php wp_nonce_field( 'wcusage_restore_registration_fields' ); ?>
                <p>
                    <button type="submit" name="wcusage_restore_registration_fields" value="1" class="button button-primary">
                        <?php echo esc_html__( 'Copy Answers To Profiles', 'woo-coupon-usage' ); ?>
                    </button>
                </p>
            </form>

        <?php endif; ?>

    </div>

    <?php

}
