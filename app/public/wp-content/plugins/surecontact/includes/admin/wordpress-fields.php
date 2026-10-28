<?php
/**
 * Default WordPress Fields
 *
 * Defines standard WordPress user fields that are available for syncing
 *
 * @since 0.0.1
 *
 * @package SureContact
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Standard WordPress user fields
 *
 * Field structure:
 * - 'label': Display label for the field
 * - 'type': Field type for formatting/validation
 * - 'group': Field grouping for UI organization (default: 'wp')
 * - 'pseudo': Optional - if true, field is not a direct user meta field
 *
 * @since 0.0.1
 */
$surecontact_wp_fields = array();

// =============================================================================
// Standard WordPress Fields (group: 'wp')
// =============================================================================

$surecontact_wp_fields['first_name'] = array(
	'label' => __( 'First Name', 'surecontact' ),
	'type'  => 'text',
);

$surecontact_wp_fields['last_name'] = array(
	'label' => __( 'Last Name', 'surecontact' ),
	'type'  => 'text',
);

$surecontact_wp_fields['user_email'] = array(
	'label'  => __( 'Email Address', 'surecontact' ),
	'type'   => 'email',
	'pseudo' => true,
);

$surecontact_wp_fields['user_login'] = array(
	'label'  => __( 'Username', 'surecontact' ),
	'type'   => 'text',
	'pseudo' => true, // Not a meta field, from wp_users table.
);

$surecontact_wp_fields['display_name'] = array(
	'label'  => __( 'Display Name', 'surecontact' ),
	'type'   => 'text',
	'pseudo' => true,
);

$surecontact_wp_fields['user_url'] = array(
	'label'  => __( 'Website', 'surecontact' ),
	'type'   => 'url',
	'pseudo' => true,
);

$surecontact_wp_fields['user_registered'] = array(
	'label'  => __( 'User Registered', 'surecontact' ),
	'type'   => 'date',
	'pseudo' => true,
);

$surecontact_wp_fields['role'] = array(
	'label'  => __( 'Role', 'surecontact' ),
	'type'   => 'text',
	'pseudo' => true,
);

$surecontact_wp_fields['nickname'] = array(
	'label' => __( 'Nickname', 'surecontact' ),
	'type'  => 'text',
);

$surecontact_wp_fields['description'] = array(
	'label' => __( 'Biographical Info', 'surecontact' ),
	'type'  => 'textarea',
);

$surecontact_wp_fields['locale'] = array(
	'label' => __( 'Language/Locale', 'surecontact' ),
	'type'  => 'text',
);

// Apply default group to all fields that don't have one specified.
// This is O(n) - single pass through all fields.
foreach ( $surecontact_wp_fields as &$surecontact_field ) {
	if ( ! isset( $surecontact_field['group'] ) ) {
		$surecontact_field['group'] = 'wp';
	}
}
unset( $surecontact_field ); // Break reference.

/**
 * Filter available WordPress meta fields
 *
 * Allows integrations to add/modify available WordPress fields
 *
 * @since 0.0.1
 *
 * @param array $surecontact_wp_fields Available WordPress fields.
 */
return apply_filters( 'surecontact_meta_fields', $surecontact_wp_fields );
