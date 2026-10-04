<?php
/**
 * Opens the invited customer's card: `issuePass`, for one order.
 *
 * Rewloy now keeps an order from opening two cards, so the plugin no longer carries a lock for good:
 *
 *  1. Every request carries the order's own `Idempotency-Key` (`woo-<site>-<order id>`), its `orderId` and the
 *     link's `shopId`. For the same key and the same body Rewloy replays the first answer (`Idempotent-Replayed:
 *     true`) for seven days, and refuses the same key with another body (422). So an unclear answer is simply asked
 *     again, with the same key: the Client retries it, a scheduled action retries it later (a few times, over
 *     hours), and the order screen offers it by hand. It cannot open a second card.
 *  2. What makes a repeat safe is the same body and the same key. So the first attempt writes its fingerprint (the
 *     body and the key's own hash) and its time to the order; a repeat that would send something else (the e-mail
 *     was edited, the connection was replaced), or that comes after six days, is NOT sent: it could be answered with
 *     a second card, and the note says to look in the panel.
 *  3. The answer says what became of the order (`order.result`). `resend`: the order's webhook had reached Rewloy
 *     before the card existed and was recorded "Kartı yok"; the card has reopened it, and the order is delivered
 *     again through its WooCommerce webhook (within seven days) so that it counts. That closes the race 0.1 had.
 *  4. What the plugin still keeps, because Rewloy does not do it: (a) a short lock per order and per e-mail address
 *     that EXPIRES (two processes must not both mail the link, nor two orders of one address both invite it); (b) the
 *     state on the order (issued, unknown, failed, exists), the first thing every run reads; (c) the rule of one card
 *     per e-mail per shop, from the orders' states, since Rewloy opens a card for every call with a new key.
 *
 * The card link (it carries the customer's private viewing key) is mailed to the customer and never written to the
 * order, an order note or a log.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Issuer {

	/** The Action Scheduler hook that runs the issue off the payment request. */
	public const HOOK = 'rewloy_wc_issue_card';
	public const GROUP = 'rewloy-for-woocommerce';

	public const META_INVITE = '_rewloy_invite';
	/** The block checkout's field, as WooCommerce stores an additional checkout field. */
	public const META_INVITE_BLOCKS = '_wc_other/rewloy-for-woocommerce/invite';
	public const META_STATE  = '_rewloy_card_state';
	public const META_SERIAL = '_rewloy_card_serial';
	public const META_CODE   = '_rewloy_card_code';
	/** The first request's record: `at` (when), `n` (how many times it was sent), `sig` (its fingerprint), `next` (earliest repeat). */
	public const META_TRY    = '_rewloy_card_try';

	public const STATE_ISSUED  = 'issued';
	public const STATE_UNKNOWN = 'unknown';
	public const STATE_FAILED  = 'failed';
	public const STATE_EXISTS  = 'exists';

	/** States after which nothing is sent again for the order. `unknown` is not one: it is asked again, with the same key. */
	private const FINAL = array( self::STATE_ISSUED, self::STATE_FAILED, self::STATE_EXISTS );

	/** How long a lock lives, in seconds: more than the longest run (three attempts of ten seconds), so a process that died cannot hold an order for good. */
	public const LOCK_TTL = 120;
	/**
	 * How long after the first request a repeat is still safe, in seconds: Rewloy keeps an Idempotency-Key for seven
	 * days; six leaves a day of margin.
	 */
	public const KEY_WINDOW = 518_400;
	/** Seconds to wait before each repeat of an unclear request, in turn: 5 minutes, an hour, six hours. The first request is attempt 1. */
	public const RETRY_DELAYS = array( 300, 3600, 21_600 );

	/** @var callable(string=): ?Client */
	private $client_factory;
	/** @var callable(int, int): bool */
	private $scheduler;
	private Lock $lock;

	/**
	 * @param Settings                   $settings       The saved settings.
	 * @param callable(string=): ?Client $client_factory A client for the saved key; null without one.
	 * @param Lock|null                  $lock           The per-order and per-address lock.
	 * @param Webhooks|null              $webhooks       The shop's webhook, to deliver an order again when Rewloy asks.
	 * @param (callable(int, int): bool)|null $scheduler Schedules another run of an order at a time (timestamp, order id):
	 *                                                   Action Scheduler's when it is there; true when it was scheduled.
	 */
	public function __construct( private Settings $settings, callable $client_factory, ?Lock $lock = null, private ?Webhooks $webhooks = null, ?callable $scheduler = null ) {
		$this->client_factory = $client_factory;
		$this->lock           = $lock ?? new Lock();
		$this->scheduler      = $scheduler ?? static function ( int $when, int $order_id ): bool {
			if ( ! function_exists( 'as_schedule_single_action' ) ) {
				return false;
			}
			// Not Action Scheduler's `unique`: its check counts the action that is running right now (this very hook
			// and arguments, in progress) as the same action, so a repeat scheduled from inside the run that needs
			// it is silently dropped (seen on a real WordPress: nothing was scheduled, and the order note said it
			// was). A repeat already waiting for about that time or later is enough; anything else is scheduled.
			if ( function_exists( 'as_get_scheduled_actions' ) ) {
				$waiting = as_get_scheduled_actions(
					array(
						'hook'         => self::HOOK,
						'args'         => array( $order_id ),
						'group'        => self::GROUP,
						'status'       => 'pending',
						'date'         => $when - 60,
						'date_compare' => '>=',
						'per_page'     => 1,
					),
					'ids'
				);
				if ( is_array( $waiting ) && array() !== $waiting ) {
					return true;
				}
			}
			// 0 is Action Scheduler saying it did not schedule (not ready, a store error): then nothing is on its way.
			return (int) as_schedule_single_action( $when, self::HOOK, array( $order_id ), self::GROUP ) > 0;
		};
	}

	/** Is the invitation on and the shop connected to a card? */
	public function is_active(): bool {
		$s = $this->settings->get();
		return $s['invite'] && '' !== $s['link_id'] && '' !== $s['program_id'];
	}

	/** Was the invitation box ticked for this order? */
	public function was_ticked( \WC_Order $order ): bool {
		return 'yes' === $order->get_meta( self::META_INVITE ) || '1' === (string) $order->get_meta( self::META_INVITE_BLOCKS ) || 'yes' === $order->get_meta( self::META_INVITE_BLOCKS );
	}

	/**
	 * `woocommerce_order_status_processing` / `_completed`: a paid order whose box was ticked is queued for the card.
	 * It writes nothing to the order: the order object WooCommerce passes may be stale, so it is read afresh, and
	 * whatever is queued twice is stopped by run().
	 *
	 * @param int|string     $order_id The order's id.
	 * @param \WC_Order|null $order    The order, when WooCommerce passes it (not trusted).
	 */
	public function on_paid( $order_id, $order = null ): void {
		unset( $order );
		if ( ! $this->is_active() ) {
			return;
		}
		$id    = (int) $order_id;
		$fresh = $id > 0 ? wc_get_order( $id ) : false;
		if ( ! $fresh instanceof \WC_Order || ! $this->was_ticked( $fresh ) || $this->settled( $fresh ) ) {
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			// Off the payment gateway's request: a slow Rewloy must not hold a customer's checkout.
			as_enqueue_async_action( self::HOOK, array( $id ), self::GROUP, true );
		} else {
			$this->run( $id );
		}
	}

	/**
	 * The Action Scheduler hook's callback: the same as run(), which returns what became of the order for tests.
	 *
	 * @param int|string $order_id The order's id.
	 */
	public function run_queued( $order_id ): void {
		$this->run( $order_id );
	}

	/** Has a card been opened for the order, or been refused for good? (`unknown` has not been settled: it is asked again.) */
	private function settled( \WC_Order $order ): bool {
		return in_array( (string) $order->get_meta( self::META_STATE ), self::FINAL, true ) || '' !== (string) $order->get_meta( self::META_SERIAL );
	}

	/**
	 * Opens the card for the order, once. Returns what became of it (a STATE_* or a reason), for tests and logs.
	 *
	 * @param int|string $order_id The order's id.
	 * @param bool       $force    True for a person's own request (the order action): it does not wait for the schedule
	 *                             or stop at the number of automatic attempts.
	 */
	public function run( $order_id, bool $force = false ): string {
		$id    = (int) $order_id;
		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order || $id <= 0 ) {
			return 'no-order';
		}
		if ( ! $this->is_active() ) {
			return 'off';
		}
		if ( ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			return 'not-paid';
		}
		if ( ! $this->was_ticked( $order ) ) {
			return 'not-ticked';
		}
		if ( $this->settled( $order ) || '' !== $this->not_yet( $order, $force ) ) {
			return 'done';
		}

		// One run per order at a time: two must not both mail the link. The lock expires, so a dead process holds nothing for good.
		$claim = Settings::CLAIM_PREFIX . $id;
		if ( ! $this->lock->acquire( $claim, self::LOCK_TTL ) ) {
			return 'claimed';
		}
		try {
			return $this->run_locked( $id, $force );
		} finally {
			$this->lock->release( $claim );
		}
	}

	private function run_locked( int $id, bool $force ): string {
		// Whoever held the lock before may have finished: read the order again.
		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order ) {
			return 'no-order';
		}
		if ( $this->settled( $order ) || '' !== $this->not_yet( $order, $force ) ) {
			return 'done';
		}

		$email = trim( (string) $order->get_billing_email() );
		if ( ! is_email( $email ) ) {
			$this->refuse( $order, 'NO_EMAIL', __( 'Rewloy: no card was opened: the order has no valid billing e-mail.', 'rewloy-for-woocommerce' ) );
			return self::STATE_FAILED;
		}

		// One e-mail, one card, per shop: Rewloy opens a card for every call with a new key, so this is the plugin's. The
		// address is locked first and searched second, so an order that finishes between the two cannot be missed.
		$email_claim = Settings::CLAIM_PREFIX . 'email_' . substr( wp_hash( strtolower( $email ) ), 0, 32 );
		if ( ! $this->lock->acquire( $email_claim, self::LOCK_TTL ) ) {
			$this->persist( $order, self::STATE_EXISTS, '' );
			$order->add_order_note( __( 'Rewloy: no new card was opened: another order of this e-mail address is inviting it or has invited it, and one e-mail gets one card from this shop.', 'rewloy-for-woocommerce' ), 0 );
			return self::STATE_EXISTS;
		}
		try {
			return $this->issue( $order, $email );
		} finally {
			$this->lock->release( $email_claim );
		}
	}

	/** The order is paid, ticked and not settled, and both locks are held: ask Rewloy for the card. */
	private function issue( \WC_Order $order, string $email ): string {
		$id      = $order->get_id();
		$earlier = $this->earlier_invitation( $email, $id );
		if ( null !== $earlier ) {
			$this->persist( $order, self::STATE_EXISTS, '' );
			$order->add_order_note(
				/* translators: %s: an order number. */
				sprintf( __( 'Rewloy: no new card was opened: order #%s already invited this e-mail address, and one e-mail gets one card from this shop.', 'rewloy-for-woocommerce' ), (string) $earlier ),
				0
			);
			return self::STATE_EXISTS;
		}

		$client = ( $this->client_factory )( '' );
		if ( null === $client ) {
			// Nothing was sent: no key. A definite, safe failure.
			$this->refuse( $order, 'NO_KEY', __( 'Rewloy: no card was opened: there is no API key.', 'rewloy-for-woocommerce' ) );
			return self::STATE_FAILED;
		}

		$body = array(
			'programId'   => $this->settings->get()['program_id'],
			'email'       => $email,
			// The checkout showed the notice beside the box; this states that, as the API's description asks.
			'kvkkConsent' => true,
			// The order that earns the card, and the link it came by: it counts toward this card, whichever of the two arrives first.
			'orderId'     => (string) $id,
			'shopId'      => $this->settings->get()['link_id'],
		);
		$sig  = $this->fingerprint( $body );
		$try  = $order->get_meta( self::META_TRY );
		$try  = is_array( $try ) ? $try : array();
		if ( array() !== $try && ( $try['sig'] ?? '' ) !== $sig ) {
			// A repeat must be the same request: another body under the same key is refused, and under another key it could open a second card.
			$this->persist( $order, self::STATE_UNKNOWN, 'CHANGED' );
			$order->add_order_note( $this->blocked_note( 'changed' ), 0 );
			return self::STATE_UNKNOWN;
		}
		$attempt = (int) ( $try['n'] ?? 0 ) + 1;
		$record  = array(
			'at'   => (int) ( $try['at'] ?? time() ),
			'n'    => $attempt,
			'sig'  => $sig,
			'next' => time() + (int) ( self::RETRY_DELAYS[ $attempt - 1 ] ?? 0 ),
		);

		// Before the request: "unknown" and the request's record, and the next attempt already scheduled, so a process
		// that dies mid-request leaves exactly what a repeat needs and the repeat is on its way. A scheduled run that
		// finds the card opened does nothing.
		$this->persist( $order, self::STATE_UNKNOWN, 'SENDING', $record );
		$later = $attempt <= count( self::RETRY_DELAYS ) && ( $this->scheduler )( $record['next'], $id );
		try {
			$card = $client->issue_pass( $body, $this->idempotency_key( $id ) );
		} catch ( RewloyException $e ) {
			if ( 'IDEMPOTENCY_KEY_REUSED' === $e->api_code ) {
				// Rewloy holds a different request under this key: it may have opened a card, and a repeat cannot be the same.
				$this->persist( $order, self::STATE_UNKNOWN, 'CHANGED', $record );
				$order->add_order_note( $this->blocked_note( 'changed' ), 0 );
				return self::STATE_UNKNOWN;
			}
			if ( $e->outcome_unknown() ) {
				return $this->unclear( $order, $e->api_code, $record, $later );
			}
			$this->refuse(
				$order,
				$e->api_code,
				/* translators: %s: why Rewloy refused. */
				sprintf( __( 'Rewloy: no card was opened. %s', 'rewloy-for-woocommerce' ), Messages::for_error( $e ) )
			);
			return self::STATE_FAILED;
		} catch ( \Throwable $e ) {
			unset( $e ); // Whatever it was, a request may have gone out; the same one is asked again.
			return $this->unclear( $order, 'ERROR', $record, $later );
		}

		// The card exists: record it first, mail afterwards. Nothing after this line may undo that.
		$order->update_meta_data( self::META_SERIAL, $card['serial'] );
		$order->delete_meta_data( self::META_TRY );
		$this->persist( $order, self::STATE_ISSUED, '' );
		$this->unschedule( $id );
		try {
			$mailed = $this->mail_card( $email, $order, $card['cardUrl'] );
		} catch ( \Throwable $e ) {
			unset( $e );
			$mailed = false;
		}
		$order->add_order_note( $this->issued_note( $card, $mailed, $this->deliver_again( $card['order_result'], $id ) ), 0 );
		return self::STATE_ISSUED;
	}

	/**
	 * Why a run for an `unknown` order does not send yet ('' when it may): its first request left no record, the six
	 * days are over, the request would not be the same, or (for an automatic run) it is not yet time or the automatic
	 * attempts are used. Anything but `unknown` is always ''.
	 *
	 * @return ''|'no-record'|'expired'|'changed'|'wait'|'attempts'
	 */
	private function not_yet( \WC_Order $order, bool $force ): string {
		if ( self::STATE_UNKNOWN !== (string) $order->get_meta( self::META_STATE ) ) {
			return '';
		}
		$block = $this->retry_block( $order );
		if ( '' !== $block ) {
			return $block;
		}
		if ( $force ) {
			return '';
		}
		$try = (array) $order->get_meta( self::META_TRY );
		if ( (int) ( $try['n'] ?? 0 ) >= count( self::RETRY_DELAYS ) + 1 ) {
			return 'attempts';
		}
		return time() < (int) ( $try['next'] ?? 0 ) ? 'wait' : '';
	}

	/**
	 * Why an `unknown` order's request cannot be repeated by anyone (a person's own request included): '' when it can.
	 *
	 * @return ''|'no-record'|'expired'|'changed'
	 */
	private function retry_block( \WC_Order $order ): string {
		if ( in_array( (string) $order->get_meta( self::META_CODE ), array( 'CHANGED' ), true ) ) {
			return 'changed';
		}
		$try = $order->get_meta( self::META_TRY );
		if ( ! is_array( $try ) || ! isset( $try['at'], $try['sig'] ) ) {
			return 'no-record';
		}
		return time() - (int) $try['at'] > self::KEY_WINDOW ? 'expired' : '';
	}

	/** What the plugin writes on the order when a request that went out cannot be repeated. */
	private function blocked_note( string $reason ): string {
		switch ( $reason ) {
			case 'expired':
				return __( 'Rewloy: the first request for this order is older than six days, and Rewloy no longer replays it, so asking again could open a second card. Look for this customer by e-mail in the Rewloy panel.', 'rewloy-for-woocommerce' );
			case 'changed':
				return __( 'Rewloy: the order\'s e-mail address or the connection changed since the first request, so the same request cannot be repeated: Rewloy would refuse it, or open a second card. Look for this customer by e-mail in the Rewloy panel.', 'rewloy-for-woocommerce' );
			default:
				return __( 'Rewloy: this order has no record of its first request, so asking again could open a second card. Look for this customer by e-mail in the Rewloy panel.', 'rewloy-for-woocommerce' );
		}
	}

	/**
	 * An answer that is not clear (no answer, a 5xx, "still running", anything thrown): the card may or may not be open.
	 * The state stays `unknown`, and the SAME request is asked again later with the same key (Rewloy answers it with the
	 * card it already opened, so a second cannot be opened): a few times by a scheduled action, then by the order screen.
	 *
	 * @param array{at:int,n:int,sig:string,next:int} $record The first request's record.
	 * @param bool                                    $later  Whether the next attempt is scheduled.
	 */
	private function unclear( \WC_Order $order, string $code, array $record, bool $later ): string {
		$this->persist( $order, self::STATE_UNKNOWN, $code, $record );
		// Said on the first attempt, and when the last one has been spent (or nothing is scheduled): not on every attempt in between.
		if ( 1 === $record['n'] || ! $later ) {
			$order->add_order_note(
				$later
					? __( 'Rewloy: no clear answer came, so the card may or may not have been opened. The same request is repeated automatically, a few times over the next hours: Rewloy answers a repeat with the card it already opened, so a second one cannot be opened.', 'rewloy-for-woocommerce' )
					: __( 'Rewloy: no clear answer came, so the card may or may not have been opened. Use "Rewloy: try opening the card again" on this order: it repeats the same request, and Rewloy answers a repeat with the card it already opened. Or look for this customer by e-mail in the Rewloy panel.', 'rewloy-for-woocommerce' ),
				0
			);
		}
		return self::STATE_UNKNOWN;
	}

	/**
	 * The order note for a card that was opened: its serial, whether the link was mailed, whether Rewloy answered with
	 * a card an earlier attempt had opened, and what became of the order's own delivery.
	 *
	 * @param array{serial:string,cardUrl:string,replayed:bool,order_result:string} $card      The answer.
	 * @param bool                                                                  $mailed    Did the mail go out?
	 * @param bool|null                                                             $delivered Null: nothing to deliver; true: queued again; false: could not.
	 */
	private function issued_note( array $card, bool $mailed, ?bool $delivered ): string {
		$note = $mailed
			/* translators: %s: the card's serial number. */
			? sprintf( __( 'Rewloy: card opened (%s). Its link was e-mailed to the customer.', 'rewloy-for-woocommerce' ), $card['serial'] )
			/* translators: %s: the card's serial number. */
			: sprintf( __( 'Rewloy: card opened (%s), but the e-mail with its link could not be sent. The link is private and is not kept here; find the card in the Rewloy panel.', 'rewloy-for-woocommerce' ), $card['serial'] );
		if ( $card['replayed'] ) {
			$note .= ' ' . __( 'Rewloy answered with the card an earlier request of this order had opened.', 'rewloy-for-woocommerce' );
		}
		if ( true === $delivered ) {
			$note .= ' ' . __( 'The order had reached Rewloy before the card existed, so it was queued to be sent again; it counts toward the card once that delivery arrives.', 'rewloy-for-woocommerce' );
		} elseif ( false === $delivered ) {
			$note .= ' ' . __( 'The order had reached Rewloy before the card existed, and it could not be sent again because the webhook is missing or not active. Turn the webhook on and save the order again within seven days, or the order will not count toward the card.', 'rewloy-for-woocommerce' );
		}
		return $note;
	}

	/**
	 * Rewloy says `resend`: the order was recorded without a card before the card existed, and the card has reopened it.
	 * Deliver the order's webhook again, so Rewloy credits it from its own signed body (within seven days).
	 *
	 * @return bool|null Null when Rewloy asked for nothing; true when the delivery was queued; false when it could not be.
	 */
	private function deliver_again( string $order_result, int $order_id ): ?bool {
		if ( 'resend' !== $order_result ) {
			return null;
		}
		try {
			return null !== $this->webhooks && $this->webhooks->redeliver( $this->settings->get()['webhook_id'], $order_id );
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	/**
	 * The fingerprint of a request: its body and the key's own hash, so a repeat with another body, or another
	 * credential (Rewloy keeps an Idempotency-Key per credential), is known before it is sent. Not the key itself.
	 *
	 * @param array<string,mixed> $body The request body.
	 */
	private function fingerprint( array $body ): string {
		return wp_hash( (string) wp_json_encode( $body ) . '|' . $this->settings->api_key() );
	}

	/**
	 * The order screen's action list: after a clear refusal, or an unclear answer, the card may be asked for again.
	 *
	 * @param mixed $actions The order actions.
	 * @return mixed
	 */
	public function order_actions( $actions ) {
		global $theorder;
		if ( ! is_array( $actions ) || ! $theorder instanceof \WC_Order || ! current_user_can( 'manage_woocommerce' ) ) {
			return $actions;
		}
		if ( in_array( (string) $theorder->get_meta( self::META_STATE ), array( self::STATE_FAILED, self::STATE_UNKNOWN ), true ) && '' === (string) $theorder->get_meta( self::META_SERIAL ) ) {
			$actions['rewloy_retry_card'] = __( 'Rewloy: try opening the card again', 'rewloy-for-woocommerce' );
		}
		return $actions;
	}

	/**
	 * The order action. `failed` (Rewloy said no; nothing was opened): the state is cleared and the card asked for
	 * afresh. `unknown` (no clear answer): the SAME request is asked again, which cannot open a second card, unless
	 * it can no longer be the same (see retry_block), and then the note says so. Needs `manage_woocommerce`, like
	 * every other action of the plugin. The order is read afresh, and run()'s lock stops two clicks at once.
	 *
	 * @param mixed $order The order.
	 */
	public function retry( $order ): void {
		if ( ! $order instanceof \WC_Order || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$id    = $order->get_id();
		$fresh = wc_get_order( $id );
		if ( ! $fresh instanceof \WC_Order || '' !== (string) $fresh->get_meta( self::META_SERIAL ) ) {
			return;
		}
		$state = (string) $fresh->get_meta( self::META_STATE );
		if ( self::STATE_FAILED === $state ) {
			$fresh->delete_meta_data( self::META_STATE );
			$fresh->delete_meta_data( self::META_CODE );
			$fresh->delete_meta_data( self::META_TRY );
			$fresh->save_meta_data();
		} elseif ( self::STATE_UNKNOWN === $state ) {
			$block = $this->retry_block( $fresh );
			if ( '' !== $block ) {
				$fresh->add_order_note( $this->blocked_note( $block ), 0 );
				return;
			}
		} else {
			return;
		}
		$this->run( $id, true );
	}

	/** `woo-<site>-<order id>`: stable for the order, under the API's 64 characters. */
	public function idempotency_key( int $order_id ): string {
		return 'woo-' . $this->settings->site_id() . '-' . $order_id;
	}

	/** Orders of one address asked for at a time, and how many such pages are read before the search stops. */
	private const SEARCH_PAGE  = 50;
	private const SEARCH_PAGES = 10;

	/**
	 * Another order that already invited this address (issued, or possibly issued), or null.
	 *
	 * The orders of the address are listed (by billing e-mail, which every order store supports) and each one's
	 * state is read here. A `meta_query` on the state would be shorter, but the legacy post-based order store
	 * ignores it (WooCommerce only notes "not supported on the current order datastore"): every other order of
	 * the address would then count as an earlier invitation, and a returning customer whose first order never
	 * invited anyone would be told "exists" and get no card. The search reads at most SEARCH_PAGES * SEARCH_PAGE
	 * orders of one address, newest first; the e-mail claim (see run()) is the guard past that. The order itself is
	 * skipped in the loop, not with `exclude` (a NOT IN the WordPress VIP and Plugin Check rules warn against).
	 *
	 * @return int|null The earlier order's id.
	 */
	private function earlier_invitation( string $email, int $order_id ): ?int {
		for ( $page = 1; $page <= self::SEARCH_PAGES; $page++ ) {
			$ids = wc_get_orders(
				array(
					'billing_email' => $email,
					'limit'         => self::SEARCH_PAGE,
					'paged'         => $page,
					'orderby'       => 'ID',
					'order'         => 'DESC',
					'return'        => 'ids',
				)
			);
			if ( ! is_array( $ids ) || array() === $ids ) {
				return null;
			}
			foreach ( $ids as $found ) {
				$earlier = $this->order_of( $found );
				if ( null !== $earlier && $earlier->get_id() !== $order_id
					&& in_array( (string) $earlier->get_meta( self::META_STATE ), array( self::STATE_ISSUED, self::STATE_UNKNOWN ), true ) ) {
					return $earlier->get_id();
				}
			}
			if ( count( $ids ) < self::SEARCH_PAGE ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * An order from what wc_get_orders gave: an id (what 'return' => 'ids' yields) or already an order.
	 *
	 * @param mixed $found One row of the list.
	 */
	private function order_of( mixed $found ): ?\WC_Order {
		if ( $found instanceof \WC_Order ) {
			return $found;
		}
		$order = is_numeric( $found ) ? wc_get_order( (int) $found ) : false;
		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * Writes the state (and its code, and the first request's record) to the order and saves it.
	 *
	 * @param array<string,mixed>|null $record The first request's record, or null to leave it as it is.
	 */
	private function persist( \WC_Order $order, string $state, string $code, ?array $record = null ): void {
		$order->update_meta_data( self::META_STATE, $state );
		if ( '' !== $code ) {
			$order->update_meta_data( self::META_CODE, substr( preg_replace( '/[^A-Z_]/', '', $code ) ?? '', 0, 40 ) );
		} else {
			$order->delete_meta_data( self::META_CODE );
		}
		if ( null !== $record ) {
			$order->update_meta_data( self::META_TRY, $record );
		}
		$order->save_meta_data();
	}

	/**
	 * A clear refusal: nothing was opened. The state is final (until the order action), and the note says why.
	 */
	private function refuse( \WC_Order $order, string $code, string $note ): void {
		$this->persist( $order, self::STATE_FAILED, $code );
		$order->delete_meta_data( self::META_TRY );
		$order->save_meta_data();
		$this->unschedule( $order->get_id() );
		$order->add_order_note( $note, 0 );
	}

	/** The next attempt was scheduled before the request; the order is settled now, so it is not needed. */
	private function unschedule( int $order_id ): void {
		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( self::HOOK, array( $order_id ), self::GROUP );
		}
	}

	/** Mails the card link to the buyer. The link is not kept. */
	private function mail_card( string $email, \WC_Order $order, string $card_url ): bool {
		if ( ! $this->settings->is_rewloy_url( $card_url ) ) {
			return false;
		}
		$s    = $this->settings->get();
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$name = '' !== $s['controller_name'] ? $s['controller_name'] : $site;
		/* translators: %s: the shop's name. */
		$subject = sprintf( __( 'Your %s loyalty card', 'rewloy-for-woocommerce' ), $site );
		$body    = sprintf(
			/* translators: 1: order number, 2: shop name, 3: card link, 4: data controller's name, 5: privacy notice link. */
			__( "Hello,\n\nYou asked for a loyalty card with your order #%1\$s at %2\$s. Open it here:\n\n%3\$s\n\nThis link is private: it opens your card with your details. Keep it to yourself.\n\nData controller: %4\$s. Details: %5\$s\n", 'rewloy-for-woocommerce' ),
			$order->get_order_number(),
			$site,
			$card_url,
			$name,
			Checkout::PRIVACY_URL
		);
		return (bool) wp_mail( $email, $subject, $body );
	}
}
