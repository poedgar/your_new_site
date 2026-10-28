<?php
/**
 * Resources the blocker actually matched on this site.
 *
 * The catalog is a blocking-only input: nothing on Scripts and Embeds reads it.
 * So a resource gated purely by a catalog pattern - a YouTube embed, a Presto
 * video - has no row anywhere, and an admin cannot recategorise it, exclude it,
 * or even see that SureCookie is acting on it. The scanner cannot fill the gap
 * either, because by the time it looks the blocker has already replaced the
 * resource with a placeholder that carries no URL.
 *
 * Record what the blocker matched while it runs, and surface those as rows. The
 * list is per-site and stays honest: it only ever contains resources this site
 * actually served.
 *
 * @package SureCookie\Inc\Modules\ScriptBlocking
 * @since 1.5.0
 */

namespace SureCookie\Inc\Modules\ScriptBlocking;

use SureCookie\Inc\Functions\Cookie_Identity;
use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Modules\Services\Declared_Cookies;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Matched_Resources
 *
 * @since 1.5.0
 */
class Matched_Resources {
	use GetInstance;

	/**
	 * Option holding the per-site matched set.
	 */
	public const OPTION = 'surecookie_matched_resources';

	/**
	 * Upper bound on stored patterns per kind, so a site that generates unique
	 * hostnames cannot grow this without limit.
	 */
	private const MAX_PER_KIND = 200;

	/**
	 * Patterns matched during this request, as [ kind => [ pattern => entry ] ].
	 *
	 * @var array<string, array<string, array<string, string>>>
	 */
	private array $seen = [];

	/**
	 * Whether this request saw something the stored set does not have.
	 *
	 * @var bool
	 */
	private bool $dirty = false;

	/**
	 * Constructor.
	 *
	 * @since 1.5.0
	 */
	private function __construct() {
		add_filter( 'surecookie_scanned_resources', [ $this, 'merge_into_payload' ] );
		add_filter( 'surecookie_scanned_cookies', [ $this, 'merge_declared_cookies' ] );
		add_action( 'shutdown', [ $this, 'flush' ] );
	}

	/**
	 * Note that the blocker matched a resource.
	 *
	 * Called on every match, not only on the ones that end up parked, so a
	 * resource an admin has already excluded still has a row to switch back.
	 *
	 * @since 1.5.0
	 * @param string $kind     Resource kind ('script'|'iframe').
	 * @param string $subject  Resource URL, or the pattern that matched.
	 * @param string $service  Matched service key.
	 * @param string $category Category the pattern resolved to.
	 * @param string $via      How the pattern matched: 'content' for an inline body, 'src' otherwise.
	 * @return void
	 */
	public function record( string $kind, string $subject, string $service, string $category, string $via = 'src' ): void {
		$kind = $kind === 'iframe' ? 'iframe' : 'script';

		// A custom rule already has a row of its own, so it is not news here.
		if ( strncmp( $service, 'custom-', 7 ) === 0 ) {
			return;
		}

		// Catalog patterns are not all hosts: `pintrk` and `firebase-settings`
		// ship as inline-code keywords, and giving those a scheme invents a
		// domain that then shows up as a resource row.
		if ( strpos( $subject, '.' ) === false ) {
			return;
		}

		$pattern = $this->host_of( $subject );
		if ( $pattern === '' || $this->is_first_party( $pattern ) ) {
			return;
		}

		// Provenance only ever strengthens, so a page carrying both an inline
		// mention and the real <script src> does not depend on tag order.
		$via  = $via === 'content' ? 'content' : 'src';
		$seen = $this->seen[ $kind ][ $pattern ]['via'] ?? null;
		if ( $seen === 'src' || $seen === $via ) {
			return;
		}

		$this->seen[ $kind ][ $pattern ] = [
			'service'  => $service,
			'category' => $category,
			'via'      => $via,
		];

		$this->dirty = true;
	}

