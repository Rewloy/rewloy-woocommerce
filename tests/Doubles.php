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

	public function get_order_number(): string {
		return (string) $this->id;
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

	public function delete( bool $force = false ): bool {
		$this->deleted = true;
		unset( self::$db[ $this->id ] );
		return $force;
	}
}

/** The bits of $wpdb the uninstaller uses. */
class FakeWpdb {
	public string $options = 'wp_options';
	public string $prefix  = 'wp_';
	/** @var list<string> */
	public array $queries = array();

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( string $query, mixed ...$args ): string {
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}

	public function query( string $sql ): int {
		$this->queries[] = $sql;
		return 0;
	}

	public function get_var( string $sql ): ?string {
		return null;
	}

	public function delete( string $table, array $where, array $format = array() ): int {
		return 0;
	}
}
