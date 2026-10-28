<?php
/**
 * Inject a "Delete all data on uninstall" checkbox into the existing BSF
 * deactivation feedback modal on the Plugins screen.
 *
 * We do not modify the shared `bsf-analytics` library (overwritten on
 * updates, used by other BSF plugins). Instead this class:
 *   1. Prints a tiny inline JS/CSS into the Plugins screen footer.
 *   2. The JS injects a checkbox into each SureRank survey form
 *      (`#deactivation-survey-surerank`, `#deactivation-survey-surerank-pro`)
 *      pre-set to the persisted toggle value.
 *   3. Intercepts the form's submit in the capture phase so we can AJAX-save
 *      the toggle BEFORE the library's bubble-phase submit handler kicks off
 *      the actual deactivation.
 *
 * The actual data deletion still happens inside `uninstall.php` when the user
 * later clicks "Delete" on the Plugins screen — this checkbox just persists
 * the gate to the same option as the Miscellaneous tab toggle.
 *
 * @package surerank
 * @since 1.9.0
 */

namespace SureRank\Inc\Admin;

use SureRank\Inc\Functions\Update;
use SureRank\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delete_Data_Checkbox class.
 *
 * @since 1.9.0
 */
class Delete_Data_Checkbox {

	use Get_Instance;

	/**
	 * Survey wrapper IDs registered by Analytics::__construct().
	 */
	private const TARGET_FORM_IDS = [
		'deactivation-survey-surerank',
		'deactivation-survey-surerank-pro',
	];

	/**
	 * AJAX action + nonce action name.
	 */
	private const AJAX_ACTION = 'surerank_set_delete_on_uninstall';

