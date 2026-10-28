<?php
/**
 * Functions
 *
 * @since  2.0.0
 * @package Astra Sites
 */

if ( ! function_exists( 'astra_sites_error_log' ) ) :

	/**
	 * Error Log
	 *
	 * A wrapper function for the error_log() function.
	 *
	 * @since 2.0.0
	 *
	 * @param  mixed $message Error message.
	 * @return void
	 */
	function astra_sites_error_log( $message = '' ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			if ( is_array( $message ) ) {
				$message = wp_json_encode( $message );
			}

			if ( apply_filters( 'astra_sites_debug_logs', false ) ) {
				error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- This is for the debug logs while importing. This is conditional and will not be logged in the debug.log file for normal users.
			}
		}
	}

endif;

if ( ! function_exists( 'astra_sites_get_suggestion_link' ) ) :
	/**
	 *
	 * Get suggestion link.
	 *
	 * @since 2.6.1
	 *
	 * @return suggestion link.
	 */
	function astra_sites_get_suggestion_link() {
		$white_label_link = Astra_Sites_White_Label::get_option( 'astra-agency', 'licence' );

		if ( empty( $white_label_link ) ) {
			$white_label_link = 'https://wpastra.com/sites-suggestions/?utm_source=demo-import-panel&utm_campaign=astra-sites&utm_medium=suggestions';
		}
		return apply_filters( 'astra_sites_suggestion_link', $white_label_link );
	}
endif;

if ( ! function_exists( 'astra_sites_is_valid_image' ) ) :
	/**
	 * Check for the valid image
	 *
	 * @param string $link  The Image link.
	 *
	 * @since 2.6.2
	 * @return boolean
	 */
	function astra_sites_is_valid_image( $link = '' ) {
		return preg_match( '/^((https?:\/\/)|(www\.))([a-z0-9-].?)+(:[0-9]+)?(\/[\w\-\@]+)+\.(jpg|png|gif|jpeg|svg|webp)\/?$/i', $link );
	}
endif;

if ( ! function_exists( 'astra_get_site_data' ) ) :
	/**
	 * Returns the value of the index for the Site Data
	 *
	 * @param string $index  The index value of the data.
	 *
	 * @since 2.6.14
	 * @return mixed
	 */
	function astra_get_site_data( $index = '' ) {
		
		$demo_data = Astra_Sites_File_System::get_instance()->get_demo_content();
		if ( ! empty( $demo_data ) && isset( $demo_data[ $index ] ) ) {
			return $demo_data[ $index ];
		}
		return '';
	}
endif;

if ( ! function_exists( 'astra_sites_get_reset_form_data' ) ) :
	/**
	 * Get all the forms to be reset.
	 *
	 * @since 3.0.3
	 * @return array
	 */
	function astra_sites_get_reset_form_data() {
		global $wpdb;

		$form_ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_astra_sites_imported_wp_forms'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- We need this to get all the WP forms. Traditional WP_Query would have been expensive here.

		return $form_ids;
	}
endif;

if ( ! function_exists( 'astra_sites_get_reset_term_data' ) ) :
	/**
	 * Get all the terms to be reset.
	 *
	 * @since 3.0.3
	 * @return array
	 */
	function astra_sites_get_reset_term_data() {
		global $wpdb;

		$term_ids = $wpdb->get_col( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key='_astra_sites_imported_term'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- We need this to get all the terms and taxonomy. Traditional WP_Query would have been expensive here.

		return $term_ids;
	}
endif;

if ( ! function_exists( 'astra_sites_empty_post_excerpt' ) ) :
	/**
	 * Remove the post excerpt
	 *
	 * @param int $post_id  The post ID.
	 * @since 3.1.0
	 */
	function astra_sites_empty_post_excerpt( $post_id = 0 ) {
		if ( ! $post_id ) {
			return;
		}

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_excerpt' => '',
			)
		);
	}
endif;

if ( ! function_exists( 'astra_sites_sanitize_recursive' ) ) :
	/**
	 * Recursively sanitize an array in single dimension or string using sanitize_text_field.
	 *
	 * @param mixed $data The data to sanitize. Can be a string or an array.
	 * @since 4.4.21
	 * @return mixed The sanitized data.
	 */
	function astra_sites_sanitize_recursive( $data ) {
		if ( is_array( $data ) ) {
			return array_map( 'astra_sites_sanitize_recursive', $data );
		}
		return sanitize_text_field( $data );
	}
endif;

if ( ! function_exists( 'astra_sites_localize_script_multiline' ) ) {
	/**
	 * Drop-in replacement for wp_localize_script() that prints each top-level entry on its own line.
	 *
	 * Use it for large payloads: some servers (Apache/LiteSpeed mod_substitute) reject
	 * responses containing a line longer than 1 MB. Values match wp_localize_script() output.
	 *
	 * @since 4.7.7
	 *
	 * @param string $handle      Registered script handle to attach the data to.
	 * @param string $object_name Name of the JavaScript global to define.
	 * @param mixed  $data        Data to expose, keyed by property name. Expected to be an array;
	 *                            anything else is handed to wp_localize_script() as is.
	 * @return void
	 */
	function astra_sites_localize_script_multiline( $handle, $object_name, $data ) {
		// Non-array data: keep core's _doing_it_wrong() notice and back-compat output.
		if ( ! is_array( $data ) ) {
			wp_localize_script( $handle, $object_name, $data );
			return;
		}

		/**
		 * Filters whether the data is printed one entry per line; return false to fall back to wp_localize_script().
		 *
		 * @since 4.7.7
		 *
		 * @param bool   $multiline   Whether to print one entry per line. Default true.
		 * @param string $handle      Script handle the data is attached to.
		 * @param string $object_name Name of the JavaScript global.
		 */
		if ( ! apply_filters( 'astra_sites_use_multiline_localize_script', true, $handle, $object_name ) ) {
			wp_localize_script( $handle, $object_name, $data );
			return;
		}

		// Same flags as WP_Scripts::localize() so `<` and `>` never reach the <script> body raw.
		// Fall back to `null` on encoding failure so the output stays valid JavaScript.
		$encode = function ( $value ) {
			$json = wp_json_encode( $value, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ); // phpcs:ignore PHPCompatibility.Constants.NewConstants.json_unescaped_slashesFound -- PHP 5.4+ constant; the plugin requires PHP 7.4.
			return false === $json ? 'null' : $json;
		};

		$lines = array();

		foreach ( $data as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$value = html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
			}

			if ( is_array( $value ) && ! empty( $value ) ) {
				// One entry per line; nested values stay compact.
				$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
				$entries = array();

				foreach ( $value as $entry_key => $entry ) {
					$entries[] = ( $is_list ? '' : $encode( (string) $entry_key ) . ':' ) . $encode( $entry );
				}

				$encoded = $is_list
					? "[\n" . implode( ",\n", $entries ) . "\n]"
					: "{\n" . implode( ",\n", $entries ) . "\n}";
			} else {
				$encoded = $encode( $value );
			}

			$lines[] = $encode( (string) $key ) . ':' . $encoded;
		}

		wp_add_inline_script( $handle, 'var ' . $object_name . " = {\n" . implode( ",\n", $lines ) . "\n};", 'before' );
	}
}
