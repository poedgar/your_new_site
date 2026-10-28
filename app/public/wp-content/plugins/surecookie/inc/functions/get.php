<?php
/**
 * Get
 *
 * @package SureCookie
 * @since 0.0.1
 */

namespace SureCookie\Inc\Functions;

use SureCookie\Inc\Modules\PrivacyPolicy\Countries;
use SureCookie\Inc\Modules\ScriptBlocking\Resource_Categories;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Get - This class will handle all functions to get data from database.
 *
 * @since 0.0.1
 */
class Get {
	/**
	 * Fields a cookie row may carry into a visitor-facing payload.
	 *
	 * The preferences modal renders provider, duration and expires, and a
	 * withdrawal deletes by name within category, so every other stored field is
	 * weight each anonymous visitor pays for. The cookie policy page is unaffected:
	 * it renders Purpose and Domain server-side from the untrimmed rows.
	 *
	 * @since 1.6.0
	 */
	public const PUBLIC_COOKIE_FIELDS = [ 'id', 'name', 'category', 'provider', 'duration', 'expires' ];
	/**
	 * Per-request memo for the category usage tally. Never persisted: the tally
	 * must stay dynamic so a newly detected cookie or script reveals its category.
	 *
	 * @var array<string, array{cookies: int, scripts: int, services: int}>|null
	 */
	private static ?array $category_usage_memo = null;

	/**
	 * Check whether current locale direction is RTL.
	 *
	 * @since 0.0.1
	 * @return bool
	 */
	public static function is_rtl(): bool {
		return function_exists( 'is_rtl' ) && is_rtl();
	}

	/**
	 * Get option
	 * This function will get option
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $default     Default value.
	 * @param string $format      Format.
	 *
	 * @since 0.0.1
	 * @return mixed
	 */
	public static function option( $option_name, $default = false, $format = 'string' ) {
		$get = get_option( $option_name, $default );

		if ( $format === 'array' ) {
			$validate = Validate::array( $get );
			return empty( $validate ) || ! is_array( $validate ) ? $default : $validate;
		}

		return $get;
	}

	/**
	 * Get the post types selectable in a SureCookie picker.
	 *
	 * Single source of truth for both the Cookie Policy page picker
	 * ($context = 'policy') and the Site Scanner page picker ($context =
	 * 'scanner'). Both default to the `page` post type; the list is extensible
	 * per surface via the `surecookie_searchable_post_types` filter. The result
	 * is always intersected with public post types and stripped of `attachment`
	 * so a filter can never surface private, non-existent, or media types.
	 *
	 * @param string $context Picker surface: 'policy' or 'scanner'.
	 * @since 1.2.4
	 * @return array<int, string> Indexed array of post type slugs.
	 */
	public static function searchable_post_types( string $context = 'policy' ): array {
		$post_types = [ 'page' => 'page' ];

		/**
		 * Filter the post types selectable in a SureCookie picker.
		 *
		 * Fires for the Cookie Policy picker ($context = 'policy') and the Site
		 * Scanner picker ($context = 'scanner'). Defaults to ['page'] for both.
		 * Return a superset to opt additional PUBLIC post types in; branch on
		 * $context to target a single surface.
		 *
		 * Example - add a `product` CPT to the SCANNER only (functions.php):
		 *
		 *     add_filter( 'surecookie_searchable_post_types', function ( $types, $context ) {
		 *         if ( 'scanner' === $context ) {   // omit the check to target both surfaces
		 *             $types['product'] = 'product'; // must be registered public => true
		 *         }
		 *         return $types;
		 *     }, 10, 2 );
		 *
		 * @param array<string, string> $post_types Post type slugs keyed by slug.
		 * @param string                $context    'policy' | 'scanner'.
		 * @since 1.2.4 Added the $context parameter.
		 * @since 0.0.1-beta.2
		 */
		$post_types = (array) apply_filters( 'surecookie_searchable_post_types', $post_types, $context );

		// Guard against non-public / non-existent slugs a filter may have added,
		// and drop media - attachment titles are noise in a page picker. Strip
		// by value with array_diff (not unset by key): array_intersect preserves
		// the filter's keys, so an indexed return like ['page','attachment']
		// would keep numeric keys and slip past a keyed unset.
		$post_types = array_intersect( $post_types, get_post_types( [ 'public' => true ] ) );
		$post_types = array_diff( $post_types, [ 'attachment' ] );

		return array_values( $post_types );
	}

	/**
	 * Get cookie categories definitions.
	 *
	 * Single source of truth for all cookie category descriptions.
	 * Returns associative array format to match database storage.
	 *
	 * @since 0.0.1
	 * @return array<string, array<string, string|bool>>
	 */
	public static function default_cookie_categories() {
		return [
			'essential'     => [
				'id'          => 'essential',
				'name'        => __( 'Essential Cookies', 'surecookie' ),
				'description' => __( 'Required for basic website functionality.', 'surecookie' ),
				'required'    => true,
			],
			'functional'    => [
				'id'          => 'functional',
				'name'        => __( 'Functional Cookies', 'surecookie' ),
				'description' => __( 'Enable features like saved preferences and personalized content.', 'surecookie' ),
				'required'    => false,
			],
			'analytics'     => [
				'id'          => 'analytics',
				'name'        => __( 'Analytics Cookies', 'surecookie' ),
				// Named for the processing purpose rather than for our benefit from
				// it: "so we can improve it" is the vague phrasing German and EU
				// practice rejects (#1244).
				'description' => __( 'Measure how this site is used, such as which pages are viewed and where visitors arrive from. Also called reach measurement.', 'surecookie' ),
				'required'    => false,
			],
			'marketing'     => [
				'id'          => 'marketing',
				'name'        => __( 'Marketing Cookies', 'surecookie' ),
				'description' => __( 'Used to show you relevant ads based on your interests.', 'surecookie' ),
				'required'    => false,
			],
			'uncategorized' => [
				'id'          => 'uncategorized',
				'name'        => __( 'Uncategorized Cookies', 'surecookie' ),
				'description' => __( 'Cookies that haven\'t been categorized yet.', 'surecookie' ),
				'required'    => false,
			],
		];
	}

	/**
	 * Get default cookie categories keys only.
	 *
	 * @since 0.0.1
	 * @return array<string>
	 */
	public static function default_cookie_categories_keys() {
		return array_keys( self::default_cookie_categories() );
	}

	/**
	 * Get unique ID - Applicable for cookies, categories.
	 *
	 * @param string $prefix Prefix.
	 *
	 * @since 0.0.1
	 * @return string
	 */
	public static function unique_id( $prefix = '' ) {
		// Must be collision-free even when called many times within the same
		// second (e.g. importing a whole service's cookies in one go); time()
		// alone repeats and callers key arrays by this id, so entries clobbered.
		return $prefix . wp_generate_uuid4();
	}

