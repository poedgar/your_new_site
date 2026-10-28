<?php
/**
 * Ability base class.
 *
 * @package SureRank\Inc\Abilities
 */

namespace SureRank\Inc\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Base ability implementation for SureRank.
 */
abstract class Ability_Base {
	/**
	 * Minimum capability allowed for SureRank abilities.
	 */
	private const MIN_CAPABILITY = 'manage_options';

	/**
	 * Unique ability identifier.
	 *
	 * @var string
	 */
	protected $id = '';

	/**
	 * Human-readable label.
	 *
	 * @var string
	 */
	protected $label = '';

	/**
	 * Ability description.
	 *
	 * @var string
	 */
	protected $description = '';

	/**
	 * Ability category.
	 *
	 * @var string
	 */
	protected $category = 'surerank';

	/**
	 * Required WordPress capability.
	 *
	 * @var string
	 */
	protected $capability = 'manage_options';

	/**
	 * Get the JSON Schema for ability input.
	 *
	 * @since 1.7.5
	 * @return array<string, mixed>
	 */
	abstract public function get_input_schema();

	/**
	 * Get the JSON Schema for ability output.
	 *
	 * @since 1.7.5
	 * @return array<string, mixed>
	 */
	abstract public function get_output_schema();

	/**
	 * Execute the ability.
	 *
	 * @since 1.7.5
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	abstract public function execute( $input );

	/**
	 * Check whether abilities are enabled.
	 *
	 * @since 1.7.5
	 * @return bool
	 */
	public static function abilities_enabled() {
		return (bool) apply_filters( 'surerank_abilities_api_enabled', true );
	}

	/**
	 * Check whether this ability can be registered and executed.
	 *
	 * @since 1.7.5
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) apply_filters( 'surerank_ability_enabled', self::abilities_enabled(), $this->id, $this );
	}

	/**
	 * Permission callback for the abilities API.
	 *
	 * @since 1.7.5
	 * @return bool
	 */
	public function permission_callback() {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		return current_user_can( $this->capability );
	}

	/**
	 * Enforce SureRank's current admin capability policy.
	 *
	 * @since 1.7.5
	 * @return bool
	 */
	public function meets_capability_policy() {
		return self::MIN_CAPABILITY === $this->capability;
	}

	/**
	 * MCP-compatible annotations for this ability.
	 *
	 * @since 1.7.5
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			'destructive'   => false,
			'idempotent'    => false,
			'priority'      => 2.0,
			'openWorldHint' => false,
		];
	}

	/**
	 * MCP exposure settings for this ability.
	 *
	 * Abilities are exposed on the shared MCP server that the MCP Adapter
	 * populates, so a site already connected through one global endpoint can
	 * reach SureRank without adding a second server entry per plugin. This
	 * does not add tools to that server: its tool list is fixed to the
	 * adapter's own discover/get-info/execute proxies, and auto-discovery
	 * there only collects resource and prompt abilities. Marking these public
	 * simply stops the proxy from hiding and refusing them.
	 *
	 * @since 1.10.0
	 * @return array{public: bool, type: string}
	 */
	public function get_mcp() {
		/**
		 * Filter whether a SureRank ability is exposed on the shared MCP server.
		 *
		 * Return false to keep an ability reachable only through SureRank's own
		 * MCP server at surerank/v1/mcp.
		 *
		 * @since 1.10.0
		 *
		 * @param bool   $is_public Whether the ability is public for MCP. Default true.
		 * @param string $id        The ability ID, e.g. 'surerank/get-post-seo'.
		 * @param self   $ability   The ability instance.
		 */
		$is_public = apply_filters( 'surerank_ability_mcp_public', true, $this->id, $this );

		return [
			'public' => (bool) $is_public,
			'type'   => 'tool',
		];
	}

	/**
	 * Wrapper around execute() with action hooks.
	 *
	 * @since 1.7.5
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_wrapper( $input ) {
		do_action( 'surerank_before_ability_execute', $this->id, $input );

		$output = $this->execute( $input );

		do_action( 'surerank_after_ability_execute', $this->id, $input, $output );

		return $output;
	}

	/**
	 * Register the ability with the WordPress Abilities API.
	 *
	 * @since 1.7.5
	 * @return void
	 */
	public function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			$this->id,
			[
				'label'               => $this->label,
				'description'         => $this->description,
				'category'            => $this->category,
				'input_schema'        => $this->get_input_schema(),
				'output_schema'       => $this->get_output_schema(),
				'permission_callback' => [ $this, 'permission_callback' ],
				'execute_callback'    => [ $this, 'execute_wrapper' ],
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => $this->get_annotations(),
					'mcp'          => $this->get_mcp(),
				],
			]
		);
	}

	/**
	 * Get the ability ID.
	 *
	 * @since 1.7.5
	 * @return string
	 */
	public function get_id() {
		return $this->id;
	}
}
