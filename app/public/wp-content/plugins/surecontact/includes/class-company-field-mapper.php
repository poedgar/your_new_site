<?php
/**
 * Company Field Mapper
 *
 * Pure static transform module: takes a FluentCRM Company model and produces
 * the SureContact company payload. Unlike the contact-side `Field_Mapper`, this
 * intentionally has no user-configurable mapping — both ends are well-known
 * fixed schemas (FluentCRM Pro `fc_companies` table columns ↔ SureContact
 * `companies` table columns), so a mapping UI would offer zero meaningful
 * choice while adding cognitive cost and an extra failure mode (forgotten
 * toggle / mis-mapped field = silent data loss).
 *
 * @since 1.5.1
 *
 * @package SureContact
 */

namespace SureContact;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Company_Field_Mapper
 *
 * @since 1.5.1
 */
class Company_Field_Mapper {

	/**
	 * Map a FluentCRM Company model to the SureContact company payload.
	 *
	 * Output matches the `StoreCompanyRequest` validation rules:
	 *   { name, phone, website, logo_url, industry, employee_range,
	 *     year_founded, linkedin_url, twitter_handle, facebook_url,
	 *     description, type, address: { street, city, state, country, postal_code } }
	 *
	 * Empty / null source values are omitted so the SaaS doesn't blank out
	 * fields that weren't actually changed in FluentCRM.
	 *
	 * @since 1.5.1
	 *
	 * @param object $company FluentCRM Company model (any object exposing the
	 *                        documented column properties).
	 * @return array<string, mixed> Payload ready to send to /api/v1/companies.
	 */
	public static function map_to_crm_format( $company ) {
		if ( ! is_object( $company ) ) {
			return array();
		}

		$payload = array();

		// Direct 1:1 fields — drop empty values so we don't overwrite existing data.
		$direct_fields = array(
			'name'         => $company->name ?? null,
			'phone'        => self::truncate( $company->phone ?? '', 50 ),
			'website'      => self::truncate( $company->website ?? '', 2048 ),
			'logo_url'     => self::truncate( $company->logo ?? '', 2048 ),
			'industry'     => $company->industry ?? null,
			'linkedin_url' => self::truncate( $company->linkedin_url ?? '', 2048 ),
			'facebook_url' => self::truncate( $company->facebook_url ?? '', 2048 ),
			'description'  => self::truncate( $company->description ?? '', 10000 ),
		);
		foreach ( $direct_fields as $key => $value ) {
			if ( $value !== null && $value !== '' ) {
				$payload[ $key ] = $value;
			}
		}

		// Enum / transformed fields.
		if ( isset( $company->type ) && $company->type !== '' ) {
			$payload['type'] = self::normalize_company_type( (string) $company->type );
		}

		if ( isset( $company->employees_number ) ) {
			$bucket = self::bucket_employee_count( (int) $company->employees_number );
			if ( $bucket !== null ) {
				$payload['employee_range'] = $bucket;
			}
		}

		if ( isset( $company->twitter_url ) && $company->twitter_url !== '' ) {
			$handle = self::extract_twitter_handle( (string) $company->twitter_url );
			if ( $handle !== null ) {
				$payload['twitter_handle'] = $handle;
			}
		}

		if ( isset( $company->date_of_start ) && $company->date_of_start !== '' ) {
			$year = self::extract_year_from_date( (string) $company->date_of_start );
			if ( $year !== null ) {
				$clamped = self::clamp_year( $year );
				if ( $clamped !== null ) {
					$payload['year_founded'] = $clamped;
				}
			}
		}

		// Address (nested object).
		$address = array_filter(
			array(
				'street'      => self::compose_street( $company->address_line_1 ?? null, $company->address_line_2 ?? null ),
				'city'        => $company->city ?? null,
				'state'       => $company->state ?? null,
				'country'     => $company->country ?? null,
				'postal_code' => $company->postal_code ?? null,
			),
			static function ( $value ) {
				return $value !== null && $value !== '';
			}
		);
		if ( ! empty( $address ) ) {
			$payload['address'] = $address;
		}

		return $payload;
	}

