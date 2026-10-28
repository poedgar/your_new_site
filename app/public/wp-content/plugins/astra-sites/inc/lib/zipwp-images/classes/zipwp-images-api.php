<?php
/**
 * Zipwp Images API
 *
 * @since  1.0.0
 * @package Zipwp Images API
 */

namespace ZipWP_Images\Classes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ai_Builder
 */
class Zipwp_Images_Api {
	/**
	 * Instance
	 *
	 * @access private
	 * @var object Class Instance.
	 * @since 1.0.0
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 *
	 * @since  1.0.0
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_action( 'wp_ajax_zipwp_images_insert_image', array( $this, 'zipwp_insert_image' ) );
	}

	/**
	 * Initiator
	 *
	 * @since 1.0.0
	 * @return object initialized object of class.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get api domain
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_api_domain() {
		return trailingslashit( defined( 'ZIPWP_API' ) ? ZIPWP_API : 'https://api.zipwp.com/api/' );
	}

	/**
	 * Get api namespace
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_api_namespace() {
		return 'zipwp-images/v1';
	}

	/**
	 * Get API headers
	 *
	 * @since 1.0.0
	 * @return array<string, string>
	 */
	public function get_api_headers() {
		return array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);
	}

	/**
	 * Check whether a given request has permission to read notes.
	 *
	 * @param  object $request WP_REST_Request Full details about the request.
	 * @return object|bool
	 */
	public function get_item_permissions_check( $request ) {

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error(
				'gt_rest_cannot_access',
				__( 'Sorry, you are not allowed to do that.', 'astra-sites' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Load all the required files in the importer.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_route(): void {

		register_rest_route(
			$this->get_api_namespace(),
			'/images/',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'get_images' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'keywords'    => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'per_page'    => array(
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'page'        => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'orientation' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'required'          => false,
						),
						'engine'      => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'filter'      => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'color'       => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Get Images.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return mixed
	 */
	public function get_images( $request ) {

		$nonce = $request->get_header( 'X-WP-Nonce' );

		// Verify the nonce.
		if ( ! wp_verify_nonce( sanitize_text_field( (string) $nonce ), 'wp_rest' ) ) {
			return new \WP_Error(
				'nonce_verification_failed',
				__( 'Nonce verification failed.', 'astra-sites' ),
				array( 'status' => 403 )
			);
		}

		$api_endpoint = $this->get_api_domain() . 'images/';

		$post_data = array(
			'keywords'    => isset( $request['keywords'] ) && ! empty( $request['keywords'] ) ? [ $request['keywords'] ] : [ 'people' ],
			'per_page'    => isset( $request['per_page'] ) ? $request['per_page'] : 20,
			'page'        => isset( $request['page'] ) ? sanitize_text_field( $request['page'] ) : '1',
			// Expected orientation values are all, landscape, portrait.
			'orientation' => isset( $request['orientation'] ) ? sanitize_text_field( $request['orientation'] ) : '',
			'color'       => isset( $request['color'] ) ? sanitize_text_field( $request['color'] ) : '',
			// Expected filter values are newest, popular.
			'filter'      => isset( $request['filter'] ) ? sanitize_text_field( $request['filter'] ) : 'popular',
			'engine'      => isset( $request['engine'] ) ? sanitize_text_field( $request['engine'] ) : 'pexels',
			'details'     => true,
		);

		switch ( $post_data['engine'] ) {

			case 'pexels':
				// sort=popular.
				$post_data['filter'] = 'popular' === $post_data['filter'] ? 'popular' : 'desc';
				break;

			case 'pixabay':
				// order=popular.
				$post_data['filter'] = 'popular' === $post_data['filter'] ? 'popular' : 'latest';
				break;

			case 'unsplash':
				// order_by=popular or latest.
				$post_data['filter'] = 'popular' === $post_data['filter'] ? 'popular' : 'latest';
				break;
		}

		$request_args = array(
			'body'    => wp_json_encode( $post_data ),
			'headers' => $this->get_api_headers(),
			'timeout' => 100,
		);
		$response     = wp_safe_remote_post( $api_endpoint, $request_args ); // @phpstan-ignore-line

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'remote_request_failed',
				__( 'Remote request failed.', 'astra-sites' ),
				array( 'status' => 500 )
			);
		}
		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		if ( 200 === $response_code ) {
			$response_data = json_decode( $response_body, true );

			// Get image sizes and add to the each image.
			$images = is_array( $response_data ) && isset( $response_data['images'] ) ? $response_data['images'] : [];
			foreach ( $images as $key => $image ) {
				$images[ $key ]['sizes'] = $this->get_image_size( $image );
			}

			return rest_ensure_response(
				array(
					'data'   => $images,
					'status' => true,
				)
			);

		} else {
			return new \WP_Error(
				'api_error',
				'Failed',
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Download and save the image in the media library.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function zipwp_insert_image(): void {
		// Verify Nonce.
		check_ajax_referer( 'zipwp-images', '_ajax_nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( __( 'You are not allowed to perform this action', 'astra-sites' ) );
		}

		$url      = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : false;
		$desc     = isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '';
		$photo_id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : 0;

		// For unsplash images, photo_id can be alphanumeric.
		if ( strpos( $url, 'unsplash.com' ) === false ) {
			$photo_id = absint( $photo_id );
		}

		if ( 0 === $photo_id ) {
			wp_send_json_error( __( 'Need to send photo ID', 'astra-sites' ) );
		}

		if ( empty( $url ) ) {
			wp_send_json_error( __( 'Need to send URL of the image to be downloaded', 'astra-sites' ) );
		}

		// Reject non-allowlisted hosts (SSRF guard).
		if ( ! $this->is_allowed_image_url( $url ) ) {
			wp_send_json_error( __( 'Invalid image URL.', 'astra-sites' ) );
		}

		$image  = '';
		$result = array();

		$name  = pathinfo( (string) $name, PATHINFO_FILENAME ) . '-' . $photo_id . '.jpg';
		$image = $this->create_image_from_url( $url, $name, (string) $photo_id, $desc );

		// A failed download or sideload returns a WP_Error, which is not empty — check for it
		// explicitly so the request fails loudly instead of reporting a bogus attachment.
		if ( is_wp_error( $image ) || empty( $image ) || ! is_numeric( $image ) ) {
			wp_send_json_error( __( 'Could not download the image.', 'astra-sites' ) );
		}

		$image                    = intval( $image );
		$attachment_data          = wp_prepare_attachment_for_js( $image );
		$result['attachmentData'] = $attachment_data;

		if ( empty( $attachment_data ) ) {
			wp_send_json_error( __( 'Could not download the image.', 'astra-sites' ) );
		}

		if ( did_action( 'elementor/loaded' ) ) {
			$result['data'] = $this->get_attachment_data( $image );
		}

		// Save downloaded image reference to an option.
		if ( 0 !== $photo_id ) {
			$saved_images = get_option( 'zipwp-images-saved-images', array() );

			if ( empty( $saved_images ) ) {
				$saved_images = array();
			}

			$saved_images[] = $photo_id;
			update_option( 'zipwp-images-saved-images', $saved_images, false );
		}

		$result['updated-saved-images'] = get_option( 'zipwp-images-saved-images', array() );

		wp_send_json_success( $result );
	}

	/**
	 * Check whether an image URL points at an allowlisted stock image host.
	 *
	 * @since 1.0.32
	 * @param string $url Image URL to validate.
	 * @return bool
	 */
	public function is_allowed_image_url( $url ) {
		$allowed_hosts = apply_filters(
			'zipwp_images_allowed_hosts',
			array(
				'images.pexels.com',
				'cdn.pixabay.com',
				'pixabay.com',
				'images.unsplash.com',
				'plus.unsplash.com',
			)
		);

		$host = wp_parse_url( (string) $url, PHP_URL_HOST );

		if ( is_string( $host ) && in_array( $host, (array) $allowed_hosts, true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Create the image and return the new media upload id.
	 *
	 * @param String $url URL to pixabay image.
	 * @param String $name Name to pixabay image.
	 * @param String $photo_id Photo ID to pixabay image.
	 * @param String $description Description to pixabay image.
	 * @see http://codex.wordpress.org/Function_Reference/wp_insert_attachment#Example
	 *
	 * @return mixed
	 */
	public function create_image_from_url( $url, $name, $photo_id, $description = '' ) {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		if ( ! function_exists( 'wp_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$file_array         = array();
		$file_array['name'] = wp_basename( $name );

		// Download file to temp location.
		$file_array['tmp_name'] = download_url( $url );

		// If error storing temporarily, return the error itself so callers can detect it.
		if ( is_wp_error( $file_array['tmp_name'] ) ) {
			return $file_array['tmp_name'];
		}

		// Do the validation and storage stuff.
		$id = media_handle_sideload( $file_array, 0, null );

		// If error storing permanently, unlink.
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $file_array['tmp_name'] );
			return $id;
		}

		$alt = '' === $description ? $name : $description;

		// Store the original attachment source in meta.
		add_post_meta( $id, '_source_url', $url );

		update_post_meta( $id, 'zipwp-images', $photo_id );
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		return $id;
	}

	/**
	 * Import Image.
	 *
	 * @since  1.0.0
	 * @param int $image Downloaded Image id.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function get_attachment_data( $image ) {
		if ( empty( $image ) || ! class_exists( 'Elementor\Utils' ) ) {
			return array();
		}

		return array(
			'content' => array(
				array(
					'id'       => \Elementor\Utils::generate_random_string(),
					'elType'   => 'section',
					'settings' => array(),
					'isInner'  => false,
					'elements' => array(
						array(
							'id'       => \Elementor\Utils::generate_random_string(),
							'elType'   => 'column',
							'elements' => array(
								array(
									'id'         => \Elementor\Utils::generate_random_string(),
									'elType'     => 'widget',
									'settings'   => array(
										'image'      => array(
											'url' => wp_get_attachment_url( $image ),
											'id'  => $image,
										),
										'image_size' => 'full',
									),
									'widgetType' => 'image',
								),
							),
							'isInner'  => false,
						),
					),
				),
			),
		);
	}

	/**
	 * Image size.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $image Image Array.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_image_size( $image ) {
		$details = isset( $image['details'] ) && is_array( $image['details'] ) ? $image['details'] : array();
		$engine  = isset( $image['engine'] ) ? $image['engine'] : '';

		$image_url = isset( $image['url'] ) ? (string) $image['url'] : '';

		switch ( $engine ) {
			case 'pexels':
				$sizes = $this->get_pexels_sizes( $details, $image_url );
				break;
			case 'pixabay':
				$sizes = $this->get_pixabay_sizes( $details );
				break;
			default:
				$sizes = array();
				break;
		}

		// Drop any entries that are missing a usable URL so they never reach the UI.
		$sizes = array_values(
			array_filter(
				$sizes,
				static function ( $size ) {
					return ! empty( $size['url'] );
				}
			)
		);

		if ( ! empty( $sizes ) ) {
			return $sizes;
		}

		// No mapped sizes and nothing to fall back to.
		if ( empty( $image['url'] ) ) {
			return array();
		}

		/*
		 * Fallback: guarantee at least the original image is selectable. Without this,
		 * an engine whose size data is missing or could not be mapped leaves the
		 * "Choose a size" selector blank and the Insert action without a URL.
		 */
		$original_url = (string) $image['url'];
		$dimensions   = $this->get_image_dimensions( $original_url );

		return array(
			array(
				'id'     => 'original',
				'url'    => $original_url,
				'width'  => $dimensions['width'],
				'height' => $dimensions['height'],
			),
		);
	}

	/**
	 * Map Pexels image details to selectable sizes.
	 *
	 * The upstream API does not return a `details.src` object for Pexels, so the
	 * sizes are derived from the resizable Pexels CDN URL instead. When `details.src`
	 * is present it is preferred.
	 *
	 * @since 1.0.31
	 * @param array<string, mixed> $details   Pexels image `details` payload.
	 * @param string               $image_url Top-level image URL (resizable Pexels CDN URL).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_pexels_sizes( $details, $image_url = '' ) {
		$available_image_sizes = isset( $details['src'] ) && is_array( $details['src'] ) ? $details['src'] : array();

		if ( empty( $available_image_sizes ) ) {
			return $this->get_pexels_sizes_from_url( $image_url );
		}

		$sizes = array();
		foreach ( $available_image_sizes as $size_key => $url ) {
			if ( empty( $url ) ) {
				continue;
			}
			$dimensions = $this->get_image_dimensions( $url );
			$sizes[]    = array(
				'id'     => $size_key,
				'url'    => $url,
				'width'  => $dimensions['width'],
				'height' => $dimensions['height'],
			);
		}

		return $sizes;
	}

	/**
	 * Derive Pexels selectable sizes from the resizable CDN URL.
	 *
	 * Pexels image URLs (`https://images.pexels.com/photos/{id}/pexels-photo-{id}.jpeg`)
	 * are resized on the fly via the `w` query argument. The base URL (no width) serves
	 * the full-resolution original.
	 *
	 * @since 1.0.31
	 * @param string $image_url Pexels CDN URL to derive sizes from.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_pexels_sizes_from_url( $image_url ) {
		if ( empty( $image_url ) || strpos( $image_url, 'images.pexels.com' ) === false ) {
			return array();
		}

		// Strip any existing query so widths are applied to a clean base URL.
		$base = strtok( $image_url, '?' );

		// Size id => target width in pixels. 0 keeps the full-resolution original.
		$variants = array(
			'original' => 0,
			'medium'   => 640,
			'small'    => 300,
		);

		$sizes = array();
		foreach ( $variants as $size_key => $width ) {
			$args = array(
				'auto' => 'compress',
				'cs'   => 'tinysrgb',
			);
			if ( $width > 0 ) {
				$args['w'] = $width;
			}
			$sizes[] = array(
				'id'     => $size_key,
				'url'    => add_query_arg( $args, $base ),
				'width'  => $width > 0 ? (string) $width : '',
				'height' => '',
			);
		}

		return $sizes;
	}

	/**
	 * Map Pixabay image details to selectable sizes.
	 *
	 * @since 1.0.31
	 * @param array<string, mixed> $details Pixabay image `details` payload.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_pixabay_sizes( $details ) {
		return array(
			array(
				'id'     => 'original',
				'url'    => isset( $details['largeImageURL'] ) ? $details['largeImageURL'] : '',
				'width'  => isset( $details['imageWidth'] ) ? $details['imageWidth'] : '',
				'height' => isset( $details['imageHeight'] ) ? $details['imageHeight'] : '',
			),
			array(
				'id'     => 'medium',
				'url'    => isset( $details['webformatURL'] ) ? $details['webformatURL'] : '',
				'width'  => isset( $details['webformatWidth'] ) ? $details['webformatWidth'] : '',
				'height' => isset( $details['webformatHeight'] ) ? $details['webformatHeight'] : '',
			),
			array(
				'id'     => 'small',
				'url'    => isset( $details['previewURL'] ) ? $details['previewURL'] : '',
				'width'  => isset( $details['previewWidth'] ) ? $details['previewWidth'] : '',
				'height' => isset( $details['previewHeight'] ) ? $details['previewHeight'] : '',
			),
		);
	}

	/**
	 * Get width and height of the image.
	 *
	 * @since 1.0.0
	 * @param string $url Image URL.
	 * @return array<string, array<string, string>|string>
	 */
	public function get_image_dimensions( $url ) {
		$clean_url    = esc_url_raw( $url );
		$query_params = array();
		$query_string = explode( '?', $clean_url );
		if ( isset( $query_string[1] ) ) {
			// phpcs:ignore Generic.PHP.ForbiddenFunctions.FoundWithAlternative -- parse_str used safely into separate array
			parse_str( $query_string[1], $query_params );
		}
		return array(
			'width'  => $query_params['w'] ?? '',
			'height' => $query_params['h'] ?? '',
		);
	}
}

/**
 * Kicking this off by calling 'get_instance()' method
 */
Zipwp_Images_Api::get_instance();
