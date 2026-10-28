<?php
/**
 * Base Ability Class
 *
 * Abstract base class for all WordPress Abilities API integrations.
 * Each ability extends this class and implements the abstract methods.
 * The register() method handles the wp_register_ability() call.
 *
 * @link       https://developer.wordpress.org/apis/abilities-api/
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Integrations/Wordpress
 * @since      0.0.1-alpha.1
 */

namespace SureCookie\Inc\Integrations\Wordpress;

use SureCookie\Inc\Functions\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Base
 *
 * Abstract base class for SureCookie ability implementations.
 *
 * @since 0.0.1-alpha.1
 */
abstract class Base {
	/**
	 * Execute the ability logic.
	 *
	 * Called by the WordPress Abilities API after input validation
	 * and permission checking pass.
	 *
	 * @param mixed $input The validated input data.
	 * @return mixed The result data.
	 * @since 0.0.1-alpha.1
	 */
	abstract public function execute( $input = null );

	/**
	 * Check if the current user has permission to execute this ability.
	 *
	 * Defaults to the plugin-wide capability from {@see Helper::capability()}.
	 * Override in subclasses for more granular permission checks.
	 *
	 * @return bool
	 * @since 0.0.1-alpha.1
	 */
	public function check_permission(): bool {
		return current_user_can( Helper::capability() );
	}

	/**
	 * Register this ability with the WordPress Abilities API.
	 *
	 * Assembles all configuration from abstract and concrete methods,
	 * then calls wp_register_ability().
	 *
	 * @link https://developer.wordpress.org/reference/functions/wp_register_ability/
	 * @return void
	 * @since 0.0.1-alpha.1
	 */
	public function register(): void {
		wp_register_ability(
			$this->get_name(),
			[
				'label'               => $this->get_label(),
				'description'         => $this->get_description(),
				'category'            => $this->get_category(),
				'input_schema'        => $this->get_input_schema(),
				'output_schema'       => $this->get_output_schema(),
				'execute_callback'    => [ $this, 'execute' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'meta'                => [
					'show_in_rest' => $this->show_in_rest(),
					'annotations'  => $this->get_annotations(),
					'mcp'          => $this->get_mcp_meta(),
				],
			]
		);
	}

	/**
	 * Public accessor for the ability name.
	 *
	 * The name getter is protected, but the registrar needs the name before the
	 * ability registers so it can be offered to the enable filter.
	 *
	 * @return string
	 * @since 1.4.0
	 */
	public function get_ability_name(): string {
		return $this->get_name();
	}

	/**
	 * Get the unique ability name.
	 *
	 * Must follow the "namespace/ability-name" format.
	 * Example: 'surecookie/manage-settings'.
	 *
	 * @return lowercase-string&non-falsy-string
	 * @since 0.0.1-alpha.1
	 */
	abstract protected function get_name(): string;

	/**
	 * Get the human-readable label for this ability.
	 *
	 * @return string
	 * @since 0.0.1-alpha.1
	 */
	abstract protected function get_label(): string;

	/**
	 * Get a detailed description of what the ability does.
	 *
	 * Should be descriptive enough for AI agents and automation
	 * tools to understand the ability's purpose and usage.
	 *
	 * @return string
	 * @since 0.0.1-alpha.1
	 */
	abstract protected function get_description(): string;

	/**
	 * Get the JSON Schema defining expected inputs.
	 *
	 * @link https://json-schema.org/
	 * @return array<string, mixed>
	 * @since 0.0.1-alpha.1
	 */
	abstract protected function get_input_schema(): array;

	/**
	 * Get the JSON Schema defining the output format.
	 *
	 * @link https://json-schema.org/
	 * @return array<string, mixed>
	 * @since 0.0.1-alpha.1
	 */
	abstract protected function get_output_schema(): array;

	/**
	 * Get MCP tool annotations for this ability.
	 *
	 * Annotations guide AI agents on how to safely invoke the ability.
	 * Every subclass MUST override this method with accurate values.
	 *
	 * Keys:
	 *   - priority       (float)  Execution cost signal: 1.0 = read-only, 2.0 = write, 3.0 = destructive.
	 *   - readOnlyHint   (bool)   True if the ability only reads data and never modifies state.
	 *   - destructiveHint(bool)   True if the ability can permanently delete or irreversibly modify data.
	 *   - idempotentHint (bool)   True if calling multiple times with the same input produces the same result.
	 *   - openWorldHint  (bool)   True if the ability contacts external services (APIs, SaaS, internet).
	 *   - instructions   (string) Plain-text guidance for the AI on when and how to use this ability safely.
	 *
	 * @return array{priority: float, readOnlyHint: bool, destructiveHint: bool, idempotentHint: bool, openWorldHint: bool, instructions: string}
	 * @since 0.0.1-alpha.1
	 */
	abstract protected function get_annotations(): array;

	/**
	 * Get the category slug for this ability.
	 *
	 * Defaults to the SureCookie category. Override in subclasses
	 * to assign the ability to a different category.
	 *
	 * @return string
	 * @since 0.0.1-alpha.1
	 */
	protected function get_category(): string {
		return Init::CATEGORY_SLUG;
	}

	/**
	 * Whether to expose this ability via the REST API.
	 *
	 * @return bool
	 * @since 0.0.1-alpha.1
	 */
	protected function show_in_rest(): bool {
		return true;
	}

	/**
	 * Get the MCP meta for this ability.
	 *
	 * Defaults to a public MCP tool. Override to hide an ability from MCP while
	 * keeping it on REST, or to expose it as a resource rather than a tool.
	 *
	 * @return array{public: bool, type: string}
	 * @since 1.4.0
	 */
	protected function get_mcp_meta(): array {
		return [
			'public' => apply_filters( 'surecookie_wordpress_abilities_public_listing', true ),
			'type'   => 'tool',
		];
	}
}
