<?php
/**
 * The few WordPress and WooCommerce classes the plugin touches, as plain doubles.
 * Global namespace, as the real ones are.
 */

declare(strict_types=1);

class WP_Error {
	public function __construct( private string $code = '', private string $message = '' ) {
	}

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_code(): string {
		return $this->code;
	}
}

class WooCommerce {
}

/** A WooCommerce order that keeps its meta and notes in memory. */
class WC_Order {
	/** @var array<int,WC_Order> The "database". */
	public static array $db = array();
	/** @var array<string,mixed> */
	public array $meta = array();
	/** @var list<array{note:string,customer:mixed}> */
	public array $notes = array();
	public int $saves = 0;
	public string $currency = 'TRY';
	public string $total    = '250';

	public function __construct( private int $id = 0, private string $status = 'processing', private string $billing_email = '' ) {
		self::$db[ $id ] = $this;
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_status(): string {
		return $this->status;
	}

	public function set_status( string $status ): void {
		$this->status = $status;
	}

	public function get_billing_email(): string {
		return $this->billing_email;
	}

	public function set_billing_email( string $email ): void {
		$this->billing_email = $email;
	}

	public function get_order_number(): string {
		return (string) $this->id;
	}

	public function get_currency(): string {
		return $this->currency;
	}

	public function get_total(): string {
		return $this->total;
	}

	public string $discount_total = '0';
	public string $discount_tax   = '0';

	public function get_discount_total(): string {
		return $this->discount_total;
	}

	public function get_discount_tax(): string {
		return $this->discount_tax;
	}

	public function get_meta( string $key ): mixed {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( string $key, mixed $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( string $key ): void {
		unset( $this->meta[ $key ] );
	}

	public function save_meta_data(): void {
		++$this->saves;
	}

	/** @var array<string,list<object>> Order lines by type (`coupon`, `fee`). */
	public array $items = array();

	/** @return list<object> */
	public function get_items( $type = 'line_item' ): array {
		return $this->items[ is_array( $type ) ? (string) reset( $type ) : (string) $type ] ?? array();
	}

	public function add_order_note( string $note, $customer = 0 ): int {
		$this->notes[] = array(
			'note'     => $note,
			'customer' => $customer,
		);
		return count( $this->notes );
	}
}

/** A WooCommerce webhook that records what it was given. */
class WC_Webhook {
	/** @var array<int,WC_Webhook> */
	public static array $db = array();
	public static int $next = 40;
	public static bool $failSave = false;
	/** @var array<string,mixed> */
	public array $props = array();
	public bool $deleted = false;
	public int $id = 0;
	/** @var list<mixed> What was handed to process(): WooCommerce's entry point for delivering a resource. */
	public array $processed = array();

	public function __call( string $name, array $args ): mixed {
		if ( str_starts_with( $name, 'set_' ) ) {
			$this->props[ substr( $name, 4 ) ] = $args[0];
			return null;
		}
		if ( str_starts_with( $name, 'get_' ) ) {
			return $this->props[ substr( $name, 4 ) ] ?? ( 'failure_count' === substr( $name, 4 ) ? 0 : '' );
		}
		throw new BadMethodCallException( $name );
	}

	public function save(): int {
		if ( self::$failSave ) {
			return 0;
		}
		if ( 0 === $this->id ) {
			$this->id = ++self::$next;
		}
		self::$db[ $this->id ] = $this;
		return $this->id;
	}

	public function process( mixed $arg ): mixed {
		$this->processed[] = $arg;
		return $arg;
	}

	public function delete( bool $force = false ): bool {
		$this->deleted = true;
		unset( self::$db[ $this->id ] );
		return $force;
	}
}

/**
 * The bits of $wpdb the plugin uses. `$rows` is the test's in-memory options table
 * (bound by reference), so a lock taken here is an option there.
 */
class FakeWpdb {
	public string $options = 'wp_options';
	public string $prefix  = 'wp_';
	/** @var array<string,mixed> */
	public array $rows = array();
	/** @var list<string> */
	public array $queries = array();
	/** When set, INSERT IGNORE reports a database error. */
	public bool $failInserts = false;

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( string $query, mixed ...$args ): string {
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}

	/** @return int|false */
	public function query( string $sql ) {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'INSERT IGNORE' ) ) {
			if ( $this->failInserts ) {
				return false;
			}
			preg_match( "/VALUES \\('([^']*)', '([^']*)', 'no'\\)/", $sql, $m );
			if ( array_key_exists( $m[1], $this->rows ) ) {
				return 0; // Ignored: it was there.
			}
			$this->rows[ $m[1] ] = $m[2];
			return 1;
		}
		if ( str_starts_with( $sql, 'DELETE FROM' ) && 1 === preg_match( "/`option_name` = '([^']*)' AND CAST\\(`option_value` AS UNSIGNED\\) < (\\d+)/", $sql, $m ) ) {
			if ( array_key_exists( $m[1], $this->rows ) && (int) $this->rows[ $m[1] ] < (int) $m[2] ) {
				unset( $this->rows[ $m[1] ] );
				return 1;
			}
		}
		return 0;
	}

	public function get_var( string $sql ): ?string {
		return null;
	}

	/**
	 * @param array<string,string> $where
	 * @param list<string>         $format
	 */
	public function delete( string $table, array $where, array $format = array() ): int {
		if ( isset( $where['option_name'] ) && array_key_exists( $where['option_name'], $this->rows ) ) {
			unset( $this->rows[ $where['option_name'] ] );
			return 1;
		}
		return 0;
	}
}

/** A coupon WooCommerce built (only its code matters to the plugin). */
class WC_Coupon {
	public function __construct( private string $code = '' ) {
	}

