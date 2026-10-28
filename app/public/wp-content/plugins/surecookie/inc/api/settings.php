<?php
/**
 * Settings class
 *
 * Handles installed products related REST API endpoints for the SureCookie plugin.
 *
 * @package SureCookie\Inc\API
 */

namespace SureCookie\Inc\API;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Functions\Sanitize;
use SureCookie\Inc\Functions\SendJson;
use SureCookie\Inc\Functions\Settings as FunctionsSettings;
use SureCookie\Inc\Functions\Update;
use SureCookie\Inc\Modules\Services\Installed_Services;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Utils\Options;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Settings
 *
 * Handles this related REST API endpoints.
 */
class Settings extends Base {
	use GetInstance;

	/**
	 * Route Get Admin Settings
	 */
	protected const ADMIN_SETTINGS = '/admin/settings';

	/**
	 * Route Export Settings
	 */
	protected const EXPORT_SETTINGS = '/settings/export';

	/**
	 * Route: list the export sections available on this install.
	 */
	protected const EXPORT_SECTIONS = '/settings/export-sections';

	/**
	 * Route Import Settings
	 */
	protected const IMPORT_SETTINGS = '/settings/import';

	/**
	 * Route Get Frontend Settings
	 */
	protected const FRONTEND_SETTINGS = '/frontend/settings';

	/**
	 * Export file type.
	 */
	private const EXPORT_TYPE = 'surecookie-settings-export';

	/**
	 * Export file schema version.
	 */
	private const EXPORT_SCHEMA_VERSION = 1;

	/**
	 * Maximum import file size in bytes.
	 */
	private const MAX_IMPORT_FILE_SIZE = 5242880;

	/**
	 * Register API routes.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->get_api_namespace(),
			self::ADMIN_SETTINGS,
			[
				'methods'             => WP_REST_Server::READABLE, // Admin -- GET method.
				'callback'            => [ $this, 'get_admin_settings' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::ADMIN_SETTINGS,
			[
				'methods'             => WP_REST_Server::CREATABLE, // Admin -- POST method.
				'callback'            => [ $this, 'update_admin_settings' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'data' => [
						'required' => true,
						'type'     => 'object',
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::FRONTEND_SETTINGS,
			[
				'methods'             => WP_REST_Server::READABLE, // Frontend -- GET method.
				'callback'            => [ $this, 'get_frontend_settings' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::EXPORT_SECTIONS,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_export_sections' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::EXPORT_SETTINGS,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'export_settings' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'sections' => [
						'type'    => 'array',
						'items'   => [ 'type' => 'string' ],
						'default' => [],
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::IMPORT_SETTINGS,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'import_settings' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'file'    => [
						'required' => true,
						'type'     => 'string',
					],
					'replace' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);
	}

	/**
	 * Get admin settings
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 * @since 0.0.1
	 * @return void
	 */
	public function get_admin_settings( $request ): void {
		$data                           = FunctionsSettings::get();
		$data['surecookie_usage_optin'] = Get::option( 'surecookie_usage_optin' ) === 'yes' ? true : false;

		$data        = apply_filters( 'surecookie_get_admin_settings_data', $data );
		$decode_data = Helper::decode_html_entities_recursive( $data ) ?? $data;

		// Re-sanitize after entity decoding, else the decode undoes the sanitizer:
		// &#64;import → @import for CSS, &lt;img onerror=…&gt; → live markup for rich text.
		if ( isset( $decode_data['custom_css'] ) && is_string( $decode_data['custom_css'] ) ) {
			$decode_data['custom_css'] = Sanitize::stylesheet( $decode_data['custom_css'] );
		}

		$decode_data = Sanitize::rich_text_keys_after_decode( $decode_data );

		SendJson::success( [ 'data' => $decode_data ] );
	}

	/**
	 * Update admin settings
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 * @since 0.0.1
	 * @return void
	 */
	public function update_admin_settings( $request ): void {
		$data = $request->get_param( 'data' );
		if ( ! is_array( $data ) || empty( $data ) ) {
			SendJson::error( [ 'message' => __( 'No data found', 'surecookie' ) ] );
			return;
		}

		$previous_top_level = FunctionsSettings::get( 'top_level_menu_enabled' );
		$sanitized_settings = $this->apply_settings_update( $data );

		$response = [
			'message'      => __( 'Settings updated', 'surecookie' ),
			'redirect_url' => Get::menu_redirect_url( $sanitized_settings, $previous_top_level ),
		];

		if ( empty( $response['redirect_url'] ) ) {
			unset( $response['redirect_url'] );
		}

		SendJson::success( $response );
	}

