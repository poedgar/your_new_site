<?php
/**
 * Abandoned Cart Helpers Trait
 *
 * Shared helpers for abandoned cart classes (manager + per-integration handlers).
 *
 * `extract_uuids()` and `get_abandoned_cart_list_tag_settings()` are usable by
 * any consumer. `recover_for_email()` is only meaningful for per-integration
 * handlers and requires:
 * - `INTEGRATION_SLUG` constant
 * - `$this->manager`     — Abandoned_Cart_Manager instance
 * - `$this->integration` — Base_Integration instance
 *
 * The manager uses the trait for `extract_uuids` only; it never calls
 * `recover_for_email`. PHPStan is silenced on those handler-only references
 * since they're dead code in the manager context.
 *
 * @since 1.5.0
 *
 * @package SureContact
 */

namespace SureContact\Traits;

use SureContact\Contact_Service;
use SureContact\Integrations\Base_Integration;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait Abandoned_Cart_Helpers
 *
 * @since 1.5.0
 */
trait Abandoned_Cart_Helpers {

	/**
	 * Extract UUID values from list/tag select field data.
	 *
	 * List/tag select fields store data as arrays of objects with 'value' keys,
	 * or as plain string arrays. This normalizes both formats.
	 *
	 * @since 1.5.0
	 *
	 * @param mixed $items Field value (array of objects/strings or empty).
	 * @return array Array of UUID strings.
	 */
	protected function extract_uuids( $items ) {
		if ( empty( $items ) || ! is_array( $items ) ) {
			return array();
		}

		$uuids = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['value'] ) ) {
				$uuids[] = $item['value'];
			} elseif ( is_string( $item ) ) {
				$uuids[] = $item;
			}
		}

		return array_values( array_unique( array_filter( $uuids ) ) );
	}

	/**
	 * Get abandoned cart list/tag settings from an integration instance.
	 *
	 * Used by per-integration handlers to read the configured lists/tags for
	 * recovery tag detachment.
	 *
	 * @since 1.5.0
	 *
	 * @param Base_Integration $integration Integration instance.
	 * @return array Settings array with abandoned_cart_add_lists and abandoned_cart_add_tags keys.
	 */
	protected function get_abandoned_cart_list_tag_settings( Base_Integration $integration ) {
		return array(
			'abandoned_cart_add_lists' => $integration->get_setting( 'abandoned_cart_add_lists', array() ),
			'abandoned_cart_add_tags'  => $integration->get_setting( 'abandoned_cart_add_tags', array() ),
		);
	}

	/**
	 * Mark abandoned/active rows as recovered and detach abandoned-cart tags.
	 *
	 * Shared by order completion and the empty-cart handlers in WC + EDD so
	 * contacts never get stuck with the abandoned tag.
	 *
	 * @since 1.5.0
	 *
	 * @param string $email Customer email address.
	 * @return void
	 */
	protected function recover_for_email( $email ) {
		$slug          = static::INTEGRATION_SLUG; // @phpstan-ignore classConstant.notFound
		$contact_uuids = $this->manager->mark_recovered( $email, $slug ); // @phpstan-ignore property.notFound

		if ( empty( $contact_uuids ) ) {
			return;
		}

		$settings   = $this->get_abandoned_cart_list_tag_settings( $this->integration ); // @phpstan-ignore property.notFound
		$list_uuids = $this->extract_uuids( $settings['abandoned_cart_add_lists'] ?? array() );
		$tag_uuids  = $this->extract_uuids( $settings['abandoned_cart_add_tags'] ?? array() );

		if ( empty( $list_uuids ) && empty( $tag_uuids ) ) {
			return;
		}

		$contact_service = new Contact_Service();
		$source_options  = array( 'source' => $slug );

		foreach ( $contact_uuids as $contact_uuid ) {
			if ( ! empty( $list_uuids ) ) {
				$contact_service->detach_lists_from_contact( $contact_uuid, $list_uuids, $source_options );
			}

			if ( ! empty( $tag_uuids ) ) {
				$contact_service->detach_tags_from_contact( $contact_uuid, $tag_uuids, $source_options );
			}
		}
	}
}