	/**
	 * Add a row for every matched pattern the scan does not already cover.
	 *
	 * @since 1.5.0
	 * @param mixed $resources Scanned-resources payload.
	 * @return mixed
	 */
	public function merge_into_payload( $resources = null ) {
		if ( ! is_array( $resources ) ) {
			return $resources;
		}

		$stored = $this->stored();

		foreach ( [
			'script' => 'scripts',
			'iframe' => 'iframes',
		] as $kind => $bucket ) {
			$rows  = isset( $resources[ $bucket ] ) && is_array( $resources[ $bucket ] ) ? $resources[ $bucket ] : [];
			$known = [];

			foreach ( $rows as $row ) {
				if ( is_array( $row ) && isset( $row['domain'] ) ) {
					$known[ strtolower( (string) $row['domain'] ) ] = true;
				}
			}

			foreach ( (array) ( $stored[ $kind ] ?? [] ) as $pattern => $entry ) {
				$pattern = (string) $pattern;
				if ( isset( $known[ strtolower( $pattern ) ] ) ) {
					continue;
				}

				$rows[] = [
					'domain'       => $pattern,
					'vendor'       => '',
					'url'          => '',
					'category'     => (string) ( $entry['category'] ?? 'uncategorized' ),
					'source'       => 'catalog',
					'service_slug' => (string) ( $entry['service'] ?? '' ),
				];
			}

			$resources[ $bucket ] = $rows;
		}

		return $resources;
	}

	/**
	 * Add the declared cookies of every service we matched, for services no scan
	 * reported.
	 *
	 * A gated embed is replaced before any scan sees it, so its cookies were
	 * missing from All Cookies and from the public cookie policy even though the
	 * site demonstrably loads that service once a visitor consents.
	 *
	 * @since 1.5.0
	 * @param mixed $cookies Cookies grouped by category id.
	 * @return mixed
	 */
	public function merge_declared_cookies( $cookies = null ) {
		if ( ! is_array( $cookies ) ) {
			return $cookies;
		}

		$services = $this->matched_services();
		if ( empty( $services ) ) {
			return $cookies;
		}

		$seen = [];
		foreach ( $cookies as $rows ) {
			foreach ( (array) $rows as $row ) {
				if ( is_array( $row ) && isset( $row['name'] ) ) {
					$seen[ Cookie_Identity::key_for( $row ) ] = true;
				}
			}
		}

		foreach ( Declared_Cookies::get_instance()->build_for_services( $services ) as $category => $rows ) {
			foreach ( (array) $rows as $row ) {
				if ( ! is_array( $row ) || empty( $row['name'] ) ) {
					continue;
				}

				// Cookie_Identity strips the leading dot, so a declared
				// `.youtube.com` row dedupes against an observed `youtube.com`
				// one instead of both reaching the public cookie policy.
				$key = Cookie_Identity::key_for( $row );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ] = true;
				// Never written back, so the endpoints that edit the stored option
				// cannot find this row: the admin table reads it to explain why.
				$row['synthesized']     = true;
				$cookies[ $category ][] = $row;
			}
		}

