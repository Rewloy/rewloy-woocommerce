<?php
/**
 * WooCommerce's FeaturesUtil, recording what the plugin declares.
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Utilities;

class FeaturesUtil {
	/** @var list<array{0:string,1:string,2:bool}> */
	public static array $declared = array();

	public static function declare_compatibility( string $feature, string $file, bool $compatible = true ): bool {
		self::$declared[] = array( $feature, $file, $compatible );
		return true;
	}
}
