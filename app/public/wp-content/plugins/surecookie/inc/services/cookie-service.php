<?php
/**
 * Cookie Service
 *
 * Shared business logic for custom cookie and scanned cookie operations.
 * Used by both the REST API (inc/api/cookies.php) and the WordPress
 * Abilities integration (inc/integrations/wordpress/abilities/cookie-management.php).
 *
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Services
 * @since      0.0.0-alpha.1
 */

namespace SureCookie\Inc\Services;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Sanitize;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Functions\Update;
use SureCookie\Inc\Functions\Validate;
use SureCookie\Inc\Modules\ScriptBlocking\Matched_Resources;
use SureCookie\Inc\Modules\Services\Declared_Cookies;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class CookieService
 *
 * Handles custom cookie CRUD and scanned cookie operations.
 * Returns associative arrays (not JSON responses) so callers
 * can adapt the output to their transport layer.
 *
 * @since 0.0.0-alpha.1
 */
class CookieService {
	/**
	 * Maximum items accepted by one bulk category update. Mirrors the REST
	 * `maxItems` arg schema as defense in depth for non-REST callers.
	 *
	 * @since 1.3.0
	 */
	public const MAX_BULK_ITEMS = 100;
	/**
	 * Get scanned cookies organized by category.
	 *
	 * Merges scanner-detected cookies with user-defined custom cookies,
	 * grouped under their assigned cookie categories.
	 *
	 * @return array{success: bool, message: string, cookies?: array<int, array<string, mixed>>, scanning_details?: array<string, mixed>}
	 * @since 0.0.0-alpha.1
	 */
	public function get_scanned_cookies(): array {
		$scanned_cookies       = Get::scanned_cookies_unshadowed();
		$cookie_categories     = Settings::get( 'cookie_categories' );
		$final_cookies_dataset = [];

		if ( empty( $scanned_cookies ) || empty( $cookie_categories ) ) {
			return [
				'success' => false,
				'message' => __( 'No scanned cookies found.', 'surecookie' ),
			];
		}

		$custom_category_cookies = Get::formatted_custom_cookies();

		foreach ( $cookie_categories as $category_key => $category_data ) {
			// Array-typed settings guarantee only the outer array; a scalar row is a
			// TypeError on ['id']. The map key is the category id, so it stands in.
			if ( ! is_array( $category_data ) ) {
				continue;
			}

			$category_id = Sanitize::scalar( $category_data['id'] ?? '' );
			$category_id = $category_id !== '' ? $category_id : (string) $category_key;

			$category_based_cookies      = self::with_service_slug( $scanned_cookies[ $category_id ] ?? [] );
			$custom_cookies_for_category = $custom_category_cookies[ $category_id ] ?? [];
			$all_cookies                 = array_merge( $category_based_cookies, $custom_cookies_for_category );

			$final_cookies_dataset[] = [
				'id'          => $category_id,
				'name'        => Sanitize::scalar( $category_data['name'] ?? '' ),
				'description' => Sanitize::scalar( $category_data['description'] ?? '' ),
				'cookies'     => $all_cookies,
				'count'       => count( $all_cookies ),
			];
		}

		return [
			'success'          => true,
			'message'          => __( 'Scanned cookies retrieved successfully.', 'surecookie' ),
			'cookies'          => $final_cookies_dataset,
			'scanning_details' => Get::option( SURECOOKIE_SCANNED_DETAILS_OPTION, [ 'success' => true ], 'array' ),
		];
	}

	/**
	 * Stop publishing the cookies a catalog service declares.
	 *
	 * A declared cookie is an inference from the service being present, not an
	 * observation, so it is cleared by discarding the evidence rather than by
	 * deleting the row: the stored rows a scan wrote, and the blocker's matched
	 * resources for that service. Deliberately NOT a suppression - both sources
	 * re-establish themselves from live evidence, so a service that really is
	 * still on the site comes back and the policy stays honest. That is also why
	 * this is safe to offer next to a one-row delete (#1141).
	 *
	 * @since 1.5.1
	 * @param string $slug Catalog service slug.
	 * @return array{success: bool, message: string, removed?: int}
	 */
	public function forget_declared_service( string $slug ): array {
		$slug = sanitize_key( $slug );

		if ( $slug === '' ) {
			return [
				'success' => false,
				'message' => __( 'A service is required.', 'surecookie' ),
			];
		}

		$removed = Declared_Cookies::get_instance()->forget_service( $slug )
			+ Matched_Resources::get_instance()->forget_service( $slug );

		return [
			'success' => true,
			'message' => __( 'Service cookies removed.', 'surecookie' ),
			'removed' => $removed,
		];
	}