	/**
	 * Get redirect URL if menu location changed.
	 *
	 * @param array<string, mixed> $sanitized_settings Sanitized settings payload.
	 * @param mixed                $previous_top_level Previous value for top level menu setting.
	 * @return string
	 */
	public static function menu_redirect_url( array $sanitized_settings, $previous_top_level ): string {
		if ( ! array_key_exists( 'top_level_menu_enabled', $sanitized_settings ) ) {
			return '';
		}

		$next_top_level = (bool) $sanitized_settings['top_level_menu_enabled'];
		if ( $next_top_level === (bool) $previous_top_level ) {
			return '';
		}

		$page_fragment = SURECOOKIE_PREFIX . '#/settings/general';

		return $next_top_level
			? admin_url( 'admin.php?page=' . $page_fragment )
			: admin_url( 'options-general.php?page=' . $page_fragment );
	}

	/**
	 * Get formatted custom cookies - depending on cookie categorize separate out all custom cookies and return it to consider under scanned result.
	 *
	 * @since 0.0.1
	 * @return array<list<array<string, mixed>>> Formatted custom cookies.
	 */
	public static function formatted_custom_cookies() {
		$custom_cookies = Settings::get( 'custom_cookies' );

		if ( ! is_array( $custom_cookies ) || empty( $custom_cookies ) ) {
			return [];
		}

		$formatted_cookies = [];

		foreach ( $custom_cookies as $_custom_cookie ) {
			if ( ! is_array( $_custom_cookie ) ) {
				continue;
			}

			// Array-typed settings guarantee neither the row nor the leaf: an array leaf
			// is an illegal array key, so resolve through Sanitize::scalar() first.
			$category = Sanitize::scalar( $_custom_cookie['category'] ?? '' );
			$name     = Sanitize::scalar( $_custom_cookie['name'] ?? '' );

			if ( $category === '' || $name === '' ) {
				continue;
			}

			$formatted_cookies[ $category ] [] = [
				'name'        => $name,
				'description' => Sanitize::scalar( $_custom_cookie['description'] ?? '' ),
				'domain'      => Sanitize::scalar( $_custom_cookie['domain'] ?? '' ),
				'value'       => $_custom_cookie['value'] ?? '',
				'path'        => $_custom_cookie['path'] ?? '/',
				'expires'     => Sanitize::scalar( $_custom_cookie['expires'] ?? '' ),
				// Custom cookies are authored with an explicit lifetime in days;
				// carry it through so consumers show the day count instead of re-deriving it from the absolute 'expires' timestamp.
				'duration'    => Sanitize::scalar( $_custom_cookie['duration'] ?? '' ),
				'secure'      => $_custom_cookie['secure'] ?? false,
				'httpOnly'    => $_custom_cookie['httpOnly'] ?? false,
				'provider'    => Sanitize::scalar( $_custom_cookie['provider'] ?? '' ),
				'purpose'     => Sanitize::scalar( $_custom_cookie['purpose'] ?? '' ),
			];
		}

		return $formatted_cookies;
	}

	/**
	 * Get color palette codes.
	 *
	 * @since 0.0.1
	 * @return array<string, array<string, string>>
	 */
	public static function color_palette_codes() {
		return apply_filters(
			'surecookie_color_palette_codes',
			[
				'blue-purple' => [
					'id'                => 'blue-purple',
					'name'              => 'Blue & Purple',
					'acceptButton'      => '#1463FF',
					'acceptButtonHover' => '#3B82F6',
					'declineButton'     => '#1463FF',
					'bgColor'           => '#FFFFFF',
					'textColor'         => '#374151',
				],
				'purple-pink' => [
					'id'                => 'purple-pink',
					'name'              => 'Purple & Pink',
					'acceptButton'      => '#9335B6',
					'acceptButtonHover' => '#8528A7',
					'declineButton'     => '#9335B6',
					'bgColor'           => '#FFFFFF',
					'textColor'         => '#374151',
				],
				'blue-cyan'   => [
					'id'                => 'blue-cyan',
					'name'              => 'Blue & Cyan',
					'acceptButton'      => '#2235DD',
					'acceptButtonHover' => '#1A2BC6',
					'declineButton'     => '#2235DD',
					'bgColor'           => '#FFFFFF',
					'textColor'         => '#374151',
				],
				'green-lime'  => [
					'id'                => 'green-lime',
					'name'              => 'Green & Lime',
					'acceptButton'      => '#377A00',
					'acceptButtonHover' => '#2F6A00',
					'declineButton'     => '#377A00',
					'bgColor'           => '#FFFFFF',
					'textColor'         => '#374151',
				],
				'red-orange'  => [
					'id'                => 'red-orange',
					'name'              => 'Red & Orange',
					'acceptButton'      => '#E11B14',
					'acceptButtonHover' => '#C00902',
					'declineButton'     => '#E11B14',
					'bgColor'           => '#FFFFFF',
					'textColor'         => '#374151',
				],
				'dark-blue'   => [
					'id'                => 'dark-blue',
					'name'              => 'Dark & Blue',
					'acceptButton'      => '#0285FF',
					'acceptButtonHover' => '#0177E3',
					'declineButton'     => '#0285FF',
					'bgColor'           => '#111827',
					'textColor'         => '#E5E7EB',
				],
				'dark-green'  => [
					'id'                => 'dark-green',
					'name'              => 'Dark & Green',
					'acceptButton'      => '#1FB4A6',
					'acceptButtonHover' => '#0DAF9F',
					'declineButton'     => '#1FB4A6',
					'bgColor'           => '#111827',
					'textColor'         => '#E5E7EB',
				],
				'dark-pink'   => [
					'id'                => 'dark-pink',
					'name'              => 'Dark & Pink',
					'acceptButton'      => '#FB5FAB',
					'acceptButtonHover' => '#EA569D',
					'declineButton'     => '#FB5FAB',
					'bgColor'           => '#111827',
					'textColor'         => '#E5E7EB',
				],
				'dark-orange' => [
					'id'                => 'dark-orange',
					'name'              => 'Dark & Orange',
					'acceptButton'      => '#DCA54A',
					'acceptButtonHover' => '#D09A41',
					'declineButton'     => '#DCA54A',
					'bgColor'           => '#111827',
					'textColor'         => '#E5E7EB',
				],
			]
		);
	}

	/**
	 * Get EU/EEA countries list (ISO 3166-1 alpha-2 codes).
	 *
	 * Single source of truth for EU countries used in geo-targeting.
	 * This includes EU members, EEA countries, and other European countries
	 * commonly included for GDPR compliance purposes.
	 *
	 * @since 0.0.1
	 * @return array<string> Array of country codes.
	 */
	public static function eu_countries(): array {
		$defaults = [
			'AL',
			'AD',
			'AT',
			'BY',
			'BE',
			'BA',
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
			'IS',
			'IE',
			'IT',
			'LV',
			'LI',
			'LT',
			'LU',
			'MK',
			'MT',
			'MD',
			'MC',
			'ME',
			'NL',
			'NO',
			'PL',
			'PT',
			'RO',
			'RU',
			'SM',
			'RS',
			'SK',
			'SI',
			'ES',
			'SE',
			'CH',
			'UA',
			'GB',
			'VA',
		];

		$filtered = apply_filters( 'surecookie_eu_countries', $defaults );

		return is_array( $filtered ) ? $filtered : $defaults;
	}

