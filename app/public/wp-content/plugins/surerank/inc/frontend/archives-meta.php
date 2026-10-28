<?php
/**
 * Archives Meta Data
 *
 * This file will handle functionality to print meta_data in frontend for author, date and post type archives.
 *
 * @package surerank
 * @since 1.9.0
 */

namespace SureRank\Inc\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureRank\Inc\Functions\Helper;
use SureRank\Inc\Functions\Settings;
use SureRank\Inc\Functions\Variables;
use SureRank\Inc\Traits\Get_Instance;
use SureRank\Inc\Traits\Reset_Meta_Data;

/**
 * Archives Meta
 *
 * Populates meta data for author, date and post type archive pages so the
 * document title and social meta follow the configured title template
 * instead of falling back to the WordPress core document title.
 *
 * @since 1.9.0
 */
class Archives_Meta {

	use Get_Instance;
	use Reset_Meta_Data;

	/**
	 * Meta Data
	 *
	 * @var array<string, mixed>|null $meta_data Archive meta data.
	 * @since 1.9.0
	 */
	private $meta_data = null;

	/**
	 * Constructor
	 *
	 * @since 1.9.0
	 */
	public function __construct() {
		add_filter( 'surerank_set_meta', [ $this, 'get_meta_data' ], 1 );
		$this->register_meta_reset();
	}

	/**
	 * Add meta data for author, date and post type archives.
	 *
	 * @param array<string, mixed>|null $meta_data Meta Data.
	 * @since 1.9.0
	 * @return array<string, mixed>|null $meta_data
	 */
	public function get_meta_data( $meta_data ) {
		// The WooCommerce shop page is a product archive but carries its own
		// stored SEO meta, which Single applies from the shop page's ID. Step
		// aside only when that ID actually resolves, otherwise a site with no
		// shop page configured would get no meta from either provider.
		if ( Helper::get_shop_page_id() ) {
			return $meta_data;
		}

		if ( ! is_author() && ! is_date() && ! is_post_type_archive() ) {
			return $meta_data;
		}

		if ( null !== $this->meta_data ) {
			return $meta_data;
		}

		$label = $this->get_archive_label();

		if ( '' === $label ) {
			return $meta_data;
		}

		$this->meta_data = $this->build_archive_meta( $label );
		return $this->meta_data;
	}

	/**
	 * Build archive meta data from the configured title template.
	 *
	 * The global page_title template is reused with %title% swapped for
	 * the archive label, so archives follow the exact configured title
	 * format and separator.
	 *
	 * @param string $label Archive label used in place of %title%.
	 * @since 1.9.0
	 * @return array<string, mixed>
	 */
	private function build_archive_meta( $label ) {
		$title_template = Settings::get( 'page_title' );

		if ( empty( $title_template ) || ! is_string( $title_template ) ) {
			$title_template = '%title% %separator% %site_name%';
		}

		$title_template = str_replace( '%title%', $label, $title_template );

		$description = is_author() ? wp_strip_all_tags( (string) get_the_author_meta( 'description' ) ) : '';

		$meta = [
			'page_title'           => $title_template,
			'page_description'     => $description,
			'facebook_title'       => $title_template,
			'facebook_description' => $description,
			'twitter_title'        => $title_template,
			'twitter_description'  => $description,
		];

		return Variables::replace( $meta, 0 );
	}

	/**
	 * Get the archive label used in place of %title%.
	 *
	 * Plain author name, date or post type archive label without any
	 * prefix, consistent with how taxonomy archive titles use the bare
	 * term name.
	 *
	 * @since 1.9.0
	 * @return string
	 */
	private function get_archive_label() {
		$label = '';
		$type  = '';

		if ( is_author() ) {
			$label = (string) get_the_author();
			$type  = __( 'Author', 'surerank' );
		} elseif ( is_day() ) {
			$label = (string) get_the_date();
			$type  = __( 'Day', 'surerank' );
		} elseif ( is_month() ) {
			$label = (string) get_the_date( 'F Y' );
			$type  = __( 'Month', 'surerank' );
		} elseif ( is_year() ) {
			$label = (string) get_the_date( 'Y' );
			$type  = __( 'Year', 'surerank' );
		} elseif ( is_post_type_archive() ) {
			$post_type_object = get_queried_object();
			if ( $post_type_object instanceof \WP_Post_Type && ! empty( $post_type_object->labels->name ) ) {
				$label = (string) $post_type_object->labels->name;
				$type  = (string) ( $post_type_object->labels->singular_name ?? '' );
			}
		}

		/**
		 * Enable the archive type prefix in archive titles.
		 *
		 * When true, the dynamic archive type label is prepended to the
		 * archive title, e.g. "Author: Name", "Year: 2026", "Product: Products".
		 *
		 *     add_filter( 'surerank_archive_title_prefix', '__return_true' );
		 *
		 * @since 1.9.0
		 * @param bool $enable_prefix Whether to prefix archive titles with the archive type. Default false.
		 */
		if ( '' !== $type && apply_filters( 'surerank_archive_title_prefix', false ) ) {
			/* translators: 1: archive type label (e.g. Author), 2: archive label (e.g. author name) */
			$label = sprintf( __( '%1$s: %2$s', 'surerank' ), $type, $label );
		}

		/**
		 * Filter the archive label used in author, date and post type
		 * archive titles.
		 *
		 * Allows fully customizing the label before it is inserted into
		 * the configured title template.
		 *
		 * @since 1.9.0
		 * @param string $label The archive label (author name, date or post type label).
		 * @param string $type  Dynamic archive type label (e.g. "Author", "Year", post type singular name).
		 */
		return (string) apply_filters( 'surerank_archive_title_label', $label, $type );
	}
}
