<?php
/**
 * Coupon Affiliates REST API v2 - API key storage.
 *
 * Keys are bearer tokens in the form "wcus_<40 hex chars>". Only a SHA-256
 * hash of the token is stored; the plaintext token is shown exactly once at
 * creation time. Each key maps to a WP user (capability context) and carries
 * a set of scopes that further restricts what the key may do.
 *
 * @package WooCouponUsage\API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wcusage_api_keys_db_version;

/*
 * Bumped to 2 so the installer re-runs: it picks up the explicit column
 * widths and converts any table a pre-release build created in the server's
 * default character set (see the installer for why dbDelta cannot do that
 * part on its own).
 */
$wcusage_api_keys_db_version = '2';

if ( ! function_exists( 'wcusage_api_install_keys_table' ) ) {
	/**
	 * Create / upgrade the API keys table.
	 */
	function wcusage_api_install_keys_table() {

		global $wpdb;
		global $wcusage_api_keys_db_version;

		$installed_ver = get_option( 'wcusage_api_keys_db_version' );

		if ( $installed_ver !== $wcusage_api_keys_db_version ) {

			$table_name = $wpdb->prefix . 'wcusage_api_keys';

			// Without this the table is created in whatever character set the
			// database server happens to default to. "description" is free text
			// an administrator types, so on a server still defaulting to latin1
			// a label containing an emoji or a non-Latin script is truncated at
			// the first bad byte or rejected outright.
			$charset_collate = $wpdb->get_charset_collate();

			// Display widths are given explicitly: dbDelta compares the
			// definition it is handed against the one the server reports, and
			// without them it can decide the column differs on every run and
			// keep issuing the same ALTER.
			$sql = "CREATE TABLE $table_name (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				user_id bigint(20) NOT NULL,
				description varchar(200) NOT NULL DEFAULT '',
				key_prefix varchar(20) NOT NULL DEFAULT '',
				key_hash char(64) NOT NULL DEFAULT '',
				scopes varchar(200) NOT NULL DEFAULT 'read',
				status varchar(20) NOT NULL DEFAULT 'active',
				last_used datetime NULL,
				date_created datetime NULL,
				date_expires datetime NULL,
				PRIMARY KEY  (id),
				KEY key_hash (key_hash),
				KEY user_id (user_id)
			) $charset_collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );

			wcusage_api_maybe_upgrade_keys_table_charset( $table_name );

			update_option( 'wcusage_api_keys_db_version', $wcusage_api_keys_db_version );

		}
	}
}

if ( ! function_exists( 'wcusage_api_maybe_upgrade_keys_table_charset' ) ) {
	/**
	 * Widen the keys table to utf8mb4 when it was created in an older set.
	 *
	 * WordPress's dbDelta() never changes the character set of a table that
	 * already exists, and a column it alters simply inherits the table
	 * default - so a table created before the CREATE statement above carried
	 * a charset keeps whatever the server defaults to. That is not cosmetic:
	 * on utf8mb3, saving a key whose description holds a 4-byte character (an
	 * emoji in the label) fails the INSERT outright rather than storing a
	 * trimmed version.
	 *
	 * Core's maybe_convert_table_to_utf8mb4() cannot do this. It reads the
	 * collation name and proceeds only for "utf8" or "utf8mb4", but MySQL
	 * 8.0.30 and later report the three-byte set as "utf8mb3_*" - so it
	 * returns false on precisely the tables that need converting.
	 *
	 * @param string $table Full table name including prefix.
	 *
	 * @return bool True when the table is utf8mb4 afterwards.
	 */
	function wcusage_api_maybe_upgrade_keys_table_charset( $table ) {

		global $wpdb;

		if ( ! $wpdb->has_cap( 'utf8mb4' ) ) {
			return false;
		}

		$charset = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT ccsa.character_set_name
				FROM information_schema.TABLES t
				JOIN information_schema.COLLATION_CHARACTER_SET_APPLICABILITY ccsa
					ON ccsa.collation_name = t.table_collation
				WHERE t.table_schema = DATABASE() AND t.table_name = %s',
				$table
			)
		);

		if ( ! $charset ) {
			return false;
		}

		$charset = strtolower( $charset );

		if ( 'utf8mb4' === $charset ) {
			return true;
		}

		// Only widen from sets utf8mb4 is a superset of. Anything else was a
		// deliberate choice on the server's part and converting it could lose
		// characters. Nothing non-ASCII can have been stored in these columns
		// anyway - the connection is utf8mb4, so wpdb would already have
		// refused the write - which makes the conversion lossless here.
		if ( ! in_array( $charset, array( 'utf8', 'utf8mb3', 'latin1', 'ascii' ), true ) ) {
			return false;
		}

		$collate = ( ! empty( $wpdb->collate ) && 0 === stripos( $wpdb->collate, 'utf8mb4' ) )
			? $wpdb->collate
			: 'utf8mb4_unicode_ci';

		// Identifiers and collation names cannot be bound as values. Both are
		// built from the table prefix and the site's own database config, never
		// from a request, and the collation is reduced to word characters.
		$collate = preg_replace( '/[^A-Za-z0-9_]/', '', $collate );

		$wpdb->query( "ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE $collate" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return true;
	}
}