	/**
	 * Get nav menus list for re-consent menu integration.
	 *
	 * @since 0.0.1
	 * @return array<int, array{id: string, name: string}> Array of nav menu items with id and name.
	 */
	public static function nav_menus(): array {
		$nav_menus = wp_get_nav_menus();
		$list      = [];

		if ( ! is_array( $nav_menus ) ) {
			return [];
		}

		foreach ( $nav_menus as $menu ) {
			$list[] = [
				'id'   => (string) $menu->term_id,
				'name' => $menu->name,
			];
		}

		return $list;
	}

	/**
	 * Scan-observed cookies as the display surfaces should see them.
	 *
	 * Applies `surecookie_scanned_cookies`, so a service known only because the
	 * blocker gated it reaches All Cookies AND the public cookie policy. Read
	 * the option directly where you intend to write it back.
	 *
	 * @since 1.5.0
	 * @return array<string, mixed> Cookies grouped by category id.
	 */
	public static function scanned_cookies_for_display(): array {
		/**
		 * Filter: the scan-observed cookie set, grouped by category id.
		 *
		 * Applied on the read path only, so a source other than a scan can add
		 * to what is displayed without those rows being written back to the
		 * option by a read-modify-write caller.
		 *
		 * @since 1.5.0
		 * @param array<string, mixed> $cookies Cookies grouped by category id.
		 */
		$cookies = apply_filters(
			'surecookie_scanned_cookies',
			self::option( SURECOOKIE_SCANNED_COOKIES_OPTION, [], 'array' )
		);

		return is_array( $cookies ) ? $cookies : [];
	}

