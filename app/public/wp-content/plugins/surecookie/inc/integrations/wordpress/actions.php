<?php
/**
 * Abilities Registry
 *
 * Central registry of all ability classes.
 * Provides a filterable list so pro add-ons or third-party plugins
 * can register additional abilities.
 *
 * @link       https://developer.wordpress.org/apis/abilities-api/
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Integrations/Wordpress
 * @since      0.0.1-alpha.1
 */

namespace SureCookie\Inc\Integrations\Wordpress;

use SureCookie\Inc\Integrations\Wordpress\Abilities\ConsentLogs;
use SureCookie\Inc\Integrations\Wordpress\Abilities\ContentLookup;
use SureCookie\Inc\Integrations\Wordpress\Abilities\CookieCategories;
use SureCookie\Inc\Integrations\Wordpress\Abilities\CookieManagement;
use SureCookie\Inc\Integrations\Wordpress\Abilities\KnownServices;
use SureCookie\Inc\Integrations\Wordpress\Abilities\ManageSettings;
use SureCookie\Inc\Integrations\Wordpress\Abilities\ScriptBlocking;
use SureCookie\Inc\Integrations\Wordpress\Abilities\SiteHealth;
use SureCookie\Inc\Integrations\Wordpress\Abilities\SiteScanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Actions
 *
 * Returns the list of ability classes to register.
 *
 * @since 0.0.1-alpha.1
 */
class Actions {
	/**
	 * Get the list of ability classes.
	 *
	 * Each entry must be a fully-qualified class name extending Base.
	 * Filterable via 'surecookie_abilities_api_abilities' to allow
	 * add-ons to register additional abilities.
	 *
	 * @return array<int, class-string<Base>>
	 * @since 0.0.1-alpha.1
	 */
	public static function get_abilities(): array {
		$abilities = [
			ManageSettings::class,
			ConsentLogs::class,
			CookieManagement::class,
			CookieCategories::class,
			SiteScanner::class,
			ContentLookup::class,
			ScriptBlocking::class,
			KnownServices::class,
			SiteHealth::class,
		];

		/**
		 * Filter the list of ability classes to register.
		 *
		 * @since 0.0.1-alpha.1
		 * @param array<int, class-string<Base>> $abilities Ability class names.
		 */
		$filtered = apply_filters( 'surecookie_abilities_api_abilities', $abilities );
		return is_array( $filtered ) ? $filtered : $abilities;
	}
}
