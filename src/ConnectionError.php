<?php
/**
 * No usable answer from the API.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

/** The network failed, the call timed out, or a 2xx answer was not the JSON the API documents. */
final class ConnectionError extends RewloyException {

	/** With no usable answer the request may or may not have been carried out. */
	public function outcome_unknown(): bool {
		return true;
	}
}
