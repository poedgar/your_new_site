<?php
/**
 * Author Email Cleanup
 *
 * Clears the author email variable that shipped as the Person schema email
 * default on 1.6.2 through 1.10.0 out of saved schema data.
 *
 * @package SureRank\Inc\Upgrades
 * @since 1.10.1
 */

namespace SureRank\Inc\Upgrades;

use SureRank\Inc\Functions\Cache_Purge;
use SureRank\Inc\Functions\Get;
use SureRank\Inc\Functions\Update;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Author Email Cleanup
 *
 * Removing the field default only helps fresh installs. Any site that saved
 * its settings while the default was in place persisted the literal
 * %author.email% string, and a stored value takes precedence over the field
 * definition, so those sites keep publishing the author's account email after
 * updating. This clears the stored value once, in every store schemas can live
 * in, so nothing is required from the site owner.
 *
 * The variable itself still works. A site owner who wants an author email in
 * their schema can add it back deliberately.
 *
 * @since 1.10.1
 */
class Author_Email_Cleanup {

	/**
	 * Cleanup version applied to the saved schema data.
	 *
	 * Bump this to re-run the cleanup on every site.
	 *
	 * @since 1.10.1
	 */
	public const VERSION = 1;

	/**
	 * Option set when the cleanup actually changed something on this site.
	 *
	 * Only those sites were publishing the address, so only those sites are
	 * told about it.
	 *
	 * @since 1.10.1
	 */
	public const NOTICE_OPTION = 'surerank_author_email_cleanup_notice';

	/**
	 * Last plugin version whose exports can carry the address by default.
	 *
	 * The Person default was removed in the release after this one, so a file
	 * exported on this version or earlier may hold the address without its
	 * owner ever asking for it. A newer export that still holds it was
	 * configured on purpose and has to survive the round trip untouched.
	 *
	 * @since 1.10.1
	 */
	public const LEGACY_EXPORT_MAX_VERSION = '1.10.0';

	/**
	 * Option recording the cleanup version already applied.
	 *
	 * @since 1.10.1
	 */
	private const VERSION_OPTION = 'surerank_author_email_cleanup_version';

	/**
	 * The value that shipped as the Person email field default.
	 *
	 * @since 1.10.1
	 */
	private const VARIABLE = '%author.email%';

	/**
	 * Schema that carried the default, lowercased for comparison.
	 *
	 * Pinned here rather than read back from the Person schema class: this
	 * clears what 1.6.2 through 1.10.0 wrote to the database, so it has to keep
	 * matching that saved shape even if the schema is renamed later.
	 *
	 * @since 1.10.1
	 */
	private const SCHEMA_TYPE = 'person';

	/**
	 * Field within that schema whose default was the author email.
	 *
	 * @since 1.10.1
	 */
	private const FIELD = 'email';

	/**
	 * Meta key holding per-object schema data on posts, terms and users.
	 *
	 * @since 1.10.1
	 */
	private const META_KEY = 'surerank_settings_schemas';

	/**
	 * Option counting runs that could not write everything they found.
	 *
	 * @since 1.10.1
	 */
	private const ATTEMPTS_OPTION = 'surerank_author_email_cleanup_attempts';

	/**
	 * How many times a failing run is retried before it is given up on.
	 *
	 * A write that fails is usually transient (a lock, a full disk), so it is
	 * worth retrying. If it never succeeds, recording the version anyway stops
	 * the site scanning its meta tables on every request forever.
	 *
	 * @since 1.10.1
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Run the cleanup once per site.
	 *
	 * Hooked on wp_loaded, so the happy path costs one autoloaded option read.
	 * The version is recorded last, and only when every write that was needed
	 * actually landed, so a run that dies or is refused part way through
	 * retries instead of recording a job it did not finish.
	 *
	 * @since 1.10.1
	 * @return void
	 */
	public static function maybe_run(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION ) {
			return;
		}

		global $wpdb;

		// Each store is cleaned on its own line, so none is skipped by
		// short-circuiting once an earlier one has matched.
		$global = self::clean_global_settings();
		$posts  = self::clean_object_meta( 'post', $wpdb->postmeta, 'meta_id' );
		$terms  = self::clean_object_meta( 'term', $wpdb->termmeta, 'meta_id' );
		$users  = self::clean_object_meta( 'user', $wpdb->usermeta, 'umeta_id' );

		$cleared = $global['cleared'] || $posts['cleared'] || $terms['cleared'] || $users['cleared'];
		$failed  = $global['failed'] || $posts['failed'] || $terms['failed'] || $users['failed'];

