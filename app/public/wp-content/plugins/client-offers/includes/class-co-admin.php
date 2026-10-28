<?php
/**
 * Admin screens: campaigns, new offer, contacts and settings.
 */

defined( 'ABSPATH' ) || exit;

class CO_Admin {

	const CAP = 'manage_options';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_co_import', array( $this, 'handle_import' ) );
		add_action( 'admin_post_co_bulk_contacts', array( $this, 'handle_bulk_contacts' ) );
		add_action( 'admin_post_co_start', array( $this, 'handle_start' ) );
		add_action( 'admin_post_co_cancel', array( $this, 'handle_cancel' ) );
		add_action( 'admin_post_co_settings', array( $this, 'handle_settings' ) );
		add_action( 'wp_ajax_co_send_batch', array( $this, 'ajax_send_batch' ) );
		add_action( 'wp_ajax_co_test_send', array( $this, 'ajax_test_send' ) );
	}

	public function menu() {
		add_menu_page( __( 'Email Offers', 'client-offers' ), __( 'Email Offers', 'client-offers' ), self::CAP, 'co-campaigns', array( $this, 'page_campaigns' ), 'dashicons-email-alt', 26 );
		add_submenu_page( 'co-campaigns', __( 'Campaigns', 'client-offers' ), __( 'Campaigns', 'client-offers' ), self::CAP, 'co-campaigns', array( $this, 'page_campaigns' ) );
		add_submenu_page( 'co-campaigns', __( 'New Offer', 'client-offers' ), __( 'New Offer', 'client-offers' ), self::CAP, 'co-new', array( $this, 'page_new' ) );
		add_submenu_page( 'co-campaigns', __( 'Contacts', 'client-offers' ), __( 'Contacts', 'client-offers' ), self::CAP, 'co-contacts', array( $this, 'page_contacts' ) );
		add_submenu_page( 'co-campaigns', __( 'Settings', 'client-offers' ), __( 'Settings', 'client-offers' ), self::CAP, 'co-settings', array( $this, 'page_settings' ) );
	}

	public function assets( $hook ) {
		if ( false === strpos( $hook, 'co-' ) ) {
			return;
		}
		wp_enqueue_style( 'co-admin', CO_URL . 'assets/admin.css', array(), CO_VERSION );
		wp_enqueue_script( 'co-admin', CO_URL . 'assets/admin.js', array(), CO_VERSION, true );
		wp_localize_script(
			'co-admin',
			'coAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'co_ajax' ),
				'delay'   => (int) CO_Mailer::settings()['delay'],
			)
		);
	}

	/* ---------- helpers ---------- */

	private function check( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'client-offers' ) );
		}
		check_admin_referer( $action );
	}

	private function redirect( $page, array $args = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function notice() {
		if ( empty( $_GET['co_msg'] ) ) {
			return;
		}
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( wp_unslash( $_GET['co_msg'] ) ) );
	}

	private static function campaign_from_request() {
		$data = array();
		foreach ( array( 'en', 'uk' ) as $lang ) {
			$data[ "subject_$lang" ] = sanitize_text_field( wp_unslash( $_POST[ "subject_$lang" ] ?? '' ) );
			$data[ "body_$lang" ]    = wp_kses_post( wp_unslash( $_POST[ "body_$lang" ] ?? '' ) );
		}
		return $data;
	}

	/* ---------- Campaigns ---------- */

	public function page_campaigns() {
		$id = absint( $_GET['campaign'] ?? 0 );
		if ( $id && ( $campaign = CO_DB::get_campaign( $id ) ) ) {
			$this->page_campaign( $campaign );
			return;
		}
		$campaigns = CO_DB::get_campaigns();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Email Offers', 'client-offers' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=co-new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'New Offer', 'client-offers' ); ?></a>
			<hr class="wp-header-end">
			<?php $this->notice(); ?>
			<table class="widefat striped">
				<thead><tr><th>#</th><th><?php esc_html_e( 'Subject', 'client-offers' ); ?></th><th><?php esc_html_e( 'Created', 'client-offers' ); ?></th><th><?php esc_html_e( 'Sent / Failed / Total', 'client-offers' ); ?></th><th><?php esc_html_e( 'Status', 'client-offers' ); ?></th></tr></thead>
				<tbody>
				<?php if ( ! $campaigns ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No offers sent yet.', 'client-offers' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $campaigns as $c ) : ?>
					<tr>
						<td><?php echo (int) $c['id']; ?></td>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=co-campaigns&campaign=' . $c['id'] ) ); ?>"><strong><?php echo esc_html( $c['subject_en'] ? $c['subject_en'] : $c['subject_uk'] ); ?></strong></a></td>
						<td><?php echo esc_html( $c['created_at'] ); ?></td>
						<td><?php echo (int) $c['sent'] . ' / ' . (int) $c['failed'] . ' / ' . (int) $c['total']; ?></td>
						<td><span class="co-status co-status-<?php echo esc_attr( $c['status'] ); ?>"><?php echo esc_html( $c['status'] ); ?></span></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function page_campaign( array $c ) {
		$active   = 'sending' === $c['status'];
		$failures = CO_DB::failures( $c['id'] );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $c['subject_en'] ? $c['subject_en'] : $c['subject_uk'] ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=co-campaigns' ) ); ?>">&larr; <?php esc_html_e( 'All offers', 'client-offers' ); ?></a></p>
			<?php $this->notice(); ?>
			<div class="co-card" id="co-progress" data-campaign="<?php echo (int) $c['id']; ?>" data-autostart="<?php echo $active && ! empty( $_GET['autostart'] ) ? '1' : '0'; ?>">
				<div class="co-bar"><span style="width:<?php echo $c['total'] ? esc_attr( round( ( $c['sent'] + $c['failed'] ) / $c['total'] * 100 ) ) : 100; ?>%"></span></div>
				<p class="co-counts">
					<?php esc_html_e( 'Sent', 'client-offers' ); ?>: <b data-k="sent"><?php echo (int) $c['sent']; ?></b> &middot;
					<?php esc_html_e( 'Failed', 'client-offers' ); ?>: <b data-k="failed"><?php echo (int) $c['failed']; ?></b> &middot;
					<?php esc_html_e( 'Total', 'client-offers' ); ?>: <b data-k="total"><?php echo (int) $c['total']; ?></b> &middot;
					<?php esc_html_e( 'Status', 'client-offers' ); ?>: <b data-k="status"><?php echo esc_html( $c['status'] ); ?></b>
				</p>
				<p class="co-log" aria-live="polite"></p>
				<?php if ( $active ) : ?>
					<button type="button" class="button button-primary" id="co-toggle" data-start="<?php esc_attr_e( 'Start / resume sending', 'client-offers' ); ?>" data-pause="<?php esc_attr_e( 'Pause', 'client-offers' ); ?>"><?php esc_html_e( 'Start / resume sending', 'client-offers' ); ?></button>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="co-inline" onsubmit="return confirm('<?php echo esc_js( __( 'Cancel this offer? Contacts that have not been emailed yet will be skipped.', 'client-offers' ) ); ?>');">
						<?php wp_nonce_field( 'co_cancel' ); ?>
						<input type="hidden" name="action" value="co_cancel"><input type="hidden" name="campaign" value="<?php echo (int) $c['id']; ?>">
						<button class="button button-link-delete"><?php esc_html_e( 'Cancel offer', 'client-offers' ); ?></button>
					</form>
					<p class="description"><?php esc_html_e( 'Keep this page open while sending. You can pause and resume at any time.', 'client-offers' ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( $failures ) : ?>
				<h2><?php esc_html_e( 'Failed emails', 'client-offers' ); ?></h2>
				<table class="widefat striped"><tbody>
				<?php foreach ( $failures as $f ) : ?>
					<tr><td><?php echo esc_html( $f['email'] ); ?></td><td><?php echo esc_html( $f['error'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Content', 'client-offers' ); ?></h2>
			<?php foreach ( array( 'en' => 'English', 'uk' => 'Українська' ) as $lang => $label ) : ?>
				<?php if ( '' === $c[ "subject_$lang" ] ) { continue; } ?>
				<div class="co-card">
					<p><strong><?php echo esc_html( $label ); ?>:</strong> <?php echo esc_html( $c[ "subject_$lang" ] ); ?></p>
					<div class="co-preview"><?php echo wp_kses_post( wpautop( $c[ "body_$lang" ] ) ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	public function handle_start() {
		$this->check( 'co_start' );
		$data = self::campaign_from_request();
		if ( '' === $data['subject_en'] || '' === trim( wp_strip_all_tags( $data['body_en'] ) ) ) {
			set_transient( 'co_draft_' . get_current_user_id(), $data, HOUR_IN_SECONDS );
			$this->redirect( 'co-new', array( 'co_msg' => __( 'The English subject and message are required (they are also used for contacts without a Ukrainian version).', 'client-offers' ) ) );
		}
		$id = CO_DB::create_campaign( $data, ! empty( $_POST['only_new'] ) );
		delete_transient( 'co_draft_' . get_current_user_id() );
		$this->redirect( 'co-campaigns', array( 'campaign' => $id, 'autostart' => 1 ) );
	}

	public function handle_cancel() {
		$this->check( 'co_cancel' );
		$id = absint( $_POST['campaign'] ?? 0 );
		CO_DB::set_campaign_status( $id, 'cancelled' );
		$this->redirect( 'co-campaigns', array( 'campaign' => $id, 'co_msg' => __( 'Offer cancelled.', 'client-offers' ) ) );
	}

	public function ajax_send_batch() {
		check_ajax_referer( 'co_ajax' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		$id       = absint( $_POST['campaign'] ?? 0 );
		$campaign = CO_DB::get_campaign( $id );
		if ( ! $campaign ) {
			wp_send_json_error( 'not found', 404 );
		}

		$errors = array();
		if ( 'sending' === $campaign['status'] ) {
			CO_DB::skip_unsubscribed( $id );
			foreach ( CO_DB::claim_batch( $id, (int) CO_Mailer::settings()['batch_size'] ) as $contact ) {
				$result = CO_Mailer::send( $campaign, $contact );
				$error  = is_wp_error( $result ) ? $result->get_error_message() : '';
				CO_DB::finish_send( $contact['send_id'], $id, $contact['id'], $error );
				if ( $error ) {
					$errors[] = $contact['email'] . ': ' . $error;
				}
			}
			if ( 0 === CO_DB::count_sends( $id, 'queued' ) ) {
				CO_DB::set_campaign_status( $id, 'done' );
			}
		}

		$campaign = CO_DB::get_campaign( $id );
		wp_send_json_success(
			array(
				'sent'   => (int) $campaign['sent'],
				'failed' => (int) $campaign['failed'],
				'total'  => (int) $campaign['total'],
				'status' => $campaign['status'],
				'errors' => $errors,
			)
		);
	}

	public function ajax_test_send() {
		check_ajax_referer( 'co_ajax' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		$data = self::campaign_from_request();
		$user = wp_get_current_user();
		$sent = array();
		foreach ( array( 'en', 'uk' ) as $lang ) {
			if ( '' === $data[ "subject_$lang" ] ) {
				continue;
			}
			$sample = array( 'email' => $user->user_email, 'name' => $user->display_name, 'company' => 'Example Ltd', 'lang' => $lang, 'token' => str_repeat( '0', 32 ) );
			$result = CO_Mailer::send( $data, $sample );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( $result->get_error_message() );
			}
			$sent[] = strtoupper( $lang );
		}
		if ( ! $sent ) {
			wp_send_json_error( __( 'Fill in at least one subject.', 'client-offers' ) );
		}
		/* translators: 1: languages, 2: email address */
		wp_send_json_success( sprintf( __( 'Test (%1$s) sent to %2$s.', 'client-offers' ), implode( ' + ', $sent ), $user->user_email ) );
	}

	/* ---------- New offer ---------- */

	public function page_new() {
		$draft  = get_transient( 'co_draft_' . get_current_user_id() );
		$draft  = is_array( $draft ) ? $draft : array_fill_keys( array( 'subject_en', 'body_en', 'subject_uk', 'body_uk' ), '' );
		$all    = CO_DB::count_contacts( '', 'subscribed' );
		$uk     = CO_DB::count_contacts( '', 'subscribed', 'uk' );
		?>
		<div class="wrap co-new">
			<h1><?php esc_html_e( 'New Offer', 'client-offers' ); ?></h1>
			<?php $this->notice(); ?>
			<?php if ( ! $all ) : ?>
				<div class="notice notice-warning"><p><?php printf( wp_kses_post( __( 'You have no subscribed contacts yet. <a href="%s">Add contacts</a> first.', 'client-offers' ) ), esc_url( admin_url( 'admin.php?page=co-contacts' ) ) ); ?></p></div>
			<?php endif; ?>
			<p class="description">
				<?php esc_html_e( 'Placeholders:', 'client-offers' ); ?> <code>{name}</code> <code>{company}</code> <code>{email}</code> <code>{unsubscribe_url}</code>.
				<?php esc_html_e( 'An unsubscribe link is added to the bottom of every email automatically. Contacts marked as Ukrainian get the Ukrainian version; if it is empty they get English.', 'client-offers' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="co-offer-form" data-count="<?php echo (int) $all; ?>">
				<?php wp_nonce_field( 'co_start' ); ?>
				<input type="hidden" name="action" value="co_start">
				<?php foreach ( array( 'en' => 'English', 'uk' => 'Українська' ) as $lang => $label ) : ?>
					<div class="co-card">
						<h2><?php echo esc_html( $label ); ?><?php echo 'uk' === $lang ? ' <span class="description">(' . esc_html__( 'optional', 'client-offers' ) . ')</span>' : ''; ?></h2>
						<p><label><?php esc_html_e( 'Subject', 'client-offers' ); ?><br>
							<input type="text" name="subject_<?php echo esc_attr( $lang ); ?>" class="large-text" value="<?php echo esc_attr( $draft[ "subject_$lang" ] ); ?>"<?php echo 'en' === $lang ? ' required' : ''; ?>></label></p>
						<?php wp_editor( $draft[ "body_$lang" ], "co_body_$lang", array( 'textarea_name' => "body_$lang", 'textarea_rows' => 12, 'media_buttons' => false ) ); ?>
					</div>
				<?php endforeach; ?>
				<p><label><input type="checkbox" name="only_new" value="1"> <?php esc_html_e( 'Only contacts who have never been emailed before', 'client-offers' ); ?></label></p>
				<p>
					<?php
					/* translators: 1: subscribed contacts, 2: of them Ukrainian */
					printf( esc_html__( 'Recipients: %1$d subscribed contacts (%2$d Ukrainian).', 'client-offers' ), (int) $all, (int) $uk );
					?>
				</p>
				<p class="submit">
					<button type="button" class="button" id="co-test"><?php esc_html_e( 'Send test to me', 'client-offers' ); ?></button>
					<button type="submit" class="button button-primary"<?php disabled( ! $all ); ?>><?php esc_html_e( 'Start sending', 'client-offers' ); ?></button>
					<span class="co-test-result" aria-live="polite"></span>
				</p>
			</form>
		</div>
		<?php
	}

	/* ---------- Contacts ---------- */

	public function page_contacts() {
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$status = sanitize_key( $_GET['status'] ?? '' );
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$rows   = CO_DB::get_contacts( $search, $status, $paged );
		$total  = CO_DB::count_contacts( $search, $status );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Contacts', 'client-offers' ); ?></h1>
			<?php $this->notice(); ?>

			<details class="co-card" <?php echo $total ? '' : 'open'; ?>>
				<summary><strong><?php esc_html_e( 'Import contacts', 'client-offers' ); ?></strong></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'co_import' ); ?>
					<input type="hidden" name="action" value="co_import">
					<p class="description"><?php esc_html_e( 'One contact per line: email, name, company, language (en or uk). Only the email is required. Commas, semicolons or tabs all work, so you can paste straight from a spreadsheet.', 'client-offers' ); ?></p>
					<textarea name="contacts" rows="8" class="large-text code" placeholder="john@example.com, John Smith, Example Ltd, en&#10;olena@firma.ua; Олена; Фірма; uk"></textarea>
					<p><label><?php esc_html_e( 'Or upload a CSV file:', 'client-offers' ); ?> <input type="file" name="csv" accept=".csv,text/csv,text/plain"></label></p>
					<p><button class="button button-primary"><?php esc_html_e( 'Import', 'client-offers' ); ?></button></p>
				</form>
			</details>

			<form method="get" class="co-filter">
				<input type="hidden" name="page" value="co-contacts">
				<select name="status">
					<option value=""><?php esc_html_e( 'All statuses', 'client-offers' ); ?></option>
					<option value="subscribed" <?php selected( $status, 'subscribed' ); ?>><?php esc_html_e( 'Subscribed', 'client-offers' ); ?></option>
					<option value="unsubscribed" <?php selected( $status, 'unsubscribed' ); ?>><?php esc_html_e( 'Unsubscribed', 'client-offers' ); ?></option>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search email, name, company', 'client-offers' ); ?>">
				<button class="button"><?php esc_html_e( 'Filter', 'client-offers' ); ?></button>
				<span class="co-total"><?php echo esc_html( sprintf( _n( '%d contact', '%d contacts', $total, 'client-offers' ), $total ) ); ?></span>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'co_bulk_contacts' ); ?>
				<input type="hidden" name="action" value="co_bulk_contacts">
				<table class="widefat striped">
					<thead><tr>
						<td class="check-column"><input type="checkbox" id="co-check-all"></td>
						<th><?php esc_html_e( 'Email', 'client-offers' ); ?></th><th><?php esc_html_e( 'Name', 'client-offers' ); ?></th><th><?php esc_html_e( 'Company', 'client-offers' ); ?></th>
						<th><?php esc_html_e( 'Language', 'client-offers' ); ?></th><th><?php esc_html_e( 'Status', 'client-offers' ); ?></th><th><?php esc_html_e( 'Last emailed', 'client-offers' ); ?></th>
					</tr></thead>
					<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No contacts found.', 'client-offers' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<th class="check-column"><input type="checkbox" name="ids[]" value="<?php echo (int) $r['id']; ?>"></th>
							<td><?php echo esc_html( $r['email'] ); ?></td><td><?php echo esc_html( $r['name'] ); ?></td><td><?php echo esc_html( $r['company'] ); ?></td>
							<td><?php echo esc_html( strtoupper( $r['lang'] ) ); ?></td>
							<td><span class="co-status co-status-<?php echo esc_attr( $r['status'] ); ?>"><?php echo esc_html( $r['status'] ); ?></span></td>
							<td><?php echo esc_html( $r['last_sent_at'] ? $r['last_sent_at'] : '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<select name="bulk">
						<option value="unsubscribe"><?php esc_html_e( 'Mark as unsubscribed', 'client-offers' ); ?></option>
						<option value="subscribe"><?php esc_html_e( 'Mark as subscribed', 'client-offers' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete', 'client-offers' ); ?></option>
					</select>
					<button class="button"><?php esc_html_e( 'Apply to selected', 'client-offers' ); ?></button>
				</p>
			</form>
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'current' => $paged,
						'total'   => max( 1, (int) ceil( $total / 50 ) ),
					)
				) ?? ''
			);
			?>
		</div>
		<?php
	}

	public function handle_import() {
		$this->check( 'co_import' );
		$text = (string) wp_unslash( $_POST['contacts'] ?? '' );
		if ( ! empty( $_FILES['csv']['tmp_name'] ) && is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) {
			$text .= "\n" . file_get_contents( $_FILES['csv']['tmp_name'] );
		}
		$text = preg_replace( '/^\xEF\xBB\xBF/', '', $text ); // Excel BOM.

		$rows = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$delimiter = false !== strpos( $line, "\t" ) ? "\t" : ( substr_count( $line, ';' ) > substr_count( $line, ',' ) ? ';' : ',' );
			$cells     = array_map( 'trim', str_getcsv( $line, $delimiter ) );
			if ( ! is_email( $cells[0] ) && preg_match( '/e-?mail/i', $cells[0] ) ) {
				continue; // Header row.
			}
			$rows[] = $cells;
		}
		$r = CO_DB::import( $rows );
		/* translators: 1: added, 2: updated, 3: invalid */
		$this->redirect( 'co-contacts', array( 'co_msg' => sprintf( __( 'Import finished: %1$d added, %2$d updated, %3$d invalid lines skipped.', 'client-offers' ), $r['added'], $r['updated'], $r['invalid'] ) ) );
	}

	public function handle_bulk_contacts() {
		$this->check( 'co_bulk_contacts' );
		$action = sanitize_key( $_POST['bulk'] ?? '' );
		$count  = CO_DB::bulk_contacts( $action, (array) ( $_POST['ids'] ?? array() ) );
		/* translators: %d: number of contacts */
		$this->redirect( 'co-contacts', array( 'co_msg' => sprintf( __( '%d contacts updated.', 'client-offers' ), $count ) ) );
	}

	/* ---------- Settings ---------- */

	public function page_settings() {
		$s = CO_Mailer::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Email Offers Settings', 'client-offers' ); ?></h1>
			<?php $this->notice(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'co_settings' ); ?>
				<input type="hidden" name="action" value="co_settings">
				<table class="form-table">
					<tr><th><label for="co-from-name"><?php esc_html_e( 'From name', 'client-offers' ); ?></label></th>
						<td><input id="co-from-name" name="from_name" class="regular-text" value="<?php echo esc_attr( $s['from_name'] ); ?>"></td></tr>
					<tr><th><label for="co-from-email"><?php esc_html_e( 'From email', 'client-offers' ); ?></label></th>
						<td><input id="co-from-email" type="email" name="from_email" class="regular-text" value="<?php echo esc_attr( $s['from_email'] ); ?>">
						<p class="description"><?php esc_html_e( 'Use an address on a domain your SMTP service (e.g. SureMails) is allowed to send from, or emails will land in spam.', 'client-offers' ); ?></p></td></tr>
					<tr><th><label for="co-batch"><?php esc_html_e( 'Emails per batch', 'client-offers' ); ?></label></th>
						<td><input id="co-batch" type="number" min="1" max="50" name="batch_size" value="<?php echo (int) $s['batch_size']; ?>"></td></tr>
					<tr><th><label for="co-delay"><?php esc_html_e( 'Pause between batches (seconds)', 'client-offers' ); ?></label></th>
						<td><input id="co-delay" type="number" min="0" max="300" name="delay" value="<?php echo (int) $s['delay']; ?>">
						<p class="description"><?php esc_html_e( 'Sending slowly protects your domain reputation and keeps you under your provider\'s rate limits.', 'client-offers' ); ?></p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function handle_settings() {
		$this->check( 'co_settings' );
		$email = sanitize_email( wp_unslash( $_POST['from_email'] ?? '' ) );
		update_option(
			'co_settings',
			array(
				'from_name'  => sanitize_text_field( wp_unslash( $_POST['from_name'] ?? '' ) ),
				'from_email' => is_email( $email ) ? $email : get_option( 'admin_email' ),
				'batch_size' => min( 50, max( 1, absint( $_POST['batch_size'] ?? 10 ) ) ),
				'delay'      => min( 300, absint( $_POST['delay'] ?? 5 ) ),
			)
		);
		$this->redirect( 'co-settings', array( 'co_msg' => __( 'Settings saved.', 'client-offers' ) ) );
	}
}
