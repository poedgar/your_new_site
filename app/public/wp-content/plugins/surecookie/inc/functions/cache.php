<?php
/**
 * Cache
 *
 * @package SureCookie
 * @since 0.0.1
 */

namespace SureCookie\Inc\Functions;

use WP_Filesystem_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Cache
 *
 * Handles file-based cache operations.
 *
 * @since 0.0.1
 */
class Cache {
	/**
	 * Cache directory path.
	 *
	 * @var string
	 */
	private static string $cache_dir = '';

	/**
	 * Initialize cache directory.
	 *
	 * @since 0.0.1
	 * @return bool True on success, false on failure.
	 */
	public static function init(): bool {
		if ( ! empty( self::$cache_dir ) ) {
			return true;
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';

		if ( empty( $base_dir ) ) {
			return false;
		}

		self::$cache_dir = trailingslashit( $base_dir ) . SURECOOKIE_PREFIX . '/';

		if ( ! file_exists( self::$cache_dir ) ) {
			return wp_mkdir_p( self::$cache_dir );
		}

		return true;
	}

	/**
	 * Store data in cache file.
	 *
	 * @param string $filename Relative filename.
	 * @param string $data     File content.
	 * @since 0.0.1
	 * @return bool True on success, false on failure.
	 */
	public static function store_file( string $filename, string $data ): bool {
		if ( ! self::init() ) {
			return false;
		}

		$filename = self::sanitize_filename( $filename );
		$filepath = self::$cache_dir . $filename;
		$dir      = dirname( $filepath );

		if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$filesystem = self::get_filesystem();
		if ( ! $filesystem ) {
			return false;
		}

		return (bool) $filesystem->put_contents( $filepath, $data, FS_CHMOD_FILE );
	}

	/**
	 * Get data from cache file.
	 *
	 * @param string $filename Relative filename.
	 * @since 0.0.1
	 * @return string|false File contents on success, false on failure.
	 */
	public static function get_file( string $filename ) {
		if ( ! self::init() ) {
			return false;
		}

		$filename = self::sanitize_filename( $filename );
		$filepath = self::$cache_dir . $filename;

		if ( ! file_exists( $filepath ) ) {
			return false;
		}

		$filesystem = self::get_filesystem();
		if ( ! $filesystem ) {
			return false;
		}

		return $filesystem->get_contents( $filepath );
	}

	/**
	 * Delete cache file.
	 *
	 * @param string $filename Relative filename.
	 * @since 0.0.1
	 * @return bool True on success, false on failure.
	 */
	public static function delete_file( string $filename ): bool {
		if ( ! self::init() ) {
			return false;
		}

		$filename = self::sanitize_filename( $filename );
		$filepath = self::$cache_dir . $filename;

		if ( ! file_exists( $filepath ) ) {
			return false;
		}

		$filesystem = self::get_filesystem();
		if ( ! $filesystem ) {
			return false;
		}

		return (bool) $filesystem->delete( $filepath );
	}

	/**
	 * Get absolute cache file path.
	 *
	 * @param string $filename Relative filename.
	 * @since 0.0.1
	 * @return string
	 */
	public static function get_file_path( string $filename ): string {
		if ( ! self::init() ) {
			return '';
		}

		$filename = self::sanitize_filename( $filename );

		return self::$cache_dir . $filename;
	}

	/**
	 * Check if cache file exists.
	 *
	 * @param string $filename Relative filename.
	 * @since 0.0.1
	 * @return bool
	 */
	public static function file_exists( string $filename ): bool {
		if ( ! self::init() ) {
			return false;
		}

		$filename = self::sanitize_filename( $filename );

		return file_exists( self::$cache_dir . $filename );
	}

	/**
	 * Clear entire cache directory.
	 *
	 * @since 0.0.1
	 * @return bool True on success, false on failure.
	 */
	public static function clear_all(): bool {
		if ( ! self::init() ) {
			return false;
		}

		if ( ! file_exists( self::$cache_dir ) ) {
			return true;
		}

		$filesystem = self::get_filesystem();
		if ( ! $filesystem ) {
			return false;
		}

		return (bool) $filesystem->delete( self::$cache_dir, true );
	}

	/**
	 * Sanitize filename to prevent directory traversal.
	 *
	 * @param string $filename Raw filename.
	 * @since 0.0.1
	 * @return string
	 */
	private static function sanitize_filename( string $filename ): string {
		$filename = str_replace( [ "\0", '..\\', '../', '..' ], '', $filename );
		return ltrim( $filename, '/' );
	}

	/**
	 * Initialize and return WordPress filesystem object.
	 *
	 * @since 0.0.1
	 * @return WP_Filesystem_Base|null
	 */
	private static function get_filesystem(): ?WP_Filesystem_Base {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! $wp_filesystem instanceof WP_Filesystem_Base ) {
			return null;
		}

		return $wp_filesystem;
	}
}