		if ( $cleared ) {
			update_option( self::NOTICE_OPTION, true );

			/*
			 * The headless REST cache stores rendered heads, which hold the
			 * resolved address rather than the variable, so no query here can
			 * find them. Every entry carries this timestamp and is dropped when
			 * it moves, so bumping it retires all of them at once. Per-object
			 * writes above already invalidate their own entry; a change to the
			 * global settings does not, and that is the case the shipped
			 * default created.
			 */
			Update::option( SURERANK_SEO_LAST_UPDATED, time() );

			// Third-party page caches do not notice option or meta writes, so
			// purge them the way the normal settings save does.
			Cache_Purge::purge_all();
		}

		if ( $failed ) {
			// Something the cleanup found could not be written. Count this run,
			// so MAX_ATTEMPTS is the number of tries made rather than the
			// number left after them, and leave the version unset so the next
			// request picks the job back up.
			$attempts = (int) get_option( self::ATTEMPTS_OPTION, 0 ) + 1;
			update_option( self::ATTEMPTS_OPTION, $attempts );

			if ( $attempts < self::MAX_ATTEMPTS ) {
				return;
			}
		}

		/*
		 * Recorded either because the job is done, or because it has been given
		 * up on. The count is kept in the second case: a recorded version with
		 * an attempt count beside it is how a site that stopped with work still
		 * outstanding can be told apart from one that finished cleanly.
		 */
		update_option( self::VERSION_OPTION, self::VERSION );

