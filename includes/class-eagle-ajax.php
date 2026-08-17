<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eagle_Ajax {

	public function __construct() {
		add_action( 'wp_ajax_mes_import_batch', [ $this, 'import_batch' ] );
	}

	private function guard(): void {
		check_ajax_referer( 'mes_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'myhome-eagle-sync' ) ], 403 );
		}
	}

	public function import_batch(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			// Image downloads are bandwidth-bound (S3 throttles to ~140 KB/s
			// per connection); parallel downloads with 120s per-image timeouts
			// need a generous budget.
			@set_time_limit( 300 );
		}

		try {
			$this->guard();

			$engine = new Eagle_Sync_Engine();
			$result = $engine->process_batch();

			if ( 'error' === $result['phase'] ) {
				Eagle_Logger::error( (string) ( $result['message'] ?? 'Unknown sync error.' ) );
				wp_send_json_error( [ 'message' => $result['message'] ?? __( 'Unknown sync error.', 'myhome-eagle-sync' ) ] );
			}

			wp_send_json_success( $result );
		} catch ( Throwable $e ) {
			Eagle_Logger::error( 'Batch crashed: ' . $e->getMessage() );
			wp_send_json_error( [ 'message' => 'Batch crashed: ' . $e->getMessage() ] );
		}
	}
}
