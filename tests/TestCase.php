<?php
/**
 * The base of every test: Brain Monkey for WordPress functions, an in-memory
 * options table, and a scripted HTTP transport. NO test touches the network:
 * the Client under test is given the scripted transport.
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use Rewloy\WooCommerce\Client;
use Rewloy\WooCommerce\Settings;

abstract class TestCase extends PhpUnitTestCase {

	public const KEY = 'rwk_abcdefghijSECRETSECRETSECRET1234';
	public const LINK = '0192a3b4-5c6d-7e8f-9a0b-1c2d3e4f5a6b';
	public const PROGRAM = '0192aaaa-5c6d-7e8f-9a0b-1c2d3e4f5a6b';

	/** @var array<string,mixed> */
	protected array $options = array();
	/** @var array<string,string> option name => autoload given when it was written */
	protected array $autoload = array();
	/** @var array<string,mixed> */
	protected array $transients = array();
	/** @var list<array{url:string,args:array<string,mixed>}> */
	protected array $requests = array();
	/** @var list<array<string,mixed>|\WP_Error> */
	protected array $responses = array();
	/** @var list<array{to:string,subject:string,body:string}> */
	protected array $mails = array();
	protected \FakeWpdb $wpdb;
	protected bool $can = true;
	protected bool $mailOk = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		\WC_Order::$db     = array();
		\WC_Webhook::$db   = array();
		\WC_Webhook::$next = 40;
		\WC_Webhook::$failSave = false;

		$this->options = $this->autoload = $this->transients = $this->requests = $this->responses = $this->mails = array();
		$this->wpdb              = new \FakeWpdb();
		$this->wpdb->rows        = &$this->options;
		$GLOBALS['wpdb']         = $this->wpdb;
		$this->can     = true;
		$this->mailOk  = true;

		Functions\when( 'get_option' )->alias( fn( string $n, $d = false ) => $this->options[ $n ] ?? $d );
		Functions\when( 'update_option' )->alias(
			function ( string $n, $v, $autoload = null ): bool {
				$this->options[ $n ]  = $v;
				$this->autoload[ $n ] = var_export( $autoload, true );
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( string $n, $v = '', $d = '', $autoload = 'yes' ): bool {
				if ( array_key_exists( $n, $this->options ) ) {
					return false;
				}
				$this->options[ $n ]  = $v;
				$this->autoload[ $n ] = var_export( $autoload, true );
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $n ): bool {
				$had = array_key_exists( $n, $this->options );
				unset( $this->options[ $n ] );
				return $had;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( string $n ) => $this->transients[ $n ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $n, $v ): bool {
				$this->transients[ $n ] = $v;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $n ): bool {
				unset( $this->transients[ $n ] );
				return true;
			}
		);

		Functions\when( 'home_url' )->justReturn( 'https://shop.example.com/' );
		Functions\when( 'untrailingslashit' )->alias( fn( $s ) => rtrim( (string) $s, '/' ) );
		Functions\when( 'admin_url' )->alias( fn( string $p = '' ) => 'https://shop.example.com/wp-admin/' . $p );
		Functions\when( 'wp_parse_url' )->alias( fn( string $u, int $c = -1 ) => parse_url( $u, $c ) );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( $r ) => $r['response']['code'] ?? 0 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] ?? '' );
		Functions\when( 'wp_remote_retrieve_header' )->alias( fn( $r, string $h ) => $r['headers'][ strtolower( $h ) ] ?? '' );
		Functions\when( 'sanitize_text_field' )->alias( fn( $s ) => trim( strip_tags( (string) $s ) ) );
		Functions\when( 'sanitize_email' )->alias( fn( $s ) => trim( (string) $s ) );
		Functions\when( 'is_email' )->alias( fn( $s ) => false !== filter_var( $s, FILTER_VALIDATE_EMAIL ) ? $s : false );
		Functions\when( 'wp_unslash' )->alias( fn( $v ) => is_string( $v ) ? stripslashes( $v ) : $v );
		Functions\when( 'number_format_i18n' )->alias( fn( $n, $d = 0 ) => number_format( (float) $n, (int) $d, ',', '.' ) );
		Functions\when( 'get_bloginfo' )->justReturn( 'Örnek Mağaza' );
		Functions\when( 'wp_specialchars_decode' )->alias( fn( $s ) => (string) $s );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'current_user_can' )->alias( fn( string $cap ) => $this->can );
		Functions\when( 'wp_mail' )->alias(
			function ( string $to, string $subject, string $body ): bool {
				$this->mails[] = array(
					'to'      => $to,
					'subject' => $subject,
					'body'    => $body,
				);
				return $this->mailOk;
			}
		);
		Functions\when( 'wc_get_order' )->alias( fn( $id = false ) => \WC_Order::$db[ (int) $id ] ?? false );
		Functions\when( 'wc_get_webhook' )->alias( fn( $id ) => \WC_Webhook::$db[ (int) $id ] ?? null );
		Functions\when( 'wc_get_orders' )->alias( fn( array $args ) => $this->queryOrders( $args ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** An HTTP answer, as wp_remote_request returns it. */
	protected function answer( int $status, ?array $body = null, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => null === $body ? '' : (string) json_encode( $body ),
			'headers'  => $headers,
		);
	}

	/** An API error answer, in the API's documented shape. */
	protected function failure( int $status, string $code, array $headers = array() ): array {
		return $this->answer(
			$status,
			array(
				'error' => array(
					'code'      => $code,
					'message'   => 'Türkçe API iletisi',
					'requestId' => 'req-123',
					'status'    => $status,
				),
			),
			$headers
		);
	}

	/** Scripts the next HTTP answers, in order. When the script runs out, the test fails loudly. */
	protected function script( array|\WP_Error ...$responses ): void {
		$this->responses = array_values( $responses );
	}

	protected function transport(): callable {
		return function ( string $url, array $args ) {
			$this->requests[] = array(
				'url'  => $url,
				'args' => $args,
			);
			if ( array() === $this->responses ) {
				throw new \LogicException( 'Unscripted HTTP request: ' . ( $args['method'] ?? '?' ) . ' ' . $url );
			}
			return array_shift( $this->responses );
		};
	}

	protected function client( string $key = self::KEY, string $base = 'https://app.rewloy.com' ): Client {
		return new Client( $key, $base, $this->transport(), static function ( float $s ): void {} );
	}

	/** @return callable(string=): ?Client */
	protected function factory( ?Client $client = null ): callable {
		$client ??= $this->client();
		return static fn( string $key = '' ) => $client;
	}

	/** The saved settings, as if connected to a stamp card. */
	protected function connected( array $extra = array() ): Settings {
		$settings = new Settings();
		$settings->update(
			array_merge(
				array(
					'link_id'      => self::LINK,
					'webhook_id'   => 41,
					'program_id'   => self::PROGRAM,
					'program_name' => 'Kahve Kartı',
					'program_type' => 'stamp',
					'currency'     => 'TRY',
					'join_url'     => 'https://rewloy.com/join/' . self::PROGRAM,
					'invite'       => true,
				),
				$extra
			)
		);
		return $settings;
	}

	/** @return list<int> */
	private function queryOrders( array $args ): array {
		$out = array();
		foreach ( \WC_Order::$db as $id => $order ) {
			if ( in_array( $id, (array) ( $args['exclude'] ?? array() ), true ) ) {
				continue;
			}
			if ( isset( $args['billing_email'] ) && strtolower( $order->get_billing_email() ) !== strtolower( (string) $args['billing_email'] ) ) {
				continue;
			}
			foreach ( (array) ( $args['meta_query'] ?? array() ) as $q ) {
				if ( ! in_array( $order->get_meta( $q['key'] ), (array) $q['value'], true ) ) {
					continue 2;
				}
			}
			$out[] = $id;
		}
		return array_slice( $out, 0, (int) ( $args['limit'] ?? 10 ) );
	}
}