	/**
	 * Get all custom cookies.
	 *
	 * @return array{success: bool, message: string, cookies: array<string, mixed>, count: int}
	 * @since 0.0.0-alpha.1
	 */
	public function get_custom_cookies(): array {
		$custom_cookies = Settings::get( 'custom_cookies' );

		return [
			'success' => true,
			'message' => __( 'Custom cookies retrieved successfully.', 'surecookie' ),
			'cookies' => $custom_cookies,
			'count'   => is_array( $custom_cookies ) ? count( $custom_cookies ) : 0,
		];
	}

	/**
	 * Create a new custom cookie.
	 *
	 * @param array<string, mixed> $data Cookie data with keys: name (required), category (required),
	 *                                   description, duration, provider, purpose, domain, type.
	 * @return array{success: bool, message: string, cookie?: array<string, mixed>}
	 * @since 0.0.0-alpha.1
	 */
	public function create_custom_cookie( array $data ): array {
		$name        = sanitize_text_field( $data['name'] ?? '' );
		$category    = sanitize_text_field( $data['category'] ?? '' );
		$description = sanitize_textarea_field( $data['description'] ?? '' );
		$duration    = sanitize_text_field( (string) ( $data['duration'] ?? '' ) );
		$provider    = sanitize_text_field( $data['provider'] ?? '' );
		$purpose     = sanitize_textarea_field( $data['purpose'] ?? '' );
		$domain      = Sanitize::cookie_domain( $data['domain'] ?? '' );
		$type        = sanitize_text_field( $data['type'] ?? 'custom' );
		// Provenance: set when this cookie is declared by adding a Known Service,
		// so uninstalling that service can safely remove only its own rows.
		$service_slug = sanitize_text_field( $data['service_slug'] ?? '' );

		// Validate required fields.
		if ( empty( $name ) || ! is_string( $name ) ) {
			return [
				'success' => false,
				'message' => __( 'Cookie name is required.', 'surecookie' ),
			];
		}

		if ( empty( $category ) || ! is_string( $category ) ) {
			return [
				'success' => false,
				'message' => __( 'Cookie category is required.', 'surecookie' ),
			];
		}

		// Get existing cookies.
		$custom_cookies = (array) Settings::get( 'custom_cookies' );

		// Reject a duplicate by name. The store is keyed by a generated id (not
		// the cookie name), so scan the values case-insensitively.
		foreach ( $custom_cookies as $existing_cookie ) {
			if ( is_array( $existing_cookie ) && isset( $existing_cookie['name'] )
				&& strcasecmp( (string) $existing_cookie['name'], $name ) === 0 ) {
				return [
					'success' => false,
					'code'    => 'duplicate_name',
					'message' => __( 'A cookie with this name already exists.', 'surecookie' ),
				];
			}
		}

		$cookie_id = Get::unique_id( 'cookie_' );

		// Calculate expiration date.
		$expires = null;
		$days    = absint( $duration );
		if ( $days ) {
			$expires = gmdate( 'Y-m-d H:i:s', (int) strtotime( "+{$days} days" ) );
		}

		$new_cookie = [
			'id'          => $cookie_id,
			'name'        => $name,
			'description' => $description,
			'category'    => $category,
			'duration'    => $duration,
			'provider'    => $provider,
			'purpose'     => $purpose,
			'domain'      => $domain,
			'type'        => $type,
			'expires'     => $expires,
		];

		if ( $service_slug !== '' ) {
			$new_cookie['service_slug'] = $service_slug;
		}

		// Set a new cookie.
		$custom_cookies[ $cookie_id ] = $new_cookie;
		Settings::update( 'custom_cookies', $custom_cookies );

		return [
			'success' => true,
			'message' => __( 'Custom cookie created successfully.', 'surecookie' ),
			'cookie'  => $new_cookie,
		];
	}

