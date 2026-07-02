<?php
/**
 * Admin notices: quota exhaustion (the upgrade prompt), the verify-button
 * rate ceiling, and the not-yet-configured nudge.
 *
 * @package FaceVault
 */

namespace FaceVault\WP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens to API-client actions and renders admin_notices.
 */
class Admin_Notices {

	/**
	 * Hook everything.
	 */
	public function register() {
		add_action( 'facevault_api_quota_exceeded', array( $this, 'store_quota_notice' ) );
		add_action( 'facevault_api_ok', array( $this, 'clear_quota_notice' ) );
		add_action( 'admin_init', array( $this, 'maybe_dismiss' ) );
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Remember the latest quota failure (detail string comes from the API).
	 *
	 * @param string $detail API error detail.
	 */
	public function store_quota_notice( $detail ) {
		update_option(
			'facevault_quota_notice',
			array(
				'detail' => (string) $detail,
				'time'   => time(),
			),
			false
		);
	}

	/**
	 * A successful mint means the quota problem is gone.
	 */
	public function clear_quota_notice() {
		delete_option( 'facevault_quota_notice' );
	}

	/**
	 * Handle notice dismissals.
	 */
	public function maybe_dismiss() {
		if ( ! isset( $_GET['facevault_dismiss'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'facevault_dismiss' ) ) {
			return;
		}
		$which = sanitize_key( wp_unslash( $_GET['facevault_dismiss'] ) );
		if ( 'quota' === $which ) {
			delete_option( 'facevault_quota_notice' );
		} elseif ( 'ratecap' === $which ) {
			delete_option( 'facevault_ratecap_notice' );
		}
	}

	/**
	 * Render notices for admins.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$quota = get_option( 'facevault_quota_notice' );
		if ( is_array( $quota ) && ! empty( $quota['detail'] ) ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s" target="_blank" rel="noopener">%4$s</a> <a href="%5$s">%6$s</a></p></div>',
				esc_html__( 'FaceVault:', 'facevault-identity-verification' ),
				esc_html( $quota['detail'] ),
				esc_url( 'https://devdash.facevault.id/?utm_source=wp-plugin&utm_medium=quota-notice' ),
				esc_html__( 'Upgrade plan →', 'facevault-identity-verification' ),
				esc_url( wp_nonce_url( add_query_arg( 'facevault_dismiss', 'quota' ), 'facevault_dismiss' ) ),
				esc_html__( 'Dismiss', 'facevault-identity-verification' )
			);
		}

		$ratecap = (int) get_option( 'facevault_ratecap_notice' );
		if ( $ratecap > 0 && ( time() - $ratecap ) < DAY_IN_SECONDS ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
				esc_html__( 'FaceVault:', 'facevault-identity-verification' ),
				esc_html__( 'The site-wide verification rate ceiling was hit in the last 24 hours. If this was real customer traffic, contact FaceVault about higher limits.', 'facevault-identity-verification' ),
				esc_url( wp_nonce_url( add_query_arg( 'facevault_dismiss', 'ratecap' ), 'facevault_dismiss' ) ),
				esc_html__( 'Dismiss', 'facevault-identity-verification' )
			);
		}

		if ( ! Settings::is_configured() ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && 'settings_page_facevault' === $screen->id ) {
				return;
			}
			printf(
				'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'FaceVault Identity Verification is almost ready.', 'facevault-identity-verification' ),
				esc_url( admin_url( 'options-general.php?page=facevault' ) ),
				esc_html__( 'Finish setup →', 'facevault-identity-verification' )
			);
		}
	}
}
