<?php
/**
 * Everything the plugin keeps: the API key (its own option, never autoloaded)
 * and the connection and choices (one option, no secret in it).
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Settings {

	/** The API key; not autoloaded. A REWLOY_API_KEY constant wins when defined. */
	public const KEY_OPTION = 'rewloy_wc_api_key';
	/** The connection and choices; holds no secret. */
	public const OPTION = 'rewloy_wc_settings';
	/** One row per order, and per e-mail address, being invited: a lock that expires, so two processes do not both mail the link. */
	public const CLAIM_PREFIX = 'rewloy_wc_claim_';

	/** How the shop was connected: with a connect code (the plugin holds a key bound to its link) or with an API key. */
	public const VIA_CODE = 'code';
	public const VIA_KEY  = 'key';

	/** The shape of a connect code the Rewloy panel makes: `rwc_` and 43 URL-safe characters. */
	private const CODE_PATTERN = '/^rwc_[A-Za-z0-9_-]{43}$/';

	/** Programme types a shop can fill (a visit, stamps, points or cashback). */
	public const LINKABLE_TYPES = array( 'stamp', 'points', 'vip', 'cashback' );

	/**
	 * @return array{link_id:string,via:string,webhook_id:int,program_id:string,program_name:string,program_type:string,currency:string,join_url:string,rule:string,per_amount_minor:int,step:int,invite:bool,account_tab:bool,controller_name:string,controller_email:string}
	 */
	public function defaults(): array {
		return array(
			'link_id'          => '',
			'via'              => '',
			'webhook_id'       => 0,
			'program_id'       => '',
			'program_name'     => '',
			'program_type'     => '',
			'currency'         => '',
			'join_url'         => '',
			'rule'             => 'order',
			'per_amount_minor' => 0,
			'step'             => 1,
			'invite'           => false,
			'account_tab'      => false,
			'controller_name'  => '',
			'controller_email' => '',
		);
	}

	/**
	 * The saved settings over their defaults, each value of its own type.
	 *
	 * @return array{link_id:string,via:string,webhook_id:int,program_id:string,program_name:string,program_type:string,currency:string,join_url:string,rule:string,per_amount_minor:int,step:int,invite:bool,account_tab:bool,controller_name:string,controller_email:string}
	 */
	public function get(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$d     = $this->defaults();
		return array(
			'link_id'          => is_string( $saved['link_id'] ?? null ) ? $saved['link_id'] : $d['link_id'],
			'via'              => in_array( $saved['via'] ?? '', array( self::VIA_CODE, self::VIA_KEY ), true ) ? (string) $saved['via'] : $d['via'],
			'webhook_id'       => (int) ( $saved['webhook_id'] ?? $d['webhook_id'] ),
			'program_id'       => is_string( $saved['program_id'] ?? null ) ? $saved['program_id'] : $d['program_id'],
			'program_name'     => is_string( $saved['program_name'] ?? null ) ? $saved['program_name'] : $d['program_name'],
			'program_type'     => is_string( $saved['program_type'] ?? null ) ? $saved['program_type'] : $d['program_type'],
			'currency'         => is_string( $saved['currency'] ?? null ) ? $saved['currency'] : $d['currency'],
			'join_url'         => is_string( $saved['join_url'] ?? null ) ? $saved['join_url'] : $d['join_url'],
			'rule'             => 'amount' === ( $saved['rule'] ?? '' ) ? 'amount' : 'order',
			'per_amount_minor' => (int) ( $saved['per_amount_minor'] ?? $d['per_amount_minor'] ),
			'step'             => max( 1, min( 100, (int) ( $saved['step'] ?? $d['step'] ) ) ),
			'invite'           => ! empty( $saved['invite'] ),
			'account_tab'      => ! empty( $saved['account_tab'] ),
			'controller_name'  => is_string( $saved['controller_name'] ?? null ) ? $saved['controller_name'] : $d['controller_name'],
			'controller_email' => is_string( $saved['controller_email'] ?? null ) ? $saved['controller_email'] : $d['controller_email'],
		);
	}

	/**
	 * Merges known keys into the saved settings. Unknown keys are dropped.
	 *
	 * @param array<string,mixed> $changes What to change.
	 */
	public function update( array $changes ): void {
		$merged = array_merge( $this->get(), array_intersect_key( $changes, $this->defaults() ) );
		update_option( self::OPTION, $merged, false );
	}

	/** The connection's keys, back to nothing; choices (invitation, tab, controller) stay. */
	public function clear_connection(): void {
		$this->update(
			array(
				'link_id'          => '',
				'via'              => '',
				'webhook_id'       => 0,
				'program_id'       => '',
				'program_name'     => '',
				'program_type'     => '',
				'currency'         => '',
				'join_url'         => '',
				'rule'             => 'order',
				'per_amount_minor' => 0,
				'step'             => 1,
				'invite'           => false,
			)
		);
	}

	/** @phpstan-impure It reads the database, which another process may change. */
	public function is_connected(): bool {
		return '' !== $this->get()['link_id'];
	}

	/** The key the plugin sends: the wp-config.php constant when there is one, else the saved key. */
	public function api_key(): string {
		if ( defined( 'REWLOY_API_KEY' ) && is_string( REWLOY_API_KEY ) && '' !== trim( REWLOY_API_KEY ) ) {
			return trim( REWLOY_API_KEY );
		}
		$saved = get_option( self::KEY_OPTION, '' );
		return is_string( $saved ) ? $saved : '';
	}

	/** @return 'constant'|'option'|'none' */
	public function key_source(): string {
		if ( defined( 'REWLOY_API_KEY' ) && is_string( REWLOY_API_KEY ) && '' !== trim( REWLOY_API_KEY ) ) {
			return 'constant';
		}
		$saved = get_option( self::KEY_OPTION, '' );
		return is_string( $saved ) && '' !== $saved ? 'option' : 'none';
	}

	/** Saves the key in its own option, which WordPress does not load on every request. */
	public function save_api_key( #[\SensitiveParameter] string $key ): void {
		update_option( self::KEY_OPTION, $key, false );
	}

	public function delete_api_key(): void {
		delete_option( self::KEY_OPTION );
	}

	/**
	 * A key as a person typed or pasted it: trimmed, and only if it has the shape
	 * of an API key (`rwk_` and then letters, digits, `_`, `-`). Anything else is ''.
	 */
	public function sanitize_api_key( #[\SensitiveParameter] string $raw ): string {
		$key = trim( $raw );
		return 1 === preg_match( '/^rwk_[A-Za-z0-9_-]{10,200}$/', $key ) ? $key : '';
	}

	/**
	 * What the screen may show of a key: `rwk_` (or `rwk_test_`) and the 10 characters after it,
	 * which Rewloy itself prints in its lists because they are not secret, then dots.
	 * Never the rest. A key too short to have such a prefix shows only dots.
	 */
	public function mask( #[\SensitiveParameter] string $key ): string {
		if ( '' === $key ) {
			return '';
		}
		$head = self::key_head( $key );
		// The prefix only for a key long enough that it is a small part of it.
		$shown = strlen( $key ) >= 32 && str_starts_with( $key, 'rwk_' ) ? substr( $key, 0, strlen( $head ) + 10 ) : 'rwk_';
		return $shown . '••••••••';
	}

	/** A test environment's key starts `rwk_test_`, a business's own `rwk_` (Rewloy's key format). */
	private static function key_head( string $key ): string {
		return str_starts_with( $key, 'rwk_test_' ) ? 'rwk_test_' : 'rwk_';
	}

	/** Does the key in use belong to Rewloy's test environment? Nothing in it reaches real customers. */
	public function is_test_key(): bool {
		return str_starts_with( $this->api_key(), 'rwk_test_' );
	}

	/**
	 * A connect code as a person pasted it: trimmed, and only if it has the shape the panel makes (`rwc_` and 43
	 * URL-safe characters). Anything else is ''.
	 */
	public function sanitize_connect_code( #[\SensitiveParameter] string $raw ): string {
		$code = trim( $raw );
		return 1 === preg_match( self::CODE_PATTERN, $code ) ? $code : '';
	}

	/**
	 * A short, stable id of this site for idempotency keys: 10 hex characters of
	 * the home URL's hash (the key stays under the API's 64-character limit).
	 */
	public function site_id(): string {
		return substr( sha1( strtolower( untrailingslashit( (string) home_url() ) ) ), 0, 10 );
	}

	/**
	 * A price as typed ("100", "99,50", "1250.00") to kuruş, or null when it is not
	 * an amount between 1.00 and 100000.00 (the API's range).
	 */
	public function amount_to_minor( string $raw ): ?int {
		$raw = trim( str_replace( ' ', '', $raw ) );
		if ( 1 !== preg_match( '/^(\d{1,6})(?:[.,](\d{1,2}))?$/', $raw, $m ) ) {
			return null;
		}
		$minor = (int) $m[1] * 100 + (int) str_pad( $m[2] ?? '0', 2, '0' );
		return $minor >= 100 && $minor <= 10_000_000 ? $minor : null;
	}

	/**
	 * The two choices and the controller's name, from a posted form.
	 *
	 * @param array<string,mixed> $raw Unslashed form data.
	 * @return array{invite:bool,account_tab:bool,controller_name:string,controller_email:string}
	 */
	public function sanitize_options( array $raw ): array {
		$name  = isset( $raw['controller_name'] ) && is_string( $raw['controller_name'] ) ? sanitize_text_field( $raw['controller_name'] ) : '';
		$email = isset( $raw['controller_email'] ) && is_string( $raw['controller_email'] ) ? sanitize_email( $raw['controller_email'] ) : '';
		return array(
			'invite'           => ! empty( $raw['invite'] ),
			'account_tab'      => ! empty( $raw['account_tab'] ),
			'controller_name'  => function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 120 ) : substr( $name, 0, 120 ),
			'controller_email' => is_email( $email ) ? substr( $email, 0, 254 ) : '',
		);
	}

	/**
	 * Is this a Rewloy link the plugin may print or mail (a join link, a card link)?
	 * https on rewloy.com or one of its subdomains, nothing else.
	 */
	public function is_rewloy_url( string $url ): bool {
		// Browsers read a backslash as a slash, PHP's parser does not: refuse anything that is not plain.
		if ( 1 === preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
			return false;
		}
		return 1 === preg_match( '/^(?:[a-z0-9-]+\.)*rewloy\.com$/', strtolower( $parts['host'] ?? '' ) );
	}
}