	/**
	 * Update an existing custom cookie.
	 *
	 * @param string               $cookie_id The cookie ID to update.
	 * @param array<string, mixed> $data      Fields to update (name, category, description, duration, provider, purpose, domain).
	 * @return array{success: bool, message: string, cookie?: array<string, mixed>}
	 * @since 0.0.0-alpha.1
	 */
	public function update_custom_cookie( string $cookie_id, array $data ): array {
		$cookie_id = sanitize_text_field( $cookie_id );

		if ( empty( $cookie_id ) ) {
			return [
				'success' => false,
				'message' => __( 'Cookie ID is required.', 'surecookie' ),
			];
		}

		$custom_cookies = (array) Settings::get( 'custom_cookies' );

		// A malformed row is reported as missing: writing into a scalar is a TypeError.
		if ( ! isset( $custom_cookies[ $cookie_id ] ) || ! is_array( $custom_cookies[ $cookie_id ] ) ) {
			return [
				'success' => false,
				'message' => __( 'Cookie not found.', 'surecookie' ),
			];
		}

		$cookie = $custom_cookies[ $cookie_id ];

		if ( Validate::not_empty( $data['name'] ?? '' ) ) {
			$cookie['name'] = sanitize_text_field( $data['name'] );
		}

		if ( Validate::not_empty( $data['category'] ?? '' ) ) {
			$cookie['category'] = sanitize_text_field( $data['category'] );
		}

		if ( Validate::not_empty( $data['description'] ?? '' ) ) {
			$cookie['description'] = sanitize_textarea_field( $data['description'] );
		}

		if ( Validate::not_empty( $data['provider'] ?? '' ) ) {
			$cookie['provider'] = sanitize_text_field( $data['provider'] );
		}

		if ( Validate::not_empty( $data['purpose'] ?? '' ) ) {
			$cookie['purpose'] = sanitize_textarea_field( $data['purpose'] );
		}

		if ( Validate::not_empty( $data['domain'] ?? '' ) ) {
			$cookie['domain'] = Sanitize::cookie_domain( $data['domain'] );
		}

		// Duration & expires.
		if ( absint( $data['duration'] ?? 0 ) ) {
			$days               = absint( $data['duration'] );
			$cookie['duration'] = $days;
			$cookie['expires']  = gmdate( 'Y-m-d H:i:s', (int) strtotime( "+{$days} days" ) );
		}

		$custom_cookies[ $cookie_id ] = $cookie;

		Settings::update( 'custom_cookies', $custom_cookies );

		return [
			'success' => true,
			'message' => __( 'Custom cookie updated successfully.', 'surecookie' ),
			'cookie'  => $cookie,
		];
	}

	/**
	 * Delete a custom cookie.
	 *
	 * @param string $cookie_id The cookie ID to delete.
	 * @return array{success: bool, message: string}
	 * @since 0.0.0-alpha.1
	 */
	public function delete_custom_cookie( string $cookie_id ): array {
		$cookie_id = sanitize_text_field( $cookie_id );

		if ( empty( $cookie_id ) ) {
			return [
				'success' => false,
				'message' => __( 'Cookie ID is required.', 'surecookie' ),
			];
		}

		$custom_cookies = (array) Settings::get( 'custom_cookies' );

		if ( ! isset( $custom_cookies[ $cookie_id ] ) ) {
			return [
				'success' => false,
				'message' => __( 'Cookie not found.', 'surecookie' ),
			];
		}

		unset( $custom_cookies[ $cookie_id ] );

		Settings::update( 'custom_cookies', $custom_cookies );

		return [
			'success' => true,
			'message' => __( 'Custom cookie deleted successfully.', 'surecookie' ),
		];
	}

