<?php
/**
 * Database layer: contacts, campaigns and the per-contact send queue.
 */

defined( 'ABSPATH' ) || exit;

class CO_DB {

	const DB_VERSION = '1';

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'co_' . $name;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::table( 'contacts' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL,
			name varchar(190) NOT NULL DEFAULT '',
			company varchar(190) NOT NULL DEFAULT '',
			lang varchar(5) NOT NULL DEFAULT 'en',
			status varchar(20) NOT NULL DEFAULT 'subscribed',
			token char(32) NOT NULL,
			created_at datetime NOT NULL,
			last_sent_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY token (token)
			) $collate;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'campaigns' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			subject_en text NOT NULL,
			body_en longtext NOT NULL,
			subject_uk text NOT NULL,
			body_uk longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'sending',
			total int(10) unsigned NOT NULL DEFAULT 0,
			sent int(10) unsigned NOT NULL DEFAULT 0,
			failed int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			finished_at datetime NULL,
			PRIMARY KEY  (id)
			) $collate;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'sends' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) unsigned NOT NULL,
			contact_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'queued',
			claim char(32) NOT NULL DEFAULT '',
			error varchar(255) NOT NULL DEFAULT '',
			sent_at datetime NULL,
			PRIMARY KEY  (id),
			KEY campaign_status (campaign_id,status),
			KEY claim (claim)
			) $collate;"
		);
		update_option( 'co_db_version', self::DB_VERSION );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'co_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/* ---------- Contacts ---------- */

	/**
	 * Adds or updates contacts. Existing contacts keep their subscription status,
	 * so re-importing a list never re-subscribes someone who opted out.
	 *
	 * @param array $rows List of [email, name, company, lang].
	 * @return array{added:int,updated:int,invalid:int}
	 */
	public static function import( array $rows ) {
		global $wpdb;
		$result = array( 'added' => 0, 'updated' => 0, 'invalid' => 0 );
		$table  = self::table( 'contacts' );

		foreach ( $rows as $row ) {
			$email = sanitize_email( $row[0] ?? '' );
			if ( ! is_email( $email ) ) {
				$result['invalid']++;
				continue;
			}
			$lang = strtolower( trim( $row[3] ?? '' ) );
			$lang = in_array( $lang, array( 'uk', 'ua' ), true ) ? 'uk' : 'en';

			$affected = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO $table (email, name, company, lang, status, token, created_at)
					VALUES (%s, %s, %s, %s, 'subscribed', %s, %s)
					ON DUPLICATE KEY UPDATE name = VALUES(name), company = VALUES(company), lang = VALUES(lang)",
					strtolower( $email ),
					sanitize_text_field( $row[1] ?? '' ),
					sanitize_text_field( $row[2] ?? '' ),
					$lang,
					wp_generate_password( 32, false ),
					current_time( 'mysql' )
				)
			);
			if ( 1 === $affected ) {
				$result['added']++;
			} elseif ( 2 === $affected ) {
				$result['updated']++;
			}
		}
		return $result;
	}

	public static function get_contacts( $search = '', $status = '', $page = 1, $per_page = 50 ) {
		global $wpdb;
		list( $where, $args ) = self::contacts_where( $search, $status );
		$args[] = $per_page;
		$args[] = ( max( 1, $page ) - 1 ) * $per_page;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table( 'contacts' ) . " $where ORDER BY id DESC LIMIT %d OFFSET %d", $args ),
			ARRAY_A
		);
	}

	public static function count_contacts( $search = '', $status = '', $lang = '' ) {
		global $wpdb;
		list( $where, $args ) = self::contacts_where( $search, $status, $lang );
		$sql = 'SELECT COUNT(*) FROM ' . self::table( 'contacts' ) . " $where";
		return (int) $wpdb->get_var( $args ? $wpdb->prepare( $sql, $args ) : $sql );
	}

	private static function contacts_where( $search, $status, $lang = '' ) {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(email LIKE %s OR name LIKE %s OR company LIKE %s)';
			array_push( $args, $like, $like, $like );
		}
		if ( '' !== $status ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}
		if ( '' !== $lang ) {
			$where[] = 'lang = %s';
			$args[]  = $lang;
		}
		return array( 'WHERE ' . implode( ' AND ', $where ), $args );
	}

	public static function bulk_contacts( $action, array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		$in    = implode( ',', $ids );
		$table = self::table( 'contacts' );
		switch ( $action ) {
			case 'delete':
				$wpdb->query( 'DELETE FROM ' . self::table( 'sends' ) . " WHERE contact_id IN ($in) AND status = 'queued'" );
				return (int) $wpdb->query( "DELETE FROM $table WHERE id IN ($in)" );
			case 'unsubscribe':
				return (int) $wpdb->query( "UPDATE $table SET status = 'unsubscribed' WHERE id IN ($in)" );
			case 'subscribe':
				return (int) $wpdb->query( "UPDATE $table SET status = 'subscribed' WHERE id IN ($in)" );
		}
		return 0;
	}

	public static function unsubscribe_token( $token ) {
		global $wpdb;
		return (bool) $wpdb->update( self::table( 'contacts' ), array( 'status' => 'unsubscribed' ), array( 'token' => $token ) );
	}

	/* ---------- Campaigns ---------- */

	/**
	 * Creates a campaign and queues every subscribed contact for it.
	 *
	 * @return int Campaign ID.
	 */
	public static function create_campaign( array $data, $only_new ) {
		global $wpdb;
		$wpdb->insert(
			self::table( 'campaigns' ),
			array(
				'subject_en' => $data['subject_en'],
				'body_en'    => $data['body_en'],
				'subject_uk' => $data['subject_uk'],
				'body_uk'    => $data['body_uk'],
				'status'     => 'sending',
				'created_at' => current_time( 'mysql' ),
			)
		);
		$id    = (int) $wpdb->insert_id;
		$extra = $only_new ? ' AND last_sent_at IS NULL' : '';
		$total = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::table( 'sends' ) . ' (campaign_id, contact_id) SELECT %d, id FROM ' . self::table( 'contacts' ) . " WHERE status = 'subscribed'$extra",
				$id
			)
		);
		$wpdb->update( self::table( 'campaigns' ), array( 'total' => (int) $total ), array( 'id' => $id ) );
		return $id;
	}

	public static function get_campaign( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'campaigns' ) . ' WHERE id = %d', $id ), ARRAY_A );
	}

	public static function get_campaigns( $limit = 100 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'campaigns' ) . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A );
	}

	/**
	 * Atomically claims the next queued sends so two open tabs never email the same contact twice.
	 */
	public static function claim_batch( $campaign_id, $limit ) {
		global $wpdb;
		$claim = wp_generate_password( 32, false );
		$sends = self::table( 'sends' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $sends SET status = 'sending', claim = %s WHERE campaign_id = %d AND status = 'queued' ORDER BY id LIMIT %d",
				$claim,
				$campaign_id,
				$limit
			)
		);
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id AS send_id, c.* FROM $sends s JOIN " . self::table( 'contacts' ) . ' c ON c.id = s.contact_id WHERE s.claim = %s',
				$claim
			),
			ARRAY_A
		);
	}

	public static function finish_send( $send_id, $campaign_id, $contact_id, $error = '' ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->update(
			self::table( 'sends' ),
			array( 'status' => $error ? 'failed' : 'sent', 'error' => substr( $error, 0, 255 ), 'sent_at' => $now ),
			array( 'id' => $send_id )
		);
		$col = $error ? 'failed' : 'sent';
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'campaigns' ) . " SET $col = $col + 1 WHERE id = %d", $campaign_id ) );
		if ( ! $error ) {
			$wpdb->update( self::table( 'contacts' ), array( 'last_sent_at' => $now ), array( 'id' => $contact_id ) );
		}
	}

	/**
	 * Skips queued sends whose contact unsubscribed after the campaign started.
	 */
	public static function skip_unsubscribed( $campaign_id ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table( 'sends' ) . ' s JOIN ' . self::table( 'contacts' ) . " c ON c.id = s.contact_id
				SET s.status = 'skipped' WHERE s.campaign_id = %d AND s.status = 'queued' AND c.status <> 'subscribed'",
				$campaign_id
			)
		);
	}

	public static function count_sends( $campaign_id, $status ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table( 'sends' ) . ' WHERE campaign_id = %d AND status = %s', $campaign_id, $status ) );
	}

	public static function set_campaign_status( $campaign_id, $status ) {
		global $wpdb;
		$data = array( 'status' => $status );
		if ( in_array( $status, array( 'done', 'cancelled' ), true ) ) {
			$data['finished_at'] = current_time( 'mysql' );
		}
		if ( 'cancelled' === $status ) {
			$wpdb->update( self::table( 'sends' ), array( 'status' => 'cancelled' ), array( 'campaign_id' => $campaign_id, 'status' => 'queued' ) );
		}
		$wpdb->update( self::table( 'campaigns' ), $data, array( 'id' => $campaign_id ) );
	}

	public static function failures( $campaign_id, $limit = 50 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.email, s.error FROM ' . self::table( 'sends' ) . ' s JOIN ' . self::table( 'contacts' ) . " c ON c.id = s.contact_id
				WHERE s.campaign_id = %d AND s.status = 'failed' ORDER BY s.id DESC LIMIT %d",
				$campaign_id,
				$limit
			),
			ARRAY_A
		);
	}
}
