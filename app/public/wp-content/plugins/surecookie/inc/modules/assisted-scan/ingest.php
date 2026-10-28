<?php
/**
 * Turns browser findings into scan results.
 *
 *  - `record_page()` normalizes and stores one page as soon as it arrives, marked
 *    partial. Storing eagerly keeps an abandoned walk lossless: a closed tab keeps
 *    everything already collected.
 *  - `finalize()` classifies the accumulated set against the Known Services
 *    catalog, cross-checks it with the server-side loopback pass, and fires the
 *    scan-results action once more so the diff, declared-cookie seeding and any Pro
 *    follow-up happen exactly once.
 *
 * Classification is entirely local: the bundled catalog ships with the plugin, so
 * none of this needs the SaaS - the point of an assisted scan, which exists for
 * sites the SaaS cannot reach.
 *
 * @package SureCookie\Inc\Modules\AssistedScan
 * @since   1.3.0
 */

namespace SureCookie\Inc\Modules\AssistedScan;

use SureCookie\Inc\Functions\Sanitize;
use SureCookie\Inc\Modules\Services\Service_Matcher;
use SureCookie\Inc\Modules\Services\Services_Source;
use SureCookie\Inc\Modules\SiteScanner\LoopbackScanner;
use SureCookie\Inc\Modules\SiteScanner\SaasClient;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Ingest
 *
 * @since 1.3.0
 */
class Ingest {
	use GetInstance;

	/**
	 * Scanner name recorded against everything this class produces.
	 *
	 * @since 1.3.0
	 */
	public const SOURCE = 'assisted';

	/**
	 * Cached catalog cookie index, keyed by lower-cased name.
	 *
	 * @since 1.3.0
	 * @var array<string, array<string, mixed>>|null
	 */
	private $cookie_index = null;

	/**
	 * Whether a findings payload is acceptable.
	 *
	 * The browser already trims to these caps, so exceeding them means a looping or
	 * tampered client. Rejected rather than truncated: silently keeping 150 of 5000
	 * would look like a successful scan.
	 *
	 * @param array<string, mixed> $findings Raw findings payload.
	 * @since 1.3.0
	 * @return string Error code, or an empty string when the payload is acceptable.
	 */
	public static function validate_findings( array $findings ): string {
		$cookies   = $findings['cookies'] ?? [];
		$resources = $findings['resources'] ?? [];

		if ( ! is_array( $cookies ) || ! is_array( $resources ) ) {
			return 'malformed_findings';
		}

		if ( count( $cookies ) > Session::MAX_COOKIES_PER_PAGE ) {
			return 'too_many_cookies';
		}

		if ( count( $resources ) > Session::MAX_RESOURCES_PER_PAGE ) {
			return 'too_many_resources';
		}

		return '';
	}

	/**
	 * Normalize and store one collected page.
	 *
	 * @param int                  $index    Page index being reported.
	 * @param array<string, mixed> $findings Raw findings payload.
	 * @param string               $error    Failure reason when the page could not be collected.
	 * @since 1.3.0
	 * @return bool True when the page was recorded.
	 */
	public function record_page( int $index, array $findings, string $error = '' ): bool {
		$session = Session::get_instance();
		$state   = $session->get();

		$raw_cookies   = is_array( $findings['cookies'] ?? null ) ? $findings['cookies'] : [];
		$raw_resources = is_array( $findings['resources'] ?? null ) ? $findings['resources'] : [];

		$cookies = $this->enrich_cookies(
			Normalizer::cookies( $raw_cookies, $this->existing_index(), 0, Normalizer::request_host() )
		);

		$resources = $this->enrich_resources( Normalizer::resources( $raw_resources ) );

		if ( ! $session->record_page( $index, $cookies, $resources, $error ) ) {
			return false;
		}

		$url = (string) ( $state['pages'][ $index ]['url'] ?? '' );

		if ( $error === '' ) {
			Logger::get_instance()->save_log(
				sprintf(
					/* translators: 1: page number, 2: cookie count, 3: resource count. */
					__( 'Page %1$d collected: %2$d cookies, %3$d third-party resources.', 'surecookie' ),
					$index + 1,
					count( $cookies ),
					count( $resources['scripts'] ) + count( $resources['iframes'] )
				)
			);
		} else {
			Logger::get_instance()->save_log(
				sprintf(
					/* translators: 1: page number, 2: error reason. */
					__( 'Page %1$d could not be collected (%2$s).', 'surecookie' ),
					$index + 1,
					$error
				)
			);
		}

		// Nothing to store: a failed page has no findings, and an empty one would only
		// repeat the "Processing scanned results... / 0 cookies" block finalize() logs.
		$has_findings = $cookies !== []
			|| $resources['scripts'] !== []
			|| $resources['iframes'] !== [];

		if ( $error !== '' || ! $has_findings ) {
			return true;
		}

		// Store immediately, marked partial: the once-per-scan work (declared
		// cookies, the diff, the history record) is deferred to finalize().
		do_action(
			'surecookie_saas_scan_results_received',
			[
				'pages'   => [
					[
						'url'     => $url,
						'cookies' => $cookies,
						'scripts' => $resources['scripts'],
						'iframes' => $resources['iframes'],
					],
				],
				'source'  => self::SOURCE,
				'partial' => true,
			]
		);

		return true;
	}

