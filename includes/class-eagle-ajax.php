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
		$this->install_fatal_watch();

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

	/**
	 * Record fatals that the try/catch above cannot see.
	 *
	 * Memory exhaustion and execution-timeout are fatal errors: they bypass
	 * try/catch entirely and leave the log empty (which is why "the log doesn't
	 * catch the real issue"). A shutdown handler still runs on those fatals; a
	 * small pre-allocated memory reserve is released first so there is headroom
	 * to record the error even when the memory limit was the thing that blew.
	 *
	 * It also writes a per-request heartbeat (peak memory + elapsed) to the PHP
	 * error log so we can see how close each batch runs to the limits, and
	 * whether requests are being cut at a fixed wall-clock (a proxy/host limit)
	 * rather than dying inside PHP.
	 */
	private function install_fatal_watch(): void {
		$GLOBALS['mes_mem_reserve'] = str_repeat( ' ', 1048576 ); // ~1 MB, freed on shutdown.
		$started = microtime( true );

		register_shutdown_function(
			static function () use ( $started ) {
				unset( $GLOBALS['mes_mem_reserve'] );

				$elapsed = round( microtime( true ) - $started, 1 );
				$peak    = memory_get_peak_usage( true );
				$limit   = ini_get( 'memory_limit' );
				$err     = error_get_last();
				$fatal   = $err && in_array( $err['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ], true );

				if ( $fatal ) {
					$msg = sprintf(
						'FATAL in batch: %s (%s:%d) | peak memory %s of %s | %ss elapsed',
						$err['message'],
						$err['file'],
						(int) $err['line'],
						size_format( $peak ),
						$limit,
						$elapsed
					);
					error_log( '[myhome-eagle-sync] ' . $msg );
					Eagle_Logger::error( $msg );
					return;
				}

				error_log(
					sprintf(
						'[myhome-eagle-sync] batch end: peak memory %s of %s | %ss elapsed',
						size_format( $peak ),
						$limit,
						$elapsed
					)
				);
			}
		);
	}
}