if ( ! function_exists( 'wcusage_api_keys_db_check' ) ) {
	/**
	 * Install the keys table when the version option is stale.
	 */
	function wcusage_api_keys_db_check() {
		global $wcusage_api_keys_db_version;
		// get_option(), to match the update_option() the installer writes with.
		// get_site_option() reads the network option on multisite, which this
		// never writes, so the versions could never match and the installer ran
		// on every request.
		if ( get_option( 'wcusage_api_keys_db_version' ) !== $wcusage_api_keys_db_version ) {
			wcusage_api_install_keys_table();
		}
	}
	add_action( 'plugins_loaded', 'wcusage_api_keys_db_check' );
}

if ( ! function_exists( 'wcusage_api_allowed_scopes' ) ) {
	/**
	 * The scopes an API key may hold.
	 *
	 * @return array scope => description.
	 */
	function wcusage_api_allowed_scopes() {
		return array(
			'read'   => __( 'Read data (stats, referrals, payouts, registrations, events).', 'woo-coupon-usage' ),
			'write'  => __( 'Create and update data (payout requests, registration approval).', 'woo-coupon-usage' ),
			// "manage" is not a peer of the other two: a key holding it can
			// issue itself another key with any scope, and can point a webhook
			// at a server of its choosing. Treat it as equivalent to full
			// access for the user the key acts as, and give it only to
			// integrations that genuinely administer the API.
			'manage' => __( 'Manage the API itself - API keys and webhooks. Equivalent to full access: a key with this scope can issue further keys.', 'woo-coupon-usage' ),
		);
	}
}

if ( ! function_exists( 'wcusage_api_sanitize_scopes' ) ) {
	/**
	 * Sanitize a scopes list against the allowed set.
	 *
	 * @param array|string $scopes Scopes as array or comma-separated string.
	 *
	 * @return array Valid scopes, defaults to array( 'read' ).
	 */
	function wcusage_api_sanitize_scopes( $scopes ) {
		if ( is_string( $scopes ) ) {
			$scopes = explode( ',', $scopes );
		}
		if ( ! is_array( $scopes ) ) {
			$scopes = array();
		}

		$allowed = array_keys( wcusage_api_allowed_scopes() );
		$scopes  = array_values( array_intersect( array_map( 'sanitize_key', $scopes ), $allowed ) );

		if ( empty( $scopes ) ) {
			$scopes = array( 'read' );
		}

		return $scopes;
	}
}

if ( ! function_exists( 'wcusage_api_hash_token' ) ) {
	/**
	 * Hash an API token for storage/lookup.
	 *
	 * @param string $token Plaintext token.
	 *
	 * @return string SHA-256 hex hash.
	 */
	function wcusage_api_hash_token( $token ) {
		return hash( 'sha256', $token );
	}
}

if ( ! function_exists( 'wcusage_api_can_create_key_for_user' ) ) {
	/**
	 * Whether the current user may mint an API key that acts as another user.
	 *
	 * A key inherits its user's capabilities, so allowing any plugin admin to
	 * target any account would let a lower-privileged manager (the plugin's
	 * admin gate can be a capability such as manage_woocommerce) mint a key
	 * acting as a full administrator. Creating a key for yourself is always
	 * allowed; targeting anyone else requires the capability to edit that
	 * user, which core/WooCommerce already deny across privilege levels.
	 *
	 * @param int $user_id Target user ID.
	 *
	 * @return bool
	 */
	function wcusage_api_can_create_key_for_user( $user_id ) {

		$user_id = absint( $user_id );

		if ( ! $user_id || ! is_user_logged_in() ) {
			return false;
		}

		if ( get_current_user_id() === $user_id ) {
			return true;
		}

		return current_user_can( 'edit_user', $user_id );
	}
}

