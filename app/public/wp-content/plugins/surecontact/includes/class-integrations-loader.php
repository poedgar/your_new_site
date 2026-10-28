<?php
/**
 * Integrations Loader Class
 *
 * Loads and manages all plugin integrations
 *
 * @since 0.0.1
 *
 * @package SureContact
 */

namespace SureContact;

use SureContact\Database\Integrations_DB;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Integrations_Loader
 *
 * Loads and initializes all available integrations
 *
 * @since 0.0.1
 */
class Integrations_Loader {

	/**
	 * Cached temporary integration instances for available-but-not-loaded integrations.
	 *
	 * @since 1.3.0
	 *
	 * @var array
	 */
	private static $temp_instances = array();

	/**
	 * Available integrations
	 *
	 * @since 0.0.1
	 *
	 * @var array
	 */
	private $available_integrations = array();

	/**
	 * Loaded integration instances
	 *
	 * @since 0.0.1
	 *
	 * @var array<string, \SureContact\Integrations\Base_Integration>
	 */
	private $loaded_integrations = array();

	/**
	 * Enabled integration slugs
	 *
	 * @since 0.0.4
	 *
	 * @var array
	 */
	private $enabled_slugs = array();

	/**
	 * Internal (hardcoded) integration slugs.
	 *
	 * Used to distinguish third-party integrations registered via the filter
	 * from built-in integrations.
	 *
	 * @since 1.2.0
	 *
	 * @var array
	 */
	private $internal_slugs = array();

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		$this->register_integrations();
		$this->enabled_slugs = array_flip( Integrations_DB::get_instance()->get_enabled_slugs() );
		$this->load_integrations();
	}

	/**
	 * Register all available integrations
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	private function register_integrations() {
		$this->available_integrations = array(
			'wordpress'              => array(
				'name'        => 'WordPress',
				'class'       => 'SureContact\\Integrations\\WordPress_Integration',
				'file'        => 'class-wordpress-integration.php',
				'dependency'  => 'wp_insert_user',
				'plugin_file' => '',
			),
			'surecart'               => array(
				'name'        => 'SureCart',
				'class'       => 'SureContact\\Integrations\\SureCart_Integration',
				'file'        => 'class-surecart-integration.php',
				'dependency'  => 'SureCart\\Models\\Purchase',
				'plugin_file' => 'surecart/surecart.php',
			),
			'latepoint'              => array(
				'name'        => 'LatePoint',
				'class'       => 'SureContact\\Integrations\\LatePoint_Integration',
				'file'        => 'class-latepoint-integration.php',
				'dependency'  => 'LatePoint',
				'plugin_file' => 'latepoint/latepoint.php',
			),
			'sureforms'              => array(
				'name'        => 'SureForms',
				'class'       => 'SureContact\\Integrations\\SureForms_Integration',
				'file'        => 'class-sureforms-integration.php',
				'dependency'  => 'SRFM\\Plugin_Loader',
				'plugin_file' => 'sureforms/sureforms.php',
			),
			'suremembers'            => array(
				'name'        => 'SureMembers',
				'class'       => 'SureContact\\Integrations\\SureMembers_Integration',
				'file'        => 'class-suremembers-integration.php',
				'dependency'  => 'SureMembers\\Inc\\Access',
				'plugin_file' => 'suremembers/suremembers.php',
			),
			'suredash'               => array(
				'name'        => 'SureDash',
				'class'       => 'SureContact\\Integrations\\SureDash_Integration',
				'file'        => 'class-suredash-integration.php',
				'dependency'  => 'SureDashboard\\Portals_Loader',
				'plugin_file' => 'suredash/suredash.php',
			),
			'woocommerce'            => array(
				'name'        => 'WooCommerce',
				'class'       => 'SureContact\\Integrations\\WooCommerce_Integration',
				'file'        => 'class-woocommerce-integration.php',
				'dependency'  => 'WooCommerce',
				'plugin_file' => 'woocommerce/woocommerce.php',
			),
			'easy_digital_downloads' => array(
				'name'        => 'Easy Digital Downloads',
				'class'       => 'SureContact\\Integrations\\EDD_Integration',
				'file'        => 'class-edd-integration.php',
				'dependency'  => 'EDD',
				'plugin_file' => 'easy-digital-downloads/easy-digital-downloads.php',
			),
			'fluentcrm'              => array(
				'name'        => 'FluentCRM',
				'class'       => 'SureContact\\Integrations\\FluentCRM_Integration',
				'file'        => 'class-fluentcrm-integration.php',
				'dependency'  => 'FluentCrm\\App\\Models\\Subscriber',
				'plugin_file' => 'fluent-crm/fluent-crm.php',
			),
			'wpforms'                => array(
				'name'        => 'WPForms',
				'class'       => 'SureContact\\Integrations\\WPForms_Integration',
				'file'        => 'class-wpforms-integration.php',
				'dependency'  => 'wpforms',
				'plugin_file' => 'wpforms-lite/wpforms.php',
			),
			'contact-form-7'         => array(
				'name'        => 'Contact Form 7',
				'class'       => 'SureContact\\Integrations\\Contact_Form_7_Integration',
				'file'        => 'class-contact-form-7-integration.php',
				'dependency'  => 'WPCF7_ContactForm',
				'plugin_file' => 'contact-form-7/wp-contact-form-7.php',
			),
			'gravity-forms'          => array(
				'name'        => 'Gravity Forms',
				'class'       => 'SureContact\\Integrations\\Gravity_Forms_Integration',
				'file'        => 'class-gravity-forms-integration.php',
				'dependency'  => 'GFForms',
				'plugin_file' => 'gravityforms/gravityforms.php',
			),
			'elementor-forms'        => array(
				'name'                => 'Elementor Forms',
				'class'               => 'SureContact\\Integrations\\Elementor_Forms',
				'file'                => 'class-elementor-forms-integration.php',
				'dependency'          => 'ElementorPro\\Plugin',
				'plugin_file'         => 'elementor-pro/elementor-pro.php',
				'plugin_dependencies' => array( 'elementor/elementor.php' ),
			),
			'fluent-forms'           => array(
				'name'        => 'Fluent Forms',
				'class'       => 'SureContact\\Integrations\\Fluent_Forms_Integration',
				'file'        => 'class-fluent-forms-integration.php',
				'dependency'  => 'FluentForm\\App\\Http\\Controllers\\IntegrationManagerController',
				'plugin_file' => 'fluentform/fluentform.php',
			),
			'presto-player'          => array(
				'name'        => 'Presto Player',
				'class'       => 'SureContact\\Integrations\\Presto_Player_Integration',
				'file'        => 'class-presto-player-integration.php',
				'dependency'  => 'PrestoPlayer\\Models\\Video',
				'plugin_file' => 'presto-player/presto-player.php',
			),
			'cartflows'              => array(
				'name'        => 'CartFlows',
				'class'       => 'SureContact\\Integrations\\CartFlows_Integration',
				'file'        => 'class-cartflows-integration.php',
				'dependency'  => 'Cartflows_Loader',
				'plugin_file' => 'cartflows/cartflows.php',
			),
			'convert-pro'            => array(
				'name'        => 'Convert Pro',
				'class'       => 'SureContact\\Integrations\\Convert_Pro_Integration',
				'file'        => 'class-convert-pro-integration.php',
				'dependency'  => 'Cp_V2_Loader',
				'plugin_file' => 'convertpro/convertpro.php',
			),
			'jetformbuilder'         => array(
				'name'        => 'JetFormBuilder',
				'class'       => 'SureContact\\Integrations\\JetFormBuilder_Integration',
				'file'        => 'class-jetformbuilder-integration.php',
				'dependency'  => 'Jet_Form_Builder\\Plugin',
				'plugin_file' => 'jetformbuilder/jet-form-builder.php',
			),
			'ws-form'                => array(
				'name'        => 'WS Form',
				'class'       => 'SureContact\\Integrations\\WSForms_Integration',
				'file'        => 'class-wsforms-integration.php',
				'dependency'  => 'WS_Form_Form',
				'plugin_file' => 'ws-form/ws-form.php',
				'icon_url'    => SURECONTACT_PLUGIN_URL . 'assets/images/brands/icons/WSForm.svg',
			),
		);

		// Capture internal slugs before applying the filter so we can distinguish
		// third-party integrations registered via the filter from built-in ones.
		$this->internal_slugs = array_keys( $this->available_integrations );

		/**
		 * Filter the available integrations array.
		 *
		 * Allows third-party plugins to register their own integrations with SureContact.
		 * External integrations should use the `file_path` key (absolute path) instead of `file` (relative).
		 *
		 * Example usage:
		 *
		 *     add_filter( 'surecontact_available_integrations', function( $integrations ) {
		 *         $integrations['my_addon'] = array(
		 *             'name'        => 'My Addon',
		 *             'class'       => 'MyAddon\\SureContact_Integration',
		 *             'file_path'   => plugin_dir_path( __FILE__ ) . 'includes/class-surecontact-integration.php',
		 *             'dependency'  => 'MyAddon\\Plugin',
		 *             'plugin_file' => 'my-addon/my-addon.php',
		 *         );
		 *         return $integrations;
		 *     } );
		 *
		 * @since 1.2.0
		 *
		 * @param array $integrations {
		 *     Associative array of integration slug => config.
		 *
		 *     @type string $name        Display name of the integration.
		 *     @type string $class       Fully-qualified class name (must extend Base_Integration).
		 *     @type string $file        Relative path inside includes/integrations/ (internal integrations).
		 *     @type string $file_path   Absolute file path (external/third-party integrations).
		 *     @type string $dependency  Class or function name required for the integration to load.
		 *     @type string $plugin_file Plugin basename (e.g., 'my-addon/my-addon.php').
		 * }
		 */
		$this->available_integrations = apply_filters( 'surecontact_available_integrations', $this->available_integrations );

		// Restore persisted third-party integrations whose plugins are currently deactivated
		// so the admin UI can still display them and offer re-activation.
		$this->restore_third_party_integrations();
	}

	/**
	 * Restore persisted third-party integrations into available_integrations.
	 *
	 * Only restores entries for plugins that are currently deactivated (i.e., not
	 * already present in available_integrations from the filter). Persistence is
	 * handled lazily via persist_third_party_integration() when a rule is saved.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function restore_third_party_integrations() {
		$persisted = get_option( 'surecontact_third_party_integrations', array() );

		if ( ! is_array( $persisted ) || empty( $persisted ) ) {
			return;
		}

		// Update persisted entries for currently active third-party integrations
		// so registration data (name, class, file_path, etc.) stays fresh.
		$internal_slugs_map = array_flip( $this->internal_slugs );
		$changed            = false;

		foreach ( $this->available_integrations as $slug => $config ) {
			if ( isset( $internal_slugs_map[ $slug ] ) || ! isset( $persisted[ $slug ] ) ) {
				continue;
			}

			$registration_data = array(
				'name'                => $config['name'] ?? '',
				'class'               => $config['class'] ?? '',
				'file_path'           => $config['file_path'] ?? '',
				'dependency'          => $config['dependency'] ?? '',
				'plugin_file'         => $config['plugin_file'] ?? '',
				'icon_url'            => $config['icon_url'] ?? '',
				'plugin_dependencies' => $config['plugin_dependencies'] ?? array(),
			);

			$merged = array_merge( $persisted[ $slug ], $registration_data );

			if ( $persisted[ $slug ] !== $merged ) {
				$persisted[ $slug ] = $merged;
				$changed            = true;
			}
		}

		if ( $changed ) {
			update_option( 'surecontact_third_party_integrations', $persisted, false );
		}

		// Restore deactivated third-party integrations into available_integrations.
		foreach ( $persisted as $slug => $config ) {
			if ( ! isset( $this->available_integrations[ $slug ] ) ) {
				$this->available_integrations[ $slug ] = $config;
			}
		}
	}

	/**
	 * Persist class-level metadata for a third-party integration.
	 *
	 * Captures item_types, description, docs_url, and icon_url from the instantiated
	 * integration class and updates the persisted WP option. This data is used as a
	 * fallback when the third-party plugin is deactivated.
	 *
	 * @since 1.2.0
	 *
	 * @param string                                     $slug     Integration slug.
	 * @param \SureContact\Integrations\Base_Integration $instance Integration instance.
	 * @return void
	 */
	private function maybe_persist_integration_metadata( $slug, $instance ) {
		$internal_slugs_map = array_flip( $this->internal_slugs );

		// Only persist metadata for third-party integrations.
		if ( isset( $internal_slugs_map[ $slug ] ) ) {
			return;
		}

		$option_key = 'surecontact_third_party_integrations';
		$persisted  = get_option( $option_key, array() );

		if ( ! is_array( $persisted ) || ! isset( $persisted[ $slug ] ) ) {
			return;
		}

		$metadata = array(
			'item_types'  => $instance->get_item_types(),
			'description' => $instance->get_description(),
			'docs_url'    => $instance->get_docs_url(),
			'icon_url'    => $instance->get_icon_url(),
		);

		$needs_update = false;
		foreach ( $metadata as $key => $value ) {
			if ( ! isset( $persisted[ $slug ][ $key ] ) || $persisted[ $slug ][ $key ] !== $value ) {
				$persisted[ $slug ][ $key ] = $value;
				$needs_update               = true;
			}
		}

		if ( $needs_update ) {
			update_option( $option_key, $persisted, false );
		}
	}

	/**
	 * Load integrations based on availability and settings
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	private function load_integrations() {
		foreach ( $this->available_integrations as $slug => $integration ) {
			// Check if dependency is met.
			if ( ! $this->check_dependency( $integration['dependency'] ) ) {
				continue;
			}

			// Check if integration is enabled (skip disabled integrations).
			// WordPress integration is always loaded.
			// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- This is a slug identifier, not the brand name.
			if ( 'wordpress' !== $slug && ! isset( $this->enabled_slugs[ $slug ] ) ) {
				continue;
			}

			// Load integration file.
			// External integrations use 'file_path' (absolute), internal use 'file' (relative).
			if ( ! empty( $integration['file_path'] ) ) {
				$file_path = $integration['file_path'];
			} else {
				$file_path = SURECONTACT_PLUGIN_PATH . 'includes/integrations/' . $integration['file'];
			}

			if ( ! file_exists( $file_path ) ) {
				continue;
			}

			require_once $file_path;

			// Instantiate integration class.
			if ( class_exists( $integration['class'] ) ) {
				$instance = new $integration['class']();
				if ( $instance instanceof \SureContact\Integrations\Base_Integration ) {
					$this->loaded_integrations[ $slug ] = $instance;

					// Capture class-level metadata for third-party integrations
					// and persist it so the data is available when the plugin is deactivated.
					$this->maybe_persist_integration_metadata( $slug, $instance );

					/**
					 * Fires after a single integration has been loaded and instantiated.
					 *
					 * @since 1.2.0
					 *
					 * @param string                                      $slug     Integration slug.
					 * @param \SureContact\Integrations\Base_Integration $instance Integration instance.
					 */
					do_action( 'surecontact_integration_loaded', $slug, $instance );
				}
			}
		}

		/**
		 * Fires after all integrations have been loaded.
		 *
		 * @since 1.2.0
		 *
		 * @param array<string, \SureContact\Integrations\Base_Integration> $loaded_integrations All loaded integration instances keyed by slug.
		 */
		do_action( 'surecontact_integrations_loaded', $this->loaded_integrations );
	}

	/**
	 * Check if dependency is met
	 *
	 * @since 0.0.1
	 *
	 * @param string $dependency Class or function name.
	 * @return bool
	 */
	private function check_dependency( $dependency ) {
		// No dependency required.
		if ( empty( $dependency ) ) {
			return true;
		}

		// Check if class exists.
		if ( class_exists( $dependency ) ) {
			return true;
		}

		// Check if function exists.
		if ( function_exists( $dependency ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get all available integrations
	 *
	 * @since 0.0.1
	 *
	 * @return array
	 */
	public function get_available_integrations() {
		$integrations = array();

		foreach ( $this->available_integrations as $slug => $config ) {
			$integrations[ $slug ] = array(
				'name'                => $config['name'],
				'available'           => $this->check_dependency( $config['dependency'] ),
				'enabled'             => isset( $this->enabled_slugs[ $slug ] ),
				'plugin_file'         => $config['plugin_file'] ?? '',
				'plugin_dependencies' => $config['plugin_dependencies'] ?? array(),
				'icon_url'            => $config['icon_url'] ?? '',
				'item_types'          => $config['item_types'] ?? array(),
				'description'         => $config['description'] ?? '',
				'docs_url'            => $config['docs_url'] ?? '',
			);
		}

		return $integrations;
	}

	/**
	 * Get integration config by slug (filesystem only, no database query).
	 *
	 * This is optimized for performance - returns integration configuration
	 * directly from the registered integrations array without database queries.
	 *
	 * @since 0.0.3
	 *
	 * @param string $slug Integration slug.
	 * @return array|null Integration config or null if not found.
	 */
	public function get_integration_config( $slug ) {
		if ( ! isset( $this->available_integrations[ $slug ] ) ) {
			return null;
		}

		$config              = $this->available_integrations[ $slug ];
		$config['available'] = $this->check_dependency( $config['dependency'] );
		$config['slug']      = $slug;

		return $config;
	}

	/**
	 * Get the WordPress plugin file path for an integration.
	 *
	 * @since 1.2.0
	 *
	 * @param string $slug Integration slug.
	 * @return string|null Plugin file path (e.g., 'woocommerce/woocommerce.php') or null.
	 */
	public function get_plugin_file( $slug ) {
		if ( ! isset( $this->available_integrations[ $slug ] ) ) {
			return null;
		}

		$plugin_file = $this->available_integrations[ $slug ]['plugin_file'] ?? '';
		return ! empty( $plugin_file ) ? $plugin_file : null;
	}

	/**
	 * Check if a slug belongs to a third-party integration.
	 *
	 * @since 1.2.0
	 *
	 * @param string $slug Integration slug.
	 * @return bool True if third-party, false if internal.
	 */
	public function is_third_party( $slug ) {
		return ! in_array( $slug, $this->internal_slugs, true );
	}

	/**
	 * Persist a third-party integration's config to the WP option.
	 *
	 * Called when the first rule for a third-party integration is saved.
	 * Captures both the registration config and class-level metadata.
	 *
	 * @since 1.2.0
	 *
	 * @param string $slug Integration slug.
	 * @return void
	 */
	public function persist_third_party_integration( $slug ) {
		if ( ! $this->is_third_party( $slug ) ) {
			return;
		}

		if ( ! isset( $this->available_integrations[ $slug ] ) ) {
			return;
		}

		$config = $this->available_integrations[ $slug ];

		$data = array(
			'name'                => $config['name'] ?? '',
			'class'               => $config['class'] ?? '',
			'file_path'           => $config['file_path'] ?? '',
			'dependency'          => $config['dependency'] ?? '',
			'plugin_file'         => $config['plugin_file'] ?? '',
			'icon_url'            => $config['icon_url'] ?? '',
			'plugin_dependencies' => $config['plugin_dependencies'] ?? array(),
		);

		// Capture class-level metadata from the loaded instance.
		$instance = $this->loaded_integrations[ $slug ] ?? null;
		if ( $instance instanceof \SureContact\Integrations\Base_Integration ) {
			$data['item_types']  = $instance->get_item_types();
			$data['description'] = $instance->get_description();
			$data['docs_url']    = $instance->get_docs_url();
			$data['icon_url']    = $instance->get_icon_url();
		}

		$persisted = get_option( 'surecontact_third_party_integrations', array() );

		if ( ! is_array( $persisted ) ) {
			$persisted = array();
		}

		$persisted[ $slug ] = $data;
		update_option( 'surecontact_third_party_integrations', $persisted, false );
	}

	/**
	 * Remove a third-party integration from the persisted WP option.
	 *
	 * Called when the last rule for a third-party integration is deleted.
	 * If the option becomes empty, deletes it entirely.
	 *
	 * @since 1.2.0
	 *
	 * @param string $slug Integration slug.
	 * @return void
	 */
	public function remove_third_party_integration( $slug ) {
		$persisted = get_option( 'surecontact_third_party_integrations', array() );

		if ( ! is_array( $persisted ) || ! isset( $persisted[ $slug ] ) ) {
			return;
		}

		unset( $persisted[ $slug ] );

		if ( empty( $persisted ) ) {
			delete_option( 'surecontact_third_party_integrations' );
		} else {
			update_option( 'surecontact_third_party_integrations', $persisted, false );
		}
	}

	/**
	 * Get loaded integration instance
	 *
	 * @since 0.0.3
	 *
	 * @param string $slug Integration slug.
	 * @return \SureContact\Integrations\Base_Integration|null Integration instance or null
	 */
	public function get_integration( $slug ) {
		return $this->loaded_integrations[ $slug ] ?? null;
	}

	/**
	 * Get an integration instance, with fallback to temporary instantiation.
	 *
	 * First tries the loaded integrations (enabled + dependency met).
	 * If not loaded, temporarily instantiates the class for metadata access.
	 * The Base_Integration constructor gates init() behind is_enabled(),
	 * so event hooks won't fire for disabled integrations.
	 *
	 * Matches the pattern used by Integration_Rules_API::get_integration_instance().
	 *
	 * @since 1.3.0
	 *
	 * @param string $slug Integration slug.
	 * @return \SureContact\Integrations\Base_Integration|null Integration instance or null if not found or dependency not met.
	 */
	public function get_or_create_instance( $slug ) {
		// Try loaded integrations first.
		if ( isset( $this->loaded_integrations[ $slug ] ) ) {
			return $this->loaded_integrations[ $slug ];
		}

		// Return from temp cache if already instantiated.
		if ( isset( self::$temp_instances[ $slug ] ) ) {
			return self::$temp_instances[ $slug ];
		}

		// Check if integration exists and dependency is met.
		if ( ! isset( $this->available_integrations[ $slug ] ) ) {
			return null;
		}

		$config = $this->available_integrations[ $slug ];

		if ( ! $this->check_dependency( $config['dependency'] ) ) {
			return null;
		}

		// Load the file and instantiate.
		// External integrations use 'file_path' (absolute), internal use 'file' (relative).
		if ( ! empty( $config['file_path'] ) ) {
			$file_path = $config['file_path'];
		} else {
			$file_path = SURECONTACT_PLUGIN_PATH . 'includes/integrations/' . $config['file'];
		}

		if ( ! file_exists( $file_path ) ) {
			return null;
		}

		require_once $file_path;

		if ( ! class_exists( $config['class'] ) ) {
			return null;
		}

		$instance = new $config['class']();

		if ( ! $instance instanceof \SureContact\Integrations\Base_Integration ) {
			return null;
		}

		self::$temp_instances[ $slug ] = $instance;

		return $instance;
	}

	/**
	 * Get full integration metadata including schema and events.
	 *
	 * Works for any available integration (loaded or not).
	 * Returns null if integration not found or dependency not met.
	 *
	 * @since 1.3.0
	 *
	 * @param string $slug Integration slug.
	 * @return array|null Metadata array or null.
	 */
	public function get_integration_metadata( $slug ) {
		$instance = $this->get_or_create_instance( $slug );

		if ( ! $instance ) {
			return null;
		}

		$metadata = array(
			'name'                  => method_exists( $instance, 'get_name' ) ? $instance->get_name() : '',
			'description'           => method_exists( $instance, 'get_description' ) ? $instance->get_description() : '',
			'docs_url'              => method_exists( $instance, 'get_docs_url' ) ? $instance->get_docs_url() : '',
			'icon_url'              => method_exists( $instance, 'get_icon_url' ) ? $instance->get_icon_url() : '',
			'item_types'            => method_exists( $instance, 'get_item_types' ) ? $instance->get_item_types() : array(),
			'settings_fields'       => method_exists( $instance, 'get_settings_fields' ) ? $instance->get_settings_fields() : array(),
			'require_field_mapping' => method_exists( $instance, 'get_require_field_mapping' ) ? $instance->get_require_field_mapping() : false,
		);

		// Build events map for all item types.
		$events_map = array();
		if ( method_exists( $instance, 'get_events_by_item_type' ) ) {
			foreach ( $metadata['item_types'] as $item_type_data ) {
				$events_map[ $item_type_data['key'] ] = $instance->get_events_by_item_type( $item_type_data['key'] );
			}
		}
		$metadata['events_by_item_type'] = $events_map;

		return $metadata;
	}

	/**
	 * Fetch items from an integration by type.
	 *
	 * Dispatches to the integration's get_{type}s() method (e.g., get_forms(), get_products()).
	 * If no type is given, aggregates items from all item types.
	 *
	 * @since 1.3.0
	 *
	 * @param string      $slug Integration slug.
	 * @param string|null $type Item type key (e.g., 'form', 'product', 'coupon'). Null for all types.
	 * @return array|null Array of items, or null if integration not available.
	 */
	public function fetch_integration_items( $slug, $type = null ) {
		$instance = $this->get_or_create_instance( $slug );

		if ( ! $instance ) {
			return null;
		}

		if ( ! empty( $type ) ) {
			return $this->fetch_items_by_type( $instance, $type );
		}

		// No type given — enumerate all item types and aggregate.
		$items = array();
		if ( method_exists( $instance, 'get_item_types' ) ) {
			$item_types = $instance->get_item_types();
			foreach ( $item_types as $item_type_data ) {
				$type_items = $this->fetch_items_by_type( $instance, $item_type_data['key'] ?? '' );
				if ( ! empty( $type_items ) ) {
					$items = array_merge( $items, $type_items );
				}
			}
		}

		return $items;
	}

	/**
	 * Get fields for a specific integration item.
	 *
	 * @since 1.3.0
	 *
	 * @param string      $slug    Integration slug.
	 * @param string      $item_id Item ID.
	 * @param string|null $event   Optional event name for event-specific config fields.
	 * @return array|null Array with 'fields' and 'config_fields', or null if not available.
	 */
	public function get_item_fields( $slug, $item_id, $event = null ) {
		$instance = $this->get_or_create_instance( $slug );

		if ( ! $instance ) {
			return null;
		}

		$fields        = method_exists( $instance, 'get_item_fields' ) ? $instance->get_item_fields( $item_id ) : array();
		$config_fields = method_exists( $instance, 'get_item_config_fields' ) ? $instance->get_item_config_fields( $item_id, $event ) : array();

		return array(
			'fields'        => $fields,
			'config_fields' => $config_fields,
		);
	}

	/**
	 * Validate rule parameters against the integration's registered item types and events.
	 *
	 * Returns true if valid, or a WP_Error describing what's invalid.
	 * Skips validation gracefully when the integration plugin is not available
	 * (e.g., deactivated) — callers can still manage existing DB records.
	 *
	 * @since 1.3.0
	 *
	 * @param string      $slug      Integration slug.
	 * @param string|null $item_type Item type key (e.g., 'form', 'product').
	 * @param string|null $event     Event key (e.g., 'submission', 'purchase').
	 * @param string|null $item_id   Item identifier (e.g., '123', 'all', UUID).
	 * @return true|\WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_rule_params( $slug, $item_type = null, $event = null, $item_id = null ) {
		// If integration is not registered at all, reject with available slugs for guidance.
		if ( ! isset( $this->available_integrations[ $slug ] ) ) {
			$available_slugs = array_keys( $this->available_integrations );

			return new \WP_Error(
				'integration_not_found',
				sprintf(
					/* translators: %1$s: provided slug, %2$s: comma-separated valid slugs */
					__( 'Integration not found: "%1$s". Available integrations: %2$s', 'surecontact' ),
					$slug,
					implode( ', ', $available_slugs )
				),
				array( 'status' => 404 )
			);
		}

		// If integration plugin is not available, skip further validation.
		// This allows managing existing DB records for deactivated plugins.
		$instance = $this->get_or_create_instance( $slug );
		if ( ! $instance ) {
			return true;
		}

		// Validate item_type if provided.
		if ( ! empty( $item_type ) && method_exists( $instance, 'get_item_types' ) ) {
			$item_types      = $instance->get_item_types();
			$valid_type_keys = array_column( $item_types, 'key' );

			if ( ! in_array( $item_type, $valid_type_keys, true ) ) {
				return new \WP_Error(
					'invalid_item_type',
					sprintf(
						/* translators: %1$s: provided item type, %2$s: comma-separated valid types */
						__( 'Invalid item type "%1$s". Valid types: %2$s', 'surecontact' ),
						$item_type,
						implode( ', ', $valid_type_keys )
					),
					array( 'status' => 400 )
				);
			}
		}

		// Validate item_id using the integration's get_item_title() method.
		// This is type-aware (e.g., WooCommerce checks product vs coupon post types)
		// and integration-specific (e.g., SureCart validates UUIDs via its API).
		if ( ! empty( $item_id ) && 'all' !== $item_id && ! empty( $item_type ) && method_exists( $instance, 'get_item_title' ) ) {
			$title = $instance->get_item_title( $item_id, $item_type );

			if ( is_null( $title ) ) {
				return new \WP_Error(
					'invalid_item_id',
					sprintf(
						/* translators: %1$s: provided item ID, %2$s: item type */
						__( 'Item ID "%1$s" not found for item type "%2$s".', 'surecontact' ),
						$item_id,
						$item_type
					),
					array( 'status' => 400 )
				);
			}
		}

		// Validate event if provided (requires item_type for context).
		if ( ! empty( $event ) && ! empty( $item_type ) && method_exists( $instance, 'get_events_by_item_type' ) ) {
			$events           = $instance->get_events_by_item_type( $item_type );
			$valid_event_keys = array_column( $events, 'key' );

			// Only validate if the integration defines events for this item type.
			if ( ! empty( $valid_event_keys ) && ! in_array( $event, $valid_event_keys, true ) ) {
				return new \WP_Error(
					'invalid_event',
					sprintf(
						/* translators: %1$s: provided event, %2$s: item type, %3$s: comma-separated valid events */
						__( 'Invalid event "%1$s" for item type "%2$s". Valid events: %3$s', 'surecontact' ),
						$event,
						$item_type,
						implode( ', ', $valid_event_keys )
					),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/**
	 * Fetch items from an integration instance by type using the get_{type}s() naming convention.
	 *
	 * @since 1.3.0
	 *
	 * @param object $instance Integration instance.
	 * @param string $type     Item type key (e.g., 'form', 'product').
	 * @return array Array of items.
	 */
	private function fetch_items_by_type( $instance, $type ) {
		if ( empty( $type ) ) {
			return array();
		}

		$method_name = 'get_' . $type . 's';
		if ( method_exists( $instance, $method_name ) ) {
			$result = $instance->$method_name();
			return is_array( $result ) ? $result : array();
		}

		return array();
	}
}
