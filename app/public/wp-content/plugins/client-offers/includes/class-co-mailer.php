<?php
/**
 * Builds personalised emails, sends them, and handles unsubscribe links.
 */

defined( 'ABSPATH' ) || exit;

class CO_Mailer {

	public static function settings() {
		return wp_parse_args(
			get_option( 'co_settings', array() ),
			array(
				'from_name'  => get_bloginfo( 'name' ),
				'from_email' => get_option( 'admin_email' ),
				'batch_size' => 10,
				'delay'      => 5,
			)
		);
	}

	public static function unsubscribe_url( $token ) {
		return add_query_arg( 'co_unsubscribe', $token, home_url( '/' ) );
	}

	/**
	 * Picks the contact's language version (falls back to English) and fills in placeholders.
	 *
	 * @return array{0:string,1:string} Subject and HTML body.
	 */
	public static function render( array $campaign, array $contact ) {
		$lang    = ( 'uk' === $contact['lang'] && '' !== trim( $campaign['subject_uk'] ) ) ? 'uk' : 'en';
		$unsub   = self::unsubscribe_url( $contact['token'] );
		$values  = array(
			'{name}'    => $contact['name'],
			'{company}' => $contact['company'],
			'{email}'   => $contact['email'],
		);
		$subject = strtr( $campaign[ "subject_$lang" ], $values );
		$body    = strtr( $campaign[ "body_$lang" ], array_map( 'esc_html', $values ) + array( '{unsubscribe_url}' => esc_url( $unsub ) ) );

		$footer = 'uk' === $lang
			? 'Не хочете більше отримувати такі листи? <a href="%s">Відписатися</a>.'
			: 'Don\'t want to receive these emails? <a href="%s">Unsubscribe</a>.';

		$html = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#222;max-width:640px">'
			. wpautop( $body )
			. '<hr style="border:0;border-top:1px solid #ddd;margin:24px 0 12px">'
			. '<p style="font-size:12px;color:#888">' . sprintf( $footer, esc_url( $unsub ) ) . '</p></div>';

		return array( $subject, $html );
	}

	/**
	 * @return true|WP_Error
	 */
	public static function send( array $campaign, array $contact, $to = null ) {
		$s = self::settings();
		list( $subject, $html ) = self::render( $campaign, $contact );

		// No List-Unsubscribe header: PHPMailer RFC 2047-encodes long header values, which breaks it.
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $s['from_name'], $s['from_email'] ),
		);

		$error = null;
		$catch = function ( $wp_error ) use ( &$error ) {
			$error = $wp_error;
		};
		add_action( 'wp_mail_failed', $catch );
		$ok = wp_mail( $to ? $to : $contact['email'], $subject, $html, $headers );
		remove_action( 'wp_mail_failed', $catch );

		if ( $ok ) {
			return true;
		}
		return $error instanceof WP_Error ? $error : new WP_Error( 'co_mail', 'wp_mail() returned false.' );
	}

	public static function handle_unsubscribe() {
		if ( empty( $_GET['co_unsubscribe'] ) ) {
			return;
		}
		$token = preg_replace( '/[^A-Za-z0-9]/', '', wp_unslash( $_GET['co_unsubscribe'] ) );
		if ( 32 === strlen( $token ) ) {
			CO_DB::unsubscribe_token( $token );
		}

		wp_die(
			'<h1>You have been unsubscribed</h1><p>You will not receive any more offers from me.</p>'
			. '<h1 style="margin-top:2em">Ви відписалися</h1><p>Ви більше не отримуватимете від мене пропозицій.</p>'
			. '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( get_bloginfo( 'name' ) ) . '</a></p>',
			esc_html__( 'Unsubscribed', 'client-offers' ),
			array( 'response' => 200 )
		);
	}
}
