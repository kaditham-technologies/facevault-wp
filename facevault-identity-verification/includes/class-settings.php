<?php
/**
 * Settings screen (Settings → FaceVault) and config access.
 *
 * @package FaceVault
 */

namespace FaceVault\WP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the options page, sanitizes input, and renders the setup
 * checklist + webhook debug panel. Config reads go through Settings::get().
 */
class Settings {

	const OPTION = 'facevault_settings';

	/**
	 * Mask marker used when redisplaying stored secrets. Real keys/secrets
	 * never contain this character, so its presence on save means the field
	 * was left untouched and the stored value must be kept.
	 */
	const MASK_CHAR = "\u{2022}";

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'api_key'                  => '',
			'site_id'                  => '',
			'webhook_secret'           => '',
			'api_base'                 => 'https://api.facevault.id/api/v1',
			'embed_origin'             => 'https://app.facevault.id',
			'poll_fallback'            => true,
			'attribution'              => true,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * Read the merged settings, or a single key.
	 *
	 * @param string|null $key Optional key.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		$options = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		if ( null === $key ) {
			return $options;
		}
		return isset( $options[ $key ] ) ? $options[ $key ] : null;
	}

	/**
	 * Whether the plugin has the minimum config needed to mint sessions.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$options = self::get();
		return '' !== $options['api_key'] && '' !== $options['site_id'];
	}

	/**
	 * Hook everything.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the options page.
	 */
	public function add_menu() {
		add_options_page(
			__( 'FaceVault', 'facevault-identity-verification' ),
			__( 'FaceVault', 'facevault-identity-verification' ),
			'manage_options',
			'facevault',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue the admin script on our page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ) {
		if ( 'settings_page_facevault' !== $hook ) {
			return;
		}
		wp_enqueue_script(
			'facevault-admin-settings',
			FACEVAULT_PLUGIN_URL . 'assets/js/admin-settings.js',
			array(),
			FACEVAULT_VERSION,
			true
		);
		wp_localize_script(
			'facevault-admin-settings',
			'facevaultAdmin',
			array(
				'restUrl' => esc_url_raw( rest_url( 'facevault/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'testing' => __( 'Testing…', 'facevault-identity-verification' ),
					'copied'  => __( 'Copied!', 'facevault-identity-verification' ),
					'failed'  => __( 'Request failed — is the REST API reachable?', 'facevault-identity-verification' ),
				),
			)
		);
	}

	/**
	 * Register the option, sections, and fields.
	 */
	public function register_settings() {
		register_setting(
			'facevault',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'facevault_connection',
			__( 'Connection', 'facevault-identity-verification' ),
			array( $this, 'render_connection_intro' ),
			'facevault'
		);

		$this->add_field( 'api_key', __( 'API key', 'facevault-identity-verification' ), 'facevault_connection' );
		$this->add_field( 'site_id', __( 'Site ID', 'facevault-identity-verification' ), 'facevault_connection' );
		$this->add_field( 'webhook_secret', __( 'Webhook signing secret', 'facevault-identity-verification' ), 'facevault_connection' );

		add_settings_section(
			'facevault_display',
			__( 'Display', 'facevault-identity-verification' ),
			'__return_false',
			'facevault'
		);
		$this->add_field( 'attribution', __( '“Powered by FaceVault” link', 'facevault-identity-verification' ), 'facevault_display' );

		add_settings_section(
			'facevault_advanced',
			__( 'Advanced — environment / self-hosted', 'facevault-identity-verification' ),
			array( $this, 'render_advanced_intro' ),
			'facevault'
		);
		$this->add_field( 'api_base', __( 'API base URL', 'facevault-identity-verification' ), 'facevault_advanced' );
		$this->add_field( 'embed_origin', __( 'Embed origin', 'facevault-identity-verification' ), 'facevault_advanced' );
		$this->add_field( 'poll_fallback', __( 'Status-poll fallback', 'facevault-identity-verification' ), 'facevault_advanced' );
		$this->add_field( 'delete_data_on_uninstall', __( 'Delete data on uninstall', 'facevault-identity-verification' ), 'facevault_advanced' );
	}

	/**
	 * Field registration helper.
	 *
	 * @param string $key     Option sub-key.
	 * @param string $label   Field label.
	 * @param string $section Section id.
	 */
	private function add_field( $key, $label, $section ) {
		add_settings_field(
			'facevault_' . $key,
			$label,
			array( $this, 'render_field' ),
			'facevault',
			$section,
			array(
				'key'       => $key,
				'label_for' => 'facevault_' . $key,
			)
		);
	}

	/**
	 * Connection section intro: honest storage note.
	 */
	public function render_connection_intro() {
		echo '<p>' . esc_html__( 'Credentials are stored in the WordPress options table, readable by site administrators — the same model used by payment plugins. Scope your FaceVault API key to session creation only.', 'facevault-identity-verification' ) . '</p>';
	}

	/**
	 * Advanced section intro.
	 */
	public function render_advanced_intro() {
		echo '<p>' . esc_html__( 'Only change these when pointing at a non-production or self-hosted FaceVault environment.', 'facevault-identity-verification' ) . '</p>';
	}

	/**
	 * Render one field.
	 *
	 * @param array $args Field args (key).
	 */
	public function render_field( $args ) {
		$key     = $args['key'];
		$options = self::get();
		$value   = $options[ $key ];
		$id      = 'facevault_' . $key;
		$name    = self::OPTION . '[' . $key . ']';

		switch ( $key ) {
			case 'api_key':
			case 'webhook_secret':
				$display = '' === $value ? '' : $this->mask( $value );
				printf(
					'<input type="text" class="regular-text code" id="%1$s" name="%2$s" value="%3$s" autocomplete="off" spellcheck="false" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $display )
				);
				if ( 'api_key' === $key ) {
					echo '<p class="description">' . esc_html__( 'From your FaceVault dashboard. Needs the sessions:create scope.', 'facevault-identity-verification' ) . '</p>';
				} else {
					echo '<p class="description">' . esc_html__( '64-character hex secret, shown once when you generate it in the FaceVault dashboard. Required to accept webhooks.', 'facevault-identity-verification' ) . '</p>';
				}
				break;

			case 'site_id':
				printf(
					'<input type="text" class="regular-text code" id="%1$s" name="%2$s" value="%3$s" placeholder="fvs_pk_…" autocomplete="off" spellcheck="false" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;

			case 'api_base':
			case 'embed_origin':
				printf(
					'<input type="url" class="regular-text code" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;

			case 'poll_fallback':
				printf(
					'<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( (bool) $value, true, false ),
					esc_html__( 'Also check verification status from this server (recommended; required on hosts that cannot receive webhooks).', 'facevault-identity-verification' )
				);
				break;

			case 'attribution':
				printf(
					'<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( (bool) $value, true, false ),
					esc_html__( 'Show a small “Powered by FaceVault” link under the verify button.', 'facevault-identity-verification' )
				);
				break;

			case 'delete_data_on_uninstall':
				printf(
					'<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( (bool) $value, true, false ),
					esc_html__( 'Also delete per-user verification statuses when the plugin is uninstalled. Leave off if you need the records for compliance.', 'facevault-identity-verification' )
				);
				break;
		}
	}

	/**
	 * Sanitize submitted settings. Masked secrets keep their stored value.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input  = (array) $input;
		$stored = self::get();
		$clean  = self::defaults();

		foreach ( array( 'api_key', 'webhook_secret' ) as $secret_key ) {
			$submitted = isset( $input[ $secret_key ] ) ? trim( (string) $input[ $secret_key ] ) : '';
			if ( false !== strpos( $submitted, self::MASK_CHAR ) ) {
				$clean[ $secret_key ] = $stored[ $secret_key ]; // Untouched mask → keep.
			} else {
				$clean[ $secret_key ] = sanitize_text_field( $submitted );
			}
		}

		if ( '' !== $clean['webhook_secret'] && ! preg_match( '/^[0-9a-f]{64}$/', $clean['webhook_secret'] ) ) {
			add_settings_error(
				'facevault',
				'facevault_webhook_secret',
				__( 'The webhook secret should be the 64-character hex string from the FaceVault dashboard. Saved anyway — signature checks will fail if it is wrong.', 'facevault-identity-verification' ),
				'warning'
			);
		}

		$clean['site_id'] = sanitize_text_field( isset( $input['site_id'] ) ? trim( (string) $input['site_id'] ) : '' );
		if ( '' !== $clean['site_id'] && 0 !== strpos( $clean['site_id'], 'fvs_pk_' ) ) {
			add_settings_error(
				'facevault',
				'facevault_site_id',
				__( 'Site IDs normally start with fvs_pk_. Saved anyway — double-check it against the FaceVault dashboard.', 'facevault-identity-verification' ),
				'warning'
			);
		}

		foreach ( array( 'api_base', 'embed_origin' ) as $url_key ) {
			$url = isset( $input[ $url_key ] ) ? esc_url_raw( trim( (string) $input[ $url_key ] ), array( 'http', 'https' ) ) : '';
			if ( '' === $url ) {
				$url = self::defaults()[ $url_key ];
			}
			$url  = untrailingslashit( $url );
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) && ! $this->is_local_host( $host ) ) {
				add_settings_error(
					'facevault',
					'facevault_' . $url_key,
					__( 'Non-HTTPS URLs are only allowed for localhost. Reverted to the default.', 'facevault-identity-verification' ),
					'error'
				);
				$url = self::defaults()[ $url_key ];
			}
			$clean[ $url_key ] = $url;
		}

		$clean['poll_fallback']            = ! empty( $input['poll_fallback'] );
		$clean['attribution']              = ! empty( $input['attribution'] );
		$clean['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );

		return $clean;
	}

	/**
	 * Whether a hostname counts as local (plain-HTTP allowed, webhooks won't
	 * arrive from FaceVault's cloud).
	 *
	 * @param string|null $host Hostname.
	 * @return bool
	 */
	private function is_local_host( $host ) {
		if ( null === $host || '' === $host ) {
			return false;
		}
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		return (bool) preg_match( '/\.(local|test)$/', $host );
	}

	/**
	 * Mask a stored secret for redisplay: bullets + last 4 characters.
	 *
	 * @param string $value Stored secret.
	 * @return string
	 */
	private function mask( $value ) {
		return str_repeat( self::MASK_CHAR, 8 ) . substr( $value, -4 );
	}

	/**
	 * Render the full settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'FaceVault Identity Verification', 'facevault-identity-verification' ); ?></h1>

			<form action="options.php" method="post">
				<?php
				settings_fields( 'facevault' );
				do_settings_sections( 'facevault' );
				submit_button();
				?>
			</form>

			<hr />
			<?php $this->render_checklist(); ?>
			<hr />
			<?php $this->render_debug_panel(); ?>
		</div>
		<?php
	}

	/**
	 * Setup checklist + webhook URL + connection test.
	 */
	private function render_checklist() {
		$webhook_url = rest_url( 'facevault/v1/webhook' );
		$scheme      = wp_parse_url( home_url(), PHP_URL_SCHEME );
		$host        = wp_parse_url( home_url(), PHP_URL_HOST );
		$unreachable = ( 'https' !== $scheme ) || $this->is_local_host( $host );
		?>
		<h2><?php esc_html_e( 'Setup checklist', 'facevault-identity-verification' ); ?></h2>
		<ol>
			<li>
				<a href="https://devdash.facevault.id/?utm_source=wp-plugin&amp;utm_medium=settings" target="_blank" rel="noopener">
					<?php esc_html_e( 'Create a free FaceVault account', 'facevault-identity-verification' ); ?></a>
				<?php esc_html_e( '(50 verifications/month on the free tier).', 'facevault-identity-verification' ); ?>
			</li>
			<li><?php esc_html_e( 'In the FaceVault dashboard, create a Hosted Verification site and generate an API key with the sessions:create scope plus a webhook signing secret.', 'facevault-identity-verification' ); ?></li>
			<li><?php esc_html_e( 'Paste the Site ID, API key, and webhook secret above and save.', 'facevault-identity-verification' ); ?></li>
			<li>
				<?php esc_html_e( 'Set your webhook URL in the FaceVault dashboard to:', 'facevault-identity-verification' ); ?>
				<code id="facevault-webhook-url"><?php echo esc_url( $webhook_url ); ?></code>
				<button type="button" class="button button-small" id="facevault-copy-webhook"><?php esc_html_e( 'Copy', 'facevault-identity-verification' ); ?></button>
				<?php if ( $unreachable ) : ?>
					<p class="notice notice-warning inline" style="padding:6px 10px;">
						<?php esc_html_e( 'FaceVault delivers webhooks over HTTPS to publicly reachable hosts only. This site looks local or non-HTTPS, so webhooks will not arrive — the status-poll fallback (enabled by default) will keep statuses updated instead.', 'facevault-identity-verification' ); ?>
					</p>
				<?php endif; ?>
			</li>
			<li>
				<button type="button" class="button" id="facevault-test-connection"><?php esc_html_e( 'Test connection', 'facevault-identity-verification' ); ?></button>
				<span id="facevault-test-result" aria-live="polite"></span>
			</li>
		</ol>
		<?php
	}

	/**
	 * Webhook health line + recent deliveries table.
	 */
	private function render_debug_panel() {
		$log = get_option( 'facevault_webhook_log', array() );
		$log = is_array( $log ) && isset( $log['entries'] ) ? $log['entries'] : array();
		?>
		<h2><?php esc_html_e( 'Webhook health', 'facevault-identity-verification' ); ?></h2>
		<p>
			<?php
			if ( empty( $log ) ) {
				esc_html_e( 'No webhook received yet. The connection test cannot validate the signing secret — only a real delivery can.', 'facevault-identity-verification' );
			} else {
				$last = $log[0];
				printf(
					/* translators: 1: human time diff, 2: delivery result. */
					esc_html__( 'Last webhook received %1$s ago (%2$s).', 'facevault-identity-verification' ),
					esc_html( human_time_diff( (int) $last['time'] ) ),
					esc_html( $last['result'] )
				);
			}
			?>
		</p>
		<?php if ( ! empty( $log ) ) : ?>
			<table class="widefat striped" style="max-width:720px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'facevault-identity-verification' ); ?></th>
						<th><?php esc_html_e( 'Session', 'facevault-identity-verification' ); ?></th>
						<th><?php esc_html_e( 'Event', 'facevault-identity-verification' ); ?></th>
						<th><?php esc_html_e( 'Result', 'facevault-identity-verification' ); ?></th>
						<th><?php esc_html_e( 'HTTP', 'facevault-identity-verification' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $log as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $entry['time'] ) ); ?></td>
							<td><code><?php echo esc_html( $entry['session_id'] ); ?></code></td>
							<td><?php echo esc_html( $entry['event'] ); ?></td>
							<td><?php echo esc_html( $entry['result'] ); ?></td>
							<td><?php echo esc_html( $entry['http_code'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}
}
