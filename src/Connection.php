<?php
/**
 * Connecting the shop to Rewloy and keeping it connected: the key, the link
 * (createShop, setShopEnabled, deleteShop), the WooCommerce webhook, and the
 * health the screen shows.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Connection {

	/** The lock that keeps two Connect presses from each making a link and a webhook. */
	private const CONNECT_LOCK = 'rewloy_wc_claim_connecting';

	/** @var callable(string=): ?Client */
	private $client_factory;
	private Lock $lock;

	/**
	 * @param Settings                   $settings       The saved settings.
	 * @param Webhooks                   $webhooks       The WooCommerce webhook.
	 * @param callable(string=): ?Client $client_factory A client for the saved key, or for the key given; null without a key.
	 * @param Lock|null                  $lock           The lock for connecting.
	 */
	public function __construct( private Settings $settings, private Webhooks $webhooks, callable $client_factory, ?Lock $lock = null ) {
		$this->client_factory = $client_factory;
		$this->lock           = $lock ?? new Lock();
	}

	private function client( #[\SensitiveParameter] string $key = '' ): ?Client {
		return ( $this->client_factory )( $key );
	}

	/**
	 * Checks a pasted key with the API (a harmless read) and saves it only if Rewloy accepts it.
	 */
	public function save_key( #[\SensitiveParameter] string $raw ): Result {
		if ( 'constant' === $this->settings->key_source() ) {
			return Result::error( __( 'The key is set by REWLOY_API_KEY in wp-config.php; that one is used.', 'rewloy-for-woocommerce' ) );
		}
		if ( $this->settings->is_connected() ) {
			return Result::error( __( 'Remove the connection before changing the key.', 'rewloy-for-woocommerce' ) );
		}
		$key = $this->settings->sanitize_api_key( $raw );
		if ( '' === $key ) {
			return Result::error( __( 'That is not a Rewloy API key. It starts with rwk_ and is created in the Rewloy panel, under Developer.', 'rewloy-for-woocommerce' ) );
		}
		$client = $this->client( $key );
		if ( null === $client ) {
			return Result::error( __( 'That is not a Rewloy API key.', 'rewloy-for-woocommerce' ) );
		}
		try {
			$client->list_programs();
		} catch ( RewloyException $e ) {
			return Result::error( Messages::for_error( $e ) );
		}
		$this->settings->save_api_key( $key );
		return Result::ok( __( 'The key was accepted and saved. Now choose the card.', 'rewloy-for-woocommerce' ) );
	}

	/** Forgets the saved key (not the constant). Only while nothing is connected. */
	public function forget_key(): Result {
		if ( $this->settings->is_connected() ) {
			return Result::error( __( 'Remove the connection before changing the key.', 'rewloy-for-woocommerce' ) );
		}
		$this->settings->delete_api_key();
		return Result::ok( __( 'The key was removed from this site.', 'rewloy-for-woocommerce' ) );
	}

	/**
	 * The cards an order can fill: active stamp, points, VIP and cashback cards.
	 *
	 * @return list<array{id:string,name:string,type:string,join_url:string}>
	 *
	 * @throws RewloyException When Rewloy answers with an error or not at all.
	 * @throws \LogicException Without a key.
	 */
	public function programs(): array {
		$client = $this->client();
		if ( null === $client ) {
			throw new \LogicException( 'No API key.' );
		}
		$out = array();
		foreach ( $client->list_programs() as $p ) {
			$type = is_string( $p['type'] ?? null ) ? $p['type'] : '';
			if ( 'active' !== ( $p['status'] ?? 'active' ) || ! in_array( $type, Settings::LINKABLE_TYPES, true ) ) {
				continue;
			}
			$join = is_string( $p['joinUrl'] ?? null ) ? $p['joinUrl'] : '';
			$out[] = array(
				'id'       => is_string( $p['id'] ?? null ) ? $p['id'] : '',
				'name'     => is_string( $p['name'] ?? null ) ? $p['name'] : '',
				'type'     => $type,
				'join_url' => $this->settings->is_rewloy_url( $join ) ? $join : '',
			);
		}
		return $out;
	}

	/**
	 * Links the shop: createShop, then the webhook with the address and secret it returns.
	 * If the webhook cannot be made, the link is deleted again: the secret is shown once.
	 *
	 * @param array<string,mixed> $input program_id, rule, per_amount (as typed), step.
	 */
	public function connect( array $input ): Result {
		if ( $this->settings->is_connected() ) {
			return Result::error( __( 'This shop is already connected.', 'rewloy-for-woocommerce' ) );
		}
		// One connect at a time: a double click must not make two links and two webhooks (the first would be orphaned).
		if ( ! $this->lock->acquire( self::CONNECT_LOCK, 120 ) ) {
			return Result::error( __( 'A connection is already being made. Wait a moment and reload this page.', 'rewloy-for-woocommerce' ) );
		}
		try {
			if ( $this->settings->is_connected() ) {
				return Result::error( __( 'This shop is already connected.', 'rewloy-for-woocommerce' ) );
			}
			return $this->connect_locked( $input );
		} finally {
			$this->lock->release( self::CONNECT_LOCK );
		}
	}

	/**
	 * @param array<string,mixed> $input program_id, rule, per_amount (as typed), step.
	 */
	private function connect_locked( array $input ): Result {
		$client = $this->client();
		if ( null === $client ) {
			return Result::error( __( 'Save the API key first.', 'rewloy-for-woocommerce' ) );
		}
		$program_id = isset( $input['program_id'] ) && is_string( $input['program_id'] ) ? strtolower( trim( $input['program_id'] ) ) : '';
		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $program_id ) ) {
			return Result::error( __( 'Choose a card.', 'rewloy-for-woocommerce' ) );
		}
		try {
			$chosen = null;
			foreach ( $this->programs() as $p ) {
				if ( $p['id'] === $program_id ) {
					$chosen = $p;
				}
			}
		} catch ( RewloyException $e ) {
			return Result::error( Messages::for_error( $e ) );
		}
		if ( null === $chosen ) {
			return Result::error( __( 'That card was not found, or it is archived, or an order cannot fill it.', 'rewloy-for-woocommerce' ) );
		}

		// The rule applies to stamp and points cards only: a VIP card counts a visit, a cashback card uses its own rate.
		$body = array(
			'platform'  => 'woocommerce',
			'programId' => $program_id,
			'rule'      => 'order',
		);
		$rule = 'order';
		$per  = 0;
		$step = 1;
		if ( in_array( $chosen['type'], array( 'stamp', 'points' ), true ) ) {
			$rule = isset( $input['rule'] ) && 'amount' === $input['rule'] ? 'amount' : 'order';
			$step = isset( $input['step'] ) && is_scalar( $input['step'] ) ? (int) $input['step'] : 1;
			if ( $step < 1 || $step > 100 ) {
				return Result::error( __( 'How many stamps or points must be between 1 and 100.', 'rewloy-for-woocommerce' ) );
			}
			$body['rule'] = $rule;
			$body['step'] = $step;
			if ( 'amount' === $rule ) {
				$per = $this->settings->amount_to_minor( isset( $input['per_amount'] ) && is_string( $input['per_amount'] ) ? $input['per_amount'] : '' ) ?? 0;
				if ( $per <= 0 ) {
					return Result::error( __( 'Enter the amount threshold, between 1 and 100,000.', 'rewloy-for-woocommerce' ) );
				}
				$body['perAmountMinor'] = $per;
			}
		}

		try {
			$shop = $client->create_shop( $body );
		} catch ( RewloyException $e ) {
			return Result::error( Messages::for_error( $e ) );
		}

		$link_id = is_string( $shop['id'] ?? null ) ? $shop['id'] : '';
		$url     = is_string( $shop['webhookUrl'] ?? null ) ? $shop['webhookUrl'] : '';
		$secret  = is_string( $shop['secret'] ?? null ) ? $shop['secret'] : '';
		if ( '' === $secret || ! $this->is_delivery_url( $url, $link_id, $client->host() ) ) {
			$this->undo( $client, $link_id );
			return Result::error( __( 'Rewloy\'s answer was not what a shop link looks like, so nothing was connected.', 'rewloy-for-woocommerce' ) );
		}
		try {
			$webhook_id = $this->webhooks->create( $url, $secret );
		} catch ( \Throwable $e ) {
			$this->undo( $client, $link_id );
			return Result::error( __( 'WooCommerce could not create the webhook, so the link was removed again. Nothing was connected.', 'rewloy-for-woocommerce' ) );
		}

		$this->settings->update(
			array(
				'link_id'          => $link_id,
				'webhook_id'       => $webhook_id,
				'program_id'       => $program_id,
				'program_name'     => is_string( $shop['programName'] ?? null ) ? $shop['programName'] : $chosen['name'],
				'program_type'     => $chosen['type'],
				'currency'         => is_string( $shop['currency'] ?? null ) ? $shop['currency'] : '',
				'join_url'         => $chosen['join_url'],
				'rule'             => $rule,
				'per_amount_minor' => $per,
				'step'             => $step,
			)
		);
		$this->flush_account_endpoint();
		return Result::ok( __( 'Connected. Paid orders now reach Rewloy through a WooCommerce webhook this plugin created.', 'rewloy-for-woocommerce' ) );
	}

	/** Pauses or resumes the link. The webhook stays; while paused, Rewloy records the orders but does not add them. */
	public function set_paused( bool $paused ): Result {
		$link   = $this->settings->get()['link_id'];
		$client = $this->client();
		if ( '' === $link || null === $client ) {
			return Result::error( __( 'This shop is not connected.', 'rewloy-for-woocommerce' ) );
		}
		try {
			$client->set_shop_enabled( $link, ! $paused );
		} catch ( RewloyException $e ) {
			return Result::error( Messages::for_error( $e ) );
		}
		return Result::ok(
			$paused
				? __( 'The link is off. Orders that arrive meanwhile are recorded but not added to cards, now or later.', 'rewloy-for-woocommerce' )
				: __( 'The link is on again.', 'rewloy-for-woocommerce' )
		);
	}

	/**
	 * Deletes the link in Rewloy (cards keep what they already got), then the webhook, then what the plugin kept.
	 * `$local_only` forgets the connection here without asking Rewloy (the key is gone, Rewloy cannot be reached).
	 */
	public function disconnect( bool $local_only = false ): Result {
		$s    = $this->settings->get();
		$link = $s['link_id'];
		if ( '' === $link ) {
			return Result::error( __( 'This shop is not connected.', 'rewloy-for-woocommerce' ) );
		}
		if ( ! $local_only ) {
			$client = $this->client();
			if ( null === $client ) {
				return Result::error( __( 'There is no API key to ask Rewloy with. Use "Forget the connection on this site only".', 'rewloy-for-woocommerce' ) );
			}
			try {
				$client->delete_shop( $link );
			} catch ( RewloyException $e ) {
				// Already gone in Rewloy is what we wanted.
				if ( ! ( $e instanceof ApiError && 'SHOP_NOT_FOUND' === $e->api_code ) ) {
					return Result::error( Messages::for_error( $e ) );
				}
			}
		}
		$this->webhooks->delete( $s['webhook_id'] );
		$this->settings->clear_connection();
		$this->flush_account_endpoint();
		return Result::ok(
			$local_only
				? __( 'The connection was forgotten on this site. The link in Rewloy is still there; delete it in the Rewloy panel if you no longer want it.', 'rewloy-for-woocommerce' )
				: __( 'Disconnected. The link and the webhook are deleted; cards keep what they already got.', 'rewloy-for-woocommerce' )
		);
	}

	/** Turns a webhook WooCommerce disabled (after repeated failures) back on. */
	public function reactivate_webhook(): Result {
		$id = $this->settings->get()['webhook_id'];
		if ( ! $this->webhooks->reactivate( $id ) ) {
			return Result::error( __( 'The webhook no longer exists. Remove the connection and connect again.', 'rewloy-for-woocommerce' ) );
		}
		return Result::ok( __( 'The webhook is active again.', 'rewloy-for-woocommerce' ) );
	}

	/**
	 * What the screen shows when connected: the link as Rewloy has it, its last orders, the webhook.
	 *
	 * @return array{link:?array<string,mixed>,orders:list<array<string,mixed>>,error:string,webhook:array{exists:bool,status:string,failures:int,edit_url:string}}
	 */
	public function health(): array {
		$s      = $this->settings->get();
		$out    = array(
			'link'    => null,
			'orders'  => array(),
			'error'   => '',
			'webhook' => $this->webhooks->health( $s['webhook_id'] ),
		);
		$client = $this->client();
		if ( '' === $s['link_id'] || null === $client ) {
			return $out;
		}
		try {
			$out['link']   = $client->get_shop( $s['link_id'] );
			$out['orders'] = $client->list_shop_orders( $s['link_id'], 10 );
		} catch ( RewloyException $e ) {
			$out['error'] = Messages::for_error( $e );
		}
		return $out;
	}

	/**
	 * The My Account tab exists only while connected, so connecting and disconnecting change the rewrite rules
	 * when the tab is on: ask for one flush (Account::maybe_flush). Without it, a disconnect, any later flush
	 * (saving the permalinks) and a new connect would leave the tab in the menu and its address a 404.
	 */
	private function flush_account_endpoint(): void {
		if ( $this->settings->get()['account_tab'] ) {
			update_option( Plugin::FLUSH_OPTION, '1', false );
		}
	}

	/** Is this the address a WooCommerce shop link is delivered to: https on the API's own host, /hooks/store/<this link>? */
	private function is_delivery_url( string $url, string $link_id, string $api_host ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}
		$scheme = $parts['scheme'] ?? '';
		$host   = strtolower( $parts['host'] ?? '' );
		$local  = in_array( $host, array( 'localhost', '127.0.0.1' ), true );
		if ( ! ( 'https' === $scheme || ( 'http' === $scheme && $local ) ) || '' === $host || $host !== $api_host ) {
			return false;
		}
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $link_id )
			&& '/hooks/store/' . strtolower( $link_id ) === strtolower( $parts['path'] ?? '' );
	}

	/** Best effort: take back a link that could not be finished. */
	private function undo( Client $client, string $link_id ): void {
		if ( 1 !== preg_match( '/^[0-9a-f-]{36}$/i', $link_id ) ) {
			return;
		}
		try {
			$client->delete_shop( $link_id );
		} catch ( \Throwable $e ) {
			unset( $e ); // Nothing more can be done here; the panel lists the link and lets the owner delete it.
		}
	}
}
