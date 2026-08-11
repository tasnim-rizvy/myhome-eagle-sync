<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eagle_Settings {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'Eagle Sync', 'myhome-eagle-sync' ),
			__( 'Eagle Sync', 'myhome-eagle-sync' ),
			'manage_options',
			'mes-dashboard',
			[ $this, 'render_dashboard' ],
			'dashicons-download',
			58
		);

		add_submenu_page(
			'mes-dashboard',
			__( 'Log', 'myhome-eagle-sync' ),
			__( 'Log', 'myhome-eagle-sync' ),
			'manage_options',
			'mes-log',
			[ $this, 'render_log' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'mes-' ) && false === strpos( $hook, 'toplevel_page_mes-dashboard' ) ) {
			return;
		}

		wp_enqueue_style( 'mes-sync', MES_PLUGIN_URL . 'assets/css/sync.css', [], MES_VERSION );
		wp_enqueue_script( 'mes-sync', MES_PLUGIN_URL . 'assets/js/sync.js', [], MES_VERSION, true );
		wp_localize_script(
			'mes-sync',
			'MES',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mes_ajax' ),
			]
		);
	}

	// ---------------------------------------------------------------------
	// Dashboard
	// ---------------------------------------------------------------------

	public function render_dashboard(): void {
		$client = new Eagle_API_Client();
		$hasKey = $client->has_credentials();
		$summary = get_option( MES_OPTION_SYNC_SUMMARY, [] );
		$state = Eagle_Sync_Engine::get_state();
		?>
		<div class="wrap mes-wrap">
			<h1><?php esc_html_e( 'Eagle Software Sync', 'myhome-eagle-sync' ); ?></h1>

			<div class="mes-card">
				<h2><?php esc_html_e( 'API Credentials', 'myhome-eagle-sync' ); ?></h2>
				<?php if ( $hasKey ) : ?>
					<p class="mes-ok"><?php esc_html_e( 'Credentials are saved (stored encrypted).', 'myhome-eagle-sync' ); ?></p>
				<?php endif; ?>
				<form method="post" action="">
					<?php wp_nonce_field( 'mes_save_keys', 'mes_nonce' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="mes_client_id"><?php esc_html_e( 'Client ID', 'myhome-eagle-sync' ); ?></label></th>
							<td><input type="text" class="regular-text" id="mes_client_id" name="mes_client_id" value="<?php echo esc_attr( $client->get_client_id() ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="mes_client_secret"><?php esc_html_e( 'Client Secret', 'myhome-eagle-sync' ); ?></label></th>
							<td><input type="password" class="regular-text" id="mes_client_secret" name="mes_client_secret" value="<?php echo esc_attr( $client->get_client_secret() ); ?>" autocomplete="new-password" /></td>
						</tr>
					</table>
					<?php submit_button( __( 'Save Keys', 'myhome-eagle-sync' ) ); ?>
				</form>
			</div>

			<div class="mes-card">
				<h2><?php esc_html_e( 'Synchronize Listings', 'myhome-eagle-sync' ); ?></h2>
				<p>
					<button type="button" id="mes-start-sync" class="button button-primary button-large" <?php disabled( ! $hasKey ); ?>>
						<?php esc_html_e( 'Update Home Listings', 'myhome-eagle-sync' ); ?>
					</button>
				</p>
				<div class="mes-status-line">
					<p id="mes-status" class="mes-status" hidden></p>
					<span id="mes-spinner" class="mes-spinner" hidden></span>
				</div>
				<p class="mes-hint"><?php esc_html_e( 'Existing listings are updated in place; nothing is deleted. Only fields that already exist on your site receive imported values; no new fields are created.', 'myhome-eagle-sync' ); ?></p>
			</div>

			<?php if ( ! empty( $summary ) ) : ?>
				<div class="mes-card">
					<h2><?php esc_html_e( 'Last Run', 'myhome-eagle-sync' ); ?></h2>
					<ul>
						<li><?php printf( esc_html__( 'Finished: %s', 'myhome-eagle-sync' ), esc_html( $summary['finished'] ?? '' ) ); ?></li>
						<li><?php printf( esc_html__( 'Duration: %s', 'myhome-eagle-sync' ), esc_html( $summary['duration'] ?? '' ) ); ?></li>
						<li><?php printf( esc_html__( 'Processed: %d', 'myhome-eagle-sync' ), (int) ( $summary['processed'] ?? 0 ) ); ?></li>
						<li><?php printf( esc_html__( 'Created: %d', 'myhome-eagle-sync' ), (int) ( $summary['created'] ?? 0 ) ); ?></li>
						<li><?php printf( esc_html__( 'Updated: %d', 'myhome-eagle-sync' ), (int) ( $summary['updated'] ?? 0 ) ); ?></li>
						<li><?php printf( esc_html__( 'Skipped: %d', 'myhome-eagle-sync' ), (int) ( $summary['skipped'] ?? 0 ) ); ?></li>
						<li><?php printf( esc_html__( 'Failed: %d', 'myhome-eagle-sync' ), (int) ( $summary['failed'] ?? 0 ) ); ?></li>
					</ul>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// ---------------------------------------------------------------------
	// Log page
	// ---------------------------------------------------------------------

	public function render_log(): void {
		$log = Eagle_Logger::get_log();
		?>
		<div class="wrap mes-wrap">
			<h1><?php esc_html_e( 'Eagle Sync Log', 'myhome-eagle-sync' ); ?></h1>
			<div class="mes-card">
				<?php if ( empty( $log ) ) : ?>
					<p><?php esc_html_e( 'No log entries yet.', 'myhome-eagle-sync' ); ?></p>
				<?php else : ?>
					<pre class="mes-log"><?php echo esc_html( implode( "\n", $log ) ); ?></pre>
				<?php endif; ?>
				<form method="post" action="">
					<?php wp_nonce_field( 'mes_clear_log', 'mes_nonce' ); ?>
					<?php submit_button( __( 'Clear Log', 'myhome-eagle-sync' ), 'delete' ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	// ---------------------------------------------------------------------
	// Form handlers (dashboard POSTs)
	// ---------------------------------------------------------------------

	public static function handle_post(): void {
		if ( empty( $_POST['mes_nonce'] ) ) {
			return;
		}

		if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mes_nonce'] ) ), 'mes_save_keys' ) ) {
			$client = new Eagle_API_Client();
			$id = isset( $_POST['mes_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['mes_client_id'] ) ) : '';
			$secret = isset( $_POST['mes_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['mes_client_secret'] ) ) : '';
			$client->save_credentials( $id, $secret );
			add_settings_error( 'mes', 'mes-saved', __( 'API keys saved.', 'myhome-eagle-sync' ) );
			return;
		}

		if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mes_nonce'] ) ), 'mes_clear_log' ) ) {
			Eagle_Logger::clear();
			add_settings_error( 'mes', 'mes-log-cleared', __( 'Log cleared.', 'myhome-eagle-sync' ), 'updated' );
		}
	}
}