		if ( ! $failed ) {
			delete_option( self::ATTEMPTS_OPTION );
		}
	}

	/**
	 * Empty the author email default out of every Person schema in a map.
	 *
	 * The default only ever reached one field of one schema type, so only that
	 * field is touched. The variable stays supported, which means a site owner
	 * may have typed it into another schema deliberately, and erasing that
	 * would throw away their configuration for no security gain. An exact
	 * match is required for the same reason: a value such as
	 * "Write to %author.email%" is text they wrote.
	 *
	 * Public because the settings importer needs it too: a file exported while
	 * the default was in place carries the value, and the import replaces the
	 * saved schemas wholesale, which would otherwise put it back on a site this
	 * cleanup has already finished with.
	 *
	 * @param array<int|string, mixed> $schemas Saved schemas, keyed by schema id.
	 * @return array<int|string, mixed> The same map with the default emptied.
	 * @since 1.10.1
	 */
	public static function clear_person_email( array $schemas ): array {
		foreach ( $schemas as $key => $schema ) {
			if ( ! is_array( $schema ) || ! isset( $schema['fields'] ) || ! is_array( $schema['fields'] ) ) {
				continue;
			}

			if ( self::registry_key( $schema ) !== self::SCHEMA_TYPE ) {
				continue;
			}

			$email = $schema['fields'][ self::FIELD ] ?? '';

			if ( is_string( $email ) && trim( $email ) === self::VARIABLE ) {
				$schemas[ $key ]['fields'][ self::FIELD ] = '';
			}
		}

		return $schemas;
	}

	/**
	 * Whether an export file is old enough to carry the address by default.
	 *
	 * Content alone cannot answer this: an owner may have typed the variable
	 * into the same field on purpose, and that reads identically to the old
	 * default. The version the file was written on is the only thing that
	 * separates them, so a file from before the default was removed is
	 * cleaned, and anything newer is left exactly as exported.
	 *
	 * A file with no version predates the header that records one, so it is
	 * treated as legacy.
	 *
	 * @param mixed $version Version recorded in the export envelope.
	 * @since 1.10.1
	 * @return bool Whether the value in this file should be treated as the old default.
	 */
	public static function export_carries_default( $version ): bool {
		if ( ! is_string( $version ) || '' === trim( $version ) ) {
			return true;
		}

		return version_compare( trim( $version ), self::LEGACY_EXPORT_MAX_VERSION, '<=' );
	}

	/**
	 * Clear the default out of the global schemas map.
	 *
	 * @since 1.10.1
	 * @return array{cleared: bool, failed: bool} Whether anything was cleared, and whether a needed write was refused.
	 */
	private static function clean_global_settings(): array {
		/*
		 * Read the raw option rather than Settings::get(), so the cleanup does
		 * not freeze the code level defaults into the database as a side effect.
		 */
		$settings = Get::option( SURERANK_SETTINGS, [], 'array' );

		if ( ! is_array( $settings ) || ! isset( $settings['schemas'] ) || ! is_array( $settings['schemas'] ) ) {
			return [
				'cleared' => false,
				'failed'  => false,
			];
		}

		$cleaned = self::clear_person_email( $settings['schemas'] );

		if ( $cleaned === $settings['schemas'] ) {
			return [
				'cleared' => false,
				'failed'  => false,
			];
		}

		$settings['schemas'] = $cleaned;

		// The value definitely changed, so a false here is a refused write and
		// not update_option reporting that there was nothing to do.
		$stored = Update::option( SURERANK_SETTINGS, $settings );

		return [
			'cleared' => (bool) $stored,
			'failed'  => ! $stored,
		];
	}

	/**
	 * Clear the default out of per-object schema overrides.
	 *
	 * The query returns primary keys only, never the stored blobs, so a site
	 * with many affected rows costs a list of integers rather than every
	 * serialized schema map at once. The meta_key column is indexed, so the
	 * LIKE filter runs over this plugin's own rows rather than the whole meta
	 * table.
	 *
	 * Each row is then read and written by its own id. An object is not
	 * supposed to hold more than one row for this key, but if it does, the
	 * object-level API would rewrite all of them with whichever value it read,
	 * throwing away the others. Addressing rows individually keeps each one's
	 * own data and clears every one of them rather than only the one that
	 * renders.
	 *
	 * @param string $meta_type  Meta type accepted by the meta API (post, term or user).
	 * @param string $table      Meta table for that type, taken from $wpdb.
	 * @param string $key_column Primary key column of that table.
	 * @since 1.10.1
	 * @return array{cleared: bool, failed: bool} Whether anything was cleared, and whether a needed write was refused.
	 */
	private static function clean_object_meta( string $meta_type, string $table, string $key_column ): array {
		global $wpdb;

		$meta_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration lookup; caching a query that runs once would only leave a stale entry behind.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column names come from $wpdb and this method's own callers, never from input.
				"SELECT {$key_column} FROM {$table} WHERE meta_key = %s AND meta_value LIKE %s",
				self::META_KEY,
				'%' . $wpdb->esc_like( self::VARIABLE ) . '%'
			)
		);

		$cleared = false;
		$failed  = false;

		/*
		 * get_col() hands back an empty array both when nothing matched and
		 * when the query itself failed, so the error state is the only way to
		 * tell those apart. wpdb::query() flushes last_error before it runs,
		 * so what is read here belongs to this query. Reporting a failed read
		 * as "nothing to do" would let maybe_run() record the version and
		 * retire the job while the address is still published.
		 */
		if ( '' !== $wpdb->last_error ) {
			return [
				'cleared' => false,
				'failed'  => true,
			];
		}

		foreach ( $meta_ids as $meta_id ) {
			$meta_id = (int) $meta_id;
			$row     = get_metadata_by_mid( $meta_type, $meta_id );

			if ( ! is_object( $row ) || ! isset( $row->meta_value ) ) {
				// The query above listed this row, so failing to read it now is
				// a fault rather than an absence. Leave the job open for a
				// retry instead of passing over it.
				$failed = true;
				continue;
			}

			if ( ! is_array( $row->meta_value ) ) {
				// Not the stored shape this cleanup handles, so there is no
				// schemas map inside it and nothing here can publish an
				// address. Genuinely not applicable, not a failure.
				continue;
			}

			$stored = $row->meta_value;

			/*
			 * Only the schemas map inside the row is ever rendered, in both the
			 * override format and the older full snapshot, so nothing outside
			 * it can publish an address.
			 */
			if ( ! isset( $stored['schemas'] ) || ! is_array( $stored['schemas'] ) ) {
				continue;
			}

			$cleaned = self::clear_person_email( $stored['schemas'] );

			if ( $cleaned === $stored['schemas'] ) {
				continue;
			}

			$stored['schemas'] = $cleaned;

			/*
			 * Passed through as it was read: update_metadata_by_mid() does not
			 * unslash its value the way update_metadata() does, so slashing it
			 * here would write the escape characters into the row. The value
			 * definitely changed, so a falsy result is a refused write rather
			 * than the API reporting there was nothing to do.
			 */
			if ( update_metadata_by_mid( $meta_type, $meta_id, $stored ) ) {
				$cleared = true;
				continue;
			}

			$failed = true;
		}

		return [
			'cleared' => $cleared,
			'failed'  => $failed,
		];
	}
	/**
	 * Which schema class a saved entry renders as.
	 *
	 * The renderer resolves the class from the entry's title, which is the
	 * registry key, and treats the type key only as the node type it emits.
	 * Matching the title therefore matches exactly what renders as a Person,
	 * an entry that somehow lost its type key included. A type declared inside
	 * the fields is deliberately not consulted: it would never find saved
	 * default data, only a schema somebody built by hand.
	 *
	 * @param array<string, mixed> $schema Saved schema entry.
	 * @return string Lowercased registry key, '' when the entry declares none.
	 * @since 1.10.1
	 */
	private static function registry_key( array $schema ): string {
		$title = $schema['title'] ?? '';

		if ( is_scalar( $title ) && '' !== trim( (string) $title ) ) {
			return strtolower( trim( (string) $title ) );
		}

		$type = $schema['type'] ?? '';

		return is_scalar( $type ) ? strtolower( trim( (string) $type ) ) : '';
	}
}
