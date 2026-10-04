<?php
/**
 * A small client of the Rewloy API (https://app.rewloy.com/v1) over wp_remote_request:
 * only the calls this plugin makes, with no Composer dependency.
 *
 * - Bearer key; the key is never put in a message, a log line or an exception.
 * - `Idempotency-Key` on the one call that takes one (issuePass).
 * - Errors become ApiError with the API's stable code; no answer becomes ConnectionError.
 * - Retries only where repeating is safe (GET, PUT, PATCH, DELETE). A POST is sent once.
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
	 */
	public function __construct(
		#[\SensitiveParameter] private string $api_key,
		string $base_url = self::DEFAULT_BASE_URL,
		?callable $transport = null,
		?callable $sleep = null,
		private float $timeout = self::DEFAULT_TIMEOUT
	) {
		if ( ! str_starts_with( $api_key, 'rwk_' ) ) {
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
	 * `issuePass`: opens a card for an e-mail address. NEVER retried, whatever the
	 * failure: a repeat could open a second card. The Idempotency-Key is sent as the
	 * brief and the API's convention ask; what keeps the card single is the
	 * plugin's own claim on the order (see Issuer), not this header.
	 *
	 * @param array<string,mixed> $body            programId, email, kvkkConsent.
	 * @param string              $idempotency_key 8 to 64 characters.
	 * @return array{serial:string,cardUrl:string}
	 */
	public function issue_pass( array $body, string $idempotency_key ): array {
		$data = $this->object_of( $this->call( 'POST', '/passes', array(), $body, $idempotency_key ) );
		$serial = $data['serial'] ?? null;
		$url    = $data['cardUrl'] ?? null;
		if ( ! is_string( $serial ) || ! is_string( $url ) || 1 !== preg_match( '/^[A-Za-z0-9-]{4,40}$/', $serial ) ) {
			// A 2xx without a usable card: accepted, so possibly issued.
			throw new ConnectionError( 'The answer to issuePass did not carry the card.', 200 );
		}
		return array(
			'serial'  => $serial,
			'cardUrl' => $url,
		);
	}

	/**
	 * One call, with the retry rules: a safe method gets up to Retry::MAX_RETRIES more attempts.
	 *
	 * @param array<string,scalar> $query
	 * @param array<string,mixed>|null $body
	 * @return array<string,mixed> The decoded answer (`data`, and `meta` on lists); empty for 204.
	 *
	 * @throws RewloyException On every failure.
	 */
	private function call( string $method, string $path, array $query = array(), ?array $body = null, string $idempotency_key = '' ): array {
		$url = $this->base_url . '/v1' . $path;
		if ( array() !== $query ) {
			$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}
		$headers = array(
			'Authorization' => 'Bearer ' . $this->api_key,
			'Accept'        => 'application/json',
			'User-Agent'    => 'rewloy-for-woocommerce/' . Plugin::VERSION . ' (WordPress; PHP/' . PHP_VERSION . ')',
		);
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
		$retries = Retry::safe_method( $method ) ? Retry::MAX_RETRIES : 0;

		for ( $attempt = 0; ; $attempt++ ) {
			$res = ( $this->transport )( $url, $args );
			if ( is_wp_error( $res ) ) {
				if ( $attempt < $retries ) {
					( $this->sleep )( Retry::backoff( $attempt ) );
					continue;
				}
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
					throw new ConnectionError( 'The answer was not the JSON the API documents.', $status, '', $request_id );
				}
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

	/** An id that goes into a path must be a UUID. */
	private function uuid( string $id ): string {
		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id ) ) {
			throw new \InvalidArgumentException( 'Not a Rewloy id.' );
		}
		return strtolower( $id );
	}
}