	/**
	 * Bucket a raw employee count into a SureContact `employee_range` enum value.
	 *
	 * Buckets: 1-10, 11-50, 51-200, 201-500, 501-1000, 1001-5000, 5001-10000, 10001+.
	 * Returns null for non-positive counts so the field is dropped from the payload.
	 *
	 * @since 1.5.1
	 *
	 * @param int $count Raw employee count.
	 * @return string|null Bucket value or null if the count is zero/negative.
	 */
	public static function bucket_employee_count( $count ) {
		$count = (int) $count;

		if ( $count <= 0 ) {
			return null;
		}

		if ( $count <= 10 ) {
			return '1-10';
		}
		if ( $count <= 50 ) {
			return '11-50';
		}
		if ( $count <= 200 ) {
			return '51-200';
		}
		if ( $count <= 500 ) {
			return '201-500';
		}
		if ( $count <= 1000 ) {
			return '501-1000';
		}
		if ( $count <= 5000 ) {
			return '1001-5000';
		}
		if ( $count <= 10000 ) {
			return '5001-10000';
		}

		return '10001+';
	}

	/**
	 * Normalize a FluentCRM company type to a SureContact `CompanyType` enum value.
	 *
	 * FluentCRM ships: Prospect, Partner, Reseller, Vendor, Other.
	 * SureContact accepts: prospect, customer, partner, reseller, vendor, other.
	 *
	 * Unrecognized values fall through to `other`.
	 *
	 * @since 1.5.1
	 *
	 * @param string $type Source type value (any casing).
	 * @return string Normalized SureContact enum value.
	 */
	public static function normalize_company_type( $type ) {
		$normalized = strtolower( trim( (string) $type ) );

		$allowed = array( 'prospect', 'customer', 'partner', 'reseller', 'vendor', 'other' );

		return in_array( $normalized, $allowed, true ) ? $normalized : 'other';
	}

	/**
	 * Extract a Twitter/X handle from a URL or string.
	 *
	 * Accepts twitter.com or x.com URLs and bare handles (with or without leading @).
	 * Returns null when nothing usable can be extracted.
	 *
	 * @since 1.5.1
	 *
	 * @param string $value Source value (URL or handle).
	 * @return string|null Handle without leading @, or null.
	 */
	public static function extract_twitter_handle( $value ) {
		$value = trim( (string) $value );

		if ( $value === '' ) {
			return null;
		}

		// Strip protocol and host if it's a URL.
		if ( preg_match( '~^https?://(?:www\.)?(?:twitter\.com|x\.com)/([^/?#]+)~i', $value, $matches ) ) {
			$handle = $matches[1];
		} else {
			$handle = ltrim( $value, '@' );
		}

		$handle = trim( $handle );

		return $handle !== '' ? substr( $handle, 0, 255 ) : null;
	}

	/**
	 * Extract a 4-digit year from a date or year string.
	 *
	 * @since 1.5.1
	 *
	 * @param string $value Date in any parseable format, or a bare year.
	 * @return int|null Year, or null if unparseable.
	 */
	public static function extract_year_from_date( $value ) {
		$value = trim( (string) $value );

		if ( $value === '' ) {
			return null;
		}

		if ( preg_match( '/\b(\d{4})\b/', $value, $matches ) ) {
			$year = (int) $matches[1];
			if ( $year > 0 ) {
				return $year;
			}
		}

		$timestamp = strtotime( $value );
		if ( $timestamp !== false ) {
			return (int) gmdate( 'Y', $timestamp );
		}

		return null;
	}

	/**
	 * Clamp a year to the SureContact-accepted range (1700 ≤ year ≤ current year).
	 *
	 * @since 1.5.1
	 *
	 * @param int $year Candidate year.
	 * @return int|null Clamped year, or null if out of range entirely.
	 */
	private static function clamp_year( $year ) {
		$year = (int) $year;

		if ( $year < 1700 ) {
			return null;
		}

		$current_year = (int) gmdate( 'Y' );

		return $year > $current_year ? $current_year : $year;
	}

	/**
	 * Compose FluentCRM's two address lines into a single street string for SureContact.
	 *
	 * @since 1.5.1
	 *
	 * @param string|null $line_1 First address line.
	 * @param string|null $line_2 Second address line (optional).
	 * @return string|null Joined street, or null when both are empty.
	 */
	private static function compose_street( $line_1, $line_2 ) {
		$line_1 = trim( (string) $line_1 );
		$line_2 = trim( (string) $line_2 );

		if ( $line_1 === '' && $line_2 === '' ) {
			return null;
		}

		if ( $line_1 === '' ) {
			return $line_2;
		}
		if ( $line_2 === '' ) {
			return $line_1;
		}

		return $line_1 . "\n" . $line_2;
	}

	/**
	 * Truncate a string to a maximum length (multibyte-safe).
	 *
	 * @since 1.5.1
	 *
	 * @param string $value     Source string.
	 * @param int    $max_chars Maximum character length.
	 * @return string Truncated string.
	 */
	private static function truncate( $value, $max_chars ) {
		$value = (string) $value;

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max_chars );
		}
		return substr( $value, 0, $max_chars );
	}
}
