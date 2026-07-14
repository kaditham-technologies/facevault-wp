<?php
/**
 * Gating rules: which carts require identity verification, and whether the
 * current shopper satisfies them. Flags live on products (post meta) and
 * product categories (term meta, inherited by child categories); a global
 * settings toggle gates every purchase.
 *
 * Resolution reads meta only — never the FaceVault API — so gating keeps
 * working (and never blocks a verified shopper) during API outages.
 *
 * @package FaceVault
 */

namespace FaceVault\WP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flag storage/UI and cart/user gate resolution.
 */
class Gating_Rules {

	const META_KEY = '_facevault_requires_verification';

	/**
	 * Verdicts from user_may_checkout().
	 */
	const VERDICT_PASS    = 'pass';
	const VERDICT_REVIEW  = 'review';
	const VERDICT_BLOCKED = 'blocked';
	const VERDICT_GUEST   = 'guest';

	/**
	 * Status reader.
	 *
	 * @var User_Status
	 */
	private $user_status;

	/**
	 * Constructor.
	 *
	 * @param User_Status $user_status Status reader.
	 */
	public function __construct( User_Status $user_status ) {
		$this->user_status = $user_status;
	}

	/**
	 * Hook the product/category flag UI.
	 */
	public function register() {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_field' ) );

		add_action( 'product_cat_add_form_fields', array( $this, 'render_category_add_field' ) );
		add_action( 'product_cat_edit_form_fields', array( $this, 'render_category_edit_field' ) );
		add_action( 'created_product_cat', array( $this, 'save_category_field' ) );
		add_action( 'edited_product_cat', array( $this, 'save_category_field' ) );
	}

	/**
	 * Product checkbox in the General panel.
	 */
	public function render_product_field() {
		woocommerce_wp_checkbox(
			array(
				'id'          => 'facevault_requires_verification',
				'value'       => get_post_meta( get_the_ID(), self::META_KEY, true ),
				'label'       => __( 'Requires identity verification', 'facevault-identity-verification' ),
				'description' => __( 'Customers must verify their identity with FaceVault before checking out with this product.', 'facevault-identity-verification' ),
			)
		);
		wp_nonce_field( 'facevault_product_gate', 'facevault_product_gate_nonce' );
	}

	/**
	 * Persist the product flag (CRUD object — HPOS-safe conventions).
	 *
	 * @param \WC_Product $product Product being saved.
	 */
	public function save_product_field( $product ) {
		if ( ! isset( $_POST['facevault_product_gate_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['facevault_product_gate_nonce'] ) ), 'facevault_product_gate' ) ) {
			return;
		}
		if ( isset( $_POST['facevault_requires_verification'] ) ) {
			$product->update_meta_data( self::META_KEY, 'yes' );
		} else {
			$product->delete_meta_data( self::META_KEY );
		}
	}

	/**
	 * Category flag on the add-term form.
	 */
	public function render_category_add_field() {
		?>
		<div class="form-field">
			<label>
				<input type="checkbox" name="facevault_requires_verification" value="yes" />
				<?php esc_html_e( 'Requires identity verification', 'facevault-identity-verification' ); ?>
			</label>
			<p><?php esc_html_e( 'Applies to all products in this category, including child categories.', 'facevault-identity-verification' ); ?></p>
			<?php wp_nonce_field( 'facevault_category_gate', 'facevault_category_gate_nonce' ); ?>
		</div>
		<?php
	}

	/**
	 * Category flag on the edit-term form.
	 *
	 * @param \WP_Term $term Term being edited.
	 */
	public function render_category_edit_field( $term ) {
		$checked = 'yes' === get_term_meta( $term->term_id, self::META_KEY, true );
		?>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Identity verification', 'facevault-identity-verification' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="facevault_requires_verification" value="yes" <?php checked( $checked ); ?> />
					<?php esc_html_e( 'Requires identity verification', 'facevault-identity-verification' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Applies to all products in this category, including child categories.', 'facevault-identity-verification' ); ?></p>
				<?php wp_nonce_field( 'facevault_category_gate', 'facevault_category_gate_nonce' ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Persist the category flag.
	 *
	 * @param int $term_id Term id.
	 */
	public function save_category_field( $term_id ) {
		if ( ! isset( $_POST['facevault_category_gate_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['facevault_category_gate_nonce'] ) ), 'facevault_category_gate' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_product_terms' ) ) {
			return;
		}
		if ( isset( $_POST['facevault_requires_verification'] ) ) {
			update_term_meta( $term_id, self::META_KEY, 'yes' );
		} else {
			delete_term_meta( $term_id, self::META_KEY );
		}
	}

	/**
	 * Whether a single product is gated (own flag, or any of its categories
	 * — including ancestor categories — is flagged).
	 *
	 * @param int $product_id Parent product id (variations inherit).
	 * @return bool
	 */
	public function product_is_gated( $product_id ) {
		if ( 'yes' === get_post_meta( $product_id, self::META_KEY, true ) ) {
			return true;
		}

		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( ! is_array( $terms ) ) {
			return false;
		}

		$term_ids = array();
		foreach ( $terms as $term ) {
			$term_ids[] = (int) $term->term_id;
			foreach ( get_ancestors( (int) $term->term_id, 'product_cat', 'taxonomy' ) as $ancestor_id ) {
				$term_ids[] = (int) $ancestor_id;
			}
		}

		foreach ( array_unique( $term_ids ) as $term_id ) {
			if ( 'yes' === get_term_meta( $term_id, self::META_KEY, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the cart needs a verified customer.
	 *
	 * @param int[]|null $product_ids Parent product ids; null reads the live
	 *                                Woo cart (tests pass an array).
	 * @return bool
	 */
	public function cart_requires_verification( $product_ids = null ) {
		if ( null === $product_ids ) {
			$product_ids = $this->cart_product_ids();
		}
		if ( empty( $product_ids ) ) {
			return false;
		}

		if ( Settings::get( 'gate_all_purchases' ) ) {
			return true;
		}

		foreach ( $product_ids as $product_id ) {
			if ( $this->product_is_gated( (int) $product_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Gate verdict for the current shopper. Policy (what to do with
	 * 'review') is the caller's business.
	 *
	 * @param int $user_id User id (0 = guest).
	 * @return string One of the VERDICT_* constants.
	 */
	public function user_may_checkout( $user_id ) {
		if ( $user_id <= 0 ) {
			return self::VERDICT_GUEST;
		}

		switch ( $this->user_status->get_status( $user_id ) ) {
			case User_Status::STATUS_VERIFIED:
				return self::VERDICT_PASS;
			case User_Status::STATUS_REVIEW:
				return self::VERDICT_REVIEW;
			default:
				return self::VERDICT_BLOCKED;
		}
	}

	/**
	 * Parent product ids in the live cart.
	 *
	 * @return int[]
	 */
	private function cart_product_ids() {
		if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
			return array();
		}
		$ids = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( isset( $item['product_id'] ) ) {
				$ids[] = (int) $item['product_id'];
			}
		}
		return $ids;
	}
}