	/**
	 * The scan-observed set without any cookie a custom row already describes.
	 *
	 * A custom cookie is the admin's own record, whether authored by hand or added by a
	 * Known Service, so on the same identity (name and domain, leading dot ignored) it
	 * wins wherever it sits and in whichever category. The same name on another domain is
	 * another cookie and stays. A first-party catalog row is matched on name alone, as a
	 * site has one `_ga` whichever label of its host the scanner saw it on. One rule for
	 * the cookie policy, All Cookies and the public payload, or each lists its own
	 * duplicates (#1237).
	 *
	 * @since 1.6.1
	 * @return array<string, mixed> Cookies grouped by category id.
	 */
	public static function scanned_cookies_unshadowed(): array {
		$scanned = self::scanned_cookies_for_display();
		$keys    = [];
		$names   = [];

		foreach ( self::formatted_custom_cookies() as $rows ) {
			foreach ( $rows as $row ) {
				$keys[ Cookie_Identity::key_for( $row ) ]                    = true;
				$names[ Cookie_Identity::name_key( (string) $row['name'] ) ] = true;
			}
		}

		if ( $keys === [] ) {
			return $scanned;
		}

		foreach ( $scanned as $category_id => $rows ) {
			if ( ! is_array( $rows ) ) {
				continue;
			}

			$scanned[ $category_id ] = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $keys, $names ): bool {
						return ! is_array( $row )
							|| ! ( isset( $keys[ Cookie_Identity::key_for( $row ) ] )
								|| ( Cookie_Identity::is_first_party( $row ) && isset( $names[ Cookie_Identity::name_key( (string) ( $row['name'] ?? '' ) ) ] ) ) );
					}
				)
			);
		}

		return $scanned;
	}

	/**
	 * Scan-detected resources as the display surfaces should see them.
	 *
	 * The twin of {@see self::scanned_cookies_for_display()}: the stored option
	 * holds only what a scan observed, while the blocker's own matched set and
	 * the installed-service overlay arrive through the filter. Read the option
	 * directly where you intend to write it back.
	 *
	 * @since 1.5.1
	 * @return array<string, mixed> Payload shaped { scripts:[], iframes:[], ... }.
	 */
	public static function scanned_resources_for_display(): array {
		/** This filter is documented in inc/api/scanned-resources.php */
		$resources = apply_filters(
			'surecookie_scanned_resources',
			self::option( SURECOOKIE_SCANNED_RESOURCES_OPTION, [], 'array' )
		);

		return is_array( $resources ) ? $resources : [];
	}

	/**
	 * Project a cookie row down to PUBLIC_COOKIE_FIELDS.
	 *
	 * @since 1.6.0
	 * @param mixed $cookie Stored cookie row.
	 * @return array<string, mixed>
	 */
	public static function public_cookie_row( $cookie ): array {
		return is_array( $cookie )
			? array_intersect_key( $cookie, array_flip( self::PUBLIC_COOKIE_FIELDS ) )
			: [];
	}

	/**
	 * Get scanned cookies as a flat array with category field added to each cookie.
	 *
	 * @since 0.0.1
	 * @return array<int, array<string, mixed>> Flat array of scanned cookies.
	 */
	public static function all_scanned_cookies(): array {
		$scanned_raw = self::scanned_cookies_unshadowed();

		$flat = [];

		foreach ( $scanned_raw as $category_id => $cookies ) {
			if ( ! is_array( $cookies ) ) {
				continue;
			}
			foreach ( $cookies as $cookie ) {
				if ( ! is_array( $cookie ) ) {
					continue;
				}
				// Projected here rather than at the display getter: this is the only
				// caller, and the cookie policy reads that getter for its full rows.
				$flat[] = self::public_cookie_row(
					array_merge( $cookie, [ 'category' => $category_id ] )
				);
			}
		}

		return $flat;
	}

	/**
	 * Per-category tally of what actually exists on THIS site, keyed by category id.
	 *
	 * Only per-site evidence, and only evidence that is actually gated: the bundled
	 * catalog spans every core category (so counting it would mark everything in
	 * use), and a "Do not block" domain gates nothing. Catalog precedence and admin
	 * recategorization are both replayed, so a resource counts for the category the
	 * blocker really gates it under, not the one the scanner guessed.
	 *
	 * @since 1.4.0
	 * @return array<string, array{cookies: int, scripts: int, services: int}>
	 */
	public static function category_usage_map(): array {
		if ( self::$category_usage_memo !== null ) {
			return self::$category_usage_memo;
		}

		$categories = Settings::get( 'cookie_categories' );
		$usage      = [];

		foreach ( is_array( $categories ) ? $categories : [] as $key => $category ) {
			$id = is_array( $category ) ? Sanitize::scalar( $category['id'] ?? '' ) : '';
			$id = $id !== '' ? $id : (string) $key;
			if ( $id !== '' ) {
				$usage[ $id ] = [
					'cookies'  => 0,
					'scripts'  => 0,
					'services' => 0,
				];
			}
		}

		// Scanned cookies. Sync pre-creates all five default buckets as empty
		// arrays, so an existing key proves nothing - only a non-empty one does.
		foreach ( self::scanned_cookies_for_display() as $category_id => $cookies ) {
			if ( is_array( $cookies ) && ! empty( $cookies ) && isset( $usage[ $category_id ] ) ) {
				$usage[ $category_id ]['cookies'] += count( $cookies );
			}
		}

		// Custom cookies, which also cover cookies declared by installing a known service.
		foreach ( self::formatted_custom_cookies() as $category_id => $cookies ) {
			if ( is_array( $cookies ) && isset( $usage[ $category_id ] ) ) {
				$usage[ $category_id ]['cookies'] += count( $cookies );
			}
		}

		// Scan-detected resources, after "Do not block" and after recategorization.
		// The display set, not the raw option: a resource the blocker matched but
		// no scan saw is the only usage evidence its category has, so reading raw
		// can hide a category that is still parking something (#1157).
		$resources = self::scanned_resources_for_display();
		foreach ( [
			'scripts' => 'script',
			'iframes' => 'iframe',
		] as $bucket => $kind ) {
			foreach ( is_array( $resources[ $bucket ] ?? null ) ? $resources[ $bucket ] : [] as $resource ) {
				$domain = is_array( $resource ) ? trim( (string) ( $resource['domain'] ?? '' ) ) : '';
				if ( $domain === '' || Resource_Categories::is_excluded_domain( $domain, $kind ) ) {
					continue;
				}
				$category_id = Resource_Categories::gated_category( $domain, (string) ( $resource['category'] ?? 'uncategorized' ), $kind );
				if ( isset( $usage[ $category_id ] ) ) {
					++$usage[ $category_id ]['scripts'];
				}
			}
		}

		// Manually authored block rules. `custom_blocked_scripts` is not in the
		// options registry, so Settings::get() returns null rather than [].
		$rules = Settings::get( 'custom_blocked_scripts' );
		foreach ( is_array( $rules ) ? $rules : [] as $rule ) {
			$category_id = is_array( $rule ) ? sanitize_key( Sanitize::scalar( $rule['category'] ?? '' ) ) : '';
			$category_id = $category_id !== '' ? $category_id : 'uncategorized';
			if ( isset( $usage[ $category_id ] ) ) {
				++$usage[ $category_id ]['scripts'];
			}
		}

		// Installed known services. A service can declare zero cookies, so the
		// registry is the only place its category is visible. `suppressed` entries
		// are the admin saying "not on this site" and are ignored.
		$registry = self::option( SURECOOKIE_INSTALLED_SERVICES_OPTION, [], 'array' );
		foreach ( is_array( $registry['installed'] ?? null ) ? $registry['installed'] : [] as $entry ) {
			$category_id = is_array( $entry ) ? sanitize_key( Sanitize::scalar( $entry['category'] ?? '' ) ) : '';
			$category_id = $category_id !== '' ? $category_id : 'uncategorized';
			if ( isset( $usage[ $category_id ] ) ) {
				++$usage[ $category_id ]['services'];
			}
		}

		self::$category_usage_memo = $usage;

		return $usage;
	}

	/**
	 * Per-category disclosure for the consent UI: who receives the data when the
	 * visitor allows a category, and which third countries it may reach (#1244).
	 *
	 * A sibling of category_usage_map(), not an extension of it: that one counts
	 * and its {cookies,scripts,services} shape has other consumers. Both walk the
	 * same per-site evidence and both file a resource under the category that
	 * really gates it, so a recipient is never named under a toggle that would
	 * not release it.
	 *
	 * Names come from the catalog by slug, never from a stored row's `provider`:
	 * that field is the cookie's issuer ("Google") while consent is given to a
	 * service ("Google Analytics"). A resource no catalog entry matches falls
	 * back to its scanned vendor and then to its host, which is still honest.
	 *
	 * Third countries are derived here from ISO codes rather than read as prose,
	 * so the EEA test and the visitor-facing sentence stay ours and a catalog
	 * edit can never author either. A category with nothing to disclose is
	 * omitted entirely.
	 *
	 * @since 1.7.0
	 * @return array<string, array{recipients: array<int, string>, transfers: array<int, string>}>
	 */
	public static function category_disclosure(): array {
		$catalog   = self::blocking_catalog_entries();
		$buckets   = [];
		$row_slugs = [];

		// Scan-detected and blocker-matched resources, after "Do not block" and
		// after recategorization - the same display set category_usage_map()
		// reads, and for the same reason (#1157).
		$resources = self::scanned_resources_for_display();

		foreach ( [
			'scripts' => 'script',
			'iframes' => 'iframe',
		] as $bucket => $kind ) {
			foreach ( is_array( $resources[ $bucket ] ?? null ) ? $resources[ $bucket ] : [] as $resource ) {
				$domain = is_array( $resource ) ? trim( Sanitize::scalar( $resource['domain'] ?? '' ) ) : '';

				if ( $domain === '' ) {
					continue;
				}

				$slug               = Sanitize::scalar( $resource['service_slug'] ?? '' );
				$row_slugs[ $slug ] = true;

				if ( Resource_Categories::is_excluded_domain( $domain, $kind ) ) {
					continue;
				}

				$entry = is_array( $catalog[ $slug ] ?? null ) ? $catalog[ $slug ] : [];
				$label = trim( (string) ( $entry['label'] ?? '' ) );
				$label = $label !== '' ? $label : trim( Sanitize::scalar( $resource['vendor'] ?? '' ) );

				self::disclose(
					$buckets,
					Resource_Categories::gated_category( $domain, Sanitize::scalar( $resource['category'] ?? 'uncategorized' ), $kind ),
					$label !== '' ? $label : $domain,
					$entry['transfer_countries'] ?? []
				);
			}
		}

		// Services that declared a cookie. A service can be present with no
		// resource row at all - an admin can install it by hand - so the rows
		// are the only evidence its recipient exists.
		foreach ( self::declared_cookie_slugs() as $category_id => $slugs ) {
			foreach ( $slugs as $slug ) {
				$entry = is_array( $catalog[ $slug ] ?? null ) ? $catalog[ $slug ] : [];

				self::disclose( $buckets, $category_id, (string) ( $entry['label'] ?? '' ), $entry['transfer_countries'] ?? [] );
			}
		}

		// Installed services with no resource row: a service may declare no cookies,
		// so the registry is the only place it shows. A row wins, as it carries the
		// admin's recategorization and "Do not block" that the registry never sees.
		$registry = self::option( SURECOOKIE_INSTALLED_SERVICES_OPTION, [], 'array' );

		foreach ( is_array( $registry['installed'] ?? null ) ? $registry['installed'] : [] as $slug => $service ) {
			if ( ! is_array( $service ) || isset( $row_slugs[ (string) $slug ] ) ) {
				continue;
			}

			$entry    = is_array( $catalog[ (string) $slug ] ?? null ) ? $catalog[ (string) $slug ] : [];
			$category = sanitize_key( Sanitize::scalar( $service['category'] ?? '' ) );

			// Same fallback category_usage_map() counts it under.
			self::disclose(
				$buckets,
				$category !== '' ? $category : 'uncategorized',
				(string) ( $entry['label'] ?? Sanitize::scalar( $service['label'] ?? '' ) ),
				$entry['transfer_countries'] ?? []
			);
		}

		$disclosure = [];

		foreach ( $buckets as $category_id => $bucket ) {
			$recipients = array_keys( $bucket['recipients'] );
			$transfers  = Countries::third_country_names( array_keys( $bucket['transfers'] ) );

			natcasesort( $recipients );
			sort( $transfers );

			$disclosure[ $category_id ] = [
				'recipients' => array_values( $recipients ),
				'transfers'  => $transfers,
			];
		}

		return $disclosure;
	}

	/**
	 * Record one recipient, and its transfer countries, against a category.
	 *
	 * @since 1.7.0
	 * @param array<string, array<string, array<string, bool>>> $buckets   Accumulator.
	 * @param string                                            $category  Category id.
	 * @param string                                            $label     Recipient name.
	 * @param mixed                                             $countries ISO 3166-1 alpha-2 codes.
	 * @return void
	 */
	private static function disclose( array &$buckets, string $category, string $label, $countries ): void {
		$category = sanitize_key( $category );
		$label    = trim( $label );

		if ( $category === '' || $label === '' ) {
			return;
		}

		$buckets[ $category ]['recipients'][ $label ] = true;
		$buckets[ $category ]['transfers']            = $buckets[ $category ]['transfers'] ?? [];

		foreach ( is_array( $countries ) ? $countries : [] as $code ) {
			if ( is_string( $code ) && $code !== '' ) {
				$buckets[ $category ]['transfers'][ $code ] = true;
			}
		}
	}

	/**
	 * Catalog slugs that published a cookie row, keyed by the row's category.
	 *
	 * Asked of what visitors are actually shown rather than of the installed
	 * registry, for the reason Recognised_Services documents: the two diverge
	 * whenever a service was removed after a scan had already stored its rows.
	 *
	 * @since 1.7.0
	 * @return array<string, array<int, string>>
	 */
	private static function declared_cookie_slugs(): array {
		$slugs = [];

		foreach ( self::scanned_cookies_for_display() as $category_id => $rows ) {
			foreach ( is_array( $rows ) ? $rows : [] as $row ) {
				// 'declared:{slug}:{name}', the contract reconciliation matches on.
				$parts = is_array( $row ) ? explode( ':', Sanitize::scalar( $row['signature_id'] ?? '' ), 3 ) : [];

				if ( count( $parts ) === 3 && $parts[0] === 'declared' && $parts[1] !== '' ) {
					$slugs[ (string) $category_id ][ $parts[1] ] = true;
				}
			}
		}

		// Installing a service writes its cookies to `custom_cookies` instead,
		// tagged with the slug, so those rows are declared too.
		foreach ( (array) Settings::get( 'custom_cookies' ) as $row ) {
			$slug     = is_array( $row ) ? Sanitize::scalar( $row['service_slug'] ?? '' ) : '';
			$category = is_array( $row ) ? Sanitize::scalar( $row['category'] ?? '' ) : '';

			if ( $slug !== '' && $category !== '' ) {
				$slugs[ $category ][ $slug ] = true;
			}
		}

		return array_map( 'array_keys', $slugs );
	}

	/**
	 * Ids of the categories the consent UI may show when "hide unused" is on.
	 *
	 * Never used to build the consent model itself: every registered category
	 * stays in the model regardless, hidden ones simply default to denied.
	 *
	 * @since 1.4.0
	 * @return array<int, string>
	 */
	public static function categories_in_use(): array {
		$usage  = self::category_usage_map();
		$in_use = [];

		foreach ( $usage as $id => $counts ) {
			if ( ( $counts['cookies'] + $counts['scripts'] + $counts['services'] ) > 0 ) {
				$in_use[] = $id;
			}
		}

		// Fail visible: no evidence at all means we know nothing about this site, not
		// that every category is unused. Must run BEFORE required ids are added, or
		// this never fires and a fresh install shows only the always-active row.
		if ( empty( $in_use ) ) {
			return array_keys( $usage );
		}

		// A category the visitor must always be able to see. Keyed off `required`
		// rather than the literal 'essential' id, because custom categories may
		// set it too.
		$categories = Settings::get( 'cookie_categories' );
		foreach ( is_array( $categories ) ? $categories : [] as $key => $category ) {
			if ( is_array( $category ) && ! empty( $category['required'] ) ) {
				$id       = Sanitize::scalar( $category['id'] ?? '' );
				$in_use[] = $id !== '' ? $id : (string) $key;
			}
		}

		return array_values( array_unique( $in_use ) );
	}

	/**
	 * Whether a category is currently hidden from the consent UI.
	 *
	 * A hidden category has no toggle anywhere, so it must not be grantable from
	 * a blocked-content placeholder either: the visitor would have no way to
	 * withdraw what they granted.
	 *
	 * @since 1.4.0
	 * @param string $category_id Category id.
	 * @return bool
	 */
	public static function is_category_hidden( string $category_id ): bool {
		if ( $category_id === '' || empty( Settings::get( 'hide_unused_categories' ) ) ) {
			return false;
		}

		return ! in_array( $category_id, self::categories_in_use(), true );
	}

	/**
	 * Drop the per-request usage memo. Used after a mutation and by tests.
	 *
	 * @since 1.4.0
	 * @return void
	 */
	public static function clear_category_usage_cache(): void {
		self::$category_usage_memo = null;
	}

	/**
	 * Get CSS variables for the active color palette.
	 *
	 * @since 0.0.1
	 * @return string
	 */
	public static function palette_root_css(): string {
		$color_palette = sanitize_key( (string) Settings::get( 'color_palette' ) );
		$palette_codes = self::color_palette_codes();

		// A saved id can vanish (e.g. 'astra' after switching themes); fall
		// back to the default palette instead of emitting no variables, which
		// would leave var()-less CSS rules with no color at all.
		if ( ! isset( $palette_codes[ $color_palette ] ) ) {
			$defaults      = Settings::get_settings_defaults();
			$color_palette = sanitize_key( (string) ( $defaults['color_palette'] ?? '' ) );
		}

		if ( ! isset( $palette_codes[ $color_palette ] ) ) {
			return '';
		}

		$palette_data = $palette_codes[ $color_palette ];

		/**
		 * Filters resolved palette colors before CSS-var emission.
		 *
		 * Array shape mirrors Get::color_palette_codes() entries.
		 *
		 * @since 1.0.0
		 * @param array<string, string> $palette_data  Resolved palette colors.
		 * @param string                $color_palette Active palette id.
		 */
		$filtered     = apply_filters( 'surecookie_resolved_palette_colors', $palette_data, $color_palette );
		$palette_data = is_array( $filtered ) ? $filtered : $palette_data;

		// Decline falls back to text color (not accept) so a missing
		// secondary never visually collapses accept and decline together.
		$secondary_fallback = $palette_data['textColor'] ?? '#374151';

		$primary_background   = $palette_data['acceptButton'] ?? '#1463FF';
		$secondary_background = $palette_data['declineButton'] ?? $secondary_fallback;

		$css_variables = [
			'--surecookie-banner-primary-color'        => $primary_background,
			'--surecookie-banner-primary-hover-color'  => $palette_data['acceptButtonHover'] ?? '#3B82F6',
			'--surecookie-banner-secondary-color'      => $secondary_background,
			'--surecookie-banner-text-color'           => $palette_data['textColor'] ?? '#374151',
			'--surecookie-banner-background-color'     => $palette_data['bgColor'] ?? '#FFFFFF',
			// Computed, not stored: filled-button labels take whichever of
			// white/near-black actually contrasts their background, so the
			// dark palettes and any Pro custom color stay readable (#1063).
			'--surecookie-banner-primary-text-color'   => self::button_label_color( (string) $primary_background ),
			'--surecookie-banner-secondary-text-color' => self::button_label_color( (string) $secondary_background ),
		];

		$css_output = ':root {';
		foreach ( $css_variables as $variable => $value ) {
			// Hex or strict rgba() - Pro's custom palette supports alpha.
			$sanitized_value = Sanitize::css_color( (string) $value );
			if ( empty( $sanitized_value ) ) {
				continue;
			}

			$css_output .= "\t{$variable}: {$sanitized_value};\n";
		}

		if ( $css_output === ':root {' ) {
			return '';
		}

		$css_output .= '}';
		$css_output .= "\n/* Color Palette: {$color_palette} */\n";

		return $css_output;
	}

	/**
	 * Vendor label for every service in the blocking catalog, keyed by service.
	 *
	 * A blocked element only carries its service key, so this is what lets the
	 * frontend name the vendor a placeholder is waiting on. Read from the same
	 * `surecookie_known_scripts` view the blocker matches against, so the label a
	 * visitor sees is the one the admin sees.
	 *
	 * @since 1.4.0
	 * @return array<string, string>
	 */
	public static function blocking_service_labels(): array {
		$labels = [];

		foreach ( self::blocking_catalog_entries() as $slug => $service ) {
			$label = (string) ( $service['label'] ?? '' );
			// Only worth sending when it differs from the key the element
			// already carries; the frontend falls back to that key.
			if ( $label !== '' && $label !== $slug ) {
				$labels[ $slug ] = $label;
			}
		}

		return $labels;
	}

	/**
	 * The blocking catalog flattened to slug => entry, categories collapsed.
	 *
	 * Read through `surecookie_known_scripts` rather than the catalog itself so
	 * every contributor the blocker matches against is included, and so this
	 * file does not have to reach into the services module.
	 *
	 * @since 1.7.0
	 * @return array<string, array<string, mixed>>
	 */
	private static function blocking_catalog_entries(): array {
		$catalog = apply_filters( 'surecookie_known_scripts', [] );
		$entries = [];

		foreach ( is_array( $catalog ) ? $catalog : [] as $services ) {
			foreach ( is_array( $services ) ? $services : [] as $slug => $service ) {
				if ( is_array( $service ) ) {
					$entries[ (string) $slug ] = $service;
				}
			}
		}

		return $entries;
	}

	/**
	 * Effective banner colors for surfaces painted like the banner (e.g. the
	 * blocked-content placeholder), as literal hex. Background comes from the
	 * active palette (with the same `surecookie_resolved_palette_colors` filter
	 * the banner honors, so Pro custom colors apply); the text color is computed
	 * from that background's brightness so it ALWAYS contrasts - never dark-on-dark
	 * or light-on-light. Emitting literal hex inline avoids any CSS-var mismatch.
	 *
	 * @since 1.4.0
	 * @return array{background: string, text: string, primary: string, primary_text: string}
	 */
	public static function banner_display_colors(): array {
		$palette_codes = self::color_palette_codes();
		$color_palette = (string) Settings::get( 'color_palette' );
		$palette       = isset( $palette_codes[ $color_palette ] ) && is_array( $palette_codes[ $color_palette ] )
			? $palette_codes[ $color_palette ]
			: [];

		$filtered = apply_filters( 'surecookie_resolved_palette_colors', $palette, $color_palette );
		$palette  = is_array( $filtered ) ? $filtered : $palette;

		$valid = static function ( $color ): string {
			$color = is_string( $color ) ? trim( $color ) : '';
			return preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color ) ? $color : '';
		};

		$background = $valid( $palette['bgColor'] ?? '' );
		if ( $background === '' ) {
			$background = '#1f2937';
		}

		$primary = $valid( $palette['acceptButton'] ?? '' );
		if ( $primary === '' ) {
			$primary = '#2563eb';
		}

		return [
			'background'   => $background,
			'text'         => self::readable_text_color( $background ),
			'primary'      => $primary,
			// Both placeholder builders, PHP and JS, read this rather than each
			// deciding for themselves - the JS one had white hardcoded (#1063).
			'primary_text' => self::button_label_color( $primary ),
		];
	}

	/**
	 * Get shared style which required for both frontend and in admin preview.
	 *
	 * @since 0.0.1
	 * @return string $style_file CSS file name depending on RTL or LTR.
	 */
	public static function shared_style_css() {
		$style_file = 'style.css';
		if ( self::is_rtl() && file_exists( SURECOOKIE_DIR . 'assets/css/style-rtl.css' ) ) {
			$style_file = 'style-rtl.css';
		}
		return $style_file;
	}

	/**
	 * Get admin style.
	 *
	 * @since 0.0.1
	 * @return string $admin_style_file CSS file name depending on RTL or LTR.
	 */
	public static function admin_style_css() {
		$admin_style_file = 'admin.css';
		if ( Get::is_rtl() && file_exists( SURECOOKIE_DIR . 'build/admin-rtl.css' ) ) {
			$admin_style_file = 'admin-rtl.css';
		}
		return $admin_style_file;
	}

	/**
	 * Get onboarding style.
	 *
	 * @since 0.0.1
	 * @return string $onboarding_style_file CSS file name depending on RTL or LTR.
	 */
	public static function onboarding_style_css() {
		$onboarding_style_file = 'onboarding.css';
		if ( Get::is_rtl() && file_exists( SURECOOKIE_DIR . 'build/onboarding-rtl.css' ) ) {
			$onboarding_style_file = 'onboarding-rtl.css';
		}
		return $onboarding_style_file;
	}

	/**
	 * Get jodit style.
	 *
	 * @since 1.1.0
	 * @return string $jodit_style_file CSS file name depending on RTL or LTR.
	 */
	public static function jodit_style_css() {
		$jodit_style_file = 'jodit.css';
		if ( Get::is_rtl() && file_exists( SURECOOKIE_DIR . 'assets/css/jodit-rtl.css' ) ) {
			$jodit_style_file = 'jodit-rtl.css';
		}
		return $jodit_style_file;
	}

	/**
	 * Get cookie-policy style.
	 *
	 * @since 1.1.0
	 * @return string $cookie_policy_style_file CSS file name depending on RTL or LTR.
	 */
	public static function cookie_policy_style_css() {
		// Not cookie-policy.css: that exact path is a rule in Fanboy's Cookie
		// Monster, so cookie-notice blocklists 204'd it and the tables lost every style.
		$cookie_policy_style_file = 'policy-tables.css';
		if ( Get::is_rtl() && file_exists( SURECOOKIE_DIR . 'assets/css/policy-tables-rtl.css' ) ) {
			$cookie_policy_style_file = 'policy-tables-rtl.css';
		}
		return $cookie_policy_style_file;
	}

	/**
	 * Get privacy-policy style.
	 *
	 * @since 1.5.0
	 * @return string CSS file name depending on RTL or LTR.
	 */
	public static function privacy_policy_style_css() {
		$privacy_policy_style_file = 'privacy-policy.css';
		if ( Get::is_rtl() && file_exists( SURECOOKIE_DIR . 'assets/css/privacy-policy-rtl.css' ) ) {
			$privacy_policy_style_file = 'privacy-policy-rtl.css';
		}
		return $privacy_policy_style_file;
	}

	/**
	 * Get the SureCookie-managed policy page for a setting, drafts included.
	 *
	 * Deliberately reports `status` instead of gating on `publish` the way
	 * cookie_policy_page_details() does: the privacy policy is generated as a
	 * draft on purpose, so a publish-only helper would report the page we just
	 * created as missing. Callers that need a public link check the status.
	 *
	 * `status` is an empty string when the setting points at nothing, and
	 * 'trash' when the page was thrown away, which is what the screens use to
	 * offer a replacement.
	 *
	 * @since 1.5.0
	 * @param string $setting_key Setting holding the page ID.
	 * @return array{id: int, url: string, title: string, status: string, edit_url: string}
	 */
	public static function managed_policy_page_details( string $setting_key ): array {
		$data = [
			'id'       => 0,
			'url'      => '',
			'title'    => '',
			'status'   => '',
			'edit_url' => '',
		];

		return self::policy_page_details( absint( Settings::get( $setting_key ) ) );
	}

	/**
	 * The same resolution from a raw page id, for a page WordPress owns rather
	 * than one of ours.
	 *
	 * @since 1.7.0
	 * @param int $page_id Post ID.
	 * @return array{id: int, url: string, title: string, status: string, edit_url: string}
	 */
	private static function policy_page_details( int $page_id ): array {
		$data = [
			'id'       => 0,
			'url'      => '',
			'title'    => '',
			'status'   => '',
			'edit_url' => '',
		];

		$page_id = self::resolve_translated_policy_page_id( $page_id );

		if ( $page_id <= 0 ) {
			return $data;
		}

		$page = get_post( $page_id );

		if ( ! $page instanceof \WP_Post ) {
			return $data;
		}

		$data['id']       = $page->ID;
		$data['status']   = $page->post_status;
		$data['title']    = sanitize_text_field( get_the_title( $page ) );
		$data['edit_url'] = (string) get_edit_post_link( $page->ID, 'raw' );

		$permalink = get_permalink( $page );

		if ( ! $permalink ) {
			return $data;
		}

		$parsed_url = wp_parse_url( $permalink );
		$home_url   = wp_parse_url( home_url() );

		// Same-host guard, mirroring cookie_policy_page_details(): a permalink
		// pointing off-site is never something we hand to a visitor.
		if ( ! empty( $parsed_url['host'] ) && isset( $home_url['host'] ) && strtolower( $parsed_url['host'] ) === strtolower( $home_url['host'] ) ) {
			$data['url'] = esc_url( $permalink );
		}

		return $data;
	}

	/**
	 * The site's privacy policy page (URL and title), published pages only.
	 *
	 * WordPress's own `wp_page_for_privacy_policy` is asked first, because that
	 * is the site's declared privacy policy: the page core links from the login
	 * screen and themes link from the footer. `privacy_policy_page_id` is a
	 * different thing - the page SureCookie GENERATED - and the two are
	 * deliberately separate, which is why promoting one to the other is its own
	 * admin action. Reading ours alone left every site that wrote its own policy
	 * with no privacy link in the consent modal at all, which is the one link
	 * CCPA requires in a notice at collection.
	 *
	 * Ours is the fallback rather than the ceiling, so a site that generated a
	 * policy and never promoted it still links the only one it has. Each
	 * candidate must be published, so a draft falls through instead of blocking.
	 *
	 * Shaped like cookie_policy_page_details() so token and shortcode callers
	 * can treat the policy pages the same way.
	 *
	 * @since 1.5.0
	 * @return array<string, string> Associative array with 'url' and 'title' keys.
	 */
	public static function privacy_policy_page_details(): array {
		$candidates = [
			absint( get_option( 'wp_page_for_privacy_policy' ) ),
			absint( Settings::get( 'privacy_policy_page_id' ) ),
		];

		foreach ( array_filter( array_unique( $candidates ) ) as $page_id ) {
			$page = self::policy_page_details( $page_id );

			if ( $page['status'] === 'publish' && $page['url'] !== '' ) {
				return [
					'url'   => $page['url'],
					'title' => $page['title'],
				];
			}
		}

		return [
			'url'   => '',
			'title' => '',
		];
	}

	/**
	 * Get legal notice (imprint / Impressum) page details, published pages only.
	 *
	 * @since 1.7.0
	 * @return array<string, string> Associative array with 'url' and 'title' keys.
	 */
	public static function legal_notice_page_details(): array {
		$page = self::managed_policy_page_details( 'legal_notice_page_id' );

		if ( $page['status'] !== 'publish' || $page['url'] === '' ) {
			return [
				'url'   => '',
				'title' => '',
			];
		}

		return [
			'url'   => $page['url'],
			'title' => $page['title'],
		];
	}

	/**
	 * The legal pages the consent UI links to, in disclosure order.
	 *
	 * Rendered as first-class links in the preferences-modal footer rather than
	 * left to the `{cookie_policy_link}` token: with the overlay on, every node
	 * outside the plugin's own roots is made `inert`, so a theme-footer imprint
	 * or privacy link is unreachable by mouse, keyboard and screen reader for as
	 * long as the prompt is up (#1244).
	 *
	 * Order and visibility come from `policy_links_order`, where presence is
	 * visibility, exactly as `button_order` works for the banner buttons. Each
	 * entry carries a stable `id` so the modal can suppress the duplicate when
	 * the admin has already placed that page's token in their own copy. Unset,
	 * unpublished and off-host pages resolve to an empty url and are dropped
	 * here, so a caller never has to re-check.
	 *
	 * @since 1.7.0
	 * @return array<int, array{id: string, url: string, title: string}>
	 */
	public static function consent_policy_links(): array {
		$order = Settings::normalize_policy_links_order( Settings::get( 'policy_links_order' ) );
		$links = [];

		// Resolved per visible id rather than all three up front: this runs on
		// every anonymous page load, and a hidden link is a get_post() nobody reads.
		foreach ( $order === '' ? [] : explode( ',', $order ) as $id ) {
			if ( $id === 'cookie_policy' ) {
				$page     = self::cookie_policy_page_details();
				$fallback = __( 'Cookie Policy', 'surecookie' );
			} elseif ( $id === 'privacy_policy' ) {
				$page     = self::privacy_policy_page_details();
				$fallback = __( 'Privacy Policy', 'surecookie' );
			} else {
				$page     = self::legal_notice_page_details();
				$fallback = __( 'Legal Notice', 'surecookie' );
			}

			if ( ( $page['url'] ?? '' ) === '' ) {
				continue;
			}

			// Decoded because WordPress decodes only top-level localized scalars, and
			// React prints these as text: `&#038;` would show, and cut a query string.
			$links[] = [
				'id'    => $id,
				'url'   => html_entity_decode( $page['url'], ENT_QUOTES, 'UTF-8' ),
				'title' => html_entity_decode( $page['title'] !== '' ? $page['title'] : $fallback, ENT_QUOTES, 'UTF-8' ),
			];
		}

		return $links;
	}

	/**
	 * Get cookie policy page details (URL and title).
	 *
	 * @since 0.0.1
	 * @return array<string, string> Associative array with 'url' and 'title' keys.
	 */
	public static function cookie_policy_page_details(): array {
		$data = [
			'url'   => '',
			'title' => '',
		];

		$policy_page_id = self::resolve_translated_policy_page_id( absint( Settings::get( 'cookie_policy_page_id' ) ) );

		if ( $policy_page_id > 0 ) {
			$page = get_post( $policy_page_id );
			if ( $page instanceof \WP_Post && $page->post_status === 'publish' ) {
				$permalink = get_permalink( $page );
				if ( ! $permalink ) {
					return $data;
				}

				$parsed_url = wp_parse_url( $permalink );
				$home_url   = wp_parse_url( home_url() );

				if ( ! empty( $parsed_url['host'] ) && isset( $home_url['host'] ) && strtolower( $parsed_url['host'] ) === strtolower( $home_url['host'] ) ) {
					$data['url']   = esc_url( $permalink );
					$data['title'] = sanitize_text_field( get_the_title( $page ) );
				}
			}
		}

		return $data;
	}

	/**
	 * Button label color (white or near-black), whichever has the higher WCAG
	 * contrast against the button background.
	 *
	 * A brightness threshold is not enough here (issue #1063): mid-tone
	 * buttons like dark-blue's #0285FF read as "dark", yet white text on them
	 * only reaches 3.6:1 while near-black reaches 4.9:1. Comparing the two
	 * actual contrast ratios always picks the side that can pass. White for
	 * unparseable input, matching the stylesheet's previous hardcoded label.
	 *
	 * @since 1.5.0
	 * @param string $background Button background color.
	 * @return string '#111827' or '#ffffff'.
	 */
	public static function button_label_color( string $background ): string {
		$luminance = self::relative_luminance( $background );

		if ( $luminance === null ) {
			return '#ffffff';
		}

		$dark_luminance = (float) self::relative_luminance( '#111827' );

		$against_white = 1.05 / ( $luminance + 0.05 );
		$against_dark  = ( $luminance + 0.05 ) / ( $dark_luminance + 0.05 );

		return $against_white >= $against_dark ? '#ffffff' : '#111827';
	}

	/**
	 * WCAG 2.1 relative luminance of a CSS color.
	 *
	 * Accepts hex (3 or 6 digits) and rgb()/rgba() - Pro's custom palette may
	 * store rgba. Alpha is ignored: the backdrop is unknowable, so the solid
	 * channels are the best available estimate.
	 *
	 * @since 1.5.0
	 * @param string $color Color value.
	 * @return float|null Luminance 0..1, or null when unparseable.
	 */
	private static function relative_luminance( string $color ): ?float {
		$color = trim( $color );
		$rgb   = null;

		if ( preg_match( '/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/i', $color, $m ) ) {
			$rgb = [ min( 255, (int) $m[1] ), min( 255, (int) $m[2] ), min( 255, (int) $m[3] ) ];
		} else {
			$hex = ltrim( $color, '#' );

			if ( strlen( $hex ) === 3 ) {
				$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
			}

			if ( preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
				$rgb = [ (int) hexdec( substr( $hex, 0, 2 ) ), (int) hexdec( substr( $hex, 2, 2 ) ), (int) hexdec( substr( $hex, 4, 2 ) ) ];
			}
		}

		if ( $rgb === null ) {
			return null;
		}

		$channels = [];
		foreach ( $rgb as $value ) {
			$channel    = $value / 255;
			$channels[] = $channel <= 0.03928 ? $channel / 12.92 : ( ( $channel + 0.055 ) / 1.055 ) ** 2.4;
		}

		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/**
	 * Legible text color (near-black or white) for a background hex, using
	 * perceived brightness (ITU-R BT.601). Guarantees the placeholder text
	 * contrasts its background regardless of palette. White for invalid input.
	 *
	 * @since 1.4.0
	 * @param string $hex Background color (hex).
	 * @return string '#111827' (dark) or '#ffffff' (light).
	 */
	private static function readable_text_color( string $hex ): string {
		$hex = ltrim( trim( $hex ), '#' );

		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			return '#ffffff';
		}

		$r = (int) hexdec( substr( $hex, 0, 2 ) );
		$g = (int) hexdec( substr( $hex, 2, 2 ) );
		$b = (int) hexdec( substr( $hex, 4, 2 ) );

		$brightness = ( $r * 299 + $g * 587 + $b * 114 ) / 1000;

		return $brightness > 140 ? '#111827' : '#ffffff';
	}

	/**
	 * Resolve cookie policy page ID for the current language.
	 *
	 * Supports WPML and Polylang translation APIs when available.
	 *
	 * @param int $page_id Source page ID from settings.
	 * @return int
	 * @since 1.2.1
	 */
	private static function resolve_translated_policy_page_id( int $page_id ): int {
		if ( $page_id <= 0 ) {
			return 0;
		}

		$wpml_page_id = apply_filters( 'wpml_object_id', $page_id, 'page', true );

		if ( is_numeric( $wpml_page_id ) && (int) $wpml_page_id > 0 && (int) $wpml_page_id !== $page_id ) {
			return (int) $wpml_page_id;
		}

		if ( function_exists( 'pll_get_post' ) ) {
			$polylang_page_id = pll_get_post( $page_id );
			if ( is_numeric( $polylang_page_id ) && (int) $polylang_page_id > 0 ) {
				return (int) $polylang_page_id;
			}
		}

		return $page_id;
	}
}
