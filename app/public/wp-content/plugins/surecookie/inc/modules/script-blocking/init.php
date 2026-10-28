<?php
/**
 * Initialize Script Blocking.
 *
 * @package SureCookie\Inc\Modules\ScriptBlocking
 * @since 0.0.1
 */

namespace SureCookie\Inc\Modules\ScriptBlocking;

use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Init
 *
 * @since 0.0.1
 */
class Init {
	use GetInstance;

	/**
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	private function __construct() {
		// The blocker's should_process() handles all conditions including settings check.
		Blocker::get_instance();
		Scan_Scripts::get_instance();

		// Nothing else reads the catalog, so a resource gated only by a catalog
		// pattern has no row on any admin screen. Record what the blocker matches
		// and surface those as rows.
		Matched_Resources::get_instance();

		// The catalog domain (Services_Source, Known_Scripts blocking view, the
		// refresh Cron, declared cookies) now lives in the Services module and is
		// consumed here purely through the `surecookie_known_scripts` filter.

		// Custom block rules (custom_blocked_scripts) contribute their own
		// entries to that same filter at priority 25 (after the catalog view and
		// scan-detected merge), so this stays a pure consumer of the shared view.
		Custom_Scripts::get_instance();
	}
}
