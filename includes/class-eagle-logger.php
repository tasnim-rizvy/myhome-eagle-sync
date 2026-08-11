<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eagle_Logger {

	private const MAX_ENTRIES = 200;

	public static function init(): void {
	}

	public static function log( string $message, $data = null ): void {
		self::write( $message, $data, 'log' );
	}

	public static function error( string $message, $data = null ): void {
		self::write( $message, $data, 'error' );
	}

	public static function get_log(): array {
		$log = get_option( MES_OPTION_LOG, [] );
		return is_array( $log ) ? $log : [];
	}

	public static function clear(): void {
		delete_option( MES_OPTION_LOG );
	}

	private static function write( string $message, $data, string $level ): void {
		$entry = [
			'time'  => current_time( 'mysql' ),
			'level' => $level,
			'msg'   => $message,
		];

		if ( $data !== null ) {
			$entry['data'] = self::sanitize_data( $data );
		}

		$log = self::get_log();
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, self::MAX_ENTRIES );

		update_option( MES_OPTION_LOG, $log, false );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[myhome-eagle-sync] ' . $message );
		}
	}

	/**
	 * Never persist credentials.
	 */
	private static function sanitize_data( $data ) {
		if ( is_string( $data ) ) {
			return wp_kses( $data, [] );
		}

		if ( is_array( $data ) || is_object( $data ) ) {
			$out = [];
			foreach ( (array) $data as $key => $value ) {
				if ( in_array( $key, [ 'client_id', 'client_secret', 'token' ], true ) ) {
					continue;
				}
				$out[ $key ] = self::sanitize_data( $value );
			}
			return $out;
		}

		return $data;
	}
}
