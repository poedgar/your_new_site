<?php
/**
 * Re-Consent Menu Integration.
 *
 * Injects the "Cookie Preferences" button into a configured WordPress nav menu
 * using native menu item hooks for full theme compatibility.
 *
 * @package SureCookie\Inc\Modules\ReConsent
 * @since 0.0.1
 */

namespace SureCookie\Inc\Modules\ReConsent;

use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Menu
 *
 * @since 0.0.1
 */
class Menu {
	use GetInstance;

	/**
	 * Virtual menu item ID to avoid collision with real menu items.
	 * PHP_INT_MAX, not PHP_INT_MIN: absint() turns PHP_INT_MIN into a float, so any
	 * get_post_meta( $item->ID ) from a menu plugin logs a float-to-int deprecation.
	 */
	public const VIRTUAL_ITEM_ID = PHP_INT_MAX;

	/**
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	private function __construct() {
		// Only inject virtual item on the frontend; prevents it appearing in Appearance > Menus editor.
		if ( ! is_admin() ) {
			add_filter( 'wp_get_nav_menu_items', [ $this, 'add_virtual_menu_item' ], 10, 3 );
		}
		add_filter( 'nav_menu_link_attributes', [ $this, 'modify_link_attributes' ], 10, 4 );
	}

	/**
	 * Inject a virtual menu item into the configured nav menu.
	 *
	 * Untyped by design: this is a filter boundary, so an earlier callback can
	 * hand us anything. Unusable input is returned untouched rather than
	 * normalised, so another plugin's value is never silently discarded.
	 *
	 * @param mixed $items Expected array<int, \WP_Post|\stdClass> of menu item objects.
	 * @param mixed $menu  Expected \WP_Term. Optional, so a short caller cannot fatal.
	 * @param mixed $args  Expected array<string, mixed> of wp_get_nav_menu_items() arguments.
	 * @return mixed Modified menu items, or $items untouched.
	 * @since 0.0.1
	 */
	public function add_virtual_menu_item( $items = null, $menu = null, $args = [] ) {
		if ( ! is_array( $items ) || ! isset( $menu->term_id ) ) {
			return $items;
		}

		$configured_menu_id = $this->get_configured_menu_id();

		if ( ! $configured_menu_id || (int) $menu->term_id !== $configured_menu_id ) {
			return $items;
		}

		/**
		 * Filter whether to show the cookie preferences menu item.
		 *
		 * Return false to completely prevent the menu item from being injected.
		 *
		 * @param bool   $show  Whether to show the menu item. Default true.
		 * @param object $menu  The menu object, expected \WP_Term.
		 * @param array    $items Current menu items.
		 *
		 * @since 0.0.1
		 */
		if ( ! apply_filters( 'surecookie_show_reconsent_menu_item', true, $menu, $items ) ) {
			return $items;
		}

		$item                   = new \stdClass();
		$item->ID               = self::VIRTUAL_ITEM_ID;
		$item->db_id            = self::VIRTUAL_ITEM_ID;
		$item->object_id        = self::VIRTUAL_ITEM_ID;
		$item->object           = 'custom';
		$item->type             = 'custom';
		$item->type_label       = '';
		$item->title            = Helper::translated_reconsent_label();
		$item->url              = '#surecookie-reconsent';
		$item->target           = '';
		$item->attr_title       = '';
		$item->description      = '';
		$item->classes          = [ 'menu-item', 'surecookie-reconsent-menu-item' ];
		$item->xfn              = '';
		$item->menu_item_parent = 0;
		$item->menu_order       = count( $items ) + 1;
		$item->post_type        = 'nav_menu_item';
		$item->post_status      = 'publish';
		$item->post_parent      = 0;

		$items[] = $item;

		return $items;
	}

	/**
	 * Modify link attributes for the re-consent menu item.
	 *
	 * Untyped by design: see add_virtual_menu_item(). Every parameter is
	 * optional because custom nav walkers copied from pre-4.4 core emit this
	 * filter with three arguments, which a required $depth would turn into an
	 * ArgumentCountError.
	 *
	 * @param mixed $atts      Expected array<string, string> of anchor attributes.
	 * @param mixed $menu_item Expected \WP_Post, decorated by wp_setup_nav_menu_item().
	 * @param mixed $args      Expected \stdClass of wp_nav_menu() arguments.
	 * @param mixed $depth     Expected int depth of the menu item.
	 * @return mixed Modified attributes, or $atts untouched.
	 * @since 0.0.1
	 */
	public function modify_link_attributes( $atts = null, $menu_item = null, $args = null, $depth = 0 ) {
		if ( ! is_array( $atts ) || (int) ( $menu_item->db_id ?? 0 ) !== self::VIRTUAL_ITEM_ID ) {
			return $atts;
		}

		$atts['href']                      = '#';
		$atts['role']                      = 'button';
		$atts['data-surecookie-reconsent'] = 'true';

		return $atts;
	}

	/**
	 * Get the configured menu ID.
	 *
	 * @return int The menu ID, or 0 if not configured.
	 * @since 0.0.1
	 */
	private function get_configured_menu_id(): int {
		$menu_id = Settings::get( 'reconsent_menu_id' );
		return ! empty( $menu_id ) ? absint( $menu_id ) : 0;
	}
}
