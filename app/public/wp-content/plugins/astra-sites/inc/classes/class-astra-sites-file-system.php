<?php
/**
 * Astra Sites File System
 *
 * @since  4.2.0
 * @package Astra Sites
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Astra_Sites_File_System' ) ) {

	/**
	 * Astra_Sites_File_System
	 */
	class Astra_Sites_File_System {

		/**
		 * Instance of Astra_Sites
		 *
		 * @since  4.2.0
		 * @var (self) Astra_Sites
		 */
		private static $instance = null;

		/**
		 * Folder name for the json files.
		 * 
		 * @var string
		 * @since 4.2.0
		 */
		public static $folder_name = 'json';

		/**
		 * Main site JSON folder, resolved once per request on multisite.
		 *
		 * @var string|null
		 * @since 4.7.9
		 */
		private static $main_site_dir = null;

		/**
		 * Instance of Astra_Sites.
		 *
		 * @since  4.2.0
		 * @return self Class object.
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Create files for demo content.
		 * 
		 * @return void
		 * @since 4.2.0
		 */
		public function create_file() { 
			$upload_dir = wp_upload_dir();
			$file = array(
				'file_base' => $upload_dir['basedir'] . '/astra-sites/' . self::$folder_name,
				'file_name' => 'astra_sites_import_data.json',
				'file_content' => array(),
			);

			$this->create_single_file( $file );
		}

		/**
		 * Get the folder that holds a JSON file.
		 *
		 * On multisite the library files live on the main site, as the sync flags are network wide.
		 * The import data file stays per site.
		 *
		 * @since 4.7.9
		 * @param string $file_name File name.
		 * @return string Folder path without trailing slash.
		 */
		private function get_json_dir( $file_name ) {
			if ( ! is_multisite() || 'astra_sites_import_data.json' === wp_basename( $file_name ) ) {
				$upload_dir = wp_upload_dir();
				return $upload_dir['basedir'] . '/astra-sites/' . self::$folder_name;
			}

			// Resolve the main site folder once per request, as it does not change.
			if ( null === self::$main_site_dir ) {
				switch_to_blog( get_main_site_id() );
				$upload_dir          = wp_upload_dir();
				self::$main_site_dir = $upload_dir['basedir'] . '/astra-sites/' . self::$folder_name;
				restore_current_blog();
			}

			return self::$main_site_dir;
		}

		/**
		 * Delete a JSON file from the uploads directory.
		 *
		 * Library files resolve to the shared main site folder, so only call this for per-site files.
		 *
		 * @param string $file_name File name to be deleted.
		 * @return void True on success, false on failure.
		 */
		public function delete_json_file( $file_name ) {
			$file_name = wp_basename( $file_name );
			$file_name = $this->get_json_dir( $file_name ) . '/' . $file_name;
			
			if ( file_exists( $file_name ) ) {
				wp_delete_file( $file_name );
			} else {
				astra_sites_error_log( 'File not found: ' . $file_name );
			}
		}
		
		/**
		 * Getting json file for templates from uploads.
		 * 
		 * @param string $file_name  File data.
		 * @param bool   $array_format  Is The file content array.
		 * 
		 * @return mixed
		 */
		public function get_json_file_content( $file_name, $array_format = true ) {
			$file_name = wp_basename( $file_name );
			$file_name = $this->get_json_dir( $file_name ) . '/' . $file_name;

			if ( file_exists( $file_name ) ) {
				// Ignoring the rule as it is not a remote file.
				$file_content = file_get_contents( $file_name ); //phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
		
				if ( $array_format ) {
					return json_decode( (string) $file_content, true );
				} else {
					return $file_content;
				}
			}
		
			return '';
		}

		/**
		 * Getter for get_json_file_content
		 *
		 * @since  4.2.0
		 * 
		 * @return mixed
		 */
		public function get_demo_content() {
			$data = $this->get_json_file_content( 'astra_sites_import_data.json' );

			if ( ! empty( $data ) && is_array( $data ) ) {
				return $data;
			}

			// file_put_contents can silently fail on shared hosting (disk quota, permissions).
			$fallback = get_option( 'astra_sites_import_data_fallback', array() );

			if ( ! empty( $fallback ) && is_array( $fallback ) ) {
				astra_sites_error_log( 'Demo content JSON file empty or unreadable, using database fallback.' );
				return $fallback;
			}

			return $data;
		}

		/**
		 * Delete for get_json_file_content
		 *
		 * @since  4.2.0
		 * 
		 * @return mixed
		 */
		public function delete_demo_content() {
			$this->delete_json_file( 'astra_sites_import_data.json' );
			delete_option( 'astra_sites_import_data_fallback' );
			delete_option( 'astra_sites_import_data_saved_time' );
			delete_transient( 'astra_sites_import_data_fallback' );
		}

		/**
		 * Create single json file.
		 *
		 * @since 4.2.2
		 * @param array<string, mixed> $file file data.
		 * 
		 * @return void
		 */
		public function create_single_file( $file ) {

			if ( wp_mkdir_p( $file['file_base'] ) ) {
				$file_handle = @fopen( trailingslashit( $file['file_base'] ) . $file['file_name'], 'w' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_read_fopen
				if ( $file_handle ) {
					if ( is_string( wp_json_encode( $file['file_content'] ) ) ) {
						fwrite( $file_handle, wp_json_encode( $file['file_content'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite
						fclose( $file_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
						astra_sites_error_log( 'File: ' . $file['file_name'] . ' Created Successfully!' );
					}                   
				}
			}
		}

		/**
		 * Update files/directories.
		 * 
		 * @param string $file_name    The file name.
		 * @param mixed  $file_content The file content.
		 * 
		 * @return void
		 */
		public function update_json_file( $file_name, $file_content ) {
			$file_name = wp_basename( $file_name );
			$dir_info = array(
				'path' => $this->get_json_dir( $file_name ) . '/',
			);
			
			if ( ! file_exists( $dir_info['path'] . $file_name ) ) {
				$file = array(
					'file_base' => $dir_info['path'],
					'file_name' => $file_name,
					'file_content' => '',
				);

				$this->create_single_file( $file );
			}

			if ( file_exists( $dir_info['path'] . $file_name ) && file_put_contents( $dir_info['path'] . $file_name, wp_json_encode( $file_content ) ) !== false ) { //phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
				astra_sites_error_log( 'File: ' . $file_name . ' Updated Successfully!' );
			} else {
				astra_sites_error_log( 'File: ' . $file_name . ' Not Updated!' );
			}
		}

		/**
		 * Setter for update_json_file()
		 * 
		 * @param mixed $file_content Template data array; empty or non-array data is not saved.
		 * 
		 * @return bool True when saved, false when the data was rejected.
		 */
		public function update_demo_data( $file_content ) {
			// Never replace the stored demo data with an empty or failed API response.
			if ( empty( $file_content ) || ! is_array( $file_content ) ) {
				astra_sites_error_log( 'Demo content is empty or invalid, not saved.' );
				return false;
			}

			$this->update_json_file( 'astra_sites_import_data.json', $file_content );

			// Non-autoloaded option, not a transient: with a persistent object cache a transient
			// lives only in the cache and is lost to evictions, item size limits and wp_cache_flush().
			update_option( 'astra_sites_import_data_fallback', $file_content, false );

			// Lets batch-completion cleanup tell this import's data from an older import's.
			update_option( 'astra_sites_import_data_saved_time', time(), false );

			return true;
		}
	}

	/**
	 * Kicking this off by calling 'get_instance()' method
	 */
	Astra_Sites_File_System::get_instance();

}
