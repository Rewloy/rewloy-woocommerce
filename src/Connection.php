<?php
/**
 * Connecting the shop to Rewloy and keeping it connected, in two ways:
 *
 *  - with a CONNECT CODE (the way in): a person makes a one-time code in the Rewloy panel, the plugin spends it
 *    (`connectShop`) and gets the link, its secret and an API key bound to that link. The plugin never holds a
 *    broad key, and the person who connects needs no API key at all.
 *  - with an API KEY (advanced): a key made by hand with the E-ticaret role, pasted or set in wp-config.php;
 *    the plugin makes the link itself (`createShop`).
 *
 * Both end the same way: a link in Rewloy, a WooCommerce webhook that delivers to it, and the settings. Then the
 * link's pause (setShopEnabled) and removal (deleteShop), and the health the screen shows.
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
	/** @var callable(): Client */
	private $anonymous_factory;
	private Lock $lock;

	/**
	 * @param Settings                   $settings          The saved settings.
	 * @param Webhooks                   $webhooks          The WooCommerce webhook.
	 * @param callable(string=): ?Client $client_factory    A client for the saved key, or for the key given; null without a key.
	 * @param Lock|null                  $lock              The lock for connecting.
	 * @param (callable(): Client)|null  $anonymous_factory A client without a key, for spending a connect code.
	 */
	public function __construct( private Settings $settings, private Webhooks $webhooks, callable $client_factory, ?Lock $lock = null, ?callable $anonymous_factory = null ) {
		$this->client_factory    = $client_factory;
		$this->lock              = $lock ?? new Lock();
		$this->anonymous_factory = $anonymous_factory ?? static fn (): Client => Client::anonymous( Plugin::api_base() );
	}

	private function client( #[\SensitiveParameter] string $key = '' ): ?Client {
		return ( $this->client_factory )( $key );
	}

	/**
	 * Checks a pasted key with the API (`me`, which says who the key is and what it may do) and saves it only if
	 * Rewloy accepts it and it may do what the plugin needs.
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
			$me = $client->me();
		} catch ( RewloyException $e ) {
			return Result::error( Messages::for_error( $e ) );
		}
		$problem = $this->key_problem( $me );
		if ( null !== $problem ) {
			return Result::error( $problem );
		}
		$this->settings->save_api_key( $key );
		return Result::ok(
			$this->key_is_broad( $me )
				? __( 'The key was accepted and saved. It can do more than this plugin needs; a key made with the E-ticaret role is enough, and a connect code needs no key at all. Now choose the card.', 'rewloy-for-woocommerce' )
				: __( 'The key was accepted and saved. Now choose the card.', 'rewloy-for-woocommerce' )
		);
	}

	/** The permissions the plugin needs, as groups of which one is enough (the old name is still accepted by Rewloy). */
	private const NEEDED = array(
		array( 'programs.read' ),
		array( 'shops.read', 'settings.read' ),
		array( 'shops.manage', 'apikeys.manage' ),
		array( 'passes.issue' ),
	);

	/**
	 * Why a key the API accepted cannot be used here, or null when it can: it is bound to a shop link of its own (it
	 * cannot make another), or it lacks a permission the plugin needs.
	 *
	 * @param array<string,mixed> $me The answer of `me`.
	 */
	private function key_problem( array $me ): ?string {
		$key = is_array( $me['key'] ?? null ) ? $me['key'] : array();
		if ( 'key' !== ( $me['kind'] ?? '' ) ) {
			return __( 'That is not a Rewloy API key.', 'rewloy-for-woocommerce' );
		}
		if ( is_string( $key['shopId'] ?? null ) && '' !== $key['shopId'] ) {
			return __( 'This key belongs to a shop link of its own, so it cannot make another. Use a connect code, or a key made with the E-ticaret role.', 'rewloy-for-woocommerce' );
		}
		$have    = $this->permissions( $me );
		$missing = array();
		foreach ( self::NEEDED as $any ) {
			if ( array() === array_intersect( $any, $have ) ) {
				$missing[] = $any[0];
			}
		}
		if ( array() !== $missing ) {
			/* translators: %s: permission names, such as shops.manage. */
			return sprintf( __( 'This key is missing permissions the plugin needs: %s. The E-ticaret role in the Rewloy panel has exactly those.', 'rewloy-for-woocommerce' ), implode( ', ', $missing ) );
		}
		return null;
	}

	/**
	 * Does the key hold more than the plugin needs?
	 *
	 * @param array<string,mixed> $me The answer of `me`.
	 */
	private function key_is_broad( array $me ): bool {
		$needed = array_merge( ...self::NEEDED );
		return array() !== array_diff( $this->permissions( $me ), $needed );
	}

	/**
	 * @param array<string,mixed> $me The answer of `me`.
	 * @return list<string>
	 */
	private function permissions( array $me ): array {
		$out = array();
		foreach ( is_array( $me['permissions'] ?? null ) ? $me['permissions'] : array() as $p ) {
			if ( is_string( $p ) ) {
				$out[] = $p;
			}
		}
		return $out;
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
	 * Advanced way: links the shop with the API key: createShop, then the webhook with the address and secret it
	 * returns. If the webhook cannot be made, the link is deleted again: the secret is shown once.
	 *
	 * @param array<string,mixed> $input program_id, rule, per_amount (as typed), step.
	 */
	public function connect( array $input ): Result {
		return $this->connecting( fn(): Result => $this->connect_locked( $input ) );
	}

	/**
	 * The way in: spends a connect code made in the Rewloy panel. Rewloy answers once with the link, its secret and an
	 * API key bound to that link; the plugin makes the webhook, then keeps the key (masked, never autoloaded, never
	 * shown back) and the link. The card and the rule were chosen in the panel, when the code was made.
	 */
	public function connect_with_code( #[\SensitiveParameter] string $raw ): Result {
		return $this->connecting( fn(): Result => $this->connect_code_locked( $raw ) );
	}

	/**
	 * One connect at a time: a double click must not make two links and two webhooks (the first would be orphaned),
	 * or spend a code twice.
	 *
	 * @param callable(): Result $work The connect, run while the lock is held.
	 */
	private function connecting( callable $work ): Result {
		if ( $this->settings->is_connected() ) {
			return Result::error( __( 'This shop is already connected.', 'rewloy-for-woocommerce' ) );
		}
		if ( ! $this->lock->acquire( self::CONNECT_LOCK, 120 ) ) {
			return Result::error( __( 'A connection is already being made. Wait a moment and reload this page.', 'rewloy-for-woocommerce' ) );
		}
		try {
			if ( $this->settings->is_connected() ) {
				return Result::error( __( 'This shop is already connected.', 'rewloy-for-woocommerce' ) );
			}
			return $work();
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
			return Result::error(
				$this->undo( $client, $link_id )
					? __( 'Rewloy\'s answer was not what a shop link looks like, so nothing was connected.', 'rewloy-for-woocommerce' )
					: __( 'Rewloy\'s answer was not what a shop link looks like, so nothing was connected. A link may have been made: if one of this shop is listed under E-ticaret in the Rewloy panel, delete it there.', 'rewloy-for-woocommerce' )
			);
		}
		try {
			$webhook_id = $this->webhooks->create( $url, $secret );
		} catch ( \Throwable $e ) {
			return Result::error(
				$this->undo( $client, $link_id )
					? __( 'WooCommerce could not create the webhook, so the link was removed again. Nothing was connected.', 'rewloy-for-woocommerce' )
					: __( 'WooCommerce could not create the webhook, and the link could not be removed again. Nothing was connected; delete the link under E-ticaret in the Rewloy panel.', 'rewloy-for-woocommerce' )
			);
		}

		$this->settings->update(
			array(
				'link_id'          => $link_id,
				'via'              => Settings::VIA_KEY,
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

	/** The shop's name for the key's name in the panel: the site title, cleaned and cut to the 40 characters Rewloy takes. */
	private function shop_name(): string {
		$name = trim( sanitize_text_field( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 40 ) : substr( $name, 0, 40 );
	}

	private function connect_code_locked( #[\SensitiveParameter] string $raw ): Result {
		$source = $this->settings->key_source();
		if ( 'constant' === $source ) {
			return Result::error( __( 'REWLOY_API_KEY is set in wp-config.php, and that key would win over the one a code makes. Remove it from wp-config.php to connect with a code.', 'rewloy-for-woocommerce' ) );
		}
		if ( 'option' === $source ) {
			return Result::error( __( 'An API key is saved on this site. Remove it first to connect with a code.', 'rewloy-for-woocommerce' ) );
		}
		$code = $this->settings->sanitize_connect_code( $raw );
		if ( '' === $code ) {
			return Result::error( __( 'That is not a Rewloy connect code. It starts with rwc_ and is made in the Rewloy panel: E-ticaret › Mağaza bağla › WooCommerce › "Rewloy eklentisiyle".', 'rewloy-for-woocommerce' ) );
		}

		$anonymous = ( $this->anonymous_factory )();
		try {
			$answer = $anonymous->connect_shop( $code, $this->shop_name() );
		} catch ( RewloyException $e ) {
			// A code is spent by the first answer; with no clear answer it may or may not have been.
			return Result::error(
				$e->outcome_unknown()
					? __( 'Rewloy gave no clear answer, so the code may or may not have been spent. Try the same code once more. If Rewloy then says it is not valid, open E-ticaret in the Rewloy panel, delete this shop\'s link if one is listed, and make a new code.', 'rewloy-for-woocommerce' )
					: Messages::for_error( $e )
			);
		}

		$shop    = is_array( $answer['shop'] ?? null ) ? $answer['shop'] : array();
		$apikey  = is_array( $answer['apiKey'] ?? null ) ? $answer['apiKey'] : array();
		$link_id = is_string( $shop['id'] ?? null ) ? $shop['id'] : '';
		$url     = is_string( $shop['webhookUrl'] ?? null ) ? $shop['webhookUrl'] : '';
		$secret  = is_string( $answer['secret'] ?? null ) ? $answer['secret'] : '';
		$token   = $this->settings->sanitize_api_key( is_string( $apikey['token'] ?? null ) ? $apikey['token'] : '' );
		$client  = '' !== $token ? $this->client( $token ) : null;

		// The code is spent now, and a link and a key exist in Rewloy: if what came back cannot be used, take them back.
		if ( null === $client || '' === $secret || ! $this->is_delivery_url( $url, $link_id, $client->host() ) ) {
			return Result::error(
				null !== $client && $this->undo( $client, $link_id )
					? __( 'Rewloy\'s answer was not what a connection looks like, so nothing was connected, and what it made was removed again. The code is spent: make a new one.', 'rewloy-for-woocommerce' )
					: __( 'Rewloy\'s answer was not what a connection looks like, so nothing was connected. The code is spent, and a link may have been made: if one of this shop is listed under E-ticaret in the Rewloy panel, delete it there and make a new code.', 'rewloy-for-woocommerce' )
			);
		}
		try {
			$webhook_id = $this->webhooks->create( $url, $secret );
		} catch ( \Throwable $e ) {
			return Result::error(
				$this->undo( $client, $link_id )
					? __( 'WooCommerce could not create the webhook, so the link and its key were removed again. Nothing was connected. The code is spent: make a new one.', 'rewloy-for-woocommerce' )
					: __( 'WooCommerce could not create the webhook, and the link could not be removed again. Nothing was connected. The code is spent: delete the link under E-ticaret in the Rewloy panel and make a new code.', 'rewloy-for-woocommerce' )
			);
		}

		// The key is kept in its own option, not autoloaded; the link and what the code chose, in the settings. The
		// secret is kept nowhere but in the webhook.
		$this->settings->save_api_key( $token );
		$type = is_string( $shop['programType'] ?? null ) && in_array( $shop['programType'], Settings::LINKABLE_TYPES, true ) ? $shop['programType'] : '';
		$this->settings->update(
			array(
				'link_id'          => $link_id,
				'via'              => Settings::VIA_CODE,
				'webhook_id'       => $webhook_id,
				'program_id'       => is_string( $shop['programId'] ?? null ) ? $shop['programId'] : '',
				'program_name'     => is_string( $shop['programName'] ?? null ) ? $shop['programName'] : '',
				'program_type'     => $type,
				'currency'         => is_string( $shop['currency'] ?? null ) ? $shop['currency'] : '',
				'join_url'         => '',
				'rule'             => 'amount' === ( $shop['rule'] ?? '' ) ? 'amount' : 'order',
				'per_amount_minor' => is_numeric( $shop['perAmountMinor'] ?? null ) ? (int) $shop['perAmountMinor'] : 0,
				'step'             => is_numeric( $shop['step'] ?? null ) ? max( 1, min( 100, (int) $shop['step'] ) ) : 1,
			)
		);
		$this->flush_account_endpoint();
		$this->take_join_link();

		return Result::ok(
			$this->settings->is_test_key()
				? __( 'Connected to your Rewloy test environment. Orders reach it through a WooCommerce webhook this plugin created; nothing in it reaches your real customers, but a checkout invitation still e-mails the card link to the address typed at checkout.', 'rewloy-for-woocommerce' )
				: __( 'Connected. Paid orders now reach Rewloy through a WooCommerce webhook this plugin created, and the key this site holds can work with this shop\'s link only.', 'rewloy-for-woocommerce' )
		);
	}

	/** The card's join link, for the My Account tab: asked of Rewloy with the new key; without it the tab only omits the link. */
	private function take_join_link(): void {
		$program = $this->settings->get()['program_id'];
		try {
			foreach ( $this->programs() as $p ) {
				if ( $p['id'] === $program && '' !== $p['join_url'] ) {
					$this->settings->update( array( 'join_url' => $p['join_url'] ) );
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
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
	 *
	 * A key a connect code made belongs to its link and is revoked by Rewloy with it, so the plugin drops it too (a
	 * key made by hand stays: it was the person's own).
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
		$by_code = Settings::VIA_CODE === $s['via'];
		$this->settings->clear_connection();
		if ( $by_code && 'option' === $this->settings->key_source() ) {
			$this->settings->delete_api_key();
		}
		$this->flush_account_endpoint();
		if ( $local_only ) {
			return Result::ok(
				$by_code
					? __( 'The connection was forgotten on this site, with the key it held. The link in Rewloy is still there; delete it in the Rewloy panel if you no longer want it (that revokes the key).', 'rewloy-for-woocommerce' )
					: __( 'The connection was forgotten on this site. The link in Rewloy is still there; delete it in the Rewloy panel if you no longer want it.', 'rewloy-for-woocommerce' )
			);
		}
		return Result::ok(
			$by_code
				? __( 'Disconnected. The link and the webhook are deleted, Rewloy revoked the key with the link, and cards keep what they already got.', 'rewloy-for-woocommerce' )
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

	/** Best effort: take back a link that could not be finished. True when Rewloy confirmed the link is gone. */
	private function undo( Client $client, string $link_id ): bool {
		if ( 1 !== preg_match( '/^[0-9a-f-]{36}$/i', $link_id ) ) {
			return false;
		}
		try {
			$client->delete_shop( $link_id );
			return true;
		} catch ( \Throwable $e ) {
			unset( $e ); // The panel lists the link and lets the owner delete it; the message says so.
			return false;
		}
	}
}
