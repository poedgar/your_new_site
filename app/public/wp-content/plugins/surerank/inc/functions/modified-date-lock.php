<?php
/**
 * Modified Date Lock Handler.
 *
 * @package SureRank\Inc\Functions
 * @since 1.7.5
 */

namespace SureRank\Inc\Functions;

use SureRank\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Enables filter-based control over preserving WordPress modified timestamps.
 */
class Modified_Date_Lock {
	use Get_Instance;

	/**
	 * Shared UTC timezone instance.
	 *
	 * @var \DateTimeZone|null
	 */
	private static $utc_timezone = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'wp_insert_post_data', [ $this, 'maybe_preserve_modified_date' ], 10, 4 );
	}

	/**
	 * Preserve or override post_modified values on updates when SureRank filters opt in.
	 *
	 * Precedence:
	 * 1. Freeze current database values.
	 * 2. Use an explicit modified date override.
	 * 3. Clone publish date into modified date.
	 * 4. Fall back to WordPress defaults.
	 *
	 * @param array<string, mixed>        $data                Sanitized post data to be inserted.
	 * @param array<string, mixed>        $postarr             Raw and sanitized post data.
	 * @param array<string, mixed>|object $unsanitized_postarr Original unchanged post data. Normalized to an array internally.
	 * @param bool                        $update              Whether this is an existing post being updated.
	 * @return array<string, mixed>
	 */
	public function maybe_preserve_modified_date( array $data, array $postarr, $unsanitized_postarr, bool $update ): array {
		if ( ! $update ) {
			return $data;
		}

		// WordPress core does not enforce a type on this filter argument, so a
		// caller may pass an object. Normalize to an array to avoid a fatal.
		// get_object_vars() keeps only public properties, unlike an (array)
		// cast which leaks mangled private/protected keys into our filters.
		if ( is_object( $unsanitized_postarr ) ) {
			$unsanitized_postarr = get_object_vars( $unsanitized_postarr );
		} elseif ( ! is_array( $unsanitized_postarr ) ) {
			$unsanitized_postarr = [];
		}

		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		if ( $post_id <= 0 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return $data;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return $data;
		}

		$frozen_data = $this->get_frozen_modified_date_data( $data, $post_id, $post, $postarr, $unsanitized_postarr );
		if ( null !== $frozen_data ) {
			return $frozen_data;
		}

		$override_data = $this->get_explicit_modified_date_data( $data, $post_id, $post, $postarr, $unsanitized_postarr );
		if ( null !== $override_data ) {
			return $override_data;
		}

		$clone_data = $this->get_publish_date_clone_data( $data, $post_id, $post, $postarr, $unsanitized_postarr );
		if ( null !== $clone_data ) {
			return $clone_data;
		}

		return $data;
	}

	/**
	 * Resolve a freeze override, if requested.
	 *
	 * @param array<string, mixed> $data                Outgoing sanitized post data.
	 * @param int                  $post_id             Post ID being updated.
	 * @param \WP_Post             $post                Current post object from database (pre-update).
	 * @param array<string, mixed> $postarr             Post data array.
	 * @param array<string, mixed> $unsanitized_postarr Original unchanged post data.
	 * @return array<string, mixed>|null
	 */
	private function get_frozen_modified_date_data( array $data, int $post_id, \WP_Post $post, array $postarr, array $unsanitized_postarr ): ?array {
		/**
		 * Allow developers to freeze modified timestamps for a post update.
		 *
		 * Return true to preserve current database values for `post_modified`
		 * and `post_modified_gmt` during this update.
		 *
		 * @since 1.7.5
		 *
		 * @param bool                 $freeze              Whether to freeze modified timestamps. Default false.
		 * @param int                  $post_id             Post ID being updated.
		 * @param \WP_Post             $post                Current post object from database (pre-update).
		 * @param array<string, mixed> $data                Outgoing sanitized post data.
		 * @param array<string, mixed> $postarr             Post data array.
		 * @param array<string, mixed> $unsanitized_postarr Original unchanged post data.
		 */
		$freeze_modified_date = (bool) apply_filters( 'surerank_freeze_modified_date', false, $post_id, $post, $data, $postarr, $unsanitized_postarr );
		if ( ! $freeze_modified_date ) {
			return null;
		}

		return $this->set_modified_date_values(
			$data,
			$post->post_modified,
			$post->post_modified_gmt
		);
	}

	/**
	 * Resolve an explicit modified date override, if provided.
	 *
	 * @param array<string, mixed> $data                Outgoing sanitized post data.
	 * @param int                  $post_id             Post ID being updated.
	 * @param \WP_Post             $post                Current post object from database (pre-update).
	 * @param array<string, mixed> $postarr             Post data array.
	 * @param array<string, mixed> $unsanitized_postarr Original unchanged post data.
	 * @return array<string, mixed>|null
	 */
	private function get_explicit_modified_date_data( array $data, int $post_id, \WP_Post $post, array $postarr, array $unsanitized_postarr ): ?array {
		/**
		 * Allow developers to explicitly set modified timestamps for a post update.
		 *
		 * Return `null`, `false`, or an empty string to skip this override. Supported
		 * values include a `DateTimeInterface` instance, a Unix timestamp, a datetime
		 * string parseable in the site timezone, or an array with `post_modified`
		 * and optional `post_modified_gmt` values.
		 *
		 * @since 1.9.3
		 *
		 * @param mixed                $modified_date       Modified date override value. Default null.
		 * @param int                  $post_id             Post ID being updated.
		 * @param \WP_Post             $post                Current post object from database (pre-update).
		 * @param array<string, mixed> $data                Outgoing sanitized post data.
		 * @param array<string, mixed> $postarr             Post data array.
		 * @param array<string, mixed> $unsanitized_postarr Original unchanged post data.
		 */
		$modified_date_override = apply_filters( 'surerank_set_modified_date', null, $post_id, $post, $data, $postarr, $unsanitized_postarr );
		$normalized_override    = $this->normalize_modified_date_override( $modified_date_override );
		if ( empty( $normalized_override ) ) {
			return null;
		}

		return $this->set_modified_date_values(
			$data,
			$normalized_override['post_modified'],
			$normalized_override['post_modified_gmt']
		);
	}

	/**
	 * Resolve a publish-date clone override, if requested.
	 *
	 * @param array<string, mixed> $data                Outgoing sanitized post data.
	 * @param int                  $post_id             Post ID being updated.
	 * @param \WP_Post             $post                Current post object from database (pre-update).
	 * @param array<string, mixed> $postarr             Post data array.
	 * @param array<string, mixed> $unsanitized_postarr Original unchanged post data.
	 * @return array<string, mixed>|null
	 */
	private function get_publish_date_clone_data( array $data, int $post_id, \WP_Post $post, array $postarr, array $unsanitized_postarr ): ?array {
		/**
		 * Allow developers to clone the publish date into the modified date.
		 *
		 * When enabled, SureRank uses the outgoing `post_date` and `post_date_gmt`
		 * values when available, then falls back to the current database values.
		 *
		 * @since 1.9.3
		 *
		 * @param bool                 $clone_publish_date  Whether to clone publish date into modified date. Default false.
		 * @param int                  $post_id             Post ID being updated.
		 * @param \WP_Post             $post                Current post object from database (pre-update).
		 * @param array<string, mixed> $data                Outgoing sanitized post data.
		 * @param array<string, mixed> $postarr             Post data array.
		 * @param array<string, mixed> $unsanitized_postarr Original unchanged post data.
		 */
		$clone_publish_date = (bool) apply_filters( 'surerank_clone_publish_date_to_modified_date', false, $post_id, $post, $data, $postarr, $unsanitized_postarr );
		if ( ! $clone_publish_date ) {
			return null;
		}

		$normalized_publish_date = $this->normalize_modified_date_override(
			[
				'post_modified'     => $data['post_date'] ?? $post->post_date,
				'post_modified_gmt' => $data['post_date_gmt'] ?? $post->post_date_gmt,
			]
		);
		if ( empty( $normalized_publish_date ) ) {
			return null;
		}

		return $this->set_modified_date_values(
			$data,
			$normalized_publish_date['post_modified'],
			$normalized_publish_date['post_modified_gmt']
		);
	}

	/**
	 * Normalize a modified date override into local and GMT datetime strings.
	 *
	 * @param mixed $modified_date_override Override value from the filter.
	 * @return array<string, string>
	 */
	private function normalize_modified_date_override( $modified_date_override ): array {
		if ( $modified_date_override instanceof \DateTimeInterface ) {
			return $this->format_modified_date_values( $modified_date_override->getTimestamp() );
		}

		if ( is_array( $modified_date_override ) ) {
			return $this->normalize_modified_date_override_array( $modified_date_override );
		}

		$timestamp = $this->get_timestamp_from_value( $modified_date_override, wp_timezone() );
		if ( null === $timestamp ) {
			return [];
		}

		return $this->format_modified_date_values( $timestamp );
	}

	/**
	 * Normalize array-based modified date overrides.
	 *
	 * @param array<string, mixed> $modified_date_override Override values.
	 * @return array<string, string>
	 */
	private function normalize_modified_date_override_array( array $modified_date_override ): array {
		$local_timestamp = $this->get_timestamp_from_value(
			$modified_date_override['post_modified'] ?? null,
			wp_timezone()
		);
		$gmt_timestamp   = $this->get_timestamp_from_value(
			$modified_date_override['post_modified_gmt'] ?? null,
			self::get_utc_timezone()
		);

		$timestamp = null !== $local_timestamp ? $local_timestamp : $gmt_timestamp;
		if ( null === $timestamp ) {
			return [];
		}

		return $this->format_modified_date_values( $timestamp );
	}

	/**
	 * Convert a supported modified date value into a Unix timestamp.
	 *
	 * @param mixed         $value            Modified date value.
	 * @param \DateTimeZone $default_timezone Default timezone for string parsing.
	 * @return int|null
	 */
	private function get_timestamp_from_value( $value, \DateTimeZone $default_timezone ): ?int {
		if ( $value instanceof \DateTimeInterface ) {
			return $value->getTimestamp();
		}

		if ( is_int( $value ) ) {
			return $value;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( sanitize_text_field( $value ) );
		if ( '' === $value ) {
			return null;
		}

		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}

		try {
			$date = new \DateTimeImmutable( $value, $default_timezone );
		} catch ( \Exception $exception ) {
			return null;
		}

		return $date->getTimestamp();
	}

	/**
	 * Format a timestamp into WordPress local and GMT modified date values.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return array<string, string>
	 */
	private function format_modified_date_values( int $timestamp ): array {
		return [
			'post_modified'     => (string) wp_date( 'Y-m-d H:i:s', $timestamp, wp_timezone() ),
			'post_modified_gmt' => (string) wp_date( 'Y-m-d H:i:s', $timestamp, self::get_utc_timezone() ),
		];
	}

	/**
	 * Set outgoing modified date fields on the post data array.
	 *
	 * @param array<string, mixed> $data              Post data array.
	 * @param string               $post_modified     Local modified datetime.
	 * @param string               $post_modified_gmt GMT modified datetime.
	 * @return array<string, mixed>
	 */
	private function set_modified_date_values( array $data, string $post_modified, string $post_modified_gmt ): array {
		$data['post_modified']     = $post_modified;
		$data['post_modified_gmt'] = $post_modified_gmt;

		return $data;
	}

	/**
	 * Get a shared UTC timezone instance.
	 *
	 * @return \DateTimeZone
	 */
	private static function get_utc_timezone(): \DateTimeZone {
		if ( null === self::$utc_timezone ) {
			self::$utc_timezone = new \DateTimeZone( 'UTC' );
		}

		return self::$utc_timezone;
	}
}