	/**
	 * Constructor.
	 *
	 * @since 1.9.0
	 */
	public function __construct() {
		add_action( 'admin_footer-plugins.php', [ $this, 'render_inline_script' ] );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'handle_save' ] );
		add_filter( 'surerank_role_manager_option_mappings', [ $this, 'whitelist_for_role_manager' ] );
	}

	/**
	 * Whitelist our toggle key for the Pro Role_Manager capability filter.
	 *
	 * Pro's `filter_settings_by_capability` strips any key not in this list
	 * from both the GET and POST data, which silently breaks our toggle when
	 * Pro is active. Adding our key here keeps it passing through.
	 *
	 * @param array<string, array<int, string>> $mappings Capability → option list.
	 * @since 1.9.0
	 * @return array<string, array<int, string>>
	 */
	public function whitelist_for_role_manager( $mappings ) {
		if ( ! isset( $mappings['surerank_global_setting'] ) ) {
			return $mappings;
		}

		if ( ! in_array( 'surerank_delete_on_uninstall', $mappings['surerank_global_setting'], true ) ) {
			$mappings['surerank_global_setting'][] = 'surerank_delete_on_uninstall';
		}

		return $mappings;
	}

	/**
	 * Print the inline CSS + JS that injects the checkbox.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public function render_inline_script(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$currently_enabled = get_option( SURERANK_DELETE_ON_UNINSTALL ) === 'yes';
		$nonce             = wp_create_nonce( self::AJAX_ACTION );
		$ajax_url          = admin_url( 'admin-ajax.php' );
		$target_ids_json   = wp_json_encode( self::TARGET_FORM_IDS );
		$strong_text       = __( 'Delete all SureRank data on uninstall', 'surerank' );
		$body_text         = __( 'Settings, schema, sitemap, post and term meta, custom tables, and scheduled tasks will be permanently removed when you delete the plugin. This cannot be undone.', 'surerank' );
		?>
		<style>
			.surerank-delete-data-fieldset {
				margin: 12px 0 8px;
				padding: 12px;
				background: #f6f7f7;
				border-left: 3px solid #d63638;
				border-radius: 4px;
			}
			.surerank-delete-data-row {
				display: flex;
				align-items: flex-start;
				gap: 8px;
				cursor: pointer;
				font-size: 13px;
				line-height: 1.5;
			}
			.surerank-delete-data-row input { margin-top: 3px; }
			.surerank-delete-data-row span { color: #50575e; }
			.surerank-delete-data-row strong { color: #1d2327; display: block; margin-bottom: 4px; }
		</style>
		<script type="text/javascript">
		jQuery( function( $ ) {
			var TARGET_FORM_IDS = <?php echo $target_ids_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe JSON encode of static slug list ?>;
			var AJAX_URL = <?php echo wp_json_encode( $ajax_url ); ?>;
			var AJAX_ACTION = <?php echo wp_json_encode( self::AJAX_ACTION ); ?>;
			var NONCE = <?php echo wp_json_encode( $nonce ); ?>;
			var currentState = <?php echo wp_json_encode( $currently_enabled ); ?>;

			var checkboxHtml =
				'<fieldset class="surerank-delete-data-fieldset">' +
					'<label class="surerank-delete-data-row">' +
						'<input type="checkbox" class="surerank-delete-data-checkbox"' + ( currentState ? ' checked' : '' ) + '/>' +
						'<span>' +
							'<strong><?php echo esc_js( $strong_text ); ?></strong>' +
							'<?php echo esc_js( $body_text ); ?>' +
						'</span>' +
					'</label>' +
				'</fieldset>';

			TARGET_FORM_IDS.forEach( function( id ) {
				var $wrapper = $( '#' + id );
				if ( ! $wrapper.length ) { return; }

				var $form = $wrapper.find( '.uds-feedback-form' );
				if ( ! $form.length || $form.find( '.surerank-delete-data-fieldset' ).length ) { return; }

				// Insert above the Submit & Skip buttons.
				$form.find( '.uds-feedback-form-sumbit--actions' ).before( checkboxHtml );

				var $checkbox = $form.find( '.surerank-delete-data-checkbox' );
				var buttons = $form.find( '.uds-feedback-submit, .uds-feedback-skip' ).toArray();

				// Capture-phase click intercepts run BEFORE the BSF library's
				// jQuery `.on('click', ...)` handlers on these buttons. We
				// can't hook the form's `submit` event because the library
				// calls `$form.submit()` via jQuery, which goes through the
				// jQuery event system and does not reliably dispatch a native
				// submit event — capture-phase native listeners on `submit`
				// would never fire.
				buttons.forEach( function( btn ) {
					function intercept( event ) {
						var nextState = $checkbox.is( ':checked' );

						// No change — let the library handle the click.
						if ( nextState === currentState ) {
							return;
						}

						event.preventDefault();
						event.stopImmediatePropagation();

						// Remove ourselves so the re-click flows straight
						// through to the library, then re-attach for any
						// future click in the same session.
						btn.removeEventListener( 'click', intercept, true );

						$.post( AJAX_URL, {
							action: AJAX_ACTION,
							nonce: NONCE,
							delete: nextState ? '1' : '0',
						} ).always( function() {
							currentState = nextState;
							btn.click();
							btn.addEventListener( 'click', intercept, true );
						} );
					}

					btn.addEventListener( 'click', intercept, true );
				} );
			} );
		} );
		</script>
		<?php
	}

	/**
	 * AJAX handler — persist the toggle to the standalone option.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				[ 'message' => __( 'Insufficient permissions.', 'surerank' ) ],
				403
			);
		}

		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$delete = isset( $_POST['delete'] ) ? sanitize_text_field( wp_unslash( $_POST['delete'] ) ) : '0';
		$value  = '1' === $delete ? 'yes' : 'no';

		// Use Update::option (autoload defaults to false) so this admin/uninstall-only
		// flag isn't autoloaded on every page when this AJAX path is the first writer.
		Update::option( SURERANK_DELETE_ON_UNINSTALL, $value );

		wp_send_json_success( [ 'value' => $value ] );
	}
}