		return $cookies;
	}

	/**
	 * Persist anything new this request saw.
	 *
	 * Writes only when a pattern is genuinely new, so a settled site stops
	 * touching the option entirely after the first few page views.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function flush(): void {
		if ( ! $this->dirty ) {
			return;
		}

		$this->dirty = false;
		$stored      = $this->stored();
		$changed     = false;

		foreach ( $this->seen as $kind => $patterns ) {
			foreach ( $patterns as $pattern => $entry ) {
				if ( isset( $stored[ $kind ][ $pattern ] ) ) {
					// Only a src sighting rewrites an entry. A row stored before this
					// option carried provenance is a real load, and nothing downgrades
					// one: withdrawing a cookie the site really sets is the worse error.
					if ( ( $stored[ $kind ][ $pattern ]['via'] ?? '' ) !== 'content' || $entry['via'] !== 'src' ) {
						continue;
					}
				} elseif ( count( $stored[ $kind ] ?? [] ) >= self::MAX_PER_KIND ) {
					break;
				}

				$stored[ $kind ][ $pattern ] = $entry;
				$changed                     = true;
			}
		}

		if ( $changed ) {
			update_option( self::OPTION, $stored, false );
		}
	}

	/**
	 * Drop what this site recorded for one service.
	 *
	 * The ledger is evidence, and it is append-only, so an entry outlives the tag
	 * that produced it and keeps declaring its cookies (#1141). Discarding one
	 * service's evidence is self-correcting in the safe direction: if the resource
	 * is genuinely gone nothing re-records it, and if it still loads the next page
	 * view that serves it writes the entry back and the policy is accurate again.
	 * Scoped to one service because wiping the ledger also erases rows that are a
	 * gated embed's only admin row, and flips the blocking-state telemetry.
	 *
	 * @since 1.5.1
	 * @param string $service Catalog service key.
	 * @return int Entries dropped.
	 */
	public function forget_service( string $service ): int {
		if ( $service === '' ) {
			return 0;
		}

		$stored  = $this->stored();
		$dropped = 0;

		foreach ( $stored as $kind => $patterns ) {
			foreach ( $patterns as $pattern => $entry ) {
				if ( (string) ( $entry['service'] ?? '' ) === $service ) {
					unset( $stored[ $kind ][ $pattern ], $this->seen[ $kind ][ $pattern ] );
					++$dropped;
				}
			}
		}

		if ( $dropped === 0 ) {
			return 0;
		}

		// An option holding two empty kinds is not the same as no option: analytics
		// reads a present-but-empty ledger as "blocking is inert on a site with
		// trackers". Having discarded the evidence, the honest answer is unknown.
		if ( empty( $stored['script'] ) && empty( $stored['iframe'] ) ) {
			delete_option( self::OPTION );
		} else {
			update_option( self::OPTION, $stored, false );
		}

		return $dropped;
	}

	/**
	 * Distinct catalog service keys recorded on this site.
	 *
	 * @since 1.5.0
	 * @return array<int, string>
	 */
	private function matched_services(): array {
		$services = [];

		foreach ( $this->stored() as $patterns ) {
			foreach ( $patterns as $entry ) {
				$service = (string) ( $entry['service'] ?? '' );
				// Scan rows carry their own cookies already, and a body that merely
				// names a host is evidence for blocking, not that the site loads it.
				if ( $service === '' || strncmp( $service, 'scan_', 5 ) === 0 || ( $entry['via'] ?? 'src' ) === 'content' ) {
					continue;
				}

				$services[ $service ] = true;
			}
		}

		return array_keys( $services );
	}

	/**
	 * Whether a host belongs to this site.
	 *
	 * Presto's self-hosted and audio providers resolve to a local file, and a
	 * first-party host recorded as a resource can be always-loaded by an admin,
	 * which would exempt every same-host resource on the site.
	 *
	 * @since 1.5.0
	 * @param string $host Lowercased host.
	 * @return bool
	 */
	private function is_first_party( string $host ): bool {
		$site = Blocker::without_www( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$host = Blocker::without_www( $host );

		if ( $site === '' ) {
			return false;
		}

		return $host === $site || substr( $host, - ( strlen( $site ) + 1 ) ) === '.' . $site;
	}

	/**
	 * Host of a resource URL, or of a bare pattern.
	 *
	 * Recording the host rather than the catalog pattern keeps the row honest:
	 * it names what this site actually contacted, which is also the value the
	 * admin UI writes an exclusion or override against.
	 *
	 * @since 1.5.0
	 * @param string $subject Resource URL, or a bare blocking pattern.
	 * @return string Lowercased host, or '' when there is none to record.
	 */
	private function host_of( string $subject ): string {
		$subject = trim( $subject );

		// A data: URI names no host, and a root-relative path is first-party.
		if ( $subject === '' || stripos( $subject, 'data:' ) === 0 || strpos( $subject, '/' ) === 0 ) {
			return strpos( $subject, '//' ) === 0 ? $this->host_of( 'https:' . $subject ) : '';
		}

		if ( strpos( $subject, '://' ) === false ) {
			$subject = 'https://' . $subject;
		}

		$host = wp_parse_url( $subject, PHP_URL_HOST );

		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * The stored set, normalised to both kinds.
	 *
	 * @since 1.5.0
	 * @return array<string, array<string, array<string, string>>>
	 */
	private function stored(): array {
		$stored = Get::option( self::OPTION, [], 'array' );

		return [
			'script' => is_array( $stored['script'] ?? null ) ? $stored['script'] : [],
			'iframe' => is_array( $stored['iframe'] ?? null ) ? $stored['iframe'] : [],
		];
	}
}
