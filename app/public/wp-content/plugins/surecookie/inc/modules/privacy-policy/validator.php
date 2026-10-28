<?php
/**
 * Business Details validation and completeness rules.
 *
 * Single source of truth for which identity fields are required. Both the
 * publish gate and the admin notice read from here, so "cannot publish" and
 * "complete your business details" can never disagree about what is missing.
 *
 * @package SureCookie\Inc\Modules\PrivacyPolicy
 * @since 1.5.0
 */

namespace SureCookie\Inc\Modules\PrivacyPolicy;

use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Utils\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Validator
 *
 * @since 1.5.0
 */
final class Validator {
	/**
	 * Setting key holding the controller identity.
	 *
	 * @since 1.5.0
	 */
	public const DETAILS_KEY = 'organization_details';

	/**
	 * Setting key holding the selected privacy regimes.
	 *
	 * @since 1.5.0
	 */
	public const JURISDICTIONS_KEY = 'privacy_jurisdictions';

	/**
	 * Regimes whose sections the generator can emit.
	 *
	 * Named per statute rather than per region: COPPA is not a region, and a
	 * region code would imply the law applies by visitor location, which is the
	 * opposite of how any of these attach. EU and UK are separate because they
	 * have different supervisory authorities and transfer mechanisms.
	 *
	 * @since 1.5.0
	 */
	public const JURISDICTIONS = [ 'gdpr', 'uk_gdpr', 'cpra', 'dpdp', 'coppa' ];

	/**
	 * Identity fields that must be filled before the policy can be published.
	 *
	 * @since 1.5.0
	 */
	public const REQUIRED_FIELDS = [
		'legal_entity_name',
		'postal_address_lines',
		'country',
		'privacy_contact_email',
	];

	/**
	 * Fields required only when a particular regime is selected.
	 *
	 * Must cover every field jurisdiction_is_satisfied() checks, or a site reads
	 * as complete and still publishes an unfinished section.
	 *
	 * @since 1.5.0
	 */
	public const CONDITIONAL_FIELDS = [
		'dpdp'    => [ 'grievance_officer_name', 'grievance_officer_email' ],
		'gdpr'    => [ 'transfer_safeguard_text' ],
		'uk_gdpr' => [ 'transfer_safeguard_text' ],
		'cpra'    => [ 'ccpa_sells_or_shares' ],
	];

	/**
	 * Maximum stored address lines.
	 *
	 * @since 1.5.0
	 */
	private const MAX_ADDRESS_LINES = 8;

	/**
	 * Declared defaults for the nested identity value.
	 *
	 * Read from the registry rather than duplicated, so the shape is declared
	 * once in Options::get_all_configurations().
	 *
	 * @since 1.5.0
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		$configured = Options::get_all_configurations()[ self::DETAILS_KEY ]['default'] ?? [];
		return is_array( $configured ) ? $configured : [];
	}

	/**
	 * Stored identity, merged over the declared defaults.
	 *
	 * Settings::get() merges only at the top level, so a partially stored value
	 * replaces the whole nested default and a newly added field would read as
	 * undefined on an existing install. Never read the raw setting directly.
	 *
	 * @since 1.5.0
	 * @return array<string, mixed>
	 */
	public static function details(): array {
		$stored = Settings::get( self::DETAILS_KEY );
		return wp_parse_args( is_array( $stored ) ? $stored : [], self::defaults() );
	}

	/**
	 * Selected regimes, clamped to the known set.
	 *
	 * @since 1.5.0
	 * @return array<int, string>
	 */
	public static function selected_jurisdictions(): array {
		$stored = Settings::get( self::JURISDICTIONS_KEY );
		if ( ! is_array( $stored ) ) {
			return [];
		}

		return array_values( array_intersect( self::JURISDICTIONS, $stored ) );
	}

