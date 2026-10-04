<?php
/**
 * A small client of the Rewloy API (https://app.rewloy.com/v1) over wp_remote_request:
 * only the calls this plugin makes, with no Composer dependency.
 *
 * - Bearer key; the key is never put in a message, a log line or an exception.
 * - `Idempotency-Key` on the one call that takes one (issuePass), which Rewloy replays for the same key and body.
 * - One call needs no key at all: the connect code's (`connectShop`: the code is the credential).
 * - Errors become ApiError with the API's stable code; no answer becomes ConnectionError.
 * - Retries only where repeating is safe: GET, PUT, PATCH, DELETE, and a POST that carries an Idempotency-Key.
 *   Any other POST is sent once.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Client {

	public const DEFAULT_BASE_URL = 'https://app.rewloy.com';
	/** Seconds one attempt may take. Admin screens wait for it, so it is short. */
	public const DEFAULT_TIMEOUT = 10.0;
	/** The largest answer read, in bytes: every answer this plugin reads is small. */
	public const MAX_RESPONSE = 1_048_576;

	private string $base_url;
	/** @var callable(string, array<string,mixed>): (array<string,mixed>|\WP_Error) */
	private $transport;
	/** @var callable(float): void */
	private $sleep;

	/**
	 * @param string                                                                          $api_key   An API key, `rwk_…`.
	 * @param string                                                                          $base_url  The API's origin, without `/v1`.
	 * @param (callable(string, array<string,mixed>): (array<string,mixed>|\WP_Error))|null $transport How to talk HTTP; wp_remote_request when null.
	 * @param (callable(float): void)|null                                                    $sleep     Replaces the wait between retries (tests).
	 * @param float                                                                           $timeout   Seconds one attempt may take.
	 * @param bool                                                                            $anonymous True for a client without a key (see anonymous()); then `$api_key` must be empty.
	 */
	public function __construct(
		#[\SensitiveParameter] private string $api_key,
		string $base_url = self::DEFAULT_BASE_URL,
		?callable $transport = null,
		?callable $sleep = null,
		private float $timeout = self::DEFAULT_TIMEOUT,
		bool $anonymous = false
	) {
		if ( $anonymous ? '' !== $api_key : ! str_starts_with( $api_key, 'rwk_' ) ) {
			throw new \InvalidArgumentException( 'A Rewloy API key starts with rwk_.' );
		}
		$base_url = rtrim( $base_url, '/' );
		$parts    = wp_parse_url( $base_url );
		$host     = is_array( $parts ) ? strtolower( $parts['host'] ?? '' ) : '';
		$local    = in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true );
		if ( ! is_array( $parts ) || '' === $host || ! ( 'https' === ( $parts['scheme'] ?? '' ) || ( 'http' === ( $parts['scheme'] ?? '' ) && $local ) ) ) {
			throw new \InvalidArgumentException( 'The API address must be an https origin.' );
		}
		$this->base_url  = $base_url;
		$this->transport = $transport ?? static fn ( string $url, array $args ) => wp_remote_request( $url, $args );
		$this->sleep     = $sleep ?? static function ( float $seconds ): void {
			if ( $seconds > 0 ) {
				usleep( (int) round( $seconds * 1_000_000 ) );
			}
		};
	}

	/**
	 * A client without a key, for the one call that needs none: connecting with a code. Every other call refuses to
	 * go out without a key.
	 *
	 * @param (callable(string, array<string,mixed>): (array<string,mixed>|\WP_Error))|null $transport How to talk HTTP.
	 * @param (callable(float): void)|null                                                    $sleep     Replaces the wait between retries (tests).
	 */
	public static function anonymous( string $base_url = self::DEFAULT_BASE_URL, ?callable $transport = null, ?callable $sleep = null, float $timeout = self::DEFAULT_TIMEOUT ): self {
		return new self( '', $base_url, $transport, $sleep, $timeout, true );
	}

	/** What var_dump() and print_r() show: everything but the key. */
	public function __debugInfo(): array {
		return array(
			'base_url' => $this->base_url,
			'timeout'  => $this->timeout,
		);
	}

	/** The API's host, which a webhook delivery address returned by the API must share. */
	public function host(): string {
		$host = wp_parse_url( $this->base_url, PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * The active programmes of the business that owns the key.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function list_programs(): array {
		return $this->list_of( $this->call( 'GET', '/programs', array( 'status' => 'active' ) ) );
	}

	/**
	 * `me`: who the key is. For an API key: its business, role, permissions, mode, and the shop link it is bound to
	 * (`key.shopId`, null for a key made by hand).
	 *
	 * @return array<string,mixed>
	 */
	public function me(): array {
		return $this->object_of( $this->call( 'GET', '/me' ) );
	}

	/**
	 * `connectShop`: spends a connect code (made in the Rewloy panel) and returns, once, the shop link (`shop`), its
	 * secret (`secret`) and an API key bound to that link (`apiKey.token`). No credential goes with it: the code is
	 * the credential. Never retried: a spent code answers 404 the second time, and the answer is shown only once.
	 *
	 * @param string $code      The code, `rwc_…`.
	 * @param string $shop_name The shop's name, which names the key in the Rewloy panel ('' to send none).
	 * @return array<string,mixed>
	 */
	public function connect_shop( #[\SensitiveParameter] string $code, string $shop_name = '' ): array {
		$body = array( 'token' => $code );
		if ( '' !== $shop_name ) {
			$body['shopName'] = $shop_name;
		}
		return $this->object_of( $this->call( 'POST', '/shops/connect', array(), $body, '', false ) );
	}

	/**
	 * `createShop`: links a shop. Not retried: it creates something, and only 5 are allowed.
	 *
	 * @param array<string,mixed> $body The platform, programme and rule.
	 * @return array<string,mixed> The link, with `webhookUrl` and (WooCommerce) `secret`, shown once.
	 */
	public function create_shop( array $body ): array {
		return $this->object_of( $this->call( 'POST', '/shops', array(), $body ) );
	}

	/** @return array<string,mixed> */
	public function get_shop( string $id ): array {
		return $this->object_of( $this->call( 'GET', '/shops/' . $this->uuid( $id ) ) );
	}

	/**
	 * The last recorded orders of a link: number, outcome, time. No personal data.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function list_shop_orders( string $id, int $limit = 10 ): array {
		return $this->list_of( $this->call( 'GET', '/shops/' . $this->uuid( $id ) . '/orders', array( 'limit' => max( 1, min( 100, $limit ) ) ) ) );
	}

	/** @return array<string,mixed> */
	public function set_shop_enabled( string $id, bool $enabled ): array {
		return $this->object_of( $this->call( 'PATCH', '/shops/' . $this->uuid( $id ), array(), array( 'enabled' => $enabled ) ) );
	}

	public function delete_shop( string $id ): void {
		$this->call( 'DELETE', '/shops/' . $this->uuid( $id ) );
	}

	/**
	 * `issuePass`: opens a card for an e-mail address, for an order. With an Idempotency-Key Rewloy replays the first
	 * answer (`Idempotent-Replayed: true`) for the same key and the same body, and refuses the same key with another
	 * body (422 IDEMPOTENCY_KEY_REUSED), so a repeat is safe and is retried here, up to Retry::MAX_RETRIES_KEYED times.
	 *
	 * @param array<string,mixed> $body            programId, email, kvkkConsent, orderId, shopId.
	 * @param string              $idempotency_key 8 to 64 characters.
	 * @return array{serial:string,cardUrl:string,replayed:bool,order_result:string} `order_result` is Rewloy's
	 *         `order.result` (`waiting`, `resend`, `recorded`), or '' when the answer named no order.
	 */
	public function issue_pass( array $body, string $idempotency_key ): array {
		$meta   = array();
		$data   = $this->object_of( $this->call( 'POST', '/passes', array(), $body, $idempotency_key, true, $meta ) );
		$serial = $data['serial'] ?? null;
		$url    = $data['cardUrl'] ?? null;
		if ( ! is_string( $serial ) || ! is_string( $url ) || 1 !== preg_match( '/^[A-Za-z0-9-]{4,40}$/', $serial ) ) {
			// A 2xx without a usable card: accepted, so possibly issued.
			throw new ConnectionError( 'The answer to issuePass did not carry the card.', 200 );
		}
		$order  = is_array( $data['order'] ?? null ) ? $data['order'] : array();
		$result = is_string( $order['result'] ?? null ) && in_array( $order['result'], array( 'waiting', 'resend', 'recorded' ), true ) ? $order['result'] : '';
		return array(
			'serial'       => $serial,
			'cardUrl'      => $url,
			'replayed'     => true === ( $meta['replayed'] ?? false ),
			'order_result' => $result,
		);
	}

	/**
	 * `getProgram`: one card programme (name, type, its saved `sale` rule).
	 *
	 * @return array<string,mixed>
	 */
	public function get_program( string $id ): array {
		return $this->object_of( $this->call( 'GET', '/programs/' . $this->uuid( $id ) ) );
	}

	/**
	 * `getPass`: a card's state (status, balance, progress, reward readiness) and what it accepts (`actions`, `sale`).
	 * Needs `passes.read` ("Görüntüleme"). No personal data.
	 *
	 * @return array<string,mixed>
	 */
	public function get_pass( string $serial ): array {
		return $this->object_of( $this->call( 'GET', '/passes/' . $this->serial( $serial ) ) );
	}

	/**
	 * `getPassTill`: the till's rules for a card at one branch: may it be used here, the branches it is valid at, the
	 * running till promotion and the notices the cashier sees. Needs `scan.use` at that branch ("Kasa").
	 *
	 * @return array<string,mixed>
	 */
	public function get_pass_till( string $serial, string $location_id ): array {
		return $this->object_of( $this->call( 'GET', '/passes/' . $this->serial( $serial ) . '/till', array( 'locationId' => $this->uuid( $location_id ) ) ) );
	}

	/**
	 * `recordSale`: a paid total written to the card by its type. The Idempotency-Key is required: one per button press,
	 * the same on a retry of that press, so a repeat never writes twice. The receipt number goes in `reference`, never
	 * in the key.
	 *
	 * @param int    $amount_minor    The paid total in the business's currency, in minor units (0 to 10,000,000).
	 * @param string $reference       A receipt or order number ('' for none); at most 80 characters.
	 * @param string $idempotency_key 8 to 64 characters.
	 * @return array<string,mixed>
	 */
	public function record_sale( string $serial, string $location_id, int $amount_minor, string $reference, string $idempotency_key ): array {
		$body = array(
			'locationId'  => $this->uuid( $location_id ),
			'amountMinor' => max( 0, min( 10_000_000, $amount_minor ) ),
		);
		if ( '' !== $reference ) {
			$body['reference'] = function_exists( 'mb_substr' ) ? mb_substr( $reference, 0, 80 ) : substr( $reference, 0, 80 );
		}
		return $this->object_of( $this->call( 'POST', '/passes/' . $this->serial( $serial ) . '/sale', array(), $body, $this->idempotency( $idempotency_key ) ) );
	}

	/**
	 * `passAction`: one of the card's own till operations (redeem a reward, spend a balance, use a coupon…), with the
	 * Idempotency-Key of the button press.
	 *
	 * @param array<string,mixed> $body `action`, `locationId` and the fields the action needs.
	 * @return array<string,mixed>
	 */
	public function pass_action( string $serial, array $body, string $idempotency_key ): array {
		return $this->object_of( $this->call( 'POST', '/passes/' . $this->serial( $serial ) . '/actions', array(), $body, $this->idempotency( $idempotency_key ) ) );
	}

	/**
	 * `listActivity`: the latest happenings on one programme's cards, newest first. Needs `analytics.read`; for this
	 * plugin's key Rewloy sends no personal data (a teammate is "ekip üyesi", no person id).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function list_activity( string $program_id, int $limit = 20 ): array {
		return $this->list_of( $this->call( 'GET', '/activity', array( 'programId' => $this->uuid( $program_id ), 'limit' => max( 1, min( 100, $limit ) ) ) ) );
	}

	/**
	 * `getAnalytics` for one programme over 7, 30 or 90 days: open cards, cards given, visits, rewards used.
	 *
	 * @return array<string,mixed>
	 */
	public function analytics( string $program_id, int $days = 30 ): array {
		return $this->object_of( $this->call( 'GET', '/analytics', array( 'programId' => $this->uuid( $program_id ), 'days' => in_array( $days, array( 7, 30, 90 ), true ) ? $days : 30 ) ) );
	}

	/**
	 * One call, with the retry rules (see Retry::retries_for).
	 *
	 * @param array<string,scalar>     $query
	 * @param array<string,mixed>|null $body
	 * @param bool                     $auth Whether the key goes with it; false only for the connect code's call.
	 * @param array<string,mixed>      $meta Filled on success: `replayed` is true when Rewloy replayed an earlier answer.
	 * @return array<string,mixed> The decoded answer (`data`, and `meta` on lists); empty for 204.
	 *
	 * @throws RewloyException On every failure.
	 * @throws \LogicException For a call that needs a key, on a client that has none.
	 */
	private function call( string $method, string $path, array $query = array(), ?array $body = null, string $idempotency_key = '', bool $auth = true, array &$meta = array() ): array {
		if ( $auth && '' === $this->api_key ) {
			throw new \LogicException( 'This client has no API key.' );
		}
		$url = $this->base_url . '/v1' . $path;
		if ( array() !== $query ) {
			$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}
		$headers = array(
			'Accept'     => 'application/json',
			'User-Agent' => 'rewloy-for-woocommerce/' . Plugin::VERSION . ' (WordPress; PHP/' . PHP_VERSION . ')',
		);
		if ( $auth ) {
			$headers = array( 'Authorization' => 'Bearer ' . $this->api_key ) + $headers;
		}
		$json    = null;
		if ( null !== $body ) {
			$json                    = (string) wp_json_encode( $body );
			$headers['Content-Type'] = 'application/json';
		}
		if ( '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}
		$args = array(
			'method'      => $method,
			'timeout'     => $this->timeout,
			'redirection' => 0, // The key must never follow a redirect to another host.
			'headers'     => $headers,
			'limit_response_size' => self::MAX_RESPONSE,
		);
		if ( null !== $json ) {
			$args['body'] = $json;
		}
		$retries = Retry::retries_for( $method, $idempotency_key );

		for ( $attempt = 0; ; $attempt++ ) {
			$res = ( $this->transport )( $url, $args );
			if ( is_wp_error( $res ) ) {
				if ( $attempt < $retries ) {
					( $this->sleep )( Retry::backoff( $attempt ) );
					continue;
				}
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an exception's text is never printed raw: Admin and the order notes escape it where they show it.
				throw new ConnectionError( 'Rewloy did not answer: ' . $res->get_error_message() );
			}
			$status     = (int) wp_remote_retrieve_response_code( $res );
			$request_id = $this->header( $res, 'x-request-id' );
			$raw        = (string) wp_remote_retrieve_body( $res );

			if ( $status >= 200 && $status < 300 ) {
				if ( 204 === $status || '' === trim( $raw ) ) {
					return array();
				}
				$decoded = json_decode( $raw, true );
				if ( ! is_array( $decoded ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an exception's text is never printed raw: Admin and the order notes escape it where they show it.
					throw new ConnectionError( 'The answer was not the JSON the API documents.', $status, '', $request_id );
				}
				$meta['replayed'] = 'true' === strtolower( $this->header( $res, 'idempotent-replayed' ) );
				return $decoded;
			}

			$decoded = json_decode( $raw, true );
			$error   = is_array( $decoded ) && is_array( $decoded['error'] ?? null ) ? $decoded['error'] : array();
			$code    = is_string( $error['code'] ?? null ) ? $error['code'] : '';
			$message = is_string( $error['message'] ?? null ) ? $error['message'] : 'HTTP ' . $status;
			$request = is_string( $error['requestId'] ?? null ) ? $error['requestId'] : $request_id;
			$wait    = Retry::parse_retry_after( $this->header( $res, 'retry-after' ) );

			if ( $attempt < $retries && Retry::retryable_status( $status, $code ) ) {
				$delay = $wait ?? Retry::backoff( $attempt );
				if ( $delay <= Retry::MAX_RETRY_AFTER ) {
					( $this->sleep )( $delay );
					continue;
				}
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an exception's text is never printed raw: Admin and the order notes escape it where they show it.
			throw new ApiError( $message, $status, $code, $request, $wait ?? 0.0 );
		}
	}

	/**
	 * @param array<string,mixed> $answer
	 * @return array<string,mixed>
	 */
	private function object_of( array $answer ): array {
		$data = $answer['data'] ?? null;
		if ( ! is_array( $data ) ) {
			throw new ConnectionError( 'The answer had no data.', 200 );
		}
		/** @var array<string,mixed> $data */
		return $data;
	}

	/**
	 * @param array<string,mixed> $answer
	 * @return list<array<string,mixed>>
	 */
	private function list_of( array $answer ): array {
		$data = $answer['data'] ?? null;
		if ( ! is_array( $data ) ) {
			throw new ConnectionError( 'The answer had no data.', 200 );
		}
		$rows = array();
		foreach ( $data as $row ) {
			if ( is_array( $row ) ) {
				/** @var array<string,mixed> $row */
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * One response header as a string ('' when absent or repeated).
	 *
	 * @param array<string,mixed> $res The response.
	 */
	private function header( array $res, string $name ): string {
		$value = wp_remote_retrieve_header( $res, $name );
		return is_string( $value ) ? $value : '';
	}

	/** A card number that goes into a path: Rewloy's `XXXX-XXXX-XXXX`. */
	private function serial( string $serial ): string {
		$s = Serial::normalize( $serial );
		if ( '' === $s ) {
			throw new \InvalidArgumentException( 'Not a Rewloy card number.' );
		}
		return $s;
	}

	/** An Idempotency-Key as Rewloy takes it: 8 to 64 letters, digits, `-`, `_`, `.` or `:`. */
	private function idempotency( string $key ): string {
		if ( 1 !== preg_match( '/^[A-Za-z0-9._:-]{8,64}$/', $key ) ) {
			throw new \InvalidArgumentException( 'An Idempotency-Key is 8 to 64 characters.' );
		}
		return $key;
	}

	/** An id that goes into a path must be a UUID. */
	private function uuid( string $id ): string {
		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id ) ) {
			throw new \InvalidArgumentException( 'Not a Rewloy id.' );
		}
		return strtolower( $id );
	}
}