	/**
	 * Classify the whole walk, cross-check it, and close the session.
	 *
	 * Idempotent and self-guarding: a no-op when no session is open, so the
	 * abandoned-walk cron and a normal finish can both call it safely.
	 *
	 * @param bool $abandoned Whether the abandoned-walk rescue is calling this rather
	 *                        than the browser finishing normally. Recorded for
	 *                        analytics; it does not change what gets stored.
	 * @since 1.3.0
	 * @return array<string, mixed> Summary of what the walk found.
	 */
	public function finalize( bool $abandoned = false ): array {
		$session = Session::get_instance();
		$state   = $session->get();

		if ( empty( $state['token'] ) ) {
			return [
				'cookies_count'  => 0,
				'services_count' => 0,
			];
		}

		$cookies   = $this->enrich_cookies( $session->collected_cookies( $state ) );
		$resources = $this->enrich_resources( $session->collected_resources( $state ) );

		$pages = $this->build_pages( $state, $cookies, $resources );

		// Cross-check with a server-side fetch: it sees only statically embedded
		// services, but from the server - so anything the browser missed is browser-side
		// suppression (ad blocker/privacy extension) that would make a lossy scan look clean.
		$browser_slugs = $this->match_slugs( $pages );
		$supplement    = $this->loopback_supplement( $state, $browser_slugs );

		if ( ! empty( $supplement['resources'] ) ) {
			$pages[0]['scripts'] = array_merge( $pages[0]['scripts'], $supplement['resources'] );
		}

		$services_count = count( array_unique( array_merge( $browser_slugs, array_keys( $supplement['missed'] ) ) ) );

		do_action(
			'surecookie_saas_scan_results_received',
			[
				'pages'   => $pages,
				'source'  => self::SOURCE,
				'partial' => false,
			]
		);

		$this->store_outcome( count( $cookies ), $supplement['missed'] );

		Logger::get_instance()->save_log( '' );
		Logger::get_instance()->save_log(
			sprintf(
				/* translators: 1: cookie count, 2: service count. */
				__( 'Assisted scan finished: %1$d cookies, %2$d known services.', 'surecookie' ),
				count( $cookies ),
				$services_count
			)
		);

		if ( ! empty( $supplement['missed'] ) ) {
			Logger::get_instance()->save_log(
				sprintf(
					/* translators: %s: comma-separated service names. */
					__( 'A first-party check also found: %s. Your browser may have blocked these.', 'surecookie' ),
					implode( ', ', array_values( $supplement['missed'] ) )
				)
			);
		}

		$summary = [
			'cookies_count'     => count( $cookies ),
			'services_count'    => $services_count,
			'adblock_suspected' => ! empty( $supplement['missed'] ),
			'missed_services'   => array_values( $supplement['missed'] ),
		];

		// Recorded before the session is cleared, because the page count comes from it.
		Telemetry::record_completed( $summary, count( $pages ), $abandoned );

		// Leave a terminal state behind. Clearing the session alone makes the status
		// jump straight from running to idle, and the screen drives its completion
		// path (final log fetch, toast, cache invalidation) off that transition, so
		// without this the last page and the closing line never arrive.
		$state    = $session->get();
		$progress = $session->progress( $state );
		$failures = [];

		foreach ( is_array( $state['pages'] ?? null ) ? $state['pages'] : [] as $page ) {
			if ( ( $page['status'] ?? '' ) === 'failed' ) {
				$failures[] = [
					'url'   => (string) ( $page['url'] ?? '' ),
					'error' => (string) ( $page['error'] ?? '' ),
				];
			}
		}

		$session->mark_just_finished( $progress, $failures );
		$session->clear();

		return $summary;
	}

