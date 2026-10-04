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

	public function __construct(
		string $message,
		public readonly int $status = 0,
		public readonly string $api_code = '',
		public readonly string $request_id = '',
		public readonly float $retry_after = 0.0
	) {
		parent::__construct( $message );
	}

	/**
	 * May the request have been carried out although we have no clean answer?
	 * No answer at all, and a server error, both leave that open: anything that
	 * must happen once is then not repeated.
	 */
	public function outcome_unknown(): bool {
		return 0 === $this->status || $this->status >= 500;
	}
}
