<?php
/**
 * Abilities Loader
 *
 * Auto-discovers and registers all SureContact ability classes.
 *
 * @since 1.3.1
 *
 * @package SureContact\Abilities
 */

namespace SureContact\Abilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abilities_Loader class
 *
 * Scans the abilities directory recursively, resolves class names from file paths,
 * and registers each ability with the WordPress Abilities API.
 *
 * @since 1.3.1
 */
class Abilities_Loader {

	/**
	 * Singleton instance.
	 *
	 * @since 1.3.1
	 *
	 * @var Abilities_Loader|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @since 1.3.1
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — use instance().
	 *
	 * @since 1.3.1
	 */
	private function __construct() {}

	/**
	 * Hook into WordPress to register abilities when the Abilities API is ready.
	 *
	 * Silently bails if wp_register_ability does not exist, keeping the plugin
	 * safe on sites that do not have the Abilities API available.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function init(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/**
	 * Register the surecontact ability category.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		// Skip if already registered (e.g. by zipwp-mcp).
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'surecontact' ) ) {
			return;
		}

		wp_register_ability_category(
			'surecontact',
			[
				'label'       => __( 'SureContact CRM', 'surecontact' ),
				'description' => __( 'CRM integration rules, lists, tags, and contact automation tools.', 'surecontact' ),
			]
		);
	}

	/**
	 * Discover and register all ability classes.
	 *
	 * File → class name mapping convention (mirrors SureContact autoloader):
	 *   includes/abilities/crm/class-create-tag.php
	 *     → directory segments: ['crm'] → namespace parts: ['Crm']
	 *     → file segment: 'class-create-tag' → strip prefix → 'create-tag'
	 *     → hyphens to underscores, ucfirst each word → 'Create_Tag'
	 *     → full class: SureContact\Abilities\Crm\Create_Tag
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		$dir  = SURECONTACT_PLUGIN_PATH . 'includes/abilities/';
		$skip = [ 'class-abstract-ability', 'class-abilities-loader' ];

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$basename = $file->getBasename( '.php' );

			if ( in_array( $basename, $skip, true ) ) {
				continue;
			}

			// Only process files with the class- prefix.
			if ( strpos( $basename, 'class-' ) !== 0 ) {
				continue;
			}

			// Build relative path inside the abilities directory (without .php).
			$relative = (string) str_replace( [ $dir, '.php' ], '', $file->getPathname() );

			// Normalize directory separators to forward slash.
			$relative = str_replace( '\\', '/', $relative );

			$parts     = explode( '/', $relative );
			$file_part = array_pop( $parts ); // E.g. 'class-create-tag'.

			// Convert directory segments to namespace parts (ucfirst each).
			$ns_parts = array_map( 'ucfirst', $parts ); // E.g. ['Crm'].

			// Strip the 'class-' prefix from the file segment.
			$class_base = substr( $file_part, strlen( 'class-' ) ); // 'create-tag'

			// Convert hyphens to underscores, then ucfirst each word.
			$class_base = str_replace( '-', '_', $class_base ); // 'create_tag'
			$class_base = implode( '_', array_map( 'ucfirst', explode( '_', $class_base ) ) ); // 'Create_Tag'

			// Build the fully-qualified class name.
			if ( ! empty( $ns_parts ) ) {
				$full_class = 'SureContact\\Abilities\\' . implode( '\\', $ns_parts ) . '\\' . $class_base;
			} else {
				$full_class = 'SureContact\\Abilities\\' . $class_base;
			}

			if ( ! class_exists( $full_class ) ) {
				continue;
			}

			$ability = new $full_class();

			if ( $ability instanceof Abstract_Ability ) {
				$ability->register();
			}
		}
	}
}