	/**
	 * Export SureCookie settings.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return void
	 */
	public function export_settings( $request ): void {
		$sections = $request->get_param( 'sections' );
		$sections = is_array( $sections ) ? $sections : [];

		SendJson::success(
			[
				'data' => $this->get_export_payload( $sections ),
			]
		);
	}

	/**
	 * List the export picker groups available on this install. Only groups
	 * whose sections own a transferable key here are returned, so the free
	 * plugin never shows Pro-only entries.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 * @since 1.4.0
	 * @return void
	 */
	public function list_export_sections( $request ): void {
		$registry = $this->get_section_registry();
		$groups   = [];

		foreach ( $this->get_export_sections() as $section ) {
			$parent = $section['parent'];

			if ( isset( $groups[ $parent ] ) ) {
				continue;
			}

			$meta              = $registry[ $parent ] ?? [];
			$groups[ $parent ] = [
				'id'          => $parent,
				'label'       => isset( $meta['label'] ) ? (string) $meta['label'] : $this->humanize_section_id( $parent ),
				'description' => isset( $meta['description'] ) ? (string) $meta['description'] : '',
			];
		}

		$order = array_flip( array_keys( $registry ) );
		uksort(
			$groups,
			static function ( string $a, string $b ) use ( $order ): int {
				return ( $order[ $a ] ?? PHP_INT_MAX ) <=> ( $order[ $b ] ?? PHP_INT_MAX );
			}
		);

		SendJson::success( [ 'data' => array_values( $groups ) ] );
	}

	/**
	 * Import SureCookie settings from a JSON export.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return void
	 */
	public function import_settings( $request ): void {
		$file_content = $request->get_param( 'file' );
		$replace      = (bool) $request->get_param( 'replace' );

		if ( ! is_string( $file_content ) || trim( $file_content ) === '' ) {
			SendJson::error(
				[
					'message' => __( 'Please upload a SureCookie export file to import.', 'surecookie' ),
				],
				400
			);
		}

		if ( strlen( $file_content ) > self::MAX_IMPORT_FILE_SIZE ) {
			SendJson::error(
				[
					'message' => __( 'The selected file is too large to import.', 'surecookie' ),
				],
				400
			);
		}

		$payload = json_decode( $file_content, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $payload ) ) {
			SendJson::error(
				[
					'message' => __( 'The uploaded file is not valid JSON.', 'surecookie' ),
				],
				400
			);
		}

		if ( ( $payload['type'] ?? '' ) !== self::EXPORT_TYPE ) {
			SendJson::error(
				[
					'message' => __( 'The uploaded file is not a valid SureCookie settings export.', 'surecookie' ),
				],
				400
			);
		}

		if ( (int) ( $payload['schema_version'] ?? 0 ) !== self::EXPORT_SCHEMA_VERSION ) {
			SendJson::error(
				[
					'message' => __( 'This settings export uses an unsupported schema version.', 'surecookie' ),
				],
				400
			);
		}

		$imported_settings = $payload['settings'] ?? null;

		if ( ! is_array( $imported_settings ) ) {
			SendJson::error(
				[
					'message' => __( 'The uploaded file does not contain a valid settings payload.', 'surecookie' ),
				],
				400
			);
			return;
		}

		$allowed_keys      = $this->get_transferable_settings_keys();
		$allowed_key_map   = array_fill_keys( $allowed_keys, true );
		$filtered_settings = array_intersect_key( $imported_settings, $allowed_key_map );
		$skipped_keys      = array_values( array_diff( array_keys( $imported_settings ), $allowed_keys ) );

		// Recognized keys whose value cannot be stored. Reported apart from `ignored`
		// (unrecognized fields) because the outcome differs and the user needs to know
		// which: a merge keeps the stored value, a replace has already defaulted it.
		$invalid_keys      = [];
		$filtered_settings = array_filter(
			$filtered_settings,
			static function ( $value, $key ) use ( &$invalid_keys ) {
				if ( FunctionsSettings::accepts_value( (string) $key, $value ) ) {
					return true;
				}

				$invalid_keys[] = (string) $key;
				return false;
			},
			ARRAY_FILTER_USE_BOTH
		);

		// Standalone options this install knows how to apply (sanitized at
		// the boundary below); unknown option names are ignored.
		$imported_options     = isset( $payload['options'] ) && is_array( $payload['options'] ) ? $payload['options'] : [];
		$transferable_options = $this->get_transferable_options();
		$applicable_options   = array_intersect_key( $imported_options, $transferable_options );

