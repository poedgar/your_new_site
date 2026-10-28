<?php
/**
 * Operation Labels
 *
 * Resolves raw operation strings (e.g. 'woocommerce:track_purchase') into
 * human-readable, translatable labels for the logs UI.
 *
 * @since 1.4.0
 *
 * @package SureContact
 */

namespace SureContact;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Operation_Labels
 *
 * Static utility class for resolving operation labels.
 *
 * @since 1.4.0
 */
class Operation_Labels {

	/**
	 * Cached operation labels map
	 *
	 * @since 1.4.0
	 *
	 * @var array|null
	 */
	private static $operation_labels = null;

	/**
	 * Get human-readable label for a raw operation string
	 *
	 * Handles both prefixed ('woocommerce:track_purchase') and
	 * unprefixed ('track_purchase') operation strings.
	 *
	 * @since 1.4.0
	 *
	 * @param string $raw_operation Raw operation string from database.
	 * @return string Human-readable label.
	 */
	public static function get_label( $raw_operation ) {
		if ( empty( $raw_operation ) ) {
			return '';
		}

		$labels = self::get_operation_labels();

		// Split on first colon to separate source from operation.
		$parts = explode( ':', $raw_operation, 2 );

		if ( count( $parts ) === 2 ) {
			$source    = $parts[0];
			$operation = $parts[1];

			$source_label    = self::get_source_label( $source );
			$operation_label = isset( $labels[ $operation ] ) ? $labels[ $operation ] : self::humanize( $operation );

			/* translators: 1: integration source name (e.g. "WooCommerce"), 2: operation label (e.g. "Track Purchase"). */
			return sprintf( __( '%1$s — %2$s', 'surecontact' ), $source_label, $operation_label );
		}

		// No source prefix — return just the operation label.
		return isset( $labels[ $raw_operation ] ) ? $labels[ $raw_operation ] : self::humanize( $raw_operation );
	}

	/**
	 * Get source display name from Integrations_Loader
	 *
	 * @since 1.4.0
	 *
	 * @param string $slug Integration slug.
	 * @return string Human-readable source name.
	 */
	private static function get_source_label( $slug ) {
		$surecontact = \surecontact();

		if ( ! empty( $surecontact->integrations_loader ) ) {
			$config = $surecontact->integrations_loader->get_integration_config( $slug );
			if ( $config && ! empty( $config['name'] ) ) {
				return $config['name'];
			}
		}

		return self::humanize( $slug );
	}

	/**
	 * Get cached operation labels map
	 *
	 * @since 1.4.0
	 *
	 * @return array Operation key => translated label.
	 */
	private static function get_operation_labels() {
		if ( null === self::$operation_labels ) {
			self::$operation_labels = array(
				'create_contact'            => __( 'Create Contact', 'surecontact' ),
				'batch_sync_contacts'       => __( 'Bulk Sync Contacts', 'surecontact' ),
				'attach_lists_to_contact'   => __( 'Attach Lists', 'surecontact' ),
				'detach_lists_from_contact' => __( 'Detach Lists', 'surecontact' ),
				'attach_tags_to_contact'    => __( 'Attach Tags', 'surecontact' ),
				'detach_tags_from_contact'  => __( 'Detach Tags', 'surecontact' ),
				'update_email'              => __( 'Update Email', 'surecontact' ),
				'track_purchase'            => __( 'Track Purchase', 'surecontact' ),
				'cancel_purchase'           => __( 'Cancel Purchase', 'surecontact' ),
				'refund_purchase'           => __( 'Refund Purchase', 'surecontact' ),
				'create_list'               => __( 'Create List', 'surecontact' ),
				'create_tag'                => __( 'Create Tag', 'surecontact' ),
				'sync_custom_field'         => __( 'Sync Custom Field', 'surecontact' ),
			);
		}
		return self::$operation_labels;
	}

	/**
	 * Convert a snake_case/kebab-case key to Title Case
	 *
	 * @since 1.4.0
	 *
	 * @param string $key Raw key string.
	 * @return string Humanized string.
	 */
	private static function humanize( $key ) {
		return ucwords( str_replace( array( '_', '-' ), ' ', $key ) );
	}
}
