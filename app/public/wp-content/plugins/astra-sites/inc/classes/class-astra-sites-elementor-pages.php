<?php
/**
 * Elementor Importer
 *
 * @package Astra Sites
 */

namespace Elementor\TemplateLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// If plugin - 'Elementor' not exist then return.
if ( ! class_exists( '\Elementor\Plugin' ) ) {
	return;
}

use Elementor\Core\Base\Document;
use Elementor\DB;
use Elementor\Core\Settings\Page\Manager as PageSettingsManager;
use Elementor\Core\Settings\Manager as SettingsManager;
use Elementor\Core\Settings\Page\Model;
use Elementor\Editor;
use Elementor\Plugin;
use Elementor\Settings;
use Elementor\Utils;

/**
 * Elementor template library local source.
 *
 * Elementor template library local source handler class is responsible for
 * handling local Elementor templates saved by the user locally on his site.
 *
 * @since 2.0.0 Added compatibility for Elemetnor v2.5.0
 */
class Astra_Sites_Elementor_Pages extends Source_Local {
	/**
	 * Import the Elementor template data.
	 *
	 * Runs every import time transformation on the template data - WPForms ID mapping,
	 * export/import content processing and demo URL replacement - and returns the result
	 * for the caller to use. The processed data is deliberately not written onto any post:
	 * the template insert flow hands it back to the Elementor editor, which appends it to
	 * the document the user is actually editing and persists it through its own save.
	 *
	 * Note that the `on_import` pass handed to Elementor is not free of side effects: the
	 * media, gallery and icon controls side load remote template assets into the media
	 * library. No post is modified here.
	 *
	 * @since 2.0.0
	 * @since 4.7.6 Dropped the `$post_id` parameter and the post meta write. The data is
	 *              returned instead of being persisted.
	 * @param  array $data Elementor Data.
	 * @return array Processed Elementor data.
	 */
	public function import( $data = array() ) {

		// Guards against a legacy `import( $post_id, $data )` call, where the post ID would
		// otherwise land in `$data` and be processed as template data.
		if ( empty( $data ) || ! is_array( $data ) ) {
			\Astra_Sites_Importer_Log::add( 'Elementor template processing skipped - Empty or invalid template data', 'warning' );
			return array();
		}

		\Astra_Sites_Importer_Log::add( 'Processing Elementor template data for the editor' );

		$data = wp_json_encode( $data );

		// Update WP form IDs.
		$ids_mapping = get_option( 'astra_sites_wpforms_ids_mapping', array() );
		if ( $ids_mapping ) {
			\Astra_Sites_Importer_Log::add( 'Updating WPForms IDs in Elementor page - Total mappings: ' . count( $ids_mapping ) );
			foreach ( $ids_mapping as $old_id => $new_id ) {
				$data = str_replace( '[wpforms id=\"' . $old_id, '[wpforms id=\"' . $new_id, $data );
				$data = str_replace( '"select_form":"' . $old_id, '"select_form":"' . $new_id, $data );
			}
		}

		$data = json_decode( $data, true );

		// Import the data.
		$data = $this->process_export_import_content( $data, 'on_import' );

		// Replace the site urls.
		$demo_data = \Astra_Sites_File_System::get_instance()->get_demo_content();
		if ( isset( $demo_data['astra-site-url'] ) ) {
			$site_url      = get_site_url();
			$site_url      = str_replace( '/', '\/', $site_url );
			$demo_site_url = 'https:' . $demo_data['astra-site-url'];
			$demo_site_url = str_replace( '/', '\/', $demo_site_url );
			\Astra_Sites_Importer_Log::add( 'Replacing template demo URLs with site URLs in Elementor data' );
			if ( ! is_array( $data ) ) {
				$data = str_replace( $demo_site_url, $site_url, $data );
			} else {
				$data = wp_json_encode( $data );
				if ( ! empty( $data ) ) {
					$data = str_replace( $demo_site_url, $site_url, $data );
					$data = json_decode( $data, true );
				}
			}
		}

		// The return value is sent straight to the editor as JSON, so honour the documented
		// array contract even when a transformation above leaves a string or a decode failure.
		if ( ! is_array( $data ) ) {
			\Astra_Sites_Importer_Log::add( 'Elementor template processing failed - Processed template data is not a valid structure', 'warning' );
			return array();
		}

		\Astra_Sites_Importer_Log::add( 'Elementor template data processed for the editor', 'success' );

		return $data;
	}
}