if ( ! function_exists( 'wcusage_api_create_key' ) ) {
	/**
	 * Create a new API key.
	 *
	 * @param int    $user_id     WP user the key acts as.
	 * @param string $description Free-text label.
	 * @param array  $scopes      Scopes for the key.
	 * @param string $expires     Optional Y-m-d expiry date.
	 *
	 * @return array|WP_Error array( 'id' => int, 'token' => string ) on success.
	 */
	function wcusage_api_create_key( $user_id, $description = '', $scopes = array( 'read' ), $expires = '' ) {

		$user_id = absint( $user_id );
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return wcusage_api_error( 'invalid_user', __( 'The user for this API key does not exist.', 'woo-coupon-usage' ), 400 );
		}

		$scopes = wcusage_api_sanitize_scopes( $scopes );

		$date_expires = null;
		if ( $expires ) {
			if ( ! wcusage_api_validate_date_arg( $expires ) ) {
				return wcusage_api_error( 'invalid_expiry', __( 'Expiry date must use the Y-m-d format.', 'woo-coupon-usage' ), 400 );
			}
			$date_expires = $expires . ' 23:59:59';
		}

		try {
			$token = 'wcus_' . bin2hex( random_bytes( 20 ) );
		} catch ( Exception $e ) {
			return wcusage_api_error( 'no_entropy', __( 'Could not generate a secure token on this server.', 'woo-coupon-usage' ), 500 );
		}

		global $wpdb;
		wcusage_api_install_keys_table();

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'wcusage_api_keys',
			array(
				'user_id'      => $user_id,
				'description'  => sanitize_text_field( substr( $description, 0, 200 ) ),
				'key_prefix'   => substr( $token, 0, 12 ),
				'key_hash'     => wcusage_api_hash_token( $token ),
				'scopes'       => implode( ',', $scopes ),
				'status'       => 'active',
				'date_created' => current_time( 'mysql' ),
				'date_expires' => $date_expires,
			)
		);

		if ( ! $inserted ) {
			return wcusage_api_error( 'db_error', __( 'Could not save the API key.', 'woo-coupon-usage' ), 500 );
		}

		// Capture before wcusage_add_activity() runs its own insert and
		// overwrites $wpdb->insert_id.
		$key_id = (int) $wpdb->insert_id;

		if ( function_exists( 'wcusage_add_activity' ) ) {
			wcusage_add_activity( $key_id, 'api_key_created', substr( $token, 0, 12 ) );
		}

		return array(
			'id'    => $key_id,
			'token' => $token,
		);
	}
}

if ( ! function_exists( 'wcusage_api_find_key_by_token' ) ) {
	/**
	 * Look up an active, unexpired key row by plaintext token.
	 *
	 * @param string $token Plaintext token from the request.
	 *
	 * @return object|null Key row or null.
	 */
	function wcusage_api_find_key_by_token( $token ) {

		if ( ! is_string( $token ) || 0 !== strpos( $token, 'wcus_' ) || strlen( $token ) > 100 ) {
			return null;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wcusage_api_keys';

		if ( ! wcusage_api_table_exists( $table ) ) {
			return null;
		}

		$hash = wcusage_api_hash_token( $token );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wcusage_api_keys WHERE key_hash = %s AND status = 'active' LIMIT 1",
				$hash
			)
		);

		if ( ! $row ) {
			return null;
		}

		// Defense in depth: re-compare in constant time.
		if ( ! hash_equals( $row->key_hash, $hash ) ) {
			return null;
		}

		if ( ! empty( $row->date_expires ) && '0000-00-00 00:00:00' !== $row->date_expires ) {
			try {
				// date_expires is stored in the site's own timezone, which is
				// what wp_timezone() reports - but that arrived in WordPress
				// 5.3, and this runs on every key-authenticated request. Fall
				// back to the offset the site has configured rather than
				// letting an older install fatal here.
				if ( function_exists( 'wp_timezone' ) ) {
					$timezone = wp_timezone();
				} else {
					$offset   = (float) get_option( 'gmt_offset', 0 );
					$timezone = new DateTimeZone( sprintf( '%+03d:%02d', (int) $offset, abs( ( $offset - (int) $offset ) * 60 ) ) );
				}

				$expires = new DateTimeImmutable( $row->date_expires, $timezone );
			} catch ( Exception $e ) {
				// Unparseable expiry: fail closed.
				return null;
			}
			if ( $expires->getTimestamp() < time() ) {
				return null;
			}
		}

		return $row;
	}
}

if ( ! function_exists( 'wcusage_api_touch_key' ) ) {
	/**
	 * Record key usage, at most once per 5 minutes per key.
	 *
	 * @param int $key_id Key ID.
	 */
	function wcusage_api_touch_key( $key_id ) {

		$key_id   = absint( $key_id );
		$throttle = 'wcusage_api_touch_' . $key_id;

		if ( get_transient( $throttle ) ) {
			return;
		}
		set_transient( $throttle, 1, 5 * MINUTE_IN_SECONDS );

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'wcusage_api_keys',
			array( 'last_used' => current_time( 'mysql' ) ),
			array( 'id' => $key_id )
		);
	}
}