	/**
	 * Human label for an identity field, for notices and the jump-to list.
	 *
	 * @since 1.5.0
	 * @param string $field Field key.
	 * @return string
	 */
	public static function field_label( string $field ): string {
		$labels = [
			'legal_entity_name'       => __( 'Legal Entity Name', 'surecookie' ),
			'postal_address_lines'    => __( 'Postal Address', 'surecookie' ),
			'postal_code'             => __( 'Postal Code', 'surecookie' ),
			'country'                 => __( 'Country', 'surecookie' ),
			'privacy_contact_email'   => __( 'Privacy Contact Email', 'surecookie' ),
			'grievance_officer_name'  => __( 'Grievance Officer Name', 'surecookie' ),
			'grievance_officer_email' => __( 'Grievance Officer Email', 'surecookie' ),
			'transfer_safeguard_text' => __( 'Data Transfer Safeguards', 'surecookie' ),
			'ccpa_sells_or_shares'    => __( 'Sale or Sharing Declaration', 'surecookie' ),
		];

		return $labels[ $field ] ?? $field;
	}

	/**
	 * Identity fields still needed before the policy can be published.
	 *
	 * Conditional fields only count once the regime that needs them is on, so
	 * the count stays informative instead of becoming a permanent nag.
	 *
	 * @since 1.5.0
	 * @return array<int, array{field: string, label: string}> Ordered, keyed list.
	 */
	public static function missing_required_fields(): array {
		$details       = self::details();
		$jurisdictions = self::selected_jurisdictions();
		$required      = self::REQUIRED_FIELDS;

		foreach ( $jurisdictions as $code ) {
			$required = array_merge( $required, self::CONDITIONAL_FIELDS[ $code ] ?? [] );
		}

		$missing = [];

		foreach ( array_unique( $required ) as $field ) {
			if ( ! self::is_filled( $details[ $field ] ?? null ) ) {
				$missing[] = [
					'field' => $field,
					'label' => self::field_label( $field ),
				];
			}
		}

		return $missing;
	}

	/**
	 * Whether every required identity field is filled.
	 *
	 * @since 1.5.0
	 * @return bool
	 */
	public static function is_complete(): bool {
		return empty( self::missing_required_fields() );
	}

