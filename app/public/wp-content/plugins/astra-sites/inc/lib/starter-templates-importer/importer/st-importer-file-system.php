<?php
/**
 * ST Importer File System
 *
 * @since  1.0.1
 * @package ST Importer
 */

namespace STImporter\Importer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ST_Importer_File_System' ) ) {

	/**
	 * ST_Importer_File_System
	 */
	class ST_Importer_File_System {

		/**
		 * Instance of this class.
		 *
		 * @since 1.0.0
		 * @var self Class object.
		 */
		private static $instance = null;

		/**
		 * Initiator of this class.
		 *
		 * @since 1.0.0
		 * @return self initialized object of this class.
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Folder name for the json files.
		 *
		 * @var string
		 * @since 1.0.1
		 */
		public static $folder_name = 'json';

		/**
		 * Create files for demo content.
		 *
		 * @return void
		 * @since 1.0.1
		 */
		public function create_file() {
			$upload_dir = wp_upload_dir();
			$file       = array(
				'file_base'    => $upload_dir['basedir'] . '/astra-sites/' . self::$folder_name,
				'file_name'    => 'astra_sites_import_data.json',
				'file_content' => array(),
			);

			if ( wp_mkdir_p( $file['file_base'] ) && ! file_exists( trailingslashit( $file['file_base'] ) . $file['file_name'] ) ) {
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
		 * Delete a JSON file from the uploads directory.
		 *
		 * @param string $file_name File name to be deleted.
		 * @return void True on success, false on failure.
		 */
		public function delete_json_file( $file_name ) {
			$upload_dir = wp_upload_dir();
			$path       = $upload_dir['basedir'] . '/astra-sites/' . self::$folder_name . '/';
			$file_name  = $path . $file_name;

			if ( file_exists( $path ) ) {
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
			$upload_dir = wp_upload_dir();
			$path       = $upload_dir['basedir'] . '/astra-sites/' . self::$folder_name . '/';
			$file_name  = $path . $file_name;

			if ( file_exists( $file_name ) ) {
				// Ignoring the rule as it is not a remote file.
				$file_content = file_get_contents( $file_name ); //phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

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
		 * @since  1.0.1
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
		 * @since  1.0.1
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
		 * Update files/directories.
		 *
		 * @param string $file_name    The file name.
		 * @param mixed  $file_content The file content.
		 *
		 * @return void
		 */
		public function update_json_file( $file_name, $file_content ) {
			$upload_dir = wp_upload_dir();
			$dir_info   = array(
				'path' => $upload_dir['basedir'] . '/astra-sites/' . self::$folder_name . '/',
			);
			$this->create_file();
			if ( file_exists( $dir_info['path'] . $file_name ) && file_put_contents( $dir_info['path'] . $file_name, wp_json_encode( $file_content ) ) !== false ) { //phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
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

		/**
		 * Report where the demo content is still available.
		 *
		 * @since 1.1.45
		 *
		 * @return array<string, string> Keys: file, fallback, data_saved, batch_started, batch_complete.
		 */
		public function get_diagnostics() {
			$upload_dir    = wp_upload_dir();
			$file          = $upload_dir['basedir'] . '/astra-sites/' . self::$folder_name . '/astra_sites_import_data.json';
			$data_saved    = (int) get_option( 'astra_sites_import_data_saved_time', 0 );
			$batch_started = (int) get_option( 'astra_sites_batch_process_started_time', 0 );

			$data_saved_state = 'none';
			if ( $data_saved > 0 ) {
				$data_saved_state = $batch_started > 0 && $data_saved > $batch_started ? 'after_batch_start' : 'before_batch_start';
			}

			return array(
				'file'           => file_exists( $file ) && filesize( $file ) > 2 ? 'present' : 'missing',
				'fallback'       => ! empty( get_option( 'astra_sites_import_data_fallback', array() ) ) ? 'present' : 'missing',
				'data_saved'     => $data_saved_state,
				'batch_started'  => (string) get_option( 'astra_sites_batch_process_started', 'no' ),
				'batch_complete' => (string) get_option( 'astra_sites_batch_process_complete', 'no' ),
			);
		}
	}

	/**
	 * Kicking this off by calling 'get_instance()' method
	 */
	ST_Importer_File_System::get_instance();

}
