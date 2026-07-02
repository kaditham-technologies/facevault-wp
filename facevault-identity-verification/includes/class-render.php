<?php
/**
 * Shared renderer for the verify button / status badge. Used by the
 * shortcode, the Gutenberg block, and the WooCommerce account tab, so all
 * three surfaces stay behaviorally identical.
 *
 * @package FaceVault
 */

namespace FaceVault\WP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode + block registration and the state-aware button markup.
 */
class Render {

	/**
	 * Status reader/poller.
	 *
	 * @var User_Status
	 */
	private $user_status;

	/**
	 * Whether config was already attached to the script handle.
	 *
	 * @var bool
	 */
	private $localized = false;

	/**
	 * Constructor.
	 *
	 * @param User_Status $user_status Status reader.
	 */
	public function __construct( User_Status $user_status ) {
		$this->user_status = $user_status;
	}

	/**
	 * Hook everything.
	 */
	public function register() {
		add_shortcode( 'facevault_verify', array( $this, 'shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register (not enqueue) assets; they load only on pages that render
	 * the button. embed.js comes from the configured FaceVault origin and
	 * derives its widget origin from its own script src, so no extra
	 * attribute is needed even for self-hosted or custom-origin setups.
	 */
	public function register_assets() {
		wp_register_script(
			'facevault-embed',
			Settings::get( 'embed_origin' ) . '/embed.js',
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Remote service loader; a ver param would fight its cache headers.
			array(
				'in_footer' => true,
				'strategy'  => 'async',
			)
		);
		wp_register_script(
			'facevault-verify',
			FACEVAULT_PLUGIN_URL . 'assets/js/verify.js',
			array( 'facevault-embed' ),
			FACEVAULT_VERSION,
			true
		);
		wp_register_style(
			'facevault-verify',
			FACEVAULT_PLUGIN_URL . 'assets/css/verify.css',
			array(),
			FACEVAULT_VERSION
		);
	}

	/**
	 * Shortcode: [facevault_verify label="…" redirect="/thanks/"].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'label'    => __( 'Verify my identity', 'facevault-identity-verification' ),
				'redirect' => '',
			),
			$atts,
			'facevault_verify'
		);
		return $this->render_button( $atts );
	}

	/**
	 * Register the dynamic block; attributes map onto the same renderer.
	 */
	public function register_block() {
		register_block_type(
			FACEVAULT_PLUGIN_DIR . 'blocks/verify-button',
			array( 'render_callback' => array( $this, 'render_block' ) )
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_block( $attributes ) {
		return $this->render_button(
			array(
				'label'    => ! empty( $attributes['label'] ) ? (string) $attributes['label'] : __( 'Verify my identity', 'facevault-identity-verification' ),
				'redirect' => ! empty( $attributes['redirect'] ) ? (string) $attributes['redirect'] : '',
			)
		);
	}

	/**
	 * State-aware markup shared by every surface.
	 *
	 * @param array $attrs label + redirect.
	 * @return string
	 */
	public function render_button( $attrs ) {
		if ( ! is_user_logged_in() ) {
			return sprintf(
				'<div class="facevault-verify" data-status="logged-out"><a href="%s">%s</a></div>',
				esc_url( wp_login_url( $this->current_url() ) ),
				esc_html__( 'Log in to verify your identity', 'facevault-identity-verification' )
			);
		}

		$user_id = get_current_user_id();
		$this->user_status->maybe_poll( $user_id );
		$status = $this->user_status->get_status( $user_id );

		$this->enqueue_assets();

		$redirect = '';
		if ( ! empty( $attrs['redirect'] ) ) {
			$redirect = wp_validate_redirect( (string) $attrs['redirect'], '' );
		}

		$label   = isset( $attrs['label'] ) ? (string) $attrs['label'] : __( 'Verify my identity', 'facevault-identity-verification' );
		$inner   = '';
		$message = '';

		switch ( $status ) {
			case User_Status::STATUS_VERIFIED:
				$verified_at = $this->user_status->get_verified_at( $user_id );
				$inner       = sprintf(
					'<span class="facevault-verify__badge facevault-verify__badge--verified">%s</span>%s',
					esc_html__( 'Identity verified ✓', 'facevault-identity-verification' ),
					$verified_at > 0 ? sprintf(
						' <span class="facevault-verify__date">%s</span>',
						sprintf(
							/* translators: %s: date. */
							esc_html__( 'on %s', 'facevault-identity-verification' ),
							esc_html( wp_date( get_option( 'date_format', 'Y-m-d' ), $verified_at ) )
						)
					) : ''
				);
				break;

			case User_Status::STATUS_REVIEW:
				$inner = sprintf(
					'<span class="facevault-verify__badge facevault-verify__badge--review">%s</span>',
					esc_html__( 'Verification pending review', 'facevault-identity-verification' )
				);
				break;

			case User_Status::STATUS_PENDING:
				$inner   = $this->button_markup( __( 'Resume verification', 'facevault-identity-verification' ) );
				$message = esc_html__( 'Your last attempt is still in progress.', 'facevault-identity-verification' );
				break;

			case User_Status::STATUS_REJECTED:
				$inner   = $this->button_markup( $label );
				$message = esc_html__( 'Your previous attempt was unsuccessful. You can try again.', 'facevault-identity-verification' );
				break;

			default:
				$inner = $this->button_markup( $label );
				break;
		}

		$attribution = '';
		if ( Settings::get( 'attribution' ) ) {
			$attribution = sprintf(
				'<a class="facevault-verify__attribution" href="%s" target="_blank" rel="nofollow noopener">%s</a>',
				esc_url( 'https://facevault.id/?utm_source=wp-plugin&utm_medium=badge' ),
				esc_html__( 'Powered by FaceVault', 'facevault-identity-verification' )
			);
		}

		return sprintf(
			'<div class="facevault-verify" data-status="%1$s"%2$s>%3$s<span class="facevault-verify__message" aria-live="polite">%4$s</span>%5$s</div>',
			esc_attr( $status ),
			'' !== $redirect ? ' data-redirect="' . esc_url( $redirect ) . '"' : '',
			$inner,
			$message,
			$attribution
		);
	}

	/**
	 * The clickable button element.
	 *
	 * @param string $label Button label.
	 * @return string
	 */
	private function button_markup( $label ) {
		return sprintf(
			'<button type="button" class="facevault-verify__button">%s</button>',
			esc_html( $label )
		);
	}

	/**
	 * Enqueue + localize once per request.
	 */
	private function enqueue_assets() {
		wp_enqueue_style( 'facevault-verify' );
		wp_enqueue_script( 'facevault-embed' );
		wp_enqueue_script( 'facevault-verify' );

		if ( $this->localized ) {
			return;
		}
		$this->localized = true;

		wp_localize_script(
			'facevault-verify',
			'facevaultVerify',
			array(
				'restUrl' => esc_url_raw( rest_url( 'facevault/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'verified'     => __( 'Identity verified ✓', 'facevault-identity-verification' ),
					'confirming'   => __( 'Verified — confirming…', 'facevault-identity-verification' ),
					'review'       => __( 'Verification submitted — pending review.', 'facevault-identity-verification' ),
					'processing'   => __( 'Verification submitted — processing…', 'facevault-identity-verification' ),
					'rateLimited'  => __( 'Too many attempts — please wait a few minutes and try again.', 'facevault-identity-verification' ),
					'staleNonce'   => __( 'Your session expired — please reload the page and try again.', 'facevault-identity-verification' ),
					'unavailable'  => __( 'Identity verification is temporarily unavailable. Please try again later.', 'facevault-identity-verification' ),
					'widgetError'  => __( 'Something went wrong in the verification widget. Please try again.', 'facevault-identity-verification' ),
					'embedBlocked' => __( 'Could not load the verification widget — check your ad blocker and try again.', 'facevault-identity-verification' ),
				),
			)
		);
	}

	/**
	 * Current page URL for the login redirect.
	 *
	 * @return string
	 */
	private function current_url() {
		$permalink = get_permalink();
		return $permalink ? $permalink : home_url( '/' );
	}
}
