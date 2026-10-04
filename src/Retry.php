<?php
/**
 * The client's retry rules, apart so that tests can pin them. They follow
 * rewloy-php's: only a request that is safe to repeat (a GET, PUT, PATCH or
 * DELETE, or a POST that carries an Idempotency-Key) is retried, and only for
 * what another attempt can get past.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Retry {

	/**
	 * Retries after the first attempt, for a request that is safe to repeat. One, not rewloy-php's two:
	 * an admin screen waits for these calls, and two reads on a page already make four attempts.
	 */
	public const MAX_RETRIES = 1;
	/**
	 * Retries of a POST that carries an Idempotency-Key (issuePass), which runs in a background action and not
	 * under an admin page: two, as rewloy-php does.
	 */
	public const MAX_RETRIES_KEYED = 2;
	/** The first wait's ceiling, in seconds; it doubles with each attempt. */
	public const BASE = 0.5;
	/** The longest backoff, in seconds. */
	public const MAX = 4.0;
	/** A Retry-After longer than this is not waited for in an admin request: the error goes to the person. */
	public const MAX_RETRY_AFTER = 10.0;
	/** 502-504 and Cloudflare's 520-524: the origin unreachable or too slow. */
	private const GATEWAY = array( 502, 503, 504, 520, 521, 522, 523, 524 );
	/** Methods whose repetition changes nothing more than the first call did. PATCH is safe here: it only sets a value. */
	private const SAFE_METHODS = array( 'GET', 'HEAD', 'PUT', 'PATCH', 'DELETE' );

	/** Is a request of this method safe to send again whatever it is? A POST is not: it may have been carried out. */
	public static function safe_method( string $method ): bool {
		return in_array( strtoupper( $method ), self::SAFE_METHODS, true );
	}

	/**
	 * How many more attempts a request may have after the first: a safe method gets MAX_RETRIES; a POST that carries
	 * an Idempotency-Key gets MAX_RETRIES_KEYED, because Rewloy replays the first answer for the same key and body
	 * instead of doing the work twice (issuePass, since 4 Oct 2026); any other POST none.
	 */
	public static function retries_for( string $method, string $idempotency_key ): int {
		if ( '' !== $idempotency_key && 'POST' === strtoupper( $method ) ) {
			return self::MAX_RETRIES_KEYED;
		}
		return self::safe_method( $method ) ? self::MAX_RETRIES : 0;
	}

	/** The wait before the retry after attempt `$attempt` (0-based), in seconds, with jitter. */
	public static function backoff( int $attempt, ?float $random = null ): float {
		$cap     = min( self::MAX, self::BASE * ( 2 ** min( max( 0, $attempt ), 16 ) ) );
		$random ??= random_int( 0, 1_000_000 ) / 1_000_000;
		return round( $cap / 2 + $random * $cap / 2, 3 );
	}

	/** Retry-After in seconds: delta-seconds or an HTTP date; null when absent or unreadable. */
	public static function parse_retry_after( string $value, ?int $now = null ): ?float {
		$v = trim( $value );
		if ( '' === $v ) {
			return null;
		}
		if ( 1 === preg_match( '/^\d+(\.\d+)?$/', $v ) ) {
			return (float) $v;
		}
		$at = \DateTimeImmutable::createFromFormat( 'D, d M Y H:i:s \G\M\T', $v, new \DateTimeZone( 'UTC' ) );
		return false === $at ? null : (float) max( 0, $at->getTimestamp() - ( $now ?? time() ) );
	}

	/** An error answer that another attempt may get past. */
	public static function retryable_status( int $status, string $code ): bool {
		return 429 === $status || in_array( $status, self::GATEWAY, true ) || ( 409 === $status && 'IDEMPOTENCY_IN_PROGRESS' === $code );
	}
}