	/**
	 * Fill in what the catalog knows about each observed cookie.
	 *
	 * Only ever fills blanks: an existing value (from observation or the replaced
	 * row) outranks a catalog default. See Normalizer::merge_without_downgrade().
	 *
	 * @param array<int, array<string, mixed>> $cookies Normalized cookies.
	 * @since 1.3.0
	 * @return array<int, array<string, mixed>>
	 */
	public function enrich_cookies( array $cookies ): array {
		$index = $this->catalog_cookie_index();

		if ( $index === [] ) {
			return $cookies;
		}

		foreach ( $cookies as $position => $cookie ) {
			$definition = $this->match_catalog_cookie( (string) ( $cookie['name'] ?? '' ), $index );

			if ( $definition === null ) {
				continue;
			}

			foreach ( [ 'category', 'purpose', 'description' ] as $field ) {
				if ( Sanitize::is_blank( $cookie[ $field ] ?? null ) && ! Sanitize::is_blank( $definition[ $field ] ?? null ) ) {
					$cookies[ $position ][ $field ] = $definition[ $field ];
				}
			}

			if ( Sanitize::is_blank( $cookie['owner'] ?? null ) && ! Sanitize::is_blank( $definition['provider'] ?? null ) ) {
				$cookies[ $position ]['owner'] = $definition['provider'];
			}

			// A lifetime the browser could not observe; the catalog states it in days.
			// Better than publishing a persistent cookie as a session one.
			$days = absint( $definition['duration_days'] ?? 0 );
			if ( Sanitize::is_blank( $cookie['expires_at'] ?? null ) && $days > 0 ) {
				$cookies[ $position ]['expires_at'] = gmdate( 'c', (int) strtotime( "+{$days} days" ) );
			}
		}

		return $cookies;
	}

	/**
	 * Attach the catalog's service label and category to each resource.
	 *
	 * @param array<string, mixed> $resources Normalized `scripts` and `iframes`.
	 * @since 1.3.0
	 * @return array{scripts: array<int, array<string, mixed>>, iframes: array<int, array<string, mixed>>}
	 */
	public function enrich_resources( array $resources ): array {
		$catalog = Services_Source::get_instance()->get_catalog();
		$matcher = Service_Matcher::get_instance();

		$scripts = is_array( $resources['scripts'] ?? null ) ? $resources['scripts'] : [];
		$iframes = is_array( $resources['iframes'] ?? null ) ? $resources['iframes'] : [];

		foreach ( [ 'scripts', 'iframes' ] as $kind ) {
			$entries = $kind === 'scripts' ? $scripts : $iframes;

			foreach ( $entries as $position => $entry ) {
				$url  = (string) ( $entry['url'] ?? $entry['src'] ?? '' );
				$slug = $matcher->match_url( $url );

				if ( $slug === '' || ! isset( $catalog[ $slug ] ) || ! is_array( $catalog[ $slug ] ) ) {
					continue;
				}

				$service    = $catalog[ $slug ];
				$vendor_key = $kind === 'scripts' ? 'vendor_name' : 'vendor';

				if ( Sanitize::is_blank( $entry[ $vendor_key ] ?? null ) && ! Sanitize::is_blank( $service['label'] ?? null ) ) {
					$entries[ $position ][ $vendor_key ] = (string) $service['label'];
				}

				if ( Sanitize::is_blank( $entry['tracking_category'] ?? null ) && ! Sanitize::is_blank( $service['category'] ?? null ) ) {
					$entries[ $position ]['tracking_category'] = (string) $service['category'];
				}
			}

			if ( $kind === 'scripts' ) {
				$scripts = $entries;
			} else {
				$iframes = $entries;
			}
		}

		return [
			'scripts' => $scripts,
			'iframes' => $iframes,
		];
	}

