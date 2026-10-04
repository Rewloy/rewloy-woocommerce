<?php
/**
 * An answer from the API that says no.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

/** A non-2xx answer: the API's error code, its status and the request id support asks for. */
final class ApiError extends RewloyException {
}
