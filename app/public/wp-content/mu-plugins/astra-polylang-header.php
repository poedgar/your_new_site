<?php
/**
 * Plugin Name: Astra header button translation
 * Description: Translates the Astra header button text and link for non-default Polylang languages.
 */

add_filter( 'astra_get_option_header-button1-text', function ( $value ) {
	if ( function_exists( 'pll_current_language' ) && 'uk' === pll_current_language() ) {
		return 'Найняти мене';
	}
	return $value;
} );

add_filter( 'astra_get_option_header-button1-link-option', function ( $value ) {
	if ( function_exists( 'pll_current_language' ) && 'uk' === pll_current_language() && is_array( $value ) ) {
		$contact = pll_get_post( url_to_postid( $value['url'] ), 'uk' );
		if ( $contact ) {
			$value['url'] = get_permalink( $contact );
		}
	}
	return $value;
} );
