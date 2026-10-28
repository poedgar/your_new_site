<?php
/**
 * Manage Settings Ability
 *
 * Single getter-setter ability for all SureCookie plugin settings.
 * Dynamically builds its JSON Schema from Options::get_all_configurations(),
 * so new settings are automatically available without modifying this file.
 *
 * @link       https://developer.wordpress.org/apis/abilities-api/
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Integrations/Wordpress/Abilities
 * @since      0.0.1-alpha.1
 */

namespace SureCookie\Inc\Integrations\Wordpress\Abilities;

use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Integrations\Wordpress\Base;
use SureCookie\Inc\Utils\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ManageSettings
 *
 * Provides a unified get/set interface for all plugin settings.
 *
 * @since 0.0.1-alpha.1
 */
class ManageSettings extends Base {
	/**
	 * Settings owned by a dedicated ability, keyed to the ability that owns them.
	 *
	 * These are large structured blobs whose corruption breaks the consent
	 * model, and each has an ability that validates edits properly. They stay
	 * readable through "get" and declared in the schema, so a write reaches
	 * execute() and is refused with the owning ability named.
	 *
	 * @since 1.4.0
	 */
	private const DELEGATED_KEYS = [
		'cookie_categories'           => 'surecookie/cookie-categories',
		'custom_cookies'              => 'surecookie/cookie-management',
		// Keyed by opaque "script::host" strings and structured rows: a blind
		// whole-array write drops every existing entry.
		'excluded_scan_resources'     => 'surecookie/script-blocking',
		'resource_category_overrides' => 'surecookie/script-blocking',
		'custom_blocked_scripts'      => 'surecookie/script-blocking',
	];

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $input The validated input data.
	 */
	public function execute( $input = null ) {
		try {
			$action = $input['action'] ?? 'get';

			if ( $action === 'set' ) {
				return $this->handle_set( $input );
			}

			return $this->handle_get( $input );
		} catch ( \Throwable $e ) {
			return [
				'success'  => false,
				'message'  => __( 'An unexpected error occurred while managing settings.', 'surecookie' ),
				'settings' => [],
			];
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_name(): string {
		return 'surecookie/manage-settings';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label(): string {
		return __( 'Manage Settings', 'surecookie' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description(): string {
		return __( 'Get or update SureCookie plugin settings. Use action "get" to retrieve current settings (optionally filter by specific keys), or action "set" to update one or more settings. Available settings include banner appearance, layout and position, consent logging toggle, default compliance law (GDPR/CCPA), cookie policy page, Google Consent Mode, and more. Settings are stored in the WordPress options table and take effect immediately on the live site. When using "set", only the provided keys are updated — omitted keys remain unchanged.', 'surecookie' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_annotations(): array {
		return [
			'priority'        => 2.0,
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
			'instructions'    => 'For the "get" action, safe to call at any time to read current configuration. For the "set" action, always call with action "get" first to show the user current values, then confirm the proposed changes before applying. Settings take effect immediately on the live cookie consent banner visible to all site visitors.',
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'action'   => [
					'type'        => 'string',
					'enum'        => [ 'get', 'set' ],
					'description' => __( 'The action to perform: "get" to retrieve settings, "set" to update settings.', 'surecookie' ),
				],
				'keys'     => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => __( 'Optional. For "get" action, specify setting keys to retrieve. If empty, returns all settings.', 'surecookie' ),
				],
				'settings' => [
					'type'                 => 'object',
					'description'          => __( 'For "set" action, an object of setting key-value pairs to update.', 'surecookie' ),
					'properties'           => self::build_settings_properties(),
					'additionalProperties' => false,
				],
			],
			'required'   => [ 'action' ],
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'success'  => [
					'type'        => 'boolean',
					'description' => __( 'Whether the operation succeeded.', 'surecookie' ),
				],
				'message'  => [
					'type'        => 'string',
					'description' => __( 'A human-readable message describing the result.', 'surecookie' ),
				],
				'settings' => [
					'type'        => 'object',
					'description' => __( 'The current settings after the operation.', 'surecookie' ),
				],
			],
		];
	}

	/**
	 * Settings owned by a dedicated ability, including add-on contributions.
	 *
	 * Filterable so Pro can claim its own nested settings: a Pro key is only in
	 * the registry because Pro registered it, and a blind whole-array write to a
	 * nested key flattens its siblings.
	 *
	 * @return array<string, string> Setting key => owning ability name.
	 * @since 1.4.0
	 */
	private static function delegated_keys(): array {
		/**
		 * Filters the settings this ability refuses to write, per owning ability.
		 *
		 * @param array<string, string> $keys Setting key => owning ability name.
		 * @since 1.4.0
		 */
		$keys = apply_filters( 'surecookie_delegated_setting_keys', self::DELEGATED_KEYS );

		return is_array( $keys ) ? $keys : self::DELEGATED_KEYS;
	}

	/**
	 * Handle the "get" action.
	 *
	 * Returns all settings or a filtered subset when specific keys are requested.
	 *
	 * @param array<string, mixed> $input Input data.
	 * @return array{success: bool, message: string, settings: array<string, mixed>}
	 * @since 0.0.1-alpha.1
	 */
	private function handle_get( array $input ): array {
		$all_settings = Settings::get();
		$keys         = $input['keys'] ?? [];

		if ( ! empty( $keys ) && is_array( $keys ) ) {
			$filtered = [];
			foreach ( $keys as $key ) {
				$key = sanitize_text_field( $key );
				if ( isset( $all_settings[ $key ] ) ) {
					$filtered[ $key ] = $all_settings[ $key ];
				}
			}
			$all_settings = $filtered;
		}

		return [
			'success'  => true,
			'message'  => __( 'Settings retrieved successfully.', 'surecookie' ),
			'settings' => $all_settings,
		];
	}

	/**
	 * Handle the "set" action.
	 *
	 * Validates keys against the known configuration schema,
	 * rejects unknown keys, and delegates sanitization to
	 * Settings::update() which uses get_cleaned_value().
	 *
	 * @param array<string, mixed> $input Input data.
	 * @return array{success: bool, message: string, settings: array<string, mixed>}
	 * @since 0.0.1-alpha.1
	 */
	private function handle_set( array $input ): array {
		$settings_to_update = $input['settings'] ?? [];

		if ( empty( $settings_to_update ) || ! is_array( $settings_to_update ) ) {
			return [
				'success'  => false,
				'message'  => __( 'No settings provided to update.', 'surecookie' ),
				'settings' => [],
			];
		}

		// Same pipeline the REST endpoint runs (inc/api/settings.php). Writing
		// the options directly skipped it, so a module normalizing values
		// through the filter was bypassed and, more visibly, the page cache was
		// never purged: a banner change made over MCP left the old banner being
		// served while the agent reported success.
		do_action( 'surecookie_admin_settings_before_processing', $settings_to_update );

		$settings_to_update = apply_filters( 'surecookie_update_admin_settings_data', $settings_to_update );
		$settings_to_update = is_array( $settings_to_update ) ? $settings_to_update : [];

		// Validate keys against known configuration.
		$configurations = Options::get_all_configurations();
		$valid_keys     = array_keys( $configurations );
		$updated_keys   = [];
		$denied_keys    = [];
		$invalid_keys   = [];
		$delegated_keys = [];
		$delegated      = self::delegated_keys();

		foreach ( $settings_to_update as $key => $value ) {
			$key = sanitize_text_field( $key );

			if ( ! in_array( $key, $valid_keys, true ) ) {
				continue;
			}

			// Also enforced by the schema; repeated here so the agent gets a
			// message naming the ability that owns the key.
			if ( isset( $delegated[ $key ] ) ) {
				$delegated_keys[ $key ] = $delegated[ $key ];
				continue;
			}

			if ( ! empty( $configurations[ $key ]['internal'] ) ) {
				continue;
			}

			// Mirror the REST endpoint's gate (inc/api/settings.php): custom CSS
			// is inlined on every frontend page, so it requires unfiltered_html.
			if ( $key === 'custom_css' && ! current_user_can( 'unfiltered_html' ) ) {
				$denied_keys[] = $key;
				continue;
			}

			// Settings::update() refuses a value whose shape contradicts the key's
			// declared type. Reporting it as updated would tell the agent a write
			// happened that did not.
			if ( ! Settings::update( $key, $value ) ) {
				$invalid_keys[] = $key;
				continue;
			}

			$updated_keys[] = $key;
		}

		if ( ! empty( $delegated_keys ) ) {
			$pointers = [];
			foreach ( $delegated_keys as $key => $ability ) {
				/* translators: 1: setting key, 2: ability name */
				$pointers[] = sprintf( __( '%1$s (use %2$s)', 'surecookie' ), $key, $ability );
			}

			$delegated_notice = sprintf(
				/* translators: %s: comma-separated "key (use ability)" pairs */
				__( 'Skipped keys that have a dedicated ability: %s.', 'surecookie' ),
				implode( ', ', $pointers )
			);
		}

		if ( empty( $updated_keys ) ) {
			if ( ! empty( $delegated_keys ) ) {
				return [
					'success'  => false,
					'message'  => $delegated_notice,
					'settings' => [],
				];
			}

			if ( ! empty( $invalid_keys ) ) {
				return [
					'success'  => false,
					'message'  => sprintf(
						/* translators: %s: comma-separated setting keys */
						__( 'No settings were updated. These keys were given a value that cannot be stored: %s.', 'surecookie' ),
						implode( ', ', $invalid_keys )
					),
					'settings' => [],
				];
			}

			return [
				'success'  => false,
				'message'  => empty( $denied_keys )
					? __( 'No valid settings keys provided.', 'surecookie' )
					: sprintf(
						/* translators: %s: comma-separated setting keys */
						__( 'Permission denied for: %s. Updating custom_css requires the unfiltered_html capability.', 'surecookie' ),
						implode( ', ', $denied_keys )
					),
				'settings' => [],
			];
		}

		$settings = Settings::get();

		// Fires only when something was actually written, so a rejected patch
		// does not trigger a needless cache purge.
		do_action( 'surecookie_admin_settings_after_processing', $settings );

		$message = sprintf(
			/* translators: %d: number of settings updated */
			__( '%d setting(s) updated successfully.', 'surecookie' ),
			count( $updated_keys )
		);

		if ( ! empty( $denied_keys ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: comma-separated setting keys */
				__( 'Skipped (permission denied): %s.', 'surecookie' ),
				implode( ', ', $denied_keys )
			);
		}

		if ( ! empty( $invalid_keys ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: comma-separated setting keys */
				__( 'Skipped (value refused): %s.', 'surecookie' ),
				implode( ', ', $invalid_keys )
			);
		}

		if ( ! empty( $delegated_keys ) ) {
			$message .= ' ' . $delegated_notice;
		}

		return [
			'success'  => true,
			'message'  => $message,
			'settings' => $settings,
		];
	}

	/**
	 * Build JSON Schema properties from Options configurations.
	 *
	 * Dynamically generates the schema so new settings added to
	 * Options::get_all_configurations() are automatically available
	 * without modifying this file.
	 *
	 * @return array<string, array<string, string|int|array<int, string>>>
	 * @since 0.0.1-alpha.1
	 */
	private static function build_settings_properties(): array {
		$configurations = Options::get_all_configurations();
		$properties     = [];
		$delegated      = self::delegated_keys();

		$type_map = [
			'bool'         => 'boolean',
			'int'          => 'integer',
			'string'       => 'string',
			// Long-form aliases. Nothing declares these today, but an unmapped
			// type silently becomes 'string', which would misdeclare the key.
			'boolean'      => 'boolean',
			'integer'      => 'integer',
			// Genuinely strings; the value is sanitized on write.
			'url'          => 'string',
			'rich_text'    => 'string',
			'stylesheet'   => 'string',
			'button_order' => 'string',
			// PHP 'array' settings hold both lists and maps, so accept either.
			'array'        => [ 'array', 'object' ],
		];

		foreach ( $configurations as $key => $config ) {
			// Internal state is left out entirely: there is no better place to
			// send the caller, so the schema refusal is the clearest answer.
			if ( ! empty( $config['internal'] ) ) {
				continue;
			}

			$php_type    = $config['type'] ?? 'string';
			$schema_type = $type_map[ $php_type ] ?? 'string';

			$description = isset( $config['description'] ) && is_string( $config['description'] ) && $config['description'] !== ''
				? $config['description']
				: sprintf(
					/* translators: %s: setting key name */
					__( 'Plugin setting: %s', 'surecookie' ),
					$key
				);

			if ( ! empty( $config['hazard'] ) && is_string( $config['hazard'] ) ) {
				$description .= ' ' . $config['hazard'];
			}

			// Delegated keys stay in the schema so a write reaches execute() and
			// gets the message naming the ability that owns them; rejecting them
			// at the schema layer would only say "not a valid property".
			if ( isset( $delegated[ $key ] ) ) {
				$description = sprintf(
					/* translators: 1: existing description, 2: owning ability name */
					__( '%1$s Read-only here: writes are refused, use %2$s instead.', 'surecookie' ),
					$description,
					$delegated[ $key ]
				);
			}

			$property = [
				'type'        => $schema_type,
				'description' => $description,
			];

			// An enum is only safe when the registry proves the full value set;
			// a partial one would reject a legitimate value before execute().
			if ( ! empty( $config['enum'] ) && is_array( $config['enum'] ) ) {
				$property['enum'] = array_values( $config['enum'] );
			}

			// A string's `max` is a length: declare it rather than cut silently on write.
			if ( $schema_type === 'string' && isset( $config['max'] ) ) {
				$property['maxLength'] = (int) $config['max'];
			}

			$properties[ $key ] = $property;
		}

		return $properties;
	}
}