		$file_sections = isset( $payload['sections'] ) && is_array( $payload['sections'] ) ? $payload['sections'] : [];
		$summary       = $this->summarize_import( $imported_settings, array_keys( $filtered_settings ), $file_sections );

		// summarize_import() buckets everything unapplied as unrecognized; these were
		// recognized, so they belong in their own bucket.
		$summary['ignored'] = array_values( array_diff( $summary['ignored'], $invalid_keys ) );

		// Nothing this install can apply. If the file carries Pro sections, guide
		// the user to upgrade instead of failing; otherwise the file is unusable.
		if ( empty( $filtered_settings ) && empty( $applicable_options ) ) {
			if ( ! empty( $summary['skipped_pro'] ) ) {
				SendJson::success(
					[
						'message'          => __( 'No settings were imported. This export contains Pro features that are not available on this site.', 'surecookie' ),
						'applied'          => [],
						'applied_sections' => [],
						'skipped_pro'      => $summary['skipped_pro'],
						'ignored'          => $summary['ignored'],
						'invalid'          => $invalid_keys,
						'upgrade_required' => true,
						'replaced'         => $replace,
					]
				);
			}

			SendJson::error(
				[
					'message' => __( 'The uploaded file does not contain any importable SureCookie settings for this site.', 'surecookie' ),
				],
				400
			);
		}

		$previous_top_level = FunctionsSettings::get( 'top_level_menu_enabled' );
		$updated_settings   = empty( $filtered_settings ) ? [] : $this->apply_settings_update( $filtered_settings, $replace );

		// Apply standalone options through their registered sanitizers. An
		// option import always replaces the whole option - these are
		// self-contained registries (e.g. known services), not key merges.
		$applied_options = [];
		$sections        = $this->get_export_sections();
		foreach ( $applicable_options as $option_name => $value ) {
			$sanitize = $transferable_options[ $option_name ]['sanitize'] ?? null;
			if ( ! is_callable( $sanitize ) ) {
				continue;
			}

			update_option( $option_name, $sanitize( $value ), false );
			$applied_options[] = (string) $option_name;

			$section_id = (string) ( $transferable_options[ $option_name ]['section'] ?? '' );
			if ( isset( $sections[ $section_id ]['label'] ) ) {
				$summary['applied_sections'][] = $sections[ $section_id ]['label'];
				$summary['applied_sections']   = array_values( array_unique( $summary['applied_sections'] ) );
			}
		}

		$response = [
			'message'          => __( 'Settings imported successfully.', 'surecookie' ),
			'applied'          => array_keys( $filtered_settings ),
			'applied_options'  => $applied_options,
			'applied_sections' => $summary['applied_sections'],
			'skipped_pro'      => $summary['skipped_pro'],
			'ignored'          => $summary['ignored'],
			'invalid'          => $invalid_keys,
			'upgrade_required' => ! empty( $summary['skipped_pro'] ),
			'skipped'          => $skipped_keys,
			'replaced'         => $replace,
			'redirect_url'     => Get::menu_redirect_url( $updated_settings, $previous_top_level ),
		];

		if ( empty( $response['redirect_url'] ) ) {
			unset( $response['redirect_url'] );
		}

