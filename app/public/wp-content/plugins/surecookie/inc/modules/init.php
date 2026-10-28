<?php
/**
 * Modules Init class.
 *
 * @package SureCookie\Inc\Modules
 * @since 0.0.1
 */

namespace SureCookie\Inc\Modules;

use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Init class.
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
	public function __construct() {
		$modules_dir = __DIR__;
		$init_files  = glob( "{$modules_dir}/*/init.php" );

		if ( ! $init_files ) {
			return;
		}

		foreach ( $init_files as $file ) {
			$module_name    = basename( dirname( $file ) );
			$namespace_name = $this->convert_to_namespace( $module_name );
			$class_name     = "\\SureCookie\\Inc\\Modules\\{$namespace_name}\\Init";

			if ( class_exists( $class_name ) && method_exists( $class_name, 'get_instance' ) ) {
				$class_name::get_instance();
			}
		}
	}

	/**
	 * Convert folder name to proper namespace format.
	 *
	 * @param string $folder_name Module folder name.
	 * @return string
	 * @since 0.0.1
	 */
	private function convert_to_namespace( string $folder_name ): string {
		return str_replace( ' ', '', ucwords( str_replace( '-', ' ', $folder_name ) ) );
	}
}
