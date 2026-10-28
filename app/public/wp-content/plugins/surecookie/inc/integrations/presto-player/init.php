<?php
/**
 * Presto Player - Integration Initialization
 *
 * Blocks Presto Player video/audio embeds (YouTube, Vimeo, self-hosted, Bunny.net)
 * until the visitor grants consent for the relevant category.
 *
 * Hooks the `render_block` filter - Presto renders normally (its runtime
 * script enqueues, its markup is generated), but we wrap the output in a
 * consent placeholder and stash the rendered HTML inside an inert
 * `<template>` element. Custom elements inside `<template>` are never
 * upgraded by the browser, so no `<presto-player>` exists in the live DOM
 * pre-consent - no iframe loads, no cookies set.
 *
 * On accept, consentManager.js clones the template content back into the
 * DOM. The browser auto-upgrades the new `<presto-player>` and Presto's
 * already-loaded runtime starts the player - in-place, no reload.
 *
 * @package SureCookie\Inc\Integrations\PrestoPlayer
 * @since 1.2.4
 */

namespace SureCookie\Inc\Integrations\PrestoPlayer;

use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Init class.
 *
 * @since 1.2.4
 */
class Init {
	use GetInstance;

	/**
	 * Constructor - gates on Presto Player being active.
	 *
	 * @since 1.2.4
	 */
	private function __construct() {
		if ( ! self::is_presto_player_active() ) {
			return;
		}

		Block_Handler::get_instance();

		/**
		 * Action: Fires after the Presto Player integration is initialized.
		 *
		 * @since 1.2.4
		 */
		do_action( 'surecookie_presto_player_integration_initialized' );
	}

	/**
	 * Check whether Presto Player (free or pro) is loaded.
	 *
	 * Presto exposes `PRESTO_PLAYER_PLUGIN_FILE` on bootstrap. Class checks
	 * are avoided so the integration works against future Presto refactors
	 * that may rename internal classes.
	 *
	 * @since 1.2.4
	 * @return bool
	 */
	public static function is_presto_player_active(): bool {
		return defined( 'PRESTO_PLAYER_PLUGIN_FILE' );
	}
}
