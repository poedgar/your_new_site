<?php
/**
 * Script Blocking Service
 *
 * Shared read/write layer for the three settings that decide what gets blocked
 * before consent: excluded_scan_resources, resource_category_overrides and
 * custom_blocked_scripts.
 *
 * Writes always operate on the RAW stored arrays, never on normalized output:
 * the normalizer lowercases values and explodes `keywords` into a list, so
 * writing a normalized row back would corrupt the stored shape.
 *
 * @package SureCookie\Inc\Services
 * @since   1.4.0
 */

namespace SureCookie\Inc\Services;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Sanitize;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Modules\ScriptBlocking\Blocking_Surface;
use SureCookie\Inc\Modules\ScriptBlocking\Resource_Categories;
use SureCookie\Inc\Modules\ScriptBlocking\Scan_Scripts;
use SureCookie\Inc\Modules\ScriptBlocking\Utils as BlockingUtils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ScriptBlockingService
 *
 * @since 1.4.0
 */
class ScriptBlockingService {
	/**
	 * Setting holding "do not block" entries.
	 *
	 * @since 1.4.0
	 */
	public const SETTING_EXCLUDED = 'excluded_scan_resources';

	/**
	 * Setting holding per-domain category reassignments.
	 *
	 * @since 1.4.0
	 */
	public const SETTING_OVERRIDES = 'resource_category_overrides';

	/**
	 * Setting holding admin-authored blocking rules.
	 *
	 * @since 1.4.0
	 */
	public const SETTING_RULES = 'custom_blocked_scripts';

	/**
	 * Shortest accepted pattern.
	 *
	 * Exclusions and rules are substring-matched at runtime, so a token like
	 * "com" or "js" would unblock nearly every third-party resource.
	 *
	 * @since 1.4.0
	 */
	private const MIN_PATTERN_LENGTH = 4;

	/**
	 * The raw scanned-resources payload, annotated by modules.
	 *
	 * Keeps the `{ scripts, iframes, metadata }` shape the admin table reads.
	 *
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	public function get_scanned_resources_payload(): array {
		return $this->annotate_rows( Get::scanned_resources_for_display() );
	}

	/**
	 * Scan-detected resources as one flat, filterable list.
	 *
	 * @param string $type          'script', 'iframe' or 'all'.
	 * @param bool   $excluded_only Only resources currently set to "do not block".
	 * @param string $search        Substring match on domain or vendor.
	 * @return array<int, array<string, mixed>>
	 * @since 1.4.0
	 */
	public function list_resources( string $type, bool $excluded_only, string $search ): array {
		$payload = $this->get_scanned_resources_payload();
		$search  = strtolower( trim( $search ) );
		$rows    = [];

		foreach ( [
			'script' => 'scripts',
			'iframe' => 'iframes',
		] as $kind => $bucket ) {
			if ( $type !== 'all' && $type !== $kind ) {
				continue;
			}

			foreach ( (array) ( $payload[ $bucket ] ?? [] ) as $resource ) {
				if ( ! is_array( $resource ) ) {
					continue;
				}

				$domain   = (string) ( $resource['domain'] ?? '' );
				$vendor   = (string) ( $resource['vendor'] ?? '' );
				$excluded = Resource_Categories::is_excluded_domain( $domain, $kind );

				if ( $excluded_only && ! $excluded ) {
					continue;
				}

				if ( $search !== '' && strpos( strtolower( $domain . ' ' . $vendor ), $search ) === false ) {
					continue;
				}

				$scanner_category = (string) ( $resource['category'] ?? '' );

				$rows[] = [
					'type'               => $kind,
					'domain'             => $domain,
					'url'                => (string) ( $resource['url'] ?? '' ),
					// Every catalog pattern this row stands for. A service can have
					// several on one host and the row covers all of them.
					'patterns'           => array_values( array_filter( array_map( 'strval', (array) ( $resource['patterns'] ?? [] ) ) ) ),
					'vendor'             => $vendor,
					// Scanner rows carry no `source`; only service-injected rows do.
					'source'             => (string) ( $resource['source'] ?? 'scan' ),
					'scanned_at'         => isset( $resource['scanned_at'] ) ? (string) $resource['scanned_at'] : null,
					'scanner_category'   => $scanner_category,
					// Catalog first, then the admin override: the same order the
					// blocker applies, so this reports what actually gates.
					'effective_category' => Resource_Categories::gated_category( $domain, $scanner_category, $kind ),
					'excluded'           => $excluded,
					'key'                => $this->scoped_key( $kind, $domain ),
					// Annotations come from modules that boot at init:999, so
					// they can legitimately be missing on an early CLI call.
					'gcm_managed'        => ! empty( $resource['gcmManaged'] ),
					'service_slug'       => (string) ( $resource['service_slug'] ?? '' ),
					// A row is not proof of blocking; say when no pass reaches it.
					'unenforced_reason'  => (string) ( $resource['unenforced_reason'] ?? '' ),
					'enforced'           => ( $resource['unenforced_reason'] ?? '' ) === '',
				];
			}
		}

		return $rows;
	}

