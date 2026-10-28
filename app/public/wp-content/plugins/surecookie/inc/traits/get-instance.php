<?php
/**
 * Get Instance Trait.
 *
 * @package SureCookie
 * @since 0.0.1
 */

namespace SureCookie\Inc\Traits;

defined( 'ABSPATH' ) || exit;

/**
 * Trait GetInstance.
 *
 * Provides singleton pattern implementation for classes.
 *
 * @since 0.0.1
 */
trait GetInstance {
	/**
	 * Instance object.
	 *
	 * @var self|null Class Instance.
	 */
	private static ?self $instance = null;

	/**
	 * Initiator
	 *
	 * @since 0.0.1
	 * @return self initialized object of class.
	 */
	public static function get_instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}