	/**
	 * Move a scanned cookie from one category to another.
	 *
	 * @param string $cookie_name      The name of the cookie to move.
	 * @param string $current_category  The source category ID.
	 * @param string $new_category      The target category ID.
	 * @param string $domain            Domain that set the cookie, to tell same-named cookies apart.
	 * @return array{success: bool, message: string, cookie_name?: string, old_category?: string, new_category?: string}
	 * @since 0.0.0-alpha.1
	 */
	public function update_scanned_cookie_category( string $cookie_name, string $current_category, string $new_category, string $domain = '' ): array {
		return $this->update_scanned_cookie( $cookie_name, $current_category, [ 'category' => $new_category ], $domain );
	}

	/**
	 * Apply an admin's corrections to one scanned cookie.
	 *
	 * Patches the stored row so the change shows at once, and pins it so the next
	 * scan does not revert it. Both halves are needed: `inherit_from_replaced()`
	 * only blank-fills, so a non-blank incoming scan value would otherwise win.
	 *
	 * @since 1.5.0
	 * @param string                $cookie_name      Cookie to correct.
	 * @param string                $current_category Bucket it currently sits in.
	 * @param array<string, string> $changes          Any of category, purpose, duration, provider.
	 * @param string                $domain           Tells two same-named cookies apart.
	 * @return array{success: bool, message: string, cookie_name?: string, old_category?: string, new_category?: string}
	 */
	public function update_scanned_cookie( string $cookie_name, string $current_category, array $changes, string $domain = '' ): array {
		$cookie_name      = sanitize_text_field( $cookie_name );
		$current_category = sanitize_text_field( $current_category );
		$domain           = Sanitize::cookie_domain( $domain );

		$changes = $this->sanitize_scanned_changes( $changes );

		if ( $changes === null ) {
			return [
				'success' => false,
				'message' => __( 'Duration must be a whole number of days.', 'surecookie' ),
			];
		}

		$new_category = (string) ( $changes['category'] ?? '' );

		// Validate required fields.
		if ( empty( $cookie_name ) ) {
			return [
				'success' => false,
				'message' => __( 'Cookie name is required.', 'surecookie' ),
			];
		}

		if ( empty( $current_category ) ) {
			return [
				'success' => false,
				'message' => __( 'Current category is required.', 'surecookie' ),
			];
		}

		if ( array_key_exists( 'category', $changes ) && $new_category === '' ) {
			return [
				'success' => false,
				'message' => __( 'New category is required.', 'surecookie' ),
			];
		}

		if ( empty( $changes ) ) {
			return [
				'success' => false,
				'message' => __( 'Nothing to update.', 'surecookie' ),
			];
		}

		$moves = $new_category !== '' && $new_category !== $current_category;

		if ( $moves && ! in_array( $new_category, array_column( (array) Settings::get( 'cookie_categories' ), 'id' ), true ) ) {
			return [
				'success' => false,
				'message' => __( 'The selected category no longer exists. Please reload and try again.', 'surecookie' ),
			];
		}

		// A category-only call that changes nothing still succeeds, as it always has.
		if ( ! $moves && array_keys( $changes ) === [ 'category' ] ) {
			return [
				'success' => true,
				'message' => __( 'Cookie is already in the selected category.', 'surecookie' ),
			];
		}

		// Get scanned cookies from the database option.
		$scanned_cookies = Get::option( SURECOOKIE_SCANNED_COOKIES_OPTION, [], 'array' );

		if ( empty( $scanned_cookies ) || ! is_array( $scanned_cookies ) ) {
			return [
				'success' => false,
				'message' => __( 'No scanned cookies found.', 'surecookie' ),
			];
		}

		// Check if current category exists in scanned cookies.
		if ( ! isset( $scanned_cookies[ $current_category ] ) ) {
			return [
				'success' => false,
				'message' => __( 'Current category not found in scanned cookies.', 'surecookie' ),
			];
		}

		$index = $this->find_scanned_cookie( $scanned_cookies[ $current_category ], $cookie_name, $domain );

		if ( $index === null ) {
			return [
				'success' => false,
				'message' => __( 'Cookie not found in the specified category.', 'surecookie' ),
			];
		}

		$identity = $scanned_cookies[ $current_category ][ $index ];

		// A caller that echoes the row back unchanged is not correcting anything, and
		// pinning those values would freeze the scanner's own guesses for good.
		$pins = array_filter(
			$changes,
			static fn( $value, string $field ): bool => (string) $value !== (string) ( $identity[ $field ] ?? '' ),
			ARRAY_FILTER_USE_BOTH
		);

		// The policy table prefers a catalog description over the purpose, so a corrected
		// purpose has to land on both or it never reaches the page it exists to correct.
		if ( array_key_exists( 'purpose', $pins ) ) {
			$pins['description'] = $pins['purpose'];
		}

		// Read before remember_corrections() releases them: clearing a pin drops the
		// value it displaced, and that value is what the row has to fall back to.
		$restore = [];
		foreach ( [ 'purpose', 'description', 'duration', 'provider' ] as $field ) {
			if ( array_key_exists( $field, $pins ) && trim( (string) $pins[ $field ] ) === '' ) {
				$displaced         = CookieCategoryMemory::displaced_value( $identity, $field ) ?? '';
				$restore[ $field ] = $field === 'provider' ? Sanitize::vendor( $displaced ) : $displaced;
			}
		}

		CookieCategoryMemory::remember_corrections( [ $identity ], $pins );

		foreach ( [ 'purpose', 'description', 'duration', 'provider' ] as $field ) {
			if ( ! array_key_exists( $field, $pins ) ) {
				continue;
			}

			// Clearing a correction restores what the scanner reported, not an empty
			// cell: the pin is written over the row so the edit shows up immediately,
			// so blanking it would drop the value off the public cookie policy until
			// some later scan happened to run.
			$scanned_cookies[ $current_category ][ $index ][ $field ] =
				$restore[ $field ] ?? $pins[ $field ];
		}

		if ( $moves ) {
			$this->move_scanned_cookie( $scanned_cookies, $cookie_name, $current_category, $new_category, $domain );
		}

		// Save updated scanned cookies.
		Update::option( SURECOOKIE_SCANNED_COOKIES_OPTION, $scanned_cookies );

		return [
			'success'      => true,
			'message'      => $moves
				? __( 'Cookie category updated successfully.', 'surecookie' )
				: __( 'Cookie updated successfully.', 'surecookie' ),
			'cookie_name'  => $cookie_name,
			'old_category' => $current_category,
			'new_category' => $moves ? $new_category : $current_category,
		];
	}