	/**
	 * The prefix a catalog cookie name matches on, or an empty string when the name
	 * is literal.
	 *
	 * The catalog spells per-property cookies with a placeholder, not a regex
	 * (`_ga_<container-id>`, `_hjSessionUser_*`, `_dc_gtm_UA-*`), so an observed
	 * `_ga_G1AB2CD3` matches only once the placeholder is reduced to its literal prefix.
	 *
	 * @param string $name Catalog cookie name.
	 * @since 1.3.0
	 * @return string Prefix to match on, or '' when the name is literal.
	 */
	public static function catalog_name_prefix( string $name ): string {
		$cut = strcspn( $name, '<*' );

		if ( $cut === strlen( $name ) ) {
			return '';
		}

		return substr( $name, 0, $cut );
	}

	/**
	 * Find the catalog definition for an observed cookie name.
	 *
	 * Exact match first, then the longest matching placeholder prefix, so
	 * `_ga_<container-id>` never wins over a literal `_ga`.
	 *
	 * @param string                              $name  Observed cookie name.
	 * @param array<string, array<string, mixed>> $index Catalog cookie index.
	 * @since 1.3.0
	 * @return array<string, mixed>|null
	 */
	private function match_catalog_cookie( string $name, array $index ) {
		$name = strtolower( trim( $name ) );

		if ( $name === '' ) {
			return null;
		}

		if ( isset( $index[ $name ] ) ) {
			return $index[ $name ];
		}

		$best        = null;
		$best_length = 0;

		foreach ( $index as $definition ) {
			$prefix = (string) ( $definition['_prefix'] ?? '' );

			if ( $prefix === '' || strncmp( $name, $prefix, strlen( $prefix ) ) !== 0 ) {
				continue;
			}

			if ( strlen( $prefix ) > $best_length ) {
				$best        = $definition;
				$best_length = strlen( $prefix );
			}
		}

		return $best;
	}

	/**
	 * Index every catalog cookie by lower-cased name, carrying its match prefix.
	 *
	 * @since 1.3.0
	 * @return array<string, array<string, mixed>>
	 */
	private function catalog_cookie_index(): array {
		if ( $this->cookie_index !== null ) {
			return $this->cookie_index;
		}

		$index = [];

		foreach ( Services_Source::get_instance()->get_catalog() as $service ) {
			if ( ! is_array( $service ) || ! is_array( $service['cookies'] ?? null ) ) {
				continue;
			}

			foreach ( $service['cookies'] as $cookie ) {
				if ( ! is_array( $cookie ) || empty( $cookie['name'] ) ) {
					continue;
				}

				$name = strtolower( trim( (string) $cookie['name'] ) );

				if ( $name === '' || isset( $index[ $name ] ) ) {
					continue;
				}

				$cookie['_prefix'] = strtolower( self::catalog_name_prefix( $name ) );
				$index[ $name ]    = $cookie;
			}
		}

		$this->cookie_index = $index;

		return $index;
	}

	/**
	 * Build the `pages` payload the scan-results pipeline expects.
	 *
	 * One entry per page walked, so the recorded page count is honest. Findings ride
	 * on the first entry: deduplicated across the whole walk (keeping the session
	 * option bounded), and every consumer reads them across pages anyway.
	 *
	 * @param array<string, mixed>             $state     Session state.
	 * @param array<int, array<string, mixed>> $cookies   Enriched cookies.
	 * @param array<string, mixed>             $resources Enriched resources.
	 * @since 1.3.0
	 * @return array<int, array<string, mixed>>
	 */
	private function build_pages( array $state, array $cookies, array $resources ): array {
		$pages = [];

		foreach ( is_array( $state['pages'] ?? null ) ? $state['pages'] : [] as $page ) {
			$pages[] = [
				'url'     => (string) ( $page['url'] ?? '' ),
				'status'  => (string) ( $page['status'] ?? 'pending' ),
				'cookies' => [],
				'scripts' => [],
				'iframes' => [],
			];
		}

		if ( $pages === [] ) {
			$pages[] = [
				'url'     => home_url( '/' ),
				'status'  => 'done',
				'cookies' => [],
				'scripts' => [],
				'iframes' => [],
			];
		}

		$pages[0]['cookies'] = $cookies;
		$pages[0]['scripts'] = $resources['scripts'];
		$pages[0]['iframes'] = $resources['iframes'];

		return $pages;
	}

