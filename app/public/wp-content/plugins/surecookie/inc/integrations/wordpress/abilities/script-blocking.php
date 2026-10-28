<?php
/**
 * Script Blocking Ability
 *
 * Drives what SureCookie blocks before consent: scan-detected scripts and
 * iframes, and admin-authored blocking rules.
 *
 * @link       https://developer.wordpress.org/apis/abilities-api/
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Integrations/Wordpress/Abilities
 * @since      1.4.0
 */

namespace SureCookie\Inc\Integrations\Wordpress\Abilities;

use SureCookie\Inc\Integrations\Wordpress\Base;
use SureCookie\Inc\Modules\GoogleConsentMode\Whitelist_Handler;
use SureCookie\Inc\Services\ScriptBlockingService;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ScriptBlocking
 *
 * @since 1.4.0
 */
class ScriptBlocking extends Base {
	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $input The validated input data.
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : [];

		try {
			$action  = $input['action'] ?? '';
			$service = new ScriptBlockingService();

			$type   = sanitize_text_field( $input['type'] ?? '' );
			$domain = sanitize_text_field( $input['domain'] ?? '' );
			$value  = sanitize_text_field( $input['value'] ?? '' );

			switch ( $action ) {
				case 'list_resources':
					return [
						'success' => true,
						'message' => __( 'Scanned resources retrieved.', 'surecookie' ),
						'data'    => [
							'resources'       => $service->list_resources(
								in_array( $type, [ 'script', 'iframe' ], true ) ? $type : 'all',
								! empty( $input['excluded_only'] ),
								sanitize_text_field( $input['search'] ?? '' )
							),
							'blocking_active' => $service->is_blocking_active(),
						],
					];

				case 'recategorize_resource':
				case 'exclude_resource':
				case 'include_resource':
					$kind = $this->resource_type( $type );

					if ( $kind === null ) {
						return [
							'success' => false,
							'message' => __( 'This action needs an explicit type of "script" or "iframe"; the two are stored separately.', 'surecookie' ),
							'data'    => [],
						];
					}

					if ( $action === 'recategorize_resource' ) {
						return $service->recategorize_resource( $kind, $domain, sanitize_text_field( $input['category'] ?? '' ) );
					}

					return $action === 'exclude_resource'
						? $service->exclude_resource( $kind, $domain )
						: $service->include_resource( $kind, $domain );

				case 'list_rules':
					return [
						'success' => true,
						'message' => __( 'Blocking rules retrieved.', 'surecookie' ),
						'data'    => [
							'rules'           => $service->list_rules(
								sanitize_key( $input['category'] ?? '' ),
								in_array( $type, [ 'script', 'iframe', 'any' ], true ) ? $type : ''
							),
							'blocking_active' => $service->is_blocking_active(),
						],
					];

				case 'create_rule':
					return $service->create_rule( $input );

				case 'update_rule':
					return $service->update_rule( $value, $input );

				case 'delete_rule':
					return $service->delete_rule( $value );

				case 'list_gcm_whitelist':
					return $this->handle_gcm_whitelist( $service );

				default:
					return [
						'success' => false,
						'message' => __( 'Invalid script blocking action.', 'surecookie' ),
						'data'    => [],
					];
			}
		} catch ( \Throwable $e ) {
			return [
				'success' => false,
				'message' => __( 'An unexpected error occurred while managing script blocking.', 'surecookie' ),
				'data'    => [],
			];
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_name(): string {
		return 'surecookie/script-blocking';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label(): string {
		return __( 'Script Blocking', 'surecookie' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description(): string {
		return __( 'Control what SureCookie blocks before consent. Actions: "list_resources" lists scan-detected scripts and iframes with the category each is gated under, whether it is excluded from blocking, and the key used to address it. "recategorize_resource" moves one resource to another cookie category. "exclude_resource" stops blocking a resource (it will load before consent) and "include_resource" reverses that. "list_rules" returns admin-authored blocking rules; "create_rule", "update_rule" and "delete_rule" manage them, addressed by their pattern value. "list_gcm_whitelist" shows which services and URL patterns Google Consent Mode currently exempts from blocking, which is the usual answer to "why is Google Analytics still loading before consent?". Patterns are matched as SUBSTRINGS at runtime, so use a full domain like "example.com" rather than a bare token. Every response reports blocking_active: when that is false, banner_enabled or blocking_enabled is off and nothing is being blocked at all, so a change here alters configuration without changing what visitors load. Use surecookie/cookie-categories with action "list" to discover valid category IDs.', 'surecookie' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_annotations(): array {
		return [
			'priority'        => 3.0,
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
			'instructions'    => 'COMPLIANCE-CRITICAL. "exclude_resource" lets a tracker load before the visitor consents, and "delete_rule" removes a rule that was blocking something; both weaken consent gating, so state plainly what will now load and get explicit user confirmation first. Call "list_resources" or "list_rules" before any write so you address a resource or rule that exists and can show the user its current state. Patterns are substring-matched, so a short token blocks or unblocks far more than intended; the ability refuses obviously over-broad patterns but a plausible-looking one can still be wider than the user expects. Check blocking_active on every response: when it is false nothing is blocked regardless of these settings, and you should say so rather than reporting a compliance change. Exclusions cover scripts and iframes only, not embed or object tags.',
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'action'        => [
					'type'        => 'string',
					'enum'        => [
						'list_resources',
						'recategorize_resource',
						'exclude_resource',
						'include_resource',
						'list_rules',
						'create_rule',
						'update_rule',
						'delete_rule',
						'list_gcm_whitelist',
					],
					'description' => __( 'The script blocking action to perform.', 'surecookie' ),
				],
				'type'          => [
					'type'        => 'string',
					'enum'        => [ 'script', 'iframe', 'any', 'all' ],
					'description' => __( 'Resource kind. Required for the resource actions ("script" or "iframe"). Optional filter for "list_resources" ("all") and "list_rules" ("any").', 'surecookie' ),
				],
				'domain'        => [
					'type'        => 'string',
					'description' => __( 'Resource domain, for the resource actions. Take it from "list_resources".', 'surecookie' ),
				],
				'category'      => [
					'type'        => 'string',
					'description' => __( 'Cookie category ID. Required for "recategorize_resource" and "create_rule"; optional filter for "list_rules".', 'surecookie' ),
				],
				'excluded_only' => [
					'type'        => 'boolean',
					'description' => __( 'For "list_resources", return only resources currently excluded from blocking.', 'surecookie' ),
				],
				'search'        => [
					'type'        => 'string',
					'description' => __( 'For "list_resources", match a substring of the domain or vendor.', 'surecookie' ),
				],
				'value'         => [
					'type'        => 'string',
					'description' => __( 'Rule pattern (URL or domain), matched as a substring at runtime. Identifies the rule for "update_rule" and "delete_rule".', 'surecookie' ),
				],
				'new_value'     => [
					'type'        => 'string',
					'description' => __( 'For "update_rule", replace the pattern.', 'surecookie' ),
				],
				'name'          => [
					'type'        => 'string',
					'description' => __( 'Display label for a rule.', 'surecookie' ),
				],
				'location'      => [
					'type'        => 'string',
					'enum'        => [ 'head', 'body', 'footer', 'any' ],
					'description' => __( 'Restrict a rule to one page region. Defaults to "any".', 'surecookie' ),
				],
				'path'          => [
					'type'        => 'string',
					'description' => __( 'Optional narrowing substring, so a rule can target one file on a host.', 'surecookie' ),
				],
				'keywords'      => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => __( 'Dependent inline-script names blocked alongside the rule. Replaces the whole list on update.', 'surecookie' ),
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
				'success' => [
					'type'        => 'boolean',
					'description' => __( 'Whether the operation succeeded.', 'surecookie' ),
				],
				'message' => [
					'type'        => 'string',
					'description' => __( 'Result message.', 'surecookie' ),
				],
				'data'    => [
					'type'        => 'object',
					'description' => __( 'Action payload. Always includes blocking_active: false means banner_enabled or blocking_enabled is off and nothing is blocked at all, whatever these settings say.', 'surecookie' ),
				],
			],
		];
	}

	/**
	 * Handle the "list_gcm_whitelist" action.
	 *
	 * @param ScriptBlockingService $service Blocking service.
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	private function handle_gcm_whitelist( ScriptBlockingService $service ): array {
		if ( ! class_exists( Whitelist_Handler::class ) ) {
			return [
				'success' => false,
				'message' => __( 'Google Consent Mode is not available on this site.', 'surecookie' ),
				'data'    => [],
			];
		}

		$whitelist = Whitelist_Handler::get_instance()->get_whitelisted_scripts();

		return [
			'success' => true,
			'message' => __( 'Google Consent Mode whitelist retrieved.', 'surecookie' ),
			'data'    => [
				'services'        => $whitelist['services'] ?? [],
				'patterns'        => $whitelist['patterns'] ?? [],
				// Empty lists mean whitelisting is not in effect, which is a
				// different thing from Consent Mode exempting nothing.
				'active'          => ! empty( $whitelist['services'] ) || ! empty( $whitelist['patterns'] ),
				'blocking_active' => $service->is_blocking_active(),
			],
		];
	}

	/**
	 * Constrain a resource kind to one the settings can key on.
	 *
	 * @param string $type Requested kind.
	 * @return string
	 * @since 1.4.0
	 */
	private function resource_type( string $type ): ?string {
		// No default: 'any' and a missing type are ambiguous, and guessing
		// 'script' silently leaves the iframe on that host blocked.
		return in_array( $type, [ 'script', 'iframe' ], true ) ? $type : null;
	}
}