	/**
	 * Reassign a scan-detected resource to another category.
	 *
	 * @param string $type     'script' or 'iframe'.
	 * @param string $domain   Resource domain.
	 * @param string $category Target cookie category ID.
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	public function recategorize_resource( string $type, string $domain, string $category ): array {
		$domain   = trim( $domain );
		$category = sanitize_key( $category );

		if ( $category === '' ) {
			return $this->failure( __( 'A resource domain and a target category are both required.', 'surecookie' ) );
		}

		// Same guard as exclude_resource: the override is substring-matched
		// against full resource URLs, so a short token would regate every
		// script on the site.
		$guard = $this->reject_over_broad( $domain );

		if ( $guard !== null ) {
			return $guard;
		}

		if ( ! $this->category_exists( $category ) ) {
			return $this->failure( __( 'That category does not exist. Use surecookie/cookie-categories with action "list" to see valid IDs.', 'surecookie' ) );
		}

		$key       = $this->scoped_key( $type, $domain );
		$overrides = $this->raw_array( self::SETTING_OVERRIDES );
		$previous  = isset( $overrides[ $key ] ) ? (string) $overrides[ $key ] : null;

		$overrides[ $key ] = $category;

		// A legacy bare-domain override applies to every kind and would keep
		// winning for the other kind; drop it once a scoped key exists.
		$legacy_present = isset( $overrides[ $domain ] );
		unset( $overrides[ $domain ] );

		$this->save( self::SETTING_OVERRIDES, $overrides );

		return $this->success(
			__( 'Resource category updated.', 'surecookie' ),
			[
				'type'               => $type,
				'domain'             => $domain,
				'key'                => $key,
				'previous_category'  => $previous,
				'category'           => $category,
				'legacy_key_removed' => $legacy_present,
			]
		);
	}

	/**
	 * Stop blocking a scan-detected resource.
	 *
	 * @param string $type   'script' or 'iframe'.
	 * @param string $domain Resource domain.
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	public function exclude_resource( string $type, string $domain ): array {
		$domain = trim( $domain );
		$guard  = $this->reject_over_broad( $domain );

		if ( $guard !== null ) {
			return $guard;
		}

		$key      = $this->scoped_key( $type, $domain );
		$excluded = array_values( $this->raw_array( self::SETTING_EXCLUDED ) );
		$already  = in_array( $key, $excluded, true );

		if ( ! $already ) {
			$excluded[] = $key;
			$this->save( self::SETTING_EXCLUDED, $excluded );
		}

		return $this->success(
			$already
				? __( 'That resource was already excluded from blocking.', 'surecookie' )
				: __( 'Resource excluded from blocking. Note this covers scripts and iframes only, not embed or object tags.', 'surecookie' ),
			[
				'type'             => $type,
				'domain'           => $domain,
				'key'              => $key,
				'already_excluded' => $already,
			]
		);
	}

	/**
	 * Resume blocking a scan-detected resource.
	 *
	 * @param string $type   'script' or 'iframe'.
	 * @param string $domain Resource domain.
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	public function include_resource( string $type, string $domain ): array {
		$domain = trim( $domain );

		if ( $domain === '' ) {
			return $this->failure( __( 'A resource domain is required.', 'surecookie' ) );
		}

		$key      = $this->scoped_key( $type, $domain );
		$excluded = array_values( $this->raw_array( self::SETTING_EXCLUDED ) );
		$before   = count( $excluded );

		// Drop the scoped key and any legacy bare-domain entry, which would
		// otherwise keep the resource excluded for every kind.
		$excluded = array_values(
			array_filter(
				$excluded,
				static function ( $entry ) use ( $key, $domain ): bool {
					$entry = trim( Sanitize::scalar( $entry ) );
					return $entry !== $key && $entry !== $domain;
				}
			)
		);

		$removed = $before !== count( $excluded );

		if ( $removed ) {
			$this->save( self::SETTING_EXCLUDED, $excluded );
		}

		return $this->success(
			$removed
				? __( 'Resource will be blocked again until consent.', 'surecookie' )
				: __( 'That resource was not excluded, so nothing changed.', 'surecookie' ),
			[
				'type'         => $type,
				'domain'       => $domain,
				'key'          => $key,
				'was_excluded' => $removed,
			]
		);
	}

	/**
	 * Admin-authored blocking rules, normalized.
	 *
	 * @param string $category Optional category filter.
	 * @param string $type     Optional type filter ('script', 'iframe', 'any').
	 * @return array<int, array<string, mixed>>
	 * @since 1.4.0
	 */
	public function list_rules( string $category, string $type ): array {
		$rules = [];

		foreach ( $this->raw_array( self::SETTING_RULES ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$value = strtolower( trim( Sanitize::scalar( $entry['value'] ?? '' ) ) );

			if ( $value === '' ) {
				continue;
			}

			$entry_category = sanitize_key( Sanitize::scalar( $entry['category'] ?? '' ) );
			$entry_category = $entry_category !== '' ? $entry_category : 'uncategorized';
			$entry_type     = Sanitize::scalar( $entry['type'] ?? '' );
			$entry_type     = in_array( $entry_type, [ 'script', 'iframe' ], true ) ? $entry_type : 'any';
			$entry_location = Sanitize::scalar( $entry['location'] ?? '' );

			if ( $category !== '' && $entry_category !== $category ) {
				continue;
			}

			if ( $type !== '' && $entry_type !== $type ) {
				continue;
			}

			$rules[] = [
				'value'       => $value,
				'name'        => trim( Sanitize::scalar( $entry['name'] ?? '' ) ),
				'category'    => $entry_category,
				'type'        => $entry_type,
				'location'    => in_array( $entry_location, [ 'head', 'body', 'footer' ], true ) ? $entry_location : 'any',
				'path'        => strtolower( trim( Sanitize::scalar( $entry['path'] ?? '' ) ) ),
				'keywords'    => $this->split_keywords( Sanitize::scalar( $entry['keywords'] ?? '' ) ),
				// Matches the key Custom_Scripts builds for the blocking dataset.
				'service_key' => 'custom-' . md5( $value ),
			];
		}

		return $rules;
	}

	/**
	 * Add a blocking rule.
	 *
	 * @param array<string, mixed> $rule Rule fields.
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	public function create_rule( array $rule ): array {
		$value = strtolower( trim( Sanitize::scalar( $rule['value'] ?? '' ) ) );
		$guard = $this->reject_over_broad( $value );

		if ( $guard !== null ) {
			return $guard;
		}

		$category = sanitize_key( (string) ( $rule['category'] ?? '' ) );

		if ( $category === '' || ! $this->category_exists( $category ) ) {
			return $this->failure( __( 'A valid category is required. Use surecookie/cookie-categories with action "list" to see valid IDs.', 'surecookie' ) );
		}

		$stored = array_values( $this->raw_array( self::SETTING_RULES ) );

		foreach ( $stored as $entry ) {
			if ( is_array( $entry ) && strtolower( trim( Sanitize::scalar( $entry['value'] ?? '' ) ) ) === $value ) {
				return $this->failure( __( 'A rule for that pattern already exists. Use "update_rule" instead.', 'surecookie' ) );
			}
		}

		$stored[] = $this->build_row( $rule, $value, $category );

		$this->save( self::SETTING_RULES, $stored );

		return $this->success(
			__( 'Blocking rule created.', 'surecookie' ),
			[
				'rule'        => $this->list_rules_row( $value ),
				'rules_count' => count( $stored ),
			]
		);
	}

	/**
	 * Change an existing blocking rule.
	 *
	 * @param string               $value   Pattern identifying the rule.
	 * @param array<string, mixed> $changes Fields to change.
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	public function update_rule( string $value, array $changes ): array {
		$value  = strtolower( trim( $value ) );
		$stored = array_values( $this->raw_array( self::SETTING_RULES ) );
		$index  = $this->find_rule_index( $stored, $value );

		if ( $index === null ) {
			return $this->failure( __( 'No blocking rule matches that pattern. Use "list_rules" to see the current rules.', 'surecookie' ) );
		}

		$current   = is_array( $stored[ $index ] ) ? $stored[ $index ] : [];
		$new_value = isset( $changes['new_value'] )
			? strtolower( trim( (string) $changes['new_value'] ) )
			: $value;

		if ( $new_value !== $value ) {
			$guard = $this->reject_over_broad( $new_value );

			if ( $guard !== null ) {
				return $guard;
			}

			// create_rule refuses duplicates; a rename must too, or two rows
			// share a pattern and find_rule_index can only ever reach the first.
			if ( $this->find_rule_index( $stored, $new_value ) !== null ) {
				return $this->failure( __( 'Another rule already uses that pattern.', 'surecookie' ) );
			}
		}

		$category_changed = array_key_exists( 'category', $changes );
		$category         = $category_changed
			? sanitize_key( (string) $changes['category'] )
			: sanitize_key( (string) ( $current['category'] ?? '' ) );

		// An explicit category that sanitizes away is a bad value, not "unset":
		// letting it through would silently regate the rule as uncategorized.
		if ( $category_changed && $category === '' ) {
			return $this->failure( __( 'That category is not a valid ID. Use surecookie/cookie-categories with action "list" to see valid IDs.', 'surecookie' ) );
		}

		if ( $category !== '' && ! $this->category_exists( $category ) ) {
			return $this->failure( __( 'That category does not exist. Use surecookie/cookie-categories with action "list" to see valid IDs.', 'surecookie' ) );
		}

		$merged = array_merge( $current, $changes );
		unset( $merged['new_value'] );

		$stored[ $index ] = $this->build_row( $merged, $new_value, $category );

		$this->save( self::SETTING_RULES, $stored );

		return $this->success(
			__( 'Blocking rule updated.', 'surecookie' ),
			[
				'rule'     => $this->list_rules_row( $new_value ),
				'previous' => $current,
			]
		);
	}

	/**
	 * Remove a blocking rule.
	 *
	 * @param string $value Pattern identifying the rule.
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	public function delete_rule( string $value ): array {
		$value  = strtolower( trim( $value ) );
		$stored = array_values( $this->raw_array( self::SETTING_RULES ) );
		$index  = $this->find_rule_index( $stored, $value );

		if ( $index === null ) {
			return $this->failure( __( 'No blocking rule matches that pattern. Use "list_rules" to see the current rules.', 'surecookie' ) );
		}

		$deleted = $stored[ $index ];
		unset( $stored[ $index ] );
		$stored = array_values( $stored );

		$this->save( self::SETTING_RULES, $stored );

		return $this->success(
			__( 'Blocking rule deleted. The resources it covered are no longer blocked by it.', 'surecookie' ),
			[
				'deleted'     => $deleted,
				'rules_count' => count( $stored ),
			]
		);
	}

	/**
	 * Whether blocking is actually running right now.
	 *
	 * Rides on every write response: with blocking off, a rule change alters a
	 * setting but nothing about runtime behaviour, and an agent would otherwise
	 * report a compliance change that is not happening.
	 *
	 * @return bool
	 * @since 1.4.0
	 */
	public function is_blocking_active(): bool {
		return BlockingUtils::is_blocking_enabled();
	}

	/**
	 * Add `gated_category` and `unenforced_reason` to every scanned resource.
	 *
	 * The stored row keeps whatever the scanner guessed, but the blocker drops a
	 * scan row whose domain is already a catalog pattern and gates it under the
	 * catalog's category instead. Without this the table reported the scanner's
	 * guess, so a domain the catalog now treats as essential still displayed as
	 * Marketing while loading before consent. `category` is left untouched: it is
	 * the scanner's provenance, and `list_resources()` reports it as such.
	 *
	 * `unenforced_reason` says whether the blocker will gate the row at all. A
	 * row is not evidence of blocking: scanners file a stylesheet and an image
	 * alike as a script, and no pass rewrites an `<img>`.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $resources Scanned resources payload.
	 * @return array<string, mixed>
	 */
	private function annotate_rows( array $resources ): array {
		foreach ( [
			'scripts' => 'script',
			'iframes' => 'iframe',
		] as $bucket => $kind ) {
			if ( ! is_array( $resources[ $bucket ] ?? null ) ) {
				continue;
			}

			foreach ( $resources[ $bucket ] as $i => $resource ) {
				if ( ! is_array( $resource ) ) {
					continue;
				}

				$domain = trim( (string) ( $resource['domain'] ?? '' ) );
				if ( $domain === '' ) {
					continue;
				}

				$gated = Resource_Categories::catalog_category(
					$domain,
					(string) ( $resource['category'] ?? '' ),
					$kind
				);

				$resources[ $bucket ][ $i ]['gated_category']    = $gated;
				$resources[ $bucket ][ $i ]['unenforced_reason'] = Blocking_Surface::unenforced_reason(
					$domain,
					// The override is what actually gates, and the screen shows
					// its own unsaved edits on top of this.
					Resource_Categories::resolve( $domain, $gated, $kind )
				);
			}
		}

		return $resources;
	}

	/**
	 * Build the stored row shape from input.
	 *
	 * `keywords` is stored as a comma string, which is what the normalizer
	 * explodes; writing a list here would silently break the rule.
	 *
	 * @param array<string, mixed> $rule     Input fields.
	 * @param string               $value    Normalized pattern.
	 * @param string               $category Category ID.
	 * @return array<string, string>
	 * @since 1.4.0
	 */
	private function build_row( array $rule, string $value, string $category ): array {
		$type     = (string) ( $rule['type'] ?? 'any' );
		$location = (string) ( $rule['location'] ?? 'any' );
		$keywords = $rule['keywords'] ?? '';

		if ( is_array( $keywords ) ) {
			$keywords = implode( ',', array_map( 'strval', $keywords ) );
		}

		return [
			'name'     => sanitize_text_field( (string) ( $rule['name'] ?? '' ) ),
			'value'    => $value,
			'category' => $category !== '' ? $category : 'uncategorized',
			'type'     => in_array( $type, [ 'script', 'iframe' ], true ) ? $type : 'any',
			'location' => in_array( $location, [ 'head', 'body', 'footer' ], true ) ? $location : 'any',
			'path'     => strtolower( trim( (string) ( $rule['path'] ?? '' ) ) ),
			'keywords' => sanitize_text_field( (string) $keywords ),
		];
	}

	/**
	 * Locate a stored rule by pattern, comparing normalized values.
	 *
	 * The list_rules reports the normalized value, so matching raw equality here
	 * would leave any rule stored with different case or padding undeletable.
	 *
	 * @param array<int, mixed> $stored Raw stored rules.
	 * @param string            $value  Normalized pattern.
	 * @return int|null
	 * @since 1.4.0
	 */
	private function find_rule_index( array $stored, string $value ): ?int {
		foreach ( $stored as $index => $entry ) {
			if ( is_array( $entry ) && strtolower( trim( Sanitize::scalar( $entry['value'] ?? '' ) ) ) === $value ) {
				return (int) $index;
			}
		}

		return null;
	}

	/**
	 * One normalized rule row by value.
	 *
	 * @param string $value Normalized pattern.
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	private function list_rules_row( string $value ): array {
		foreach ( $this->list_rules( '', '' ) as $rule ) {
			if ( $rule['value'] === $value ) {
				return $rule;
			}
		}

		return [];
	}

	/**
	 * Refuse a pattern too broad to be meant.
	 *
	 * @param string $pattern Candidate pattern.
	 * @return array<string, mixed>|null Failure payload, or null when acceptable.
	 * @since 1.4.0
	 */
	private function reject_over_broad( string $pattern ): ?array {
		if ( $pattern === '' ) {
			return $this->failure( __( 'A domain or URL pattern is required.', 'surecookie' ) );
		}

		$looks_like_path = strpos( $pattern, '/' ) !== false;

		if ( strlen( $pattern ) < self::MIN_PATTERN_LENGTH || ( ! $looks_like_path && strpos( $pattern, '.' ) === false ) ) {
			return $this->failure(
				__( 'That pattern is too broad. Patterns are matched as substrings, so a bare token like "com" would match nearly every third-party resource on the site. Use a full domain such as "example.com" or a URL path.', 'surecookie' )
			);
		}

		return null;
	}

	/**
	 * Split a stored comma-separated keyword string.
	 *
	 * @param string $keywords Raw stored value.
	 * @return array<int, string>
	 * @since 1.4.0
	 */
	private function split_keywords( string $keywords ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', $keywords ) ) ) );
	}

	/**
	 * Scoped storage key for a resource.
	 *
	 * @param string $type   'script' or 'iframe'.
	 * @param string $domain Resource domain.
	 * @return string
	 * @since 1.4.0
	 */
	private function scoped_key( string $type, string $domain ): string {
		return $type . '::' . trim( $domain );
	}

	/**
	 * Whether a cookie category ID is registered.
	 *
	 * @param string $category Category ID.
	 * @return bool
	 * @since 1.4.0
	 */
	private function category_exists( string $category ): bool {
		$registered = array_column( (array) Settings::get( 'cookie_categories' ), 'id' );

		return in_array( $category, $registered, true );
	}

	/**
	 * Read a setting as a raw array.
	 *
	 * @param string $key Setting key.
	 * @return array<mixed>
	 * @since 1.4.0
	 */
	private function raw_array( string $key ): array {
		$stored = Settings::get( $key );

		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Persist a setting and drop the blocking caches it feeds.
	 *
	 * @param string       $key   Setting key.
	 * @param array<mixed> $value New value.
	 * @return void
	 * @since 1.4.0
	 */
	private function save( string $key, array $value ): void {
		Settings::update( $key, $value );

		// Resets the scanned-resource memo and, through it, the exclusion and
		// override caches, so a follow-up read in the same request is current.
		Scan_Scripts::clear_cache();

		// The cache integration purges the page cache from here; without it a
		// blocking change leaves cached pages serving the old behaviour.
		/** This action is documented in inc/api/settings.php */
		do_action( 'surecookie_admin_settings_after_processing', Settings::get() );
	}

	/**
	 * Success envelope carrying the live blocking state.
	 *
	 * @param string               $message Result message.
	 * @param array<string, mixed> $data    Payload.
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	private function success( string $message, array $data ): array {
		$data['blocking_active'] = $this->is_blocking_active();

		return [
			'success' => true,
			'message' => $message,
			'data'    => $data,
		];
	}

	/**
	 * Failure envelope.
	 *
	 * @param string $message Reason.
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	private function failure( string $message ): array {
		return [
			'success' => false,
			'message' => $message,
			'data'    => [],
		];
	}
}