		SendJson::success( $response );
	}

	/**
	 * Update usage tracking setting based on opt-in status.
	 *
	 * @param array<string, mixed> $data The current settings dataset.
	 * @since 0.0.1-beta.1
	 * @return array<string, mixed> The modified settings dataset.
	 */
	public function update_usage_tracking( $data ): array {
		if ( ! isset( $data['surecookie_usage_optin'] ) ) {
			return $data;
		}

		$enable_contribution = $data['surecookie_usage_optin'] ? 'yes' : 'no';
		update_option( 'surecookie_usage_optin', $enable_contribution );
		unset( $data['surecookie_usage_optin'] );

		return $data;
	}

	/**
	 * Get frontend settings
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 * @since 0.0.1
	 * @return void
	 */
	public function get_frontend_settings( $request ): void {
		$public_settings = FunctionsSettings::get_public_settings_dataset();

		$frontend_settings = apply_filters( 'surecookie_get_frontend_settings_data', $public_settings );
		$decode_data       = Helper::decode_html_entities_recursive( $frontend_settings ) ?? $frontend_settings;

		// Public route - the decode above must not hand an anonymous caller
		// markup that kses had already neutralized.
		$decode_data = Sanitize::rich_text_keys_after_decode( $decode_data );

		SendJson::success( [ 'data' => $decode_data ] );
	}

	/**
	 * Apply a settings update through the shared sanitization and hook pipeline.
	 *
	 * @param array<string, mixed> $data    Settings patch to apply.
	 * @param bool                 $replace Whether to replace transferable settings instead of merging.
	 * @return array<string, mixed>
	 */
	private function apply_settings_update( array $data, bool $replace = false ): array {
		// Defense-in-depth: custom CSS is inlined to every frontend page, so treat it like WP Core treats Customizer Additional CSS - require unfiltered_html. On multisite, sub-admins without the cap keep the previously saved value; the sanitizer is the fallback line.
		if ( array_key_exists( 'custom_css', $data ) && ! current_user_can( 'unfiltered_html' ) ) {
			unset( $data['custom_css'] );
		}

		// Before processing plugin settings data compatibility.
		do_action( 'surecookie_admin_settings_before_processing', $data );

		$data = apply_filters( 'surecookie_update_admin_settings_data', $data );
		$data = is_array( $data ) ? $data : [];

		$sanitized_patch  = $this->sanitize_settings_patch( $data );
		$sanitized_patch  = $this->update_usage_tracking( $sanitized_patch );
		$current_option   = $this->get_raw_settings_option();
		$settings_to_save = $replace
			? $this->build_replaced_settings( $current_option, $sanitized_patch )
			: array_merge( $current_option, $sanitized_patch );

		Update::option( SURECOOKIE_SETTINGS_OPTION, $settings_to_save );

		// After processing plugin settings data compatibility.
		do_action( 'surecookie_admin_settings_after_processing', $settings_to_save );

		return $settings_to_save;
	}

	/**
	 * Sanitize a settings patch without merging it into the stored option.
	 *
	 * @param array<string, mixed> $data Settings patch.
	 * @return array<string, mixed>
	 */
	private function sanitize_settings_patch( array $data ): array {
		$sanitized_settings = [];

		foreach ( $data as $key => $value ) {
			$key = (string) $key;

			// Wrong shape for an array key: drop it so the merge keeps the stored value instead of wiping it to [].
			if ( ! FunctionsSettings::accepts_value( $key, $value ) ) {
				continue;
			}

			$sanitized_settings[ $key ] = FunctionsSettings::get_cleaned_value( $key, $value );
		}

		return $sanitized_settings;
	}

	/**
	 * Build the stored settings array for a replace import.
	 *
	 * @param array<string, mixed> $current_option  Current raw option value.
	 * @param array<string, mixed> $sanitized_patch Imported settings patch.
	 * @return array<string, mixed>
	 */
	private function build_replaced_settings( array $current_option, array $sanitized_patch ): array {
		$settings_to_save = $current_option;
		$defaults         = FunctionsSettings::get_settings_defaults();

		foreach ( $this->get_transferable_settings_keys() as $key ) {
			$settings_to_save[ $key ] = $defaults[ $key ] ?? '';
		}

		return array_merge( $settings_to_save, $sanitized_patch );
	}

	/**
	 * Get the current raw settings option.
	 *
	 * @return array<string, mixed>
	 */
	private function get_raw_settings_option(): array {
		$current_option = Get::option( SURECOOKIE_SETTINGS_OPTION, [], 'array' );
		return is_array( $current_option ) ? $current_option : [];
	}

	/**
	 * Build the export payload.
	 *
	 * @param array<int, string> $section_ids Selected section ids; empty (or 'all') exports everything.
	 * @return array<string, mixed>
	 */
	private function get_export_payload( array $section_ids = [] ): array {
		$all_settings      = FunctionsSettings::get();
		$transferable_keys = $this->resolve_export_keys( $section_ids );
		$exported_settings = [];

		foreach ( $transferable_keys as $key ) {
			if ( array_key_exists( $key, $all_settings ) ) {
				$exported_settings[ $key ] = $all_settings[ $key ];
			}
		}

		// Standalone options for the selected sections (null = everything).
		$selected         = $this->resolve_selected_section_ids( $section_ids );
		$exported_options = [];
		foreach ( $this->get_transferable_options() as $option_name => $config ) {
			$section = is_array( $config ) && ! empty( $config['section'] ) ? (string) $config['section'] : 'advanced';
			if ( $selected !== null && ! in_array( $section, $selected, true ) ) {
				continue;
			}

			$value = get_option( $option_name, null );
			if ( $value !== null ) {
				$exported_options[ $option_name ] = $value;
			}
		}

		$payload = [
			'type'           => self::EXPORT_TYPE,
			'schema_version' => self::EXPORT_SCHEMA_VERSION,
			'plugin_version' => SURECOOKIE_VERSION,
			'exported_at'    => gmdate( 'c' ),
			'site_url'       => site_url(),
			// Self-describing manifest so an export can be classified on import
			// (e.g. a Pro export imported into free knows which keys are Pro).
			'sections'       => $this->build_sections_manifest( array_keys( $exported_settings ), array_keys( $exported_options ) ),
			'settings'       => $exported_settings,
			'options'        => $exported_options,
		];

		/**
		 * Filters the SureCookie settings export payload.
		 *
		 * @param array<string, mixed> $payload Export payload.
		 */
		$filtered = apply_filters( 'surecookie_settings_export_payload', $payload );
		return is_array( $filtered ) ? $filtered : $payload;
	}

	/**
	 * Export section registry: id => label, description, tier, depends_on,
	 * parent. Keys are derived from the settings dataset `group` tag (see
	 * map_keys_to_sections); untagged keys fall into `advanced`. `parent`
	 * folds a section into a picker group (picker-only; the manifest and
	 * import summary stay fine-grained). The leading entries define the
	 * picker groups, mirroring the admin side navigation.
	 *
	 * @since 1.4.0
	 * @return array<string, array<string, mixed>>
	 */
	private function get_section_registry(): array {
		$sections = [
			'cookies_scripts'    => [
				'label'       => __( 'Cookies & Scripts', 'surecookie' ),
				'description' => __( 'Cookie categories, custom cookies, known services, script blocking and scanning.', 'surecookie' ),
				'tier'        => 'free',
			],
			'geo'                => [
				'label'       => __( 'Geographic Rules', 'surecookie' ),
				'description' => __( 'Region-based banner rules.', 'surecookie' ),
				'tier'        => 'pro',
			],
			'banner'             => [
				'label'       => __( 'Banner', 'surecookie' ),
				'description' => __( 'Banner content, layout, colors, buttons and display.', 'surecookie' ),
				'tier'        => 'free',
			],
			'general'            => [
				'label'       => __( 'General', 'surecookie' ),
				'description' => __( 'Consent model, logging, preferences, custom CSS and other plugin settings.', 'surecookie' ),
				'tier'        => 'free',
			],
			'consent_frameworks' => [
				'label'       => __( 'Consent Frameworks', 'surecookie' ),
				'description' => __( 'Google Consent Mode and other consent integrations.', 'surecookie' ),
				'tier'        => 'free',
			],
			'buttons'            => [
				'label'       => __( 'Buttons & labels', 'surecookie' ),
				'description' => __( 'Button visibility, order and label text.', 'surecookie' ),
				'tier'        => 'free',
				'parent'      => 'banner',
			],
			'consent'            => [
				'label'       => __( 'Consent & compliance', 'surecookie' ),
				'description' => __( 'Compliance law, consent model, logging and duration.', 'surecookie' ),
				'tier'        => 'free',
				'parent'      => 'general',
			],
			'cookie_categories'  => [
				'label'       => __( 'Cookie categories', 'surecookie' ),
				'description' => __( 'Your consent categories.', 'surecookie' ),
				'tier'        => 'free',
				'parent'      => 'cookies_scripts',
			],
			'cookies'            => [
				'label'       => __( 'Cookies', 'surecookie' ),
				'description' => __( 'Custom cookie definitions.', 'surecookie' ),
				'tier'        => 'free',
				'depends_on'  => [ 'cookie_categories' ],
				'parent'      => 'cookies_scripts',
			],
			'blocking'           => [
				'label'       => __( 'Script blocking', 'surecookie' ),
				'description' => __( 'Blocking rules and scan resource overrides.', 'surecookie' ),
				'tier'        => 'free',
				'parent'      => 'cookies_scripts',
			],
			'services'           => [
				'label'       => __( 'Known services', 'surecookie' ),
				'description' => __( 'Services you added or removed for cookie declarations and blocking.', 'surecookie' ),
				'tier'        => 'free',
				'parent'      => 'cookies_scripts',
			],
			'gcm'                => [
				'label'       => __( 'Google Consent Mode', 'surecookie' ),
				'description' => __( 'GCM toggle and region defaults.', 'surecookie' ),
				'tier'        => 'free',
				'parent'      => 'consent_frameworks',
			],
			'scanning'           => [
				'label'       => __( 'Automatic scanning', 'surecookie' ),
				'description' => __( 'Scan frequency and scope.', 'surecookie' ),
				'tier'        => 'free',
				'parent'      => 'cookies_scripts',
			],
			'advanced'           => [
				'label'       => __( 'Advanced', 'surecookie' ),
				'description' => __( 'Custom CSS, integrations and tool settings.', 'surecookie' ),
				'tier'        => 'free',
				'parent'      => 'general',
			],
		];

		/**
		 * Filters the export section registry. Modules and Pro register their own
		 * sections here (label, description, tier, depends_on); the keys each
		 * section covers are derived automatically from the dataset `group` tag.
		 *
		 * @since 1.4.0
		 * @param array<string, array<string, mixed>> $sections Section id => meta.
		 */
		$sections = apply_filters( 'surecookie_export_sections', $sections );

		return is_array( $sections ) ? $sections : [];
	}

	/**
	 * Derive section id => transferable keys from the settings dataset. Every
	 * transferable key lands in exactly one section (its `group`, or `advanced`
	 * when untagged), so no setting is ever left out of the export.
	 *
	 * @since 1.4.0
	 * @return array<string, array<int, string>>
	 */
	private function map_keys_to_sections(): array {
		$dataset      = Options::get_all_configurations();
		$transferable = array_fill_keys( $this->get_transferable_settings_keys(), true );
		$map          = [];

		foreach ( $dataset as $key => $config ) {
			if ( ! isset( $transferable[ $key ] ) ) {
				continue;
			}

			$group           = is_array( $config ) && ! empty( $config['group'] ) ? (string) $config['group'] : 'advanced';
			$map[ $group ][] = (string) $key;
		}

		return $map;
	}

	/**
	 * Build the export sections for this install: registry meta joined with the
	 * derived keys. Only sections that own at least one transferable key here
	 * are returned. Sections present in the dataset but missing from the
	 * registry still appear (auto-labelled), so nothing is unreachable.
	 *
	 * @since 1.4.0
	 * @return array<string, array<string, mixed>>
	 */
	private function get_export_sections(): array {
		$registry = $this->get_section_registry();
		$key_map  = $this->map_keys_to_sections();

		// Standalone options join their section alongside settings keys, so a
		// section can exist with keys, options, or both.
		$option_map = [];
		foreach ( $this->get_transferable_options() as $option_name => $config ) {
			$section                  = is_array( $config ) && ! empty( $config['section'] ) ? (string) $config['section'] : 'advanced';
			$option_map[ $section ][] = (string) $option_name;
		}

		$sections = [];

		foreach ( array_unique( array_merge( array_keys( $key_map ), array_keys( $option_map ) ) ) as $id ) {
			$keys    = $key_map[ $id ] ?? [];
			$options = $option_map[ $id ] ?? [];

			if ( empty( $keys ) && empty( $options ) ) {
				continue;
			}

			$meta            = $registry[ $id ] ?? [];
			$sections[ $id ] = [
				'id'          => $id,
				'label'       => isset( $meta['label'] ) ? (string) $meta['label'] : $this->humanize_section_id( $id ),
				'description' => isset( $meta['description'] ) ? (string) $meta['description'] : '',
				'tier'        => isset( $meta['tier'] ) && $meta['tier'] === 'pro' ? 'pro' : 'free',
				'depends_on'  => isset( $meta['depends_on'] ) && is_array( $meta['depends_on'] ) ? array_values( $meta['depends_on'] ) : [],
				'parent'      => ! empty( $meta['parent'] ) ? (string) $meta['parent'] : $id,
				'keys'        => array_values( array_unique( $keys ) ),
				'options'     => array_values( array_unique( $options ) ),
			];
		}

		return $sections;
	}

	/**
	 * Resolve the transferable keys for the selected sections. Empty selection
	 * (or an explicit `all`) exports everything. Section dependencies are pulled
	 * in automatically (e.g. Cookies pulls in Cookie categories).
	 *
	 * @param array<int, string> $section_ids Selected section ids.
	 * @since 1.4.0
	 * @return array<int, string>
	 */
	private function resolve_export_keys( array $section_ids ): array {
		$transferable = $this->get_transferable_settings_keys();
		$section_ids  = array_values( array_filter( array_map( 'sanitize_key', $section_ids ) ) );

		if ( empty( $section_ids ) || in_array( 'all', $section_ids, true ) ) {
			return $transferable;
		}

		$sections = $this->get_export_sections();
		$wanted   = $this->expand_section_dependencies(
			$this->expand_parent_sections( $section_ids, $sections ),
			$sections
		);
		$keys     = [];

		foreach ( $wanted as $id ) {
			if ( ! empty( $sections[ $id ]['keys'] ) ) {
				$keys = array_merge( $keys, $sections[ $id ]['keys'] );
			}
		}

		return array_values( array_intersect( $transferable, array_unique( $keys ) ) );
	}

	/**
	 * Expand picker group ids to their member sections; fine-grained ids
	 * resolve unchanged.
	 *
	 * @param array<int, string>                  $section_ids Requested ids (groups or sections).
	 * @param array<string, array<string, mixed>> $sections    All available sections.
	 * @since 1.4.0
	 * @return array<int, string>
	 */
	private function expand_parent_sections( array $section_ids, array $sections ): array {
		$expanded = array_fill_keys( $section_ids, true );

		foreach ( $sections as $id => $section ) {
			if ( isset( $expanded[ $section['parent'] ] ) ) {
				$expanded[ $id ] = true;
			}
		}

		return array_keys( $expanded );
	}

	/**
	 * Expand a set of section ids to include every section they depend on.
	 *
	 * @param array<int, string>                  $section_ids Requested section ids.
	 * @param array<string, array<string, mixed>> $sections    All available sections.
	 * @since 1.4.0
	 * @return array<int, string>
	 */
	private function expand_section_dependencies( array $section_ids, array $sections ): array {
		$resolved = [];
		$stack    = $section_ids;

		while ( $stack ) {
			$id = (string) array_pop( $stack );

			if ( isset( $resolved[ $id ] ) || ! isset( $sections[ $id ] ) ) {
				continue;
			}

			$resolved[ $id ] = true;

			foreach ( $sections[ $id ]['depends_on'] as $dependency ) {
				if ( ! isset( $resolved[ $dependency ] ) ) {
					$stack[] = $dependency;
				}
			}
		}

		return array_keys( $resolved );
	}

	/**
	 * Build the export manifest for the given exported keys: section id =>
	 * label, tier, and the exported keys it owns. Embedded in the export so the
	 * receiving site can classify keys it cannot apply (e.g. Pro sections).
	 *
	 * @param array<int, string> $exported_keys    Keys actually included in the export.
	 * @param array<int, string> $exported_options Standalone option names included in the export.
	 * @since 1.4.0
	 * @return array<string, array<string, mixed>>
	 */
	private function build_sections_manifest( array $exported_keys, array $exported_options = [] ): array {
		$sections      = $this->get_export_sections();
		$key_lookup    = array_fill_keys( $exported_keys, true );
		$option_lookup = array_fill_keys( $exported_options, true );
		$manifest      = [];

		foreach ( $sections as $id => $section ) {
			$included = array_values(
				array_filter(
					$section['keys'],
					static function ( string $key ) use ( $key_lookup ): bool {
						return isset( $key_lookup[ $key ] );
					}
				)
			);

			$included_options = array_values(
				array_filter(
					$section['options'],
					static function ( string $option ) use ( $option_lookup ): bool {
						return isset( $option_lookup[ $option ] );
					}
				)
			);

			if ( empty( $included ) && empty( $included_options ) ) {
				continue;
			}

			$manifest[ $id ] = [
				'label'   => $section['label'],
				'tier'    => $section['tier'],
				'keys'    => $included,
				'options' => $included_options,
			];
		}

		return $manifest;
	}

	/**
	 * Turn a section id into a human-readable fallback label.
	 *
	 * @param string $id Section id.
	 * @since 1.4.0
	 * @return string
	 */
	private function humanize_section_id( string $id ): string {
		return ucwords( str_replace( [ '_', '-' ], ' ', $id ) );
	}

	/**
	 * Summarise an import into section-level buckets for the UI: which sections
	 * were applied, which Pro sections were skipped (this install can't use
	 * them), and which keys were ignored as unknown/invalid.
	 *
	 * @param array<string, mixed>                $imported_settings Raw settings from the file.
	 * @param array<int, string>                  $applied_keys      Keys actually applied.
	 * @param array<string, array<string, mixed>> $file_sections     The export file's own manifest.
	 * @since 1.4.0
	 * @return array{applied_sections: array<int, string>, skipped_pro: array<int, string>, ignored: array<int, string>}
	 */
	private function summarize_import( array $imported_settings, array $applied_keys, array $file_sections ): array {
		// Map each key to its label/tier using the file's own manifest.
		$file_key_meta = [];
		foreach ( $file_sections as $id => $section ) {
			if ( ! is_array( $section ) || empty( $section['keys'] ) || ! is_array( $section['keys'] ) ) {
				continue;
			}

			$label = isset( $section['label'] ) ? (string) $section['label'] : $this->humanize_section_id( (string) $id );
			$tier  = isset( $section['tier'] ) && $section['tier'] === 'pro' ? 'pro' : 'free';

			foreach ( $section['keys'] as $key ) {
				$file_key_meta[ (string) $key ] = [
					'label' => $label,
					'tier'  => $tier,
				];
			}
		}

		// Prefer this install's own labels for applied keys (always accurate here).
		$local_key_label = [];
		foreach ( $this->get_export_sections() as $section ) {
			foreach ( $section['keys'] as $key ) {
				$local_key_label[ $key ] = $section['label'];
			}
		}

		$applied_lookup   = array_fill_keys( $applied_keys, true );
		$applied_sections = [];
		foreach ( $applied_keys as $key ) {
			$label = $local_key_label[ $key ] ?? ( $file_key_meta[ $key ]['label'] ?? '' );
			if ( $label !== '' ) {
				$applied_sections[ $label ] = true;
			}
		}

		$skipped_pro = [];
		$ignored     = [];
		foreach ( array_keys( $imported_settings ) as $key ) {
			if ( isset( $applied_lookup[ $key ] ) ) {
				continue;
			}

			if ( isset( $file_key_meta[ $key ] ) && $file_key_meta[ $key ]['tier'] === 'pro' ) {
				$skipped_pro[ $file_key_meta[ $key ]['label'] ] = true;
			} else {
				$ignored[] = (string) $key;
			}
		}

		return [
			'applied_sections' => array_keys( $applied_sections ),
			'skipped_pro'      => array_keys( $skipped_pro ),
			'ignored'          => array_values( array_unique( $ignored ) ),
		];
	}

	/**
	 * Get transferable settings keys shared by export and import.
	 *
	 * @return array<int, string>
	 */
	private function get_transferable_settings_keys(): array {
		$settings_keys = array_keys( Options::get_all_configurations() );
		$excluded_keys = [
			'auto_scan_pages',
			'consent_renewed_at',
			'cookie_policy_page_id',
			'legal_notice_page_id',
			'preview_enabled',
			'privacy_policy_page_id',
			'reconsent_menu_id',
			'scan_pages',
			'show_preview',
			'total_logs',
		];

		/**
		 * Filters the settings keys excluded from SureCookie import/export files.
		 *
		 * @param array<int, string> $excluded_keys Excluded settings keys.
		 */
		$excluded_keys = apply_filters( 'surecookie_settings_export_excluded_keys', $excluded_keys );
		$excluded_keys = is_array( $excluded_keys ) ? $excluded_keys : [];

		return array_values( array_diff( $settings_keys, $excluded_keys ) );
	}

	/**
	 * Standalone wp_options rows that travel with an export, beyond the
	 * settings option: option name => section id + import-boundary sanitizer.
	 * Modules and Pro register theirs via the filter (e.g. per-language
	 * banner content).
	 *
	 * @since 1.4.0
	 * @return array<string, array{section: string, sanitize: callable}>
	 */
	private function get_transferable_options(): array {
		$options = [
			SURECOOKIE_INSTALLED_SERVICES_OPTION => [
				'section'  => 'services',
				'sanitize' => [ Installed_Services::class, 'sanitize_registry' ],
			],
		];

		/**
		 * Filters the standalone options included in SureCookie export files.
		 *
		 * @since 1.4.0
		 * @param array<string, array{section: string, sanitize: callable}> $options Option name => config.
		 */
		$options = apply_filters( 'surecookie_transferable_options', $options );

		return is_array( $options ) ? $options : [];
	}

	/**
	 * The selected section ids expanded with dependencies, or null when the
	 * selection means "everything" (empty or explicit `all`).
	 *
	 * @param array<int, string> $section_ids Selected section ids.
	 * @since 1.4.0
	 * @return array<int, string>|null
	 */
	private function resolve_selected_section_ids( array $section_ids ): ?array {
		$section_ids = array_values( array_filter( array_map( 'sanitize_key', $section_ids ) ) );

		if ( empty( $section_ids ) || in_array( 'all', $section_ids, true ) ) {
			return null;
		}

		$sections = $this->get_export_sections();

		return $this->expand_section_dependencies(
			$this->expand_parent_sections( $section_ids, $sections ),
			$sections
		);
	}
}
