<?php
/**
 * Uninstall cleanup. Options are always removed; user verification statuses
 * are removed only when the site owner opted in on the settings screen —
 * verification records are compliance-relevant and must not vanish because
 * of an accidental plugin delete.
 *
 * @package FaceVault
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$facevault_settings = (array) get_option( 'facevault_settings', array() );

if ( ! empty( $facevault_settings['delete_data_on_uninstall'] ) ) {
	$facevault_meta_keys = array(
		'_facevault_status',
		'_facevault_session_id',
		'_facevault_verified_session_id',
		'_facevault_verified_at',
		'_facevault_updated_at',
		'_facevault_history',
	);
	foreach ( $facevault_meta_keys as $facevault_meta_key ) {
		delete_metadata( 'user', 0, $facevault_meta_key, '', true );
	}
}

delete_option( 'facevault_settings' );
delete_option( 'facevault_webhook_log' );
delete_option( 'facevault_quota_notice' );
delete_option( 'facevault_db_version' );
delete_option( 'facevault_rewrite_flushed' );
