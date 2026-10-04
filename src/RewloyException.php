<?php
/**
 * What went wrong talking to Rewloy.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * The base of every error from the API client. `$status` is the HTTP status,
 * 0 when there was no answer; `$api_code` is the API's stable error code
 * (`INVALID_API_KEY`, `PLAN_FEATURE_MISSING`…) when it sent one.
 *
 * Nothing in here ever holds the API key.
 */
class RewloyException extends \RuntimeException {

	/**
	 * @param array<string,mixed> $details The error's `details` object when the API sent one (0.4.0: e.g. the
	 *                                     redemptions of a `HOLD_UNBACKED`); never anything of the key.
	 */
	public function __construct(
		string $message,
		public readonly int $status = 0,
		public readonly string $api_code = '',
		public readonly string $request_id = '',
		public readonly float $retry_after = 0.0,
		public readonly array $details = array()
	) {
		parent::__construct( $message );
	}

	/**
	 * May the request have been carried out although we have no clean answer?
	 * No answer at all, a server error, and "the first request with this
	 * Idempotency-Key is still running" all leave that open. A call that carries
	 * an Idempotency-Key may be repeated with the same key and body (Rewloy
	 * replays the first answer); one that does not must not be.
	 */
	public function outcome_unknown(): bool {
		return 0 === $this->status || $this->status >= 500 || 'IDEMPOTENCY_IN_PROGRESS' === $this->api_code;
	}
}
