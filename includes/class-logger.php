<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GFFPDF_Logger {

	const LEVEL_INFO  = 'INFO';
	const LEVEL_WARN  = 'WARN';
	const LEVEL_ERROR = 'ERROR';

	private static ?string $log_file = null;

	/**
	 * Write a log entry if logging is enabled.
	 */
	public static function log( string $message, string $level = self::LEVEL_INFO, array $context = [] ): void {
		$settings = get_option( 'gffpdf_settings', [] );
		if ( empty( $settings['enable_logs'] ) ) {
			return;
		}

		$log_file = self::get_log_file();
		if ( ! $log_file ) {
			return;
		}

		$timestamp   = current_time( 'Y-m-d H:i:s' );
		$context_str = empty( $context ) ? '' : ' | ' . wp_json_encode( $context );
		$line        = "[{$timestamp}] [{$level}] {$message}{$context_str}" . PHP_EOL;

		file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );
	}

	public static function info( string $message, array $context = [] ): void {
		self::log( $message, self::LEVEL_INFO, $context );
	}

	public static function warn( string $message, array $context = [] ): void {
		self::log( $message, self::LEVEL_WARN, $context );
	}

	public static function error( string $message, array $context = [] ): void {
		self::log( $message, self::LEVEL_ERROR, $context );
	}

	/**
	 * Return (creating if needed) today's log file path.
	 */
	private static function get_log_file(): ?string {
		if ( self::$log_file !== null ) {
			return self::$log_file;
		}

		$log_dir = GFFPDF_UPLOAD_DIR . 'logs/';
		if ( ! is_dir( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}

		self::$log_file = $log_dir . 'gffpdf-' . date( 'Y-m-d' ) . '.log';
		return self::$log_file;
	}

	/**
	 * Return the last N lines from today's log.
	 */
	public static function get_recent_logs( int $lines = 100 ): array {
		$log_file = self::get_log_file();
		if ( ! $log_file || ! file_exists( $log_file ) ) {
			return [];
		}

		$all = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		return array_slice( $all, -$lines );
	}

	/**
	 * List available log files.
	 */
	public static function list_log_files(): array {
		$log_dir = GFFPDF_UPLOAD_DIR . 'logs/';
		if ( ! is_dir( $log_dir ) ) {
			return [];
		}
		$files = glob( $log_dir . 'gffpdf-*.log' );
		return $files ? array_reverse( $files ) : [];
	}

	/**
	 * Delete log files older than N days.
	 */
	public static function prune_logs( int $days = 30 ): void {
		$files = self::list_log_files();
		$cutoff = strtotime( "-{$days} days" );
		foreach ( $files as $file ) {
			if ( filemtime( $file ) < $cutoff ) {
				unlink( $file );
			}
		}
	}
}