if ( ! function_exists( 'wcusage_api_count_keys' ) ) {
	/**
	 * How many API keys exist.
	 *
	 * @return int
	 */
	function wcusage_api_count_keys() {

		global $wpdb;

		if ( ! wcusage_api_table_exists( $wpdb->prefix . 'wcusage_api_keys' ) ) {
			return 0;
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wcusage_api_keys" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}

if ( ! function_exists( 'wcusage_api_get_keys' ) ) {
	/**
	 * List API keys (never includes hashes).
	 *
	 * Paged rather than capped: a fixed limit silently hid every key past it,
	 * so a site that went over could neither see nor revoke the rest.
	 *
	 * @param int $limit  Maximum rows to return.
	 * @param int $offset Rows to skip.
	 *
	 * @return array[]
	 */
	function wcusage_api_get_keys( $limit = 200, $offset = 0 ) {

		global $wpdb;
		$table = $wpdb->prefix . 'wcusage_api_keys';

		if ( ! wcusage_api_table_exists( $table ) ) {
			return array();
		}

		$limit  = max( 1, (int) $limit );
		$offset = max( 0, (int) $offset );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, user_id, description, key_prefix, scopes, status, last_used, date_created, date_expires FROM {$wpdb->prefix}wcusage_api_keys ORDER BY id DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);

		$keys = array();
		foreach ( (array) $rows as $row ) {
			$keys[] = array(
				'id'           => (int) $row->id,
				'user_id'      => (int) $row->user_id,
				'description'  => $row->description,
				'key_prefix'   => $row->key_prefix,
				'scopes'       => explode( ',', $row->scopes ),
				'status'       => $row->status,
				'last_used'    => wcusage_api_format_date( (string) $row->last_used ),
				'date_created' => wcusage_api_format_date( (string) $row->date_created ),
				'date_expires' => wcusage_api_format_date( (string) $row->date_expires ),
			);
		}

		return $keys;
	}
}

if ( ! function_exists( 'wcusage_api_get_key_row' ) ) {
	/**
	 * Fetch a key row by ID (never returns the token, which is not stored).
	 *
	 * @param int $key_id Key ID.
	 *
	 * @return object|null
	 */
	function wcusage_api_get_key_row( $key_id ) {

		global $wpdb;
		$table = $wpdb->prefix . 'wcusage_api_keys';

		if ( ! wcusage_api_table_exists( $table ) ) {
			return null;
		}

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, user_id, description, key_prefix, scopes, status FROM {$wpdb->prefix}wcusage_api_keys WHERE id = %d",
				absint( $key_id )
			)
		);
	}
}

if ( ! function_exists( 'wcusage_api_can_manage_key' ) ) {
	/**
	 * Whether the current user may revoke or delete a given key.
	 *
	 * Mirrors the create-side rule: you may always manage your own keys, and
	 * someone else's only if you could edit that user. Without this, a
	 * lower-privileged plugin admin could destroy an administrator's
	 * integration credential.
	 *
	 * @param int $key_id Key ID.
	 *
	 * @return bool
	 */
	function wcusage_api_can_manage_key( $key_id ) {

		$key = wcusage_api_get_key_row( $key_id );

		if ( ! $key ) {
			return false;
		}

		return wcusage_api_can_create_key_for_user( (int) $key->user_id );
	}
}

if ( ! function_exists( 'wcusage_api_revoke_key' ) ) {
	/**
	 * Revoke (deactivate) an API key.
	 *
	 * Idempotent: revoking a key that is already revoked reports success.
	 * $wpdb->update() returns 0 when the row already holds the new value,
	 * which is indistinguishable from "no such row" - and the REST route
	 * turns a false return into a 404, so revoking twice used to answer
	 * "API key not found" for a key that plainly exists.
	 *
	 * @param int $key_id Key ID.
	 *
	 * @return bool
	 */
	function wcusage_api_revoke_key( $key_id ) {

		$key_id = absint( $key_id );

		$key = wcusage_api_get_key_row( $key_id );
		if ( ! $key ) {
			return false;
		}

		if ( 'revoked' === $key->status ) {
			return true;
		}

		global $wpdb;
		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'wcusage_api_keys',
			array( 'status' => 'revoked' ),
			array( 'id' => $key_id )
		);

		if ( $updated && function_exists( 'wcusage_add_activity' ) ) {
			wcusage_add_activity( $key_id, 'api_key_revoked', '' );
		}

		return (bool) $updated;
	}
}

if ( ! function_exists( 'wcusage_api_delete_key' ) ) {
	/**
	 * Permanently delete an API key.
	 *
	 * @param int $key_id Key ID.
	 *
	 * @return bool
	 */
	function wcusage_api_delete_key( $key_id ) {

		global $wpdb;
		return (bool) $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'wcusage_api_keys',
			array( 'id' => absint( $key_id ) )
		);
	}
}
