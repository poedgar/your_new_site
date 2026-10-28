<?php
/**
 * Country list access.
 *
 * Wraps the shipped `inc/data/countries.php` table so PHP and the admin select
 * read one source. Memoized because the identity shortcode and the localizer
 * both ask for it on the same request.
 *
 * @package SureCookie\Inc\Modules\PrivacyPolicy
 * @since 1.5.0
 */

namespace SureCookie\Inc\Modules\PrivacyPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Countries
 *
 * @since 1.5.0
 */
final class Countries {
	/**
	 * Memoized code => name map.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $countries = null;

	/**
	 * All known countries, keyed by ISO 3166-1 alpha-2 code.
	 *
	 * @since 1.5.0
	 * @return array<string, string>
	 */
	public static function all(): array {
		if ( self::$countries !== null ) {
			return self::$countries;
		}

		$file = SURECOOKIE_DIR . 'inc/data/countries.php';

		if ( ! file_exists( $file ) ) {
			self::$countries = [];
			return self::$countries;
		}

		$countries = require $file;

		self::$countries = is_array( $countries ) ? $countries : [];

		return self::$countries;
	}

	/**
	 * English name for a country code, or an empty string when unknown.
	 *
	 * @since 1.5.0
	 * @param string $code ISO 3166-1 alpha-2 code.
	 * @return string
	 */
	public static function name( string $code ): string {
		return self::all()[ strtoupper( $code ) ] ?? '';
	}

	/**
	 * EEA member states (the EU 27 plus Iceland, Liechtenstein and Norway) and the
	 * EU territories with an ISO code of their own (Aland, the French outermost
	 * regions), which would otherwise print as third countries.
	 *
	 * Deliberately NOT Get::eu_countries(), which is the geo-targeting list for
	 * "show the GDPR banner here" and carries the UK, Switzerland, Russia,
	 * Belarus and Ukraine. Reusing it would report a transfer to London or Zurich
	 * as domestic, which is exactly the statement Art. 44 requires a site to make.
	 *
	 * @since 1.7.0
	 * @return array<int, string>
	 */
	public static function eea(): array {
		return [
			'AT',
			'BE',
			'BG',
			'HR',
			'CY',
			'CZ',
			'DK',
			'EE',
			'FI',
			'FR',
			'DE',
			'GR',
			'HU',
			'IE',
			'IS',
			'IT',
			'LI',
			'LT',
			'LU',
			'LV',
			'MT',
			'NL',
			'NO',
			'PL',
			'PT',
			'RO',
			'SE',
			'SI',
			'SK',
			'ES',
			'AX',
			'GF',
			'GP',
			'MQ',
			'MF',
			'RE',
			'YT',
		];
	}

	/**
	 * English names of the codes that are outside the EEA, in the order given.
	 *
	 * A code the shipped table does not know is dropped rather than printed
	 * raw: a bare "ZZ" in a privacy disclosure is worse than saying nothing.
	 *
	 * @since 1.7.0
	 * @param array<int, mixed> $codes ISO 3166-1 alpha-2 codes.
	 * @return array<int, string>
	 */
	public static function third_country_names( array $codes ): array {
		$eea   = self::eea();
		$names = [];

		foreach ( $codes as $code ) {
			$code = is_string( $code ) ? strtoupper( trim( $code ) ) : '';
			$name = $code !== '' && ! in_array( $code, $eea, true ) ? self::name( $code ) : '';

			if ( $name !== '' ) {
				$names[ $name ] = true;
			}
		}

		return array_keys( $names );
	}

	/**
	 * The list shaped for a force-ui select.
	 *
	 * @since 1.5.0
	 * @return array<int, array{value: string, label: string}>
	 */
	public static function options(): array {
		$options = [];

		foreach ( self::all() as $code => $name ) {
			$options[] = [
				'value' => $code,
				'label' => $name,
			];
		}

		return $options;
	}
}
