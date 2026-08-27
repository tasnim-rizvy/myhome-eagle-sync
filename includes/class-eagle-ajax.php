<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eagle_Ajax {
	private const WORKER_LOCK_OPTION = 'mes_eagle_sync_worker_lock';

	public function __construct() {
		add_action( 'wp_ajax_mes_import_batch', [ $this, 'import_batch' ] );
	}

	private function guard(): void {
		if ( ! check_ajax_referer( 'mes_ajax', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Your session expired. Refresh this page and try again.', 'myhome-eagle-sync' ) ], 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'myhome-eagle-sync' ) ], 403 );
		}
	}

	public function import_batch(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			// Each request is deliberately small. A shorter PHP ceiling prevents a
			// thumbnail operation from waiting for the host's ~180-second cutoff.
			@set_time_limit( 60 );
		}

		$this->guard();

		$owner = wp_generate_uuid4();
		if ( ! $this->acquire_worker_lock( $owner ) ) {
			$state = Eagle_Sync_Engine::get_state();
			wp_send_json_success(
				[
					'phase'        => 'running',
					'retry_after'  => 2,
					'processed'    => (int) ( $state['processed'] ?? 0 ),
					'total'        => (int) ( $state['total'] ?? 0 ),
					'offset'       => (int) ( $state['offset'] ?? 0 ),
					'images_done'  => (int) ( $state['current_images_done'] ?? 0 ),
					'images_total' => (int) ( $state['current_images_total'] ?? 0 ),
				]
			);
		}

		$this->install_fatal_watch( $owner );
		$result      = null;
		$error       = '';
		$errorStatus = 500;

		try {
			$engine = new Eagle_Sync_Engine();
			$result = $engine->process_batch();

			if ( 'error' === $result['phase'] ) {
				$error       = (string) ( $result['message'] ?? __( 'Unknown sync error.', 'myhome-eagle-sync' ) );
				$errorStatus = 400;
				Eagle_Logger::error( $error );
			}
		} catch ( Throwable $e ) {
			$state = Eagle_Sync_Engine::get_state();
			unset( $state['busy_until'] );
			Eagle_Sync_Engine::set_state( $state );
			$error = 'Batch crashed: ' . $e->getMessage();
			Eagle_Logger::error( $error );
		}

		self::release_worker_lock( $owner );

		if ( '' !== $error ) {
			wp_send_json_error( [ 'message' => $error ], $errorStatus );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Atomic cross-request lock. A stale lock expires automatically if PHP is
	 * terminated before the shutdown handler can release it.
	 */
	private function acquire_worker_lock( string $owner ): bool {
		$expires = time() + 90;
		$value   = $expires . '|' . $owner;

		if ( add_option( self::WORKER_LOCK_OPTION, $value, '', false ) ) {
			return true;
		}

		$existing = (string) get_option( self::WORKER_LOCK_OPTION, '' );
		$parts    = explode( '|', $existing, 2 );
		if ( (int) ( $parts[0] ?? 0 ) > time() ) {
			return false;
		}

		// Replace only the exact stale value we observed. This compare-and-swap
		// prevents two tabs from both deleting/recreating the same expired option.
		global $wpdb;
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				self::WORKER_LOCK_OPTION,
				$existing
			)
		);

		if ( 1 === (int) $updated ) {
			wp_cache_delete( self::WORKER_LOCK_OPTION, 'options' );
			return true;
		}

		return false;
	}

	private static function release_worker_lock( string $owner ): void {
		$lock  = (string) get_option( self::WORKER_LOCK_OPTION, '' );
		$parts = explode( '|', $lock, 2 );
		if ( isset( $parts[1] ) && hash_equals( $parts[1], $owner ) ) {
			delete_option( self::WORKER_LOCK_OPTION );
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
	private function install_fatal_watch( string $owner ): void {
		$GLOBALS['mes_mem_reserve'] = str_repeat( ' ', 1048576 ); // ~1 MB, freed on shutdown.
		$started = microtime( true );

		register_shutdown_function(
			static function () use ( $started, $owner ) {
				unset( $GLOBALS['mes_mem_reserve'] );

				$elapsed = round( microtime( true ) - $started, 1 );
				$peak    = memory_get_peak_usage( true );
				$limit   = ini_get( 'memory_limit' );
				$err     = error_get_last();
				$fatal   = $err && in_array( $err['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ], true );

				if ( $fatal ) {
					self::release_worker_lock( $owner );
					// A killed request cannot release its normal lease. Clear it here so
					// the browser can resume immediately instead of waiting for expiry.
					$state = Eagle_Sync_Engine::get_state();
					unset( $state['busy_until'] );
					Eagle_Sync_Engine::set_state( $state );

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

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log(
						sprintf(
							'[myhome-eagle-sync] batch end: peak memory %s of %s | %ss elapsed',
							size_format( $peak ),
							$limit,
							$elapsed
						)
					);
				}
			}
		);
	}
}
