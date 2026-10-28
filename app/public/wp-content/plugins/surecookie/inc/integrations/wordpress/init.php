<?php
/**
 * WordPress Abilities API Integration
 *
 * Registers the SureCookie ability category and all abilities
 * with the WordPress Abilities API (WP 6.9+).
 *
 * @link       https://developer.wordpress.org/apis/abilities-api/
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Integrations/Wordpress
 * @since      0.0.1-alpha.1
 */

namespace SureCookie\Inc\Integrations\Wordpress;

use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Init
 *
 * Hooks into the WordPress Abilities API lifecycle to register
 * the SureCookie category and individual abilities.
 *
 * @since 0.0.1-alpha.1
 */
class Init {
	use GetInstance;

	/**
	 * The slug used for the SureCookie ability category.
	 *
	 * @since 0.0.1-alpha.1
	 */
	public const CATEGORY_SLUG = 'surecookie';

	/**
	 * Constructor.
	 *
	 * @since 0.0.1-alpha.1
	 */
	public function __construct() {
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/**
	 * Whether SureCookie abilities should be registered.
	 *
	 * Driven by the `enable_mcp` admin setting (default false). When
	 * disabled, no abilities are registered and AI clients cannot connect.
	 *
	 * @return bool
	 * @since 1.1.0
	 */
	public static function abilities_enabled(): bool {
		/**
		 * Filters whether SureCookie abilities should register with the Abilities API.
		 *
		 * @param bool $enabled Defaults to the `enable_mcp` admin setting.
		 * @since 1.1.0
		 */
		return (bool) apply_filters( 'surecookie_abilities_api_enabled', (bool) Settings::get( 'enable_mcp' ) );
	}

	/**
	 * Register the SureCookie ability category.
	 *
	 * @return void
	 * @since 0.0.1-alpha.1
	 */
	public function register_category(): void {
		if ( ! self::abilities_enabled() ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY_SLUG,
			[
				'label'       => __( 'SureCookie', 'surecookie' ),
				'description' => __( 'Cookie consent management abilities provided by SureCookie.', 'surecookie' ),
			]
		);
	}

	/**
	 * Instantiate and register each ability class.
	 *
	 * Iterates over the list from Actions::get_abilities(),
	 * creates an instance, and calls its register() method.
	 *
	 * @return void
	 * @since 0.0.1-alpha.1
	 */
	public function register_abilities(): void {
		if ( ! self::abilities_enabled() ) {
			return;
		}

		$ability_classes = Actions::get_abilities();

		foreach ( $ability_classes as $class ) {
			if ( ! class_exists( $class ) || ! is_subclass_of( $class, Base::class ) ) {
				continue;
			}

			$ability = new $class();

			/**
			 * Filters whether an individual ability should register.
			 *
			 * `enable_mcp` is all-or-nothing, so this is the only way to keep,
			 * say, read-only consent-log access while withholding a destructive
			 * ability. Code-level only; there is no admin UI for it.
			 *
			 * @param bool   $enabled Whether to register. Default true.
			 * @param string $name    Ability name, e.g. 'surecookie/consent-logs'.
			 * @param string $class   Ability class name.
			 * @since 1.4.0
			 */
			if ( ! apply_filters( 'surecookie_ability_enabled', true, $ability->get_ability_name(), $class ) ) {
				continue;
			}

			$ability->register();
		}
	}
}