	/**
	 * Assign a batch of cookies (custom and/or scanned) to one category.
	 *
	 * The target category must be a registered cookie category - assigning to
	 * an unknown ID would orphan cookies out of the admin list and the banner
	 * disclosure. Items are validated, deduplicated, then applied with a single
	 * option write per store (one for custom cookies, one for scanned). Items
	 * that fail (e.g. a cookie deleted meanwhile) are counted, not fatal.
	 *
	 * @param array<int, array<string, string>> $items        Cookies to move. Each item:
	 *                                                        type 'custom' needs `id`;
	 *                                                        type 'scanned' needs `name` + `current_category`,
	 *                                                        and may add `domain` to disambiguate.
	 * @param string                            $new_category Target category ID.
	 * @return array{success: bool, message: string, updated?: int, failed?: int}
	 * @since 1.3.0
	 */
	public function bulk_update_cookie_category( array $items, string $new_category ): array {
		$new_category = sanitize_text_field( $new_category );

		if ( empty( $new_category ) ) {
			return [
				'success' => false,
				'message' => __( 'New category is required.', 'surecookie' ),
			];
		}

		if ( empty( $items ) ) {
			return [
				'success' => false,
				'message' => __( 'No cookies selected.', 'surecookie' ),
			];
		}

		if ( count( $items ) > self::MAX_BULK_ITEMS ) {
			return [
				'success' => false,
				'message' => sprintf(
					/* translators: %d: maximum number of cookies per request. */
					__( 'Too many cookies in one request. Please select up to %d.', 'surecookie' ),
					self::MAX_BULK_ITEMS
				),
			];
		}

		$registered_ids = array_column( (array) Settings::get( 'cookie_categories' ), 'id' );
		if ( ! in_array( $new_category, $registered_ids, true ) ) {
			return [
				'success' => false,
				'message' => __( 'The selected category no longer exists. Please reload and try again.', 'surecookie' ),
			];
		}

		// Validate and deduplicate items, partitioned per store so each store
		// is read and written exactly once below.
		$custom_ids    = [];
		$scanned_moves = [];
		$failed        = 0;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				$failed++;
				continue;
			}

