<?php
/**
 * Admin user management: verification-status column on the Users list and
 * a manual override on the user-edit screen. Plain WP — works without
 * WooCommerce.
 *
 * @package FaceVault
 */

namespace FaceVault\WP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Users-list column + manual verify/unverify override.
 */
class Admin_Users {

	/**
	 * Status writer.
	 *
	 * @var User_Status
	 */
	private $user_status;

	/**
	 * Constructor.
	 *
	 * @param User_Status $user_status Status writer.
	 */
	public function __construct( User_Status $user_status ) {
		$this->user_status = $user_status;
	}

	/**
	 * Hook everything.
	 */
	public function register() {
		add_filter( 'manage_users_columns', array( $this, 'add_column' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'render_column' ), 10, 3 );
		add_action( 'edit_user_profile', array( $this, 'render_override_section' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_override' ) );
	}

	/**
	 * Add the Identity column.
	 *
	 * @param array $columns Users-list columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$columns['facevault_status'] = __( 'Identity', 'facevault-identity-verification' );
		return $columns;
	}

	/**
	 * Render one cell.
	 *
	 * @param string $output    Cell content so far.
	 * @param string $column    Column key.
	 * @param int    $user_id   User id.
	 * @return string
	 */
	public function render_column( $output, $column, $user_id ) {
		if ( 'facevault_status' !== $column ) {
			return $output;
		}
		$status = $this->user_status->get_status( $user_id );
		if ( User_Status::STATUS_NONE === $status ) {
			return '—';
		}
		return sprintf(
			'<span class="facevault-user-status facevault-user-status--%1$s">%2$s</span>',
			esc_attr( $status ),
			esc_html( $this->label( $status ) )
		);
	}

	/**
	 * Manual override UI on another user's profile screen.
	 *
	 * @param \WP_User $user User being edited.
	 */
	public function render_override_section( $user ) {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}
		$status = $this->user_status->get_status( $user->ID );
		?>
		<h2><?php esc_html_e( 'FaceVault identity verification', 'facevault-identity-verification' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Current status', 'facevault-identity-verification' ); ?></th>
				<td><strong><?php echo esc_html( $this->label( $status ) ); ?></strong></td>
			</tr>
			<tr>
				<th scope="row">
					<label for="facevault_manual_override"><?php esc_html_e( 'Manual override', 'facevault-identity-verification' ); ?></label>
				</th>
				<td>
					<select name="facevault_manual_override" id="facevault_manual_override">
						<option value=""><?php esc_html_e( '— no change —', 'facevault-identity-verification' ); ?></option>
						<option value="verified"><?php esc_html_e( 'Mark as verified', 'facevault-identity-verification' ); ?></option>
						<option value="unverified"><?php esc_html_e( 'Mark as not verified', 'facevault-identity-verification' ); ?></option>
					</select>
					<p class="description">
						<?php esc_html_e( 'Overrides the FaceVault verification outcome for this user. The change is recorded in the user’s verification history with your user ID.', 'facevault-identity-verification' ); ?>
					</p>
					<?php wp_nonce_field( 'facevault_manual_override', 'facevault_manual_override_nonce' ); ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Apply a manual override.
	 *
	 * @param int $user_id User being edited.
	 */
	public function save_override( $user_id ) {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}
		if ( ! isset( $_POST['facevault_manual_override_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['facevault_manual_override_nonce'] ) ), 'facevault_manual_override' ) ) {
			return;
		}
		$choice = isset( $_POST['facevault_manual_override'] ) ? sanitize_key( wp_unslash( $_POST['facevault_manual_override'] ) ) : '';
		if ( 'verified' === $choice ) {
			$this->user_status->set_manual( $user_id, User_Status::STATUS_VERIFIED, get_current_user_id() );
		} elseif ( 'unverified' === $choice ) {
			$this->user_status->set_manual( $user_id, User_Status::STATUS_NONE, get_current_user_id() );
		}
	}

	/**
	 * Human label for a status.
	 *
	 * @param string $status Status constant.
	 * @return string
	 */
	private function label( $status ) {
		$labels = array(
			User_Status::STATUS_VERIFIED => __( 'Verified ✓', 'facevault-identity-verification' ),
			User_Status::STATUS_REVIEW   => __( 'Pending review', 'facevault-identity-verification' ),
			User_Status::STATUS_PENDING  => __( 'In progress', 'facevault-identity-verification' ),
			User_Status::STATUS_REJECTED => __( 'Rejected', 'facevault-identity-verification' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'Not verified', 'facevault-identity-verification' );
	}
}
