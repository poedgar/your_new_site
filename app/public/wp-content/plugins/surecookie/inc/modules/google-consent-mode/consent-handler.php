<?php
/**
 * Google Consent Mode - Consent Handler
 *
 * Handles default consent script output and consent state updates.
 *
 * @package SureCookie
 * @since 0.0.0-alpha.1
 */

namespace SureCookie\Inc\Modules\GoogleConsentMode;

use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Modules\ScriptBlocking\Utils as Blocking_Utils;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Consent_Handler class.
 *
 * Outputs default consent script and handles consent state management.
 *
 * @since 0.0.0-alpha.1
 */
class Consent_Handler {
	use GetInstance;

	/**
	 * Shorthands a rule may store that Google never matches, since it reads only
	 * ISO 3166-2 codes. EU is everywhere GDPR applies: the EEA (EU 27, Iceland,
	 * Liechtenstein, Norway) plus the EU regions ISO codes on their own, such as RE.
	 */
	private const REGION_ALIASES = [
		'EU' => [ 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO', 'AX', 'GF', 'GP', 'MQ', 'MF', 'RE', 'YT' ],
		'UK' => [ 'GB' ],
	];

	/**
	 * Pre-generated consent script HTML.
	 *
	 * @var string
	 */
	private $consent_script = '';

	/**
	 * Built head correction (`build/gcm-head.js`), read once per request.
	 *
	 * @var string|null
	 */
	private $head_source = null;

	/**
	 * Constructor.
	 *
	 * @since 0.0.0-alpha.1
	 */
	private function __construct() {
		// Use output buffering to inject consent script at start of <head>.
		// This guarantees execution before any tracking scripts (Critical Fix #4).
		// Open before PixelYourSite's layer-rewrite buffer so its callback cannot
		// rewrite SureCookie's cache-safe consent bootstrap.
		add_action( 'template_redirect', [ $this, 'start_output_buffer' ], -1 );

		// Warn admins when Google Site Kit's own Consent Mode is active (it
		// conflicts with ours). Wired on admin_notices directly: the frontend
		// output-buffering path never runs on wp-admin, so the conflict notice
		// would otherwise never render.
		add_action( 'admin_notices', [ $this, 'maybe_render_site_kit_conflict_notice' ] );
	}

	/**
	 * Start output buffering to inject consent script.
	 *
	 * @return void
	 * @since 0.0.0-alpha.1
	 */
	public function start_output_buffer(): void {
		// Skip on admin pages.
		if ( is_admin() ) {
			return;
		}

		// Skip if GCM not enabled.
		if ( ! Settings::get( 'gcm_enabled' ) ) {
			return;
		}

		// Pre-generate the consent script before starting the output buffer.
		// This avoids calling ob_start() inside the buffer callback (which PHP forbids).
		try {
			$this->consent_script = $this->generate_consent_script();
		} catch ( \Exception $e ) {
			$this->consent_script = '';
		}

		// Start output buffering.
		ob_start( [ $this, 'inject_consent_script' ] );
	}

	/**
	 * Inject consent script into HTML buffer.
	 *
	 * @param string $buffer HTML buffer.
	 * @return string Modified HTML buffer with consent script.
	 * @since 0.0.0-alpha.1
	 */
	public function inject_consent_script( $buffer ): string {
		// Check for conflicts before injecting.
		if ( self::has_consent_mode_conflict() ) {
			return $buffer;
		}

		// No URL/signature gate: detection can never see server-side or first-party
		// tag setups (#864), and defaults must precede any tag - GCM on means inject.

		// Inject pre-generated script immediately after <head> tag.
		$script = $this->consent_script;
		$buffer = preg_replace_callback(
			'/(<head[^>]*>)/i',
			static function ( $matches ) use ( $script ) {
				return $matches[1] . $script;
			},
			$buffer,
			1
		) ?? $buffer;

		return $buffer;
	}

	/**
	 * Show admin notice about Google Site Kit conflict.
	 *
	 * @return void
	 * @since 0.0.0-alpha.1
	 */
	public function show_site_kit_conflict_notice(): void {
		if ( ! current_user_can( Helper::capability() ) ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'SureCookie:', 'surecookie' ); ?></strong>
				<?php
				esc_html_e(
					'Google Site Kit\'s Consent Mode is active. Please disable it in Site Kit settings to use SureCookie\'s implementation.',
					'surecookie'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Admin_notices callback: warn when Google Site Kit's Consent Mode conflicts
	 * with SureCookie's, but only while our GCM is enabled. Runs on wp-admin,
	 * where the frontend buffering path that detects the conflict never executes.
	 *
	 * @return void
	 * @since 0.0.0-alpha.1
	 */
	public function maybe_render_site_kit_conflict_notice(): void {
		if ( ! Settings::get( 'gcm_enabled' ) ) {
			return;
		}
		if ( ! self::has_consent_mode_conflict() ) {
			return;
		}
		$this->show_site_kit_conflict_notice();
	}

	/**
	 * Check for Google Consent Mode conflicts with other plugins.
	 *
	 * Critical Fix #10: Google Site Kit conflict detection.
	 *
	 * @return bool True if conflict detected.
	 * @since 0.0.0-alpha.1
	 */
	public static function has_consent_mode_conflict(): bool {
		// Pure detection (no side effects): Site Kit present AND its Consent Mode
		// enabled. Callers act on the result - the frontend skips injecting our
		// script; the admin conflict notice is wired separately in the constructor
		// (previously an add_action here, which never fired since this only runs
		// during frontend output buffering).
		if ( class_exists( 'Google\Site_Kit\Core\Consent_Mode\Consent_Mode' ) ) {
			$sitekit_options = get_option( 'googlesitekit_consent_mode', [] );
			return ! empty( $sitekit_options['enabled'] );
		}

		return false;
	}

	/**
	 * Generate consent script HTML.
	 *
	 * @return string Consent script HTML.
	 * @since 0.0.0-alpha.1
	 */
	private function generate_consent_script(): string {
		// Nothing here reads the visitor's consent cookie or Sec-GPC: a page cache
		// stores this block for everyone, so one visitor's choice would become the
		// default of every visitor served that copy (#1254). The inlined head
		// correction applies the visitor's own state in their browser instead.
		$default_denied = [
			'essential'  => true,
			'functional' => false,
			'analytics'  => false,
			'marketing'  => false,
		];

		/*
		 * A scan is measuring what this site sets, not consenting on anyone's
		 * behalf. Denying here is what made a Consent Mode site undetectable: the scanner
		 * already stands the blocker down so the real tags run, then this told
		 * them to store nothing, and the scan reported the resulting silence as
		 * the site's cookie list.
		 */
		$is_scan = Blocking_Utils::is_scan_probe();

		if ( $is_scan ) {
			$preferences = [
				'essential'  => true,
				'functional' => true,
				'analytics'  => true,
				'marketing'  => true,
			];
		} else {
			$preferences = $this->global_default_preferences( $default_denied );
		}

		// Map to Google consent parameters.
		$consent_state = $this->map_to_google_consent( $preferences );

		// Get settings with validation. A scan waits for no update - the page is
		// captured within a few seconds, and holding the tags for the configured
		// delay is long enough to miss the cookies they were unblocked to set.
		$wait_time = $is_scan ? 0 : $this->validate_wait_time( Settings::get( 'gcm_wait_for_update' ) );

		// Add required parameters (Critical Fix #1 - wait_for_update).
		$consent_state['wait_for_update'] = $wait_time;

		// Add optional parameters.
		$consent_state['ads_data_redaction'] = true;
		$consent_state['url_passthrough']    = true;

		// JSON encoding flags for XSS prevention (security hardening).
		$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

		$json_state = wp_json_encode( $consent_state, $json_flags );

		// Fall back to a safe empty object if encoding fails (e.g., malformed UTF-8 in a filtered value).
		// Prevents a JS syntax error ("gtag('consent', 'default', );") from halting script execution.
		if ( $json_state === false ) {
			$json_state = '{}';
		}

		$layer_names = wp_json_encode( $this->google_layer_names(), $json_flags );
		if ( $layer_names === false ) {
			$layer_names = '["dataLayer"]';
		}

		// Build script HTML.
		$output  = '<script data-cfasync="false" data-surecookie-gcm="default">' . "\n";
		$output .= 'window.surecookieGcmLayers = ' . $layer_names . ';' . "\n";
		$output .= '(function(w){var o=w.pysOptions,v;if(o){if(o.gdpr){o.google_consent_mode=false;}return;}if(!Object.prototype.hasOwnProperty.call(w,"pysOptions")){Object.defineProperty(w,"pysOptions",{configurable:true,get:function(){return v;},set:function(o){v=o;if(o&&o.gdpr){o.google_consent_mode=false;}if(typeof w.surecookieGcmPysOptionsReady==="function"){w.surecookieGcmPysOptionsReady();}}});}})(window);' . "\n";
		$output .= 'window.surecookieGcmPushConsent = function(command,state){var i,layer;for(i=0;i<window.surecookieGcmLayers.length;i++){layer=window[window.surecookieGcmLayers[i]]=window[window.surecookieGcmLayers[i]]||[];(function(){layer.push(arguments);})("consent",command,state);}};' . "\n";
		$output .= 'window.surecookieGcmPushConsent(\'default\', ' . $json_state . ');' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already JSON-encoded with XSS flags.

		// Region-specific consent defaults (Google Consent Mode v2 region parameter).
		$output .= $this->generate_region_consent_defaults( $wait_time, $json_flags );

		// A scan has no choice to correct. The renewal time is the same for every
		// visitor, so the block stays cacheable.
		if ( ! $is_scan ) {
			$output .= 'window.surecookieGcm = ' . wp_json_encode( [ 'r' => (int) Settings::get( 'consent_renewed_at' ) ] ) . ';' . "\n";
			$output .= $this->head_source() . "\n";
		}

		$output .= '</script>';

		return $output;
	}

	/**
	 * Generate region-specific consent default calls.
	 *
	 * Uses Google's `region` parameter to set different consent defaults per region.
	 * Example: EU visitors get all-denied defaults while other regions may allow analytics.
	 *
	 * @see https://developers.google.com/tag-platform/security/guides/consent#region-specific_behavior
	 *
	 * @param int $wait_time  Validated wait time in ms.
	 * @param int $json_flags JSON encoding flags.
	 * @return string Additional gtag consent default calls for regions.
	 * @since 0.0.1-beta.1
	 */
	private function generate_region_consent_defaults( $wait_time, $json_flags ): string {
		// A region default overrides the global one for the regions it names, so
		// emitting these would put the scanner straight back on denied wherever
		// its egress IP happens to resolve.
		if ( Blocking_Utils::is_scan_probe() ) {
			return '';
		}

		$region_defaults = Settings::get( 'gcm_region_defaults' );

		if ( empty( $region_defaults ) || ! is_array( $region_defaults ) ) {
			return '';
		}

		// Limit to 50 rules maximum to prevent abuse.
		$region_defaults = array_slice( $region_defaults, 0, 50 );

		$rules = [];

		foreach ( $region_defaults as $preset ) {
			// Defense-in-depth: skip non-array entries so $preset[...] offset access below never runs on a scalar/null.
			if ( ! is_array( $preset ) || empty( $preset['region'] ) || ! is_array( $preset['region'] ) ) {
				continue;
			}

			// Sanitize and validate region codes.
			$regions = array_filter(
				array_map(
					static function ( $code ) {
						$code = strtoupper( trim( sanitize_text_field( $code ) ) );
						return preg_match( '/^[A-Z]{2}(-[A-Z0-9]{1,3})?$/', $code ) ? $code : '';
					},
					$preset['region']
				)
			);

			if ( ! empty( $regions ) ) {
				$rules[] = [ $preset, $regions ];
			}
		}

		// A code a rule names itself beats an alias covering it: gtag applies the last default for a country, so rule order would decide.
		$explicit = array_diff( array_merge( [], ...array_column( $rules, 1 ) ), array_keys( self::REGION_ALIASES ) );

		// Build array of prepared consent-state configs (not JS strings).
		$region_configs = [];

		foreach ( $rules as [ $preset, $codes ] ) {
			$regions = [];
			foreach ( $codes as $code ) {
				$regions = array_merge( $regions, isset( self::REGION_ALIASES[ $code ] ) ? array_diff( self::REGION_ALIASES[ $code ], $explicit ) : [ $code ] );
			}

			// Every code this alias covers has a rule of its own.
			if ( empty( $regions ) ) {
				continue;
			}

			// Build preferences with strict boolean resolution (handles Sanitize::array() string round-trip).
			$preferences = [
				'essential'  => true,
				'functional' => $this->resolve_preset_bool( $preset['functional'] ?? null ),
				'analytics'  => $this->resolve_preset_bool( $preset['analytics'] ?? null ),
				'marketing'  => $this->resolve_preset_bool( $preset['marketing'] ?? null ),
			];

			$consent_state                    = $this->map_to_google_consent( $preferences );
			$consent_state['region']          = array_values( array_unique( $regions ) );
			$consent_state['wait_for_update'] = $wait_time;

			$region_configs[] = $consent_state;
		}

		/**
		 * Filter: Allow developers to modify region-specific consent default configs.
		 *
		 * Return an array of consent-state arrays (not raw JS strings). Each element
		 * has the same shape as a gtag consent default call. The return value is
		 * JSON-encoded here with XSS flags - do NOT return JS strings.
		 *
		 * @param array<int, array<string, mixed>> $region_configs Array of per-region consent configs.
		 * @param int                              $wait_time      Validated wait time in ms.
		 * @since 0.0.1-beta.1
		 */
		$region_configs = apply_filters( 'surecookie_gcm_region_consent_defaults', $region_configs, $wait_time );

		// Bail gracefully if filter returns unexpected type.
		if ( ! is_array( $region_configs ) ) {
			return '';
		}

		$output = '';
		foreach ( $region_configs as $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}
			$json_state = wp_json_encode( $config, $json_flags );

			// Skip entries that fail to encode instead of emitting invalid JS that halts the script block.
			if ( $json_state === false ) {
				continue;
			}

			$output .= 'window.surecookieGcmPushConsent(\'default\', ' . $json_state . ');' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already JSON-encoded with XSS flags.
		}

		return $output;
	}

	/**
	 * Resolve the GLOBAL consent default for a visitor with no recorded choice.
	 *
	 * `gcm_default_consent` carries the admin's per-category granted/denied
	 * choice (all denied by default). Region rules emitted after the global
	 * default always override it for their regions (gtag resolves the most
	 * specific match client-side), and a recorded choice or GPC wins over
	 * everything.
	 *
	 * @param array<string, bool> $default_denied The all-denied baseline.
	 * @since 1.3.1
	 * @return array<string, bool>
	 */
	private function global_default_preferences( array $default_denied ): array {
		$defaults = Settings::get( 'gcm_default_consent' );

		if ( ! is_array( $defaults ) ) {
			return $default_denied;
		}

		return [
			'essential'  => true,
			'functional' => $this->resolve_preset_bool( $defaults['functional'] ?? null ),
			'analytics'  => $this->resolve_preset_bool( $defaults['analytics'] ?? null ),
			'marketing'  => $this->resolve_preset_bool( $defaults['marketing'] ?? null ),
		];
	}

	/**
	 * Map SureCookie categories to Google consent parameters.
	 *
	 * @param array<string, mixed> $preferences User preferences.
	 * @return array<string, mixed> Google consent state.
	 * @since 0.0.0-alpha.1
	 */
	private function map_to_google_consent( $preferences ): array {
		$mapping = [];
		foreach ( self::consent_map() as $key => $category ) {
			$mapping[ $key ] = $category === 'essential' || ! empty( $preferences[ $category ] ) ? 'granted' : 'denied';
		}

		/**
		 * Filter: Allow developers to customize category mapping.
		 *
		 * @param array $mapping    Google consent parameters.
		 * @param array $preferences User category preferences.
		 * @since 0.0.0-alpha.1
		 */
		$filtered = apply_filters( 'surecookie_google_consent_mapping', $mapping, $preferences );

		// Fall back to the original mapping if a filter returns a non-array, to avoid silent GCM misconfiguration.
		return is_array( $filtered ) ? $filtered : $mapping;
	}

	/**
	 * Consent Mode key to SureCookie category. `consent-map.json` is also bundled
	 * into the head correction and consentManager.js, so the default and every
	 * update map identically.
	 *
	 * @since 1.6.0
	 * @return array<string, string>
	 */
	private static function consent_map(): array {
		static $map = null;

		if ( $map === null ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled plugin asset, not a remote resource.
			$decoded = json_decode( (string) file_get_contents( __DIR__ . '/consent-map.json' ), true );
			$map     = is_array( $decoded ) ? $decoded : [];
		}

		return $map;
	}

	/**
	 * Resolve the Google data layers configured by PixelYourSite.
	 *
	 * @return array<int, string>
	 */
	private function google_layer_names(): array {
		$layers = [ 'dataLayer' ];

		if ( function_exists( 'PixelYourSite\\GA' )
			&& function_exists( 'PixelYourSite\\GATags' )
			&& \PixelYourSite\GA()->configured()
		) {
			$tags = \PixelYourSite\GATags();
			$type = $tags->getOption( 'gtag_datalayer_type' );
			if ( $type === 'disable' ) {
				$layers[] = 'dataLayer';
			} elseif ( $type === 'custom' ) {
				$name = $tags->getOption( 'gtag_datalayer_name' );
				if ( is_string( $name ) && $name !== '' ) {
					$layers[] = $name;
				}
			} else {
				$layers[] = 'dataLayerPYS';
			}
		}

		if ( function_exists( 'PixelYourSite\\GTM' ) && \PixelYourSite\GTM()->configured() ) {
			$name = \PixelYourSite\GTM()->getOption( 'gtm_dataLayer_name' );
			if ( is_string( $name ) && $name !== '' ) {
				$layers[] = $name;
			} else {
				$layers[] = 'dataLayer';
			}
		}

		/**
		 * Extra Google data layer names consent commands must reach.
		 *
		 * We can discover PixelYourSite's layers and the default one. A site
		 * loading its own tag on a custom layer (`gtag/js?id=...&l=myLayer`)
		 * is the only thing that knows that name, so it declares it here.
		 *
		 * @since 1.6.0
		 * @param array<int, string> $names Layer names.
		 */
		$extra = apply_filters( 'surecookie_google_data_layers', [] );
		if ( is_array( $extra ) ) {
			$layers = array_merge( $layers, $extra );
		}

		// A layer is reached as `window[ name ]`, so anything that is not a
		// plain JS identifier cannot be one. This also keeps an invalid byte out
		// of the emitted JSON: wp_json_encode() re-encodes rather than failing,
		// so a bad name would otherwise become a junk global nothing reads.
		return array_values(
			array_unique( array_filter( $layers, [ self::class, 'is_data_layer_name' ] ) )
		);
	}

	/**
	 * Whether a value can name a JavaScript global holding a data layer.
	 *
	 * @param mixed $name Candidate name.
	 * @return bool
	 */
	private static function is_data_layer_name( $name ): bool {
		return is_string( $name ) && preg_match( '/^[A-Za-z_$][A-Za-z0-9_$]*$/', $name ) === 1;
	}

	/**
	 * The built head correction, or '' when the bundle has not been built.
	 *
	 * @since 1.6.0
	 * @return string
	 */
	private function head_source(): string {
		if ( $this->head_source === null ) {
			$path = SURECOOKIE_DIR . 'build/gcm-head.js';

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled plugin asset, not a remote resource.
			$contents          = is_readable( $path ) ? file_get_contents( $path ) : false;
			$this->head_source = is_string( $contents ) ? trim( $contents ) : '';
		}

		return $this->head_source;
	}

	/**
	 * Validate wait time.
	 *
	 * Critical Fix #5: Input validation.
	 *
	 * @param int $value Wait time in milliseconds.
	 * @return int Validated wait time (0-2000ms).
	 * @since 0.0.0-alpha.1
	 */
	private function validate_wait_time( $value ): int {
		$int_value = absint( $value );
		return min( max( $int_value, 0 ), 2000 ); // Clamp to 0-2000ms range.
	}

	/**
	 * Resolve a preset boolean value from mixed input.
	 *
	 * Handles both native PHP booleans and the string representations
	 * produced by Sanitize::array() ("1" for true, "" for false).
	 *
	 * @param mixed $value Raw preset value.
	 * @return bool Resolved boolean.
	 * @since 0.0.1-beta.1
	 */
	private function resolve_preset_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		return $value === '1' || $value === 1;
	}
}
