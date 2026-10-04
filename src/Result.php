<?php
/**
 * The result of an admin action: did it work, and what to tell the person.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Result {

	public function __construct( public readonly bool $ok, public readonly string $message ) {
	}

	public static function ok( string $message ): self {
		return new self( true, $message );
	}

	public static function error( string $message ): self {
		return new self( false, $message );
	}
}