	/**
	 * Known-service slugs the browser's findings match.
	 *
	 * @param array<int, array<string, mixed>> $pages Result pages.
	 * @since 1.3.0
	 * @return array<int, string>
	 */
	private function match_slugs( array $pages ): array {
		$matcher = Service_Matcher::get_instance();

		return array_values(
			array_unique( $matcher->match_services( [], $matcher->collect_resource_urls( $pages ) ) )
		);
	}

	/**
	 * Run the server-side loopback pass and report what the browser missed.
	 *
	 * Each missed service becomes a synthetic script resource so its catalog cookies
	 * still get declared: the service demonstrably is on the site, and the browser
	 * missed it only because something suppressed the request.
	 *
	 * @param array<string, mixed> $state         Session state.
	 * @param array<int, string>   $browser_slugs Slugs the browser matched.
	 * @since 1.3.0
	 * @return array{missed: array<string, string>, resources: array<int, array<string, mixed>>}
	 */
	private function loopback_supplement( array $state, array $browser_slugs ): array {
		$urls = [];

		foreach ( is_array( $state['pages'] ?? null ) ? $state['pages'] : [] as $page ) {
			if ( ! empty( $page['url'] ) ) {
				$urls[] = (string) $page['url'];
			}
		}

		if ( $urls === [] ) {
			return [
				'missed'    => [],
				'resources' => [],
			];
		}

		$detected = LoopbackScanner::get_instance()->detect_services( $urls );
		$missed   = array_diff_key( $detected, array_fill_keys( $browser_slugs, true ) );

		if ( $missed === [] ) {
			return [
				'missed'    => [],
				'resources' => [],
			];
		}

		$catalog   = Services_Source::get_instance()->get_catalog();
		$patterns  = Service_Matcher::get_instance()->get_service_patterns( array_keys( $missed ) );
		$resources = [];

		foreach ( $missed as $slug => $label ) {
			$pattern = $patterns[ $slug ][0] ?? '';

			if ( $pattern === '' ) {
				continue;
			}

			$url    = 'https://' . ltrim( $pattern, '/' );
			$domain = Normalizer::host_only( $url );

			if ( $domain === '' ) {
				continue;
			}

			$service     = is_array( $catalog[ $slug ] ?? null ) ? $catalog[ $slug ] : [];
			$resources[] = [
				'url'               => $url,
				'domain'            => $domain,
				'is_third_party'    => true,
				'vendor_name'       => (string) ( $service['label'] ?? $label ),
				'tracking_category' => (string) ( $service['category'] ?? '' ),
			];
		}

		return [
			'missed'    => $missed,
			'resources' => $resources,
		];
	}

	/**
	 * Record the walk's outcome for the admin UI.
	 *
	 * Clears the previous cloud scan's outcome, so a successful assisted scan takes
	 * down the stale "your host blocked the scanner" notice instead of contradicting
	 * the results below.
	 *
	 * @param int                   $findings Number of cookies found.
	 * @param array<string, string> $missed   Services the browser missed, slug => label.
	 * @since 1.3.0
	 * @return void
	 */
	private function store_outcome( int $findings, array $missed ): void {
		set_transient(
			SaasClient::LAST_OUTCOME_TRANSIENT,
			[
				'outcome'           => $findings > 0 ? 'ok' : 'empty',
				'findings'          => $findings,
				// Named so the UI can tell an assisted result from a cloud one without inferring.
				'source'            => self::SOURCE,
				'adblock_suspected' => $missed !== [],
				'detected_services' => $missed,
				'at'                => time(),
			],
			DAY_IN_SECONDS
		);
	}

	/**
	 * Index of the cookies already stored, for signature reuse.
	 *
	 * @since 1.3.0
	 * @return array<string, array<string, mixed>>
	 */
	private function existing_index(): array {
		$stored = get_option( SURECOOKIE_SCANNED_COOKIES_OPTION, [] );

		return Normalizer::build_existing_index( is_array( $stored ) ? $stored : [] );
	}
}
