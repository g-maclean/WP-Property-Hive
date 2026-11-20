<?php
/**
 * Plain-text log files for Smart Location.
 *
 * One file per month in wp-content/uploads/ph-smart-location-logs/, e.g.
 * smart-location-2026-11-3f9a1c2b7d4e.log. The random suffix stops the files being
 * guessed on servers that ignore .htaccess (nginx); .htaccess blocks them on Apache.
 *
 * Line format: "2026-11-21 10:00:00 | INFO | 123 | message | {json context}"
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PHSL_Logger {

	const KEEP_MONTHS = 6;

	public static function dir() {
		$upload = wp_upload_dir( null, false );
		return trailingslashit( $upload['basedir'] ) . 'ph-smart-location-logs';
	}

	private static function suffix() {
		return substr( wp_hash( 'phsl-log-files' ), 0, 12 );
	}

	public static function file_for( $month ) {
		return self::dir() . '/smart-location-' . $month . '-' . self::suffix() . '.log';
	}

	private static function ensure_dir() {
		$dir = self::dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$protect = array(
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'index.html' => '',
			'index.php'  => "<?php\n// Silence is golden.\n",
		);
		foreach ( $protect as $name => $content ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				@file_put_contents( $dir . '/' . $name, $content ); // phpcs:ignore
			}
		}
		return true;
	}

	public static function write( $level, $message, $property_id = 0, $context = array() ) {
		if ( ! self::ensure_dir() ) {
			return;
		}
		$file  = self::file_for( gmdate( 'Y-m' ) );
		$fresh = ! file_exists( $file );
		$line  = implode(
			' | ',
			array(
				gmdate( 'Y-m-d H:i:s' ),
				strtoupper( $level ),
				(int) $property_id,
				str_replace( array( "\r", "\n", ' | ' ), array( ' ', ' ', ' / ' ), (string) $message ),
				$context ? wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '',
			)
		);
		@file_put_contents( $file, $line . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore
		if ( $fresh ) {
			self::prune();
		}
	}

	/** @return array month (Y-m) => full path, newest first. */
	public static function files() {
		$out = array();
		foreach ( (array) glob( self::dir() . '/smart-location-*-' . self::suffix() . '.log' ) as $path ) {
			if ( preg_match( '/smart-location-(\d{4}-\d{2})-/', basename( $path ), $m ) ) {
				$out[ $m[1] ] = $path;
			}
		}
		krsort( $out );
		return $out;
	}

	private static function prune() {
		foreach ( array_slice( self::files(), self::KEEP_MONTHS, null, true ) as $path ) {
			@unlink( $path ); // phpcs:ignore
		}
	}

	public static function delete_all() {
		foreach ( self::files() as $path ) {
			@unlink( $path ); // phpcs:ignore
		}
	}

	/**
	 * Last lines of a month's log, newest first, parsed.
	 *
	 * @return array list of array( time, level, property_id, message, context )
	 */
	public static function read( $month, $limit = 1000, $level = '', $property_id = 0 ) {
		$files = self::files();
		if ( ! isset( $files[ $month ] ) ) {
			return array();
		}
		$path = $files[ $month ];
		$size = filesize( $path );
		$fh   = fopen( $path, 'r' ); // phpcs:ignore
		if ( ! $fh ) {
			return array();
		}
		$max = 4 * MB_IN_BYTES; // only look at the most recent 4MB
		if ( $size > $max ) {
			fseek( $fh, $size - $max );
			fgets( $fh ); // skip partial line
		}
		$lines = array();
		while ( false !== ( $line = fgets( $fh ) ) ) {
			$lines[] = $line;
		}
		fclose( $fh ); // phpcs:ignore

		$out = array();
		foreach ( array_reverse( $lines ) as $line ) {
			$parts = explode( ' | ', rtrim( $line, "\n" ), 5 );
			if ( count( $parts ) < 4 ) {
				continue;
			}
			$row = array(
				'time'        => $parts[0],
				'level'       => strtolower( $parts[1] ),
				'property_id' => (int) $parts[2],
				'message'     => $parts[3],
				'context'     => $parts[4] ?? '',
			);
			if ( ( $level && $row['level'] !== $level ) || ( $property_id && $row['property_id'] !== $property_id ) ) {
				continue;
			}
			$out[] = $row;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}
}