	/**
	 * Whether a regime's own required inputs are present.
	 *
	 * A selected regime whose inputs are missing renders an unresolved
	 * placeholder rather than a section making promises the site cannot keep.
	 *
	 * @since 1.5.0
	 * @param string $code Jurisdiction slug.
	 * @return bool
	 */
	public static function jurisdiction_is_satisfied( string $code ): bool {
		if ( ! in_array( $code, self::selected_jurisdictions(), true ) ) {
			return false;
		}

		$details = self::details();

		foreach ( self::CONDITIONAL_FIELDS[ $code ] ?? [] as $field ) {
			if ( ! self::is_filled( $details[ $field ] ?? null ) ) {
				return false;
			}
		}

		// CPRA's rights section describes an opt-out, so the site has to have
		// decided whether it sells or shares before the section can be honest.
		if ( $code === 'cpra' && ! in_array( $details['ccpa_sells_or_shares'] ?? '', [ 'no', 'yes' ], true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Sanitize an incoming identity patch, merged over what is already stored.
	 *
	 * Hand-written because Settings::get_cleaned_value() has no reachable
	 * email, url or textarea case, so an 'array'-typed key would put every leaf
	 * through sanitize_text_field() with no address or email validation at all.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $patch Incoming partial value.
	 * @return array<string, mixed> Full value, safe to store.
	 */
	public static function sanitize_details( array $patch ): array {
		$clean = self::details();

		foreach ( $clean as $field => $current ) {
			if ( ! array_key_exists( $field, $patch ) ) {
				continue;
			}

			$clean[ $field ] = self::sanitize_field( $field, $patch[ $field ], $current );
		}

		return $clean;
	}

	/**
	 * Sanitize the selected regimes.
	 *
	 * @since 1.5.0
	 * @param mixed $value Incoming value.
	 * @return array<int, string>
	 */
	public static function sanitize_jurisdictions( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		return array_values( array_intersect( self::JURISDICTIONS, array_map( 'sanitize_key', $value ) ) );
	}

	/**
	 * Route both new keys through this validator on the admin write path.
	 *
	 * Hooked on `surecookie_update_admin_settings_data`, which runs before
	 * sanitize_settings_patch(), so the nested read-merge-write happens in one
	 * place and no caller can skip it.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $data Incoming settings patch.
	 * @return array<string, mixed>
	 */
	public static function filter_settings_patch( $data ): array {
		if ( ! is_array( $data ) ) {
			return [];
		}

		if ( isset( $data[ self::DETAILS_KEY ] ) && is_array( $data[ self::DETAILS_KEY ] ) ) {
			$data[ self::DETAILS_KEY ] = self::sanitize_details( $data[ self::DETAILS_KEY ] );
		}

		if ( isset( $data[ self::JURISDICTIONS_KEY ] ) ) {
			$data[ self::JURISDICTIONS_KEY ] = self::sanitize_jurisdictions( $data[ self::JURISDICTIONS_KEY ] );
		}

		return $data;
	}

	/**
	 * Sanitize one identity field.
	 *
	 * @since 1.5.0
	 * @param string $field   Field key.
	 * @param mixed  $value   Incoming value.
	 * @param mixed  $current Currently stored value, kept when the input is rejected.
	 * @return mixed
	 */
	private static function sanitize_field( string $field, $value, $current ) {
		switch ( $field ) {
			case 'postal_address_lines':
				return self::sanitize_address_lines( $value );

			case 'privacy_contact_email':
			case 'grievance_officer_email':
				if ( $value === '' ) {
					return '';
				}
				$email = sanitize_email( is_string( $value ) ? $value : '' );
				// Reject rather than blank: silently clearing a contact address
				// would re-arm the publish gate with no explanation.
				return is_email( $email ) ? $email : $current;

			case 'country':
				$code = strtoupper( sanitize_text_field( is_string( $value ) ? $value : '' ) );
				if ( $code === '' ) {
					return '';
				}
				return isset( Countries::all()[ $code ] ) ? $code : $current;

			case 'ccpa_sells_or_shares':
				$declared = sanitize_text_field( is_string( $value ) ? $value : '' );
				return in_array( $declared, [ '', 'no', 'yes' ], true ) ? $declared : $current;

			// Single line on purpose. Whatever this validator preserves is
			// re-sanitized moments later by Sanitize::array(), which puts every
			// leaf through sanitize_text_field() and strips newlines. The only
			// way out is the rich-text leaf filter, and that leaves nested
			// values decoded-but-unsanitized on read, so a short single-line
			// safeguard ("EU Standard Contractual Clauses") is the honest shape.
			case 'transfer_safeguard_text':
			default:
				return sanitize_text_field( is_string( $value ) ? $value : '' );
		}
	}

	/**
	 * Normalise address lines to a re-indexed list of non-empty strings.
	 *
	 * @since 1.5.0
	 * @param mixed $value Incoming value.
	 * @return array<int, string>
	 */
	private static function sanitize_address_lines( $value ): array {
		if ( is_string( $value ) ) {
			// Tolerate a pasted multi-line block by splitting it, since the
			// stored shape is a list precisely so newlines survive.
			$split = preg_split( '/\r\n|\r|\n/', $value );
			$value = is_array( $split ) ? $split : [];
		}

		if ( ! is_array( $value ) ) {
			return [];
		}

		$lines = [];

		foreach ( $value as $line ) {
			if ( ! is_string( $line ) ) {
				continue;
			}

			$line = trim( sanitize_text_field( $line ) );

			if ( $line !== '' ) {
				$lines[] = $line;
			}
		}

		return array_slice( $lines, 0, self::MAX_ADDRESS_LINES );
	}

	/**
	 * Whether a stored identity value counts as filled.
	 *
	 * @since 1.5.0
	 * @param mixed $value Stored value.
	 * @return bool
	 */
	private static function is_filled( $value ): bool {
		if ( is_array( $value ) ) {
			return ! empty( array_filter( $value, static fn( $line ) => is_string( $line ) && trim( $line ) !== '' ) );
		}

		return is_string( $value ) ? trim( $value ) !== '' : ! empty( $value );
	}
}