	public function get_code(): string {
		return $this->code;
	}
}

/** An order's coupon line. */
class WC_Order_Item_Coupon {
	public function __construct( private string $code = '', private string $discount = '0', private string $discount_tax = '0' ) {
	}

	public function get_code(): string {
		return $this->code;
	}

	public function get_discount(): string {
		return $this->discount;
	}

	public function get_discount_tax(): string {
		return $this->discount_tax;
	}
}

/** An order's fee line, with meta and taxes. */
class WC_Order_Item_Fee {
	/** @var array<string,mixed> */
	public array $meta = array();
	/** @var mixed */
	public $taxes = 'untouched';

	public function __construct( private string $total = '0' ) {
	}

	public function get_total(): string {
		return $this->total;
	}

	public function get_meta( string $key ): mixed {
		return $this->meta[ $key ] ?? '';
	}

	public function add_meta_data( string $key, mixed $value, bool $unique = false ): void {
		$this->meta[ $key ] = $value;
	}

	public function set_taxes( mixed $taxes ): void {
		$this->taxes = $taxes;
	}
}

/** WooCommerce's session: a key-value store per shopper. */
class FakeSession {
	/** @var array<string,mixed> */
	public array $data = array();
	public string $customer = '42';

	public function get( string $key, mixed $default = null ): mixed {
		return $this->data[ $key ] ?? $default;
	}

	public function set( string $key, mixed $value ): void {
		if ( null === $value ) {
			unset( $this->data[ $key ] );
			return;
		}
		$this->data[ $key ] = $value;
	}

	public function get_customer_id(): string {
		return $this->customer;
	}
}

/** WooCommerce's cart, as far as the plugin reads and changes it. */
class FakeCart {
	/** @var list<string> */
	public array $applied = array();
	/** @var array<string,float> What each coupon saves, tax included. */
	public array $saved = array();
	/** @var list<array<string,mixed>> */
	public array $fees = array();
	public float $subtotal = 0.0;
	public float $subtotal_tax = 0.0;
	public int $recalculated = 0;
	/** @var (callable(FakeCart): void)|null Runs on calculate_totals(). */
	public $on_calculate = null;

	/** @return list<string> */
	public function get_applied_coupons(): array {
		return $this->applied;
	}

	public function get_coupon_discount_amount( string $code, bool $ex_tax = true ): float {
		return $this->saved[ $code ] ?? 0.0;
	}

	public function get_subtotal(): float {
		return $this->subtotal;
	}

	public function get_subtotal_tax(): float {
		return $this->subtotal_tax;
	}

	public function fees_api(): self {
		return $this;
	}

	/** @param array<string,mixed> $fee */
	public function add_fee( array $fee ): void {
		$this->fees[] = $fee;
	}

	public function calculate_totals(): void {
		++$this->recalculated;
		if ( null !== $this->on_calculate ) {
			( $this->on_calculate )( $this );
		}
	}
}