			$type = is_string( $item['type'] ?? null ) ? $item['type'] : '';

			if ( $type === 'custom' && is_string( $item['id'] ?? null ) && $item['id'] !== '' ) {
				$custom_ids[ sanitize_text_field( $item['id'] ) ] = true;
				continue;
			}

			if (
				$type === 'scanned' &&
				is_string( $item['name'] ?? null ) && $item['name'] !== '' &&
				is_string( $item['current_category'] ?? null ) && $item['current_category'] !== ''
			) {
				$name    = sanitize_text_field( $item['name'] );
				$current = sanitize_text_field( $item['current_category'] );
				// Domain keeps same-named cookies from different domains as
				// separate moves instead of collapsing them into one.
				$domain   = Sanitize::cookie_domain( is_string( $item['domain'] ?? null ) ? $item['domain'] : '' );
				$move_key = $name . '|' . $domain . '|' . $current;

				$scanned_moves[ $move_key ] = [
					'name'    => $name,
					'current' => $current,
					'domain'  => $domain,
				];
				continue;
			}

			$failed++;
		}

		$updated = 0;

		// Custom cookies: patch categories in one read-modify-write.
		if ( $custom_ids ) {
			$custom_cookies = (array) Settings::get( 'custom_cookies' );
			$dirty          = false;

			foreach ( array_keys( $custom_ids ) as $cookie_id ) {
				// Shape, not just presence: the ?? read below is safe on a scalar row but
				// the assignment that follows it is a TypeError.
				if ( ! isset( $custom_cookies[ $cookie_id ] ) || ! is_array( $custom_cookies[ $cookie_id ] ) ) {
					$failed++;
					continue;
				}

				if ( ( $custom_cookies[ $cookie_id ]['category'] ?? '' ) !== $new_category ) {
					$custom_cookies[ $cookie_id ]['category'] = $new_category;
					$dirty                                    = true;
				}
				$updated++;
			}

			if ( $dirty ) {
				Settings::update( 'custom_cookies', $custom_cookies );
			}
		}

		// Scanned cookies: apply all moves in memory, then one option write.
		if ( $scanned_moves ) {
			$scanned_cookies = Get::option( SURECOOKIE_SCANNED_COOKIES_OPTION, [], 'array' );
			$scanned_cookies = is_array( $scanned_cookies ) ? $scanned_cookies : [];
			$moved_cookies   = [];

			foreach ( $scanned_moves as $move ) {
				// Already in the target category: success no-op, no write needed.
				if ( $move['current'] === $new_category ) {
					$updated++;
					continue;
				}

				$moved = $this->move_scanned_cookie( $scanned_cookies, $move['name'], $move['current'], $new_category, $move['domain'] );

				if ( $moved !== null ) {
					$updated++;
					$moved_cookies[] = $moved;
				} else {
					$failed++;
				}
			}

			if ( $moved_cookies ) {
				Update::option( SURECOOKIE_SCANNED_COOKIES_OPTION, $scanned_cookies );

				// Remember the choices so the next scan does not revert them.
				CookieCategoryMemory::remember( $moved_cookies, $new_category );
			}
		}

		return [
			'success' => $updated > 0,
			'message' => $updated > 0
				? sprintf(
					/* translators: %d: number of cookies updated. */
					_n(
						'%d cookie moved to the selected category.',
						'%d cookies moved to the selected category.',
						$updated,
						'surecookie'
					),
					$updated
				)
				: __( 'No cookies could be updated.', 'surecookie' ),
			'updated' => $updated,
			'failed'  => $failed,
		];
	}

	/**
	 * Remove a scanned cookie from the stored set.
	 *
	 * Deliberately not remembered. A cookie that is genuinely gone stays gone, because
	 * the scanner cannot see it; one that is still live simply returns on the next scan.
	 * That is what makes this safe to offer at all: delete cannot be used to hide a live
	 * tracker from a cookie policy, so it needs no suppression list and must not grow one.
	 *
	 * @since 1.5.0
	 * @param string $cookie_name Cookie to remove.
	 * @param string $category    Bucket it sits in.
	 * @param string $domain      Tells two same-named cookies apart.
	 * @return array{success: bool, message: string, cookie_name?: string, category?: string}
	 */
	public function delete_scanned_cookie( string $cookie_name, string $category, string $domain = '' ): array {
		$cookie_name = sanitize_text_field( $cookie_name );
		$category    = sanitize_text_field( $category );
		$domain      = Sanitize::cookie_domain( $domain );

		if ( empty( $cookie_name ) || empty( $category ) ) {
			return [
				'success' => false,
				'message' => __( 'Cookie name and category are required.', 'surecookie' ),
			];
		}

		$scanned_cookies = Get::option( SURECOOKIE_SCANNED_COOKIES_OPTION, [], 'array' );

		if ( ! is_array( $scanned_cookies ) || ! isset( $scanned_cookies[ $category ] ) || ! is_array( $scanned_cookies[ $category ] ) ) {
			return [
				'success' => false,
				'message' => __( 'Category not found in scanned cookies.', 'surecookie' ),
			];
		}

		$index = $this->find_scanned_cookie( $scanned_cookies[ $category ], $cookie_name, $domain, true );

		if ( $index === null ) {
			return [
				'success' => false,
				'message' => __( 'Cookie not found in the specified category.', 'surecookie' ),
			];
		}

		array_splice( $scanned_cookies[ $category ], $index, 1 );

		Update::option( SURECOOKIE_SCANNED_COOKIES_OPTION, $scanned_cookies );

		return [
			'success'     => true,
			'message'     => __( 'Cookie removed.', 'surecookie' ),
			'cookie_name' => $cookie_name,
			'category'    => $category,
		];
	}

	/**
	 * Name the service a declared row belongs to, for rows stored before the
	 * field existed.
	 *
	 * Admin read path only: the row's owner is what the remove action addresses,
	 * and without this a row from an older scan would remove only itself and
	 * leave the rest of its service on the policy.
	 *
	 * @since 1.5.1
	 * @param mixed $cookies Stored rows for one category.
	 * @return array<int, array<string, mixed>>
	 */
	private static function with_service_slug( $cookies ): array {
		$rows = [];

		foreach ( is_array( $cookies ) ? $cookies : [] as $cookie ) {
			if ( is_array( $cookie ) && empty( $cookie['service_slug'] ) ) {
				$parts = explode( ':', (string) ( $cookie['signature_id'] ?? '' ), 3 );

				if ( count( $parts ) === 3 && $parts[0] === 'declared' && $parts[1] !== '' ) {
					$cookie['service_slug']  = $parts[1];
					$cookie['service_label'] = Declared_Cookies::get_instance()->label_for( $parts[1] );
				}
			}

			$rows[] = $cookie;
		}

		return $rows;
	}

	/**
	 * Keep only the fields an admin may correct, sanitized.
	 *
	 * An explicitly empty value is kept, not dropped: it is how a correction is
	 * cleared so the scanned value shows again.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $changes Raw input.
	 * @return array<string, string>|null Sanitized values, or null when one is invalid.
	 */
	private function sanitize_scanned_changes( array $changes ): ?array {
		$clean = [];

		foreach ( [ 'category', 'provider' ] as $field ) {
			if ( array_key_exists( $field, $changes ) ) {
				$clean[ $field ] = sanitize_text_field( (string) $changes[ $field ] );
			}
		}

		if ( array_key_exists( 'purpose', $changes ) ) {
			$clean['purpose'] = Sanitize::textarea( (string) $changes['purpose'] );
		}

		if ( array_key_exists( 'duration', $changes ) ) {
			// A day count, matching the custom-cookie field. Empty clears the pin, and
			// anything else is refused rather than absint()'d into a silent clear.
			$duration = trim( (string) $changes['duration'] );

			if ( $duration !== '' && ! ctype_digit( $duration ) ) {
				return null;
			}

			$clean['duration'] = $duration === '' ? '' : (string) absint( $duration );
		}

		return $clean;
	}

	/**
	 * Move one scanned cookie between category groups, in memory.
	 *
	 * Shared by the single-item update and the bulk update so the splice/append
	 * logic exists once. Does not persist - callers save the option themselves,
	 * which lets the bulk path apply many moves with a single write. The moved
	 * row is returned so callers can record the assignment against the cookie's
	 * full identity (name + domain + provider).
	 *
	 * A name can appear more than once in one category - two cookies called `id`
	 * set by different domains are separate cookies that the scanner keeps apart
	 * by signature, and both may be classified the same way. So the domain the
	 * request came with is matched first, and a name-only match is the fallback
	 * for a caller that did not send one.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $scanned_cookies  Scanned cookies, grouped by category (by reference).
	 * @param string                                          $cookie_name      Cookie to move.
	 * @param string                                          $current_category Source category ID.
	 * @param string                                          $new_category     Target category ID.
	 * @param string                                          $domain           Domain that set the cookie, when known.
	 * @return array<string, mixed>|null The moved cookie, or null when it was not found.
	 * @since 1.3.0
	 */
	private function move_scanned_cookie( array &$scanned_cookies, string $cookie_name, string $current_category, string $new_category, string $domain = '' ): ?array {
		if ( ! isset( $scanned_cookies[ $current_category ] ) || ! is_array( $scanned_cookies[ $current_category ] ) ) {
			return null;
		}

		$index = $this->find_scanned_cookie( $scanned_cookies[ $current_category ], $cookie_name, $domain );

		if ( $index === null ) {
			return null;
		}

		$cookie = $scanned_cookies[ $current_category ][ $index ];

		array_splice( $scanned_cookies[ $current_category ], $index, 1 );

		if ( ! isset( $scanned_cookies[ $new_category ] ) ) {
			$scanned_cookies[ $new_category ] = [];
		}

		// Keep the row's own category field in step with its bucket, so a
		// stored cookie never reports a category it no longer sits in.
		$cookie['category'] = $new_category;

		$scanned_cookies[ $new_category ][] = $cookie;

		return $cookie;
	}

	/**
	 * Locate a cookie within one category group.
	 *
	 * Prefers an exact name+domain match so same-named cookies from different
	 * domains are never confused, and falls back to the first name match when no
	 * domain was supplied or none matched (a stored domain can differ from what
	 * the admin's page was rendered with).
	 *
	 * @param array<int, array<string, mixed>> $cookies Cookies in one category.
	 * @param string                           $name    Cookie name.
	 * @param string                           $domain  Domain to prefer, or an empty string.
	 * @param bool                             $strict  Refuse an ambiguous match, for destructive callers.
	 * @return int|null Index of the match, or null when there is none.
	 * @since 1.3.0
	 */
	private function find_scanned_cookie( array $cookies, string $name, string $domain, bool $strict = false ): ?int {
		$normalize = static fn( string $value ): string => strtolower( ltrim( trim( $value ), '.' ) );
		$wanted    = $normalize( $domain );
		$namesakes = [];

		foreach ( $cookies as $index => $cookie ) {
			if ( ! is_array( $cookie ) || ( $cookie['name'] ?? null ) !== $name ) {
				continue;
			}

			if ( $normalize( (string) ( $cookie['domain'] ?? '' ) ) === $wanted ) {
				return (int) $index;
			}

			$namesakes[] = (int) $index;
		}

		// A move tolerates a stale domain from an out-of-date screen. A delete must not:
		// a supplied domain has to match, and without one only a sole namesake is unambiguous.
		if ( $strict && ( $wanted !== '' || count( $namesakes ) > 1 ) ) {
			return null;
		}

		return $namesakes[0] ?? null;
	}
}
