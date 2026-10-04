<?php
/**
 * Opens the invited customer's card: `issuePass`, at most once per order.
 *
 * Rewloy's issuePass does not de-duplicate (every call opens a card) and does
 * not replay on an Idempotency-Key, so the guarantee is the plugin's own:
 *
 *  1. an atomic claim on the order (an options row added with INSERT IGNORE,
 *     which only one process can win; see Lock) before anything is sent. The
 *     claim is KEPT for good once a card may have been opened (issued, unknown,
 *     exists) and let go only after a clear refusal, so no later write to the
 *     order's meta, stale or not, can open the way for a second request;
 *  2. a second claim on the e-mail address, so two orders of one address paid
 *     at the same moment cannot both open a card;
 *  3. the order's state meta: "unknown" is written BEFORE the request, so a
 *     process that dies mid-way leaves a state that counts as "may have been
 *     issued"; the result then overwrites it;
 *  4. the call is sent once and NEVER retried. When the answer is unclear (no
 *     answer, a 5xx, anything thrown), the state stays "unknown" and nothing is
 *     sent again;
 *  5. an e-mail address already invited from another order is not invited again.
 *
 * The card link (it carries the customer's private viewing key) is mailed to the
 * customer and never written to the order, an order note or a log.
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

	public const STATE_ISSUED  = 'issued';
	public const STATE_UNKNOWN = 'unknown';
	public const STATE_FAILED  = 'failed';
	public const STATE_EXISTS  = 'exists';

	/** States after which nothing is ever sent again for the order. */
	private const FINAL = array( self::STATE_ISSUED, self::STATE_UNKNOWN, self::STATE_FAILED, self::STATE_EXISTS );

	/** @var callable(string=): ?Client */
	private $client_factory;
	private Lock $lock;

	/**
	 * @param Settings                   $settings       The saved settings.
	 * @param callable(string=): ?Client $client_factory A client for the saved key; null without one.
	 * @param Lock|null                  $lock           The per-order lock.
	 */
	public function __construct( private Settings $settings, callable $client_factory, ?Lock $lock = null ) {
		$this->client_factory = $client_factory;
		$this->lock           = $lock ?? new Lock();
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
	 * whatever is queued twice is stopped by run()'s claim.
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
		if ( ! $fresh instanceof \WC_Order || ! $this->was_ticked( $fresh ) ) {
			return;
		}
		if ( in_array( (string) $fresh->get_meta( self::META_STATE ), self::FINAL, true ) ) {
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

	/**
	 * Opens the card for the order, once. Returns what became of it (a STATE_* or a reason), for tests and logs.
	 *
	 * @param int|string $order_id The order's id.
	 */
	public function run( $order_id ): string {
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
		if ( in_array( (string) $order->get_meta( self::META_STATE ), self::FINAL, true ) ) {
			return 'done';
		}

		// The claim: an INSERT IGNORE on a unique name, so exactly one process wins it (see Lock).
		$claim = Settings::CLAIM_PREFIX . $id;
		if ( ! $this->lock->acquire( $claim ) ) {
			return 'claimed';
		}
		// Whoever held the claim before may have finished and let go: read the order again.
		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order ) {
			$this->lock->release( $claim );
			return 'no-order';
		}
		$state = (string) $order->get_meta( self::META_STATE );
		if ( in_array( $state, self::FINAL, true ) ) {
			if ( self::STATE_FAILED === $state ) {
				$this->lock->release( $claim ); // A clear refusal holds nothing.
			}
			return 'done';
		}

		$email = trim( (string) $order->get_billing_email() );
		if ( ! is_email( $email ) ) {
			$this->refuse( $order, array( $claim ), 'NO_EMAIL', __( 'Rewloy: no card was opened: the order has no valid billing e-mail.', 'rewloy-for-woocommerce' ) );
			return self::STATE_FAILED;
		}

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
		// Two orders of one address, paid at the same moment, cannot both pass the check above: the address is claimed too.
		$email_claim = Settings::CLAIM_PREFIX . 'email_' . substr( wp_hash( strtolower( $email ) ), 0, 32 );
		if ( ! $this->lock->acquire( $email_claim ) ) {
			$this->persist( $order, self::STATE_EXISTS, '' );
			$order->add_order_note( __( 'Rewloy: no new card was opened: another order of this e-mail address is inviting it or has invited it, and one e-mail gets one card from this shop.', 'rewloy-for-woocommerce' ), 0 );
			return self::STATE_EXISTS;
		}

		$client = ( $this->client_factory )( '' );
		if ( null === $client ) {
			// Nothing was sent: no key. A definite, safe failure.
			$this->refuse( $order, array( $claim, $email_claim ), 'NO_KEY', __( 'Rewloy: no card was opened: there is no API key.', 'rewloy-for-woocommerce' ) );
			return self::STATE_FAILED;
		}

		// Before the request: "unknown" counts as "may have been opened", so a process that dies
		// mid-request leaves exactly the state that keeps a second card from being opened.
		$this->persist( $order, self::STATE_UNKNOWN, 'SENDING' );
		try {
			$card = $client->issue_pass(
				array(
					'programId'   => $this->settings->get()['program_id'],
					'email'       => $email,
					// The checkout showed the notice beside the box; this states that, as the API's description asks.
					'kvkkConsent' => true,
				),
				$this->idempotency_key( $id )
			);
		} catch ( RewloyException $e ) {
			if ( $e->outcome_unknown() ) {
				$this->persist( $order, self::STATE_UNKNOWN, $e->api_code );
				$order->add_order_note( $this->unknown_note(), 0 );
				return self::STATE_UNKNOWN;
			}
			$this->refuse(
				$order,
				array( $claim, $email_claim ),
				$e->api_code,
				/* translators: %s: why Rewloy refused. */
				sprintf( __( 'Rewloy: no card was opened. %s', 'rewloy-for-woocommerce' ), Messages::for_error( $e ) )
			);
			return self::STATE_FAILED;
		} catch ( \Throwable $e ) {
			unset( $e ); // Whatever it was, a request may have gone out: never repeated.
			$this->persist( $order, self::STATE_UNKNOWN, 'ERROR' );
			$order->add_order_note( $this->unknown_note(), 0 );
			return self::STATE_UNKNOWN;
		}

		// The card exists: record it first, mail afterwards. Nothing after this line may undo that.
		$order->update_meta_data( self::META_SERIAL, $card['serial'] );
		$this->persist( $order, self::STATE_ISSUED, '' );
		try {
			$mailed = $this->mail_card( $email, $order, $card['cardUrl'] );
		} catch ( \Throwable $e ) {
			unset( $e );
			$mailed = false;
		}
		$order->add_order_note(
			$mailed
				/* translators: %s: the card's serial number. */
				? sprintf( __( 'Rewloy: card opened (%s). Its link was e-mailed to the customer.', 'rewloy-for-woocommerce' ), $card['serial'] )
				/* translators: %s: the card's serial number. */
				: sprintf( __( 'Rewloy: card opened (%s), but the e-mail with its link could not be sent. The link is private and is not kept here; find the card in the Rewloy panel.', 'rewloy-for-woocommerce' ), $card['serial'] ),
			0
		);
		return self::STATE_ISSUED;
	}

	/**
	 * The order screen's action list: after a clear refusal (never after an unclear answer) the card may be tried again.
	 *
	 * @param mixed $actions The order actions.
	 * @return mixed
	 */
	public function order_actions( $actions ) {
		global $theorder;
		if ( ! is_array( $actions ) || ! $theorder instanceof \WC_Order || ! current_user_can( 'manage_woocommerce' ) ) {
			return $actions;
		}
		if ( self::STATE_FAILED === (string) $theorder->get_meta( self::META_STATE ) ) {
			$actions['rewloy_retry_card'] = __( 'Rewloy: try opening the card again', 'rewloy-for-woocommerce' );
		}
		return $actions;
	}

	/**
	 * The order action: only a "failed" state (Rewloy said no, nothing was opened) is cleared and run again.
	 * Needs `manage_woocommerce`, like every other action of the plugin. The order is read afresh, and run()'s
	 * claim stops two clicks at once.
	 *
	 * @param mixed $order The order.
	 */
	public function retry( $order ): void {
		if ( ! $order instanceof \WC_Order || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$id    = $order->get_id();
		$fresh = wc_get_order( $id );
		if ( ! $fresh instanceof \WC_Order || self::STATE_FAILED !== (string) $fresh->get_meta( self::META_STATE ) ) {
			return;
		}
		$fresh->delete_meta_data( self::META_STATE );
		$fresh->delete_meta_data( self::META_CODE );
		$fresh->save_meta_data();
		$this->run( $id );
	}

	/** `woo-<site>-<order id>`: stable for the order, under the API's 64 characters. */
	public function idempotency_key( int $order_id ): string {
		return 'woo-' . $this->settings->site_id() . '-' . $order_id;
	}

	/**
	 * Another order that already invited this address (issued, or possibly issued), or null.
	 *
	 * @return int|null The earlier order's id.
	 */
	private function earlier_invitation( string $email, int $order_id ): ?int {
		$ids = wc_get_orders(
			array(
				'billing_email' => $email,
				'exclude'       => array( $order_id ),
				'limit'         => 1,
				'return'        => 'ids',
				'meta_query'    => array(
					array(
						'key'     => self::META_STATE,
						'value'   => array( self::STATE_ISSUED, self::STATE_UNKNOWN ),
						'compare' => 'IN',
					),
				),
			)
		);
		if ( ! is_array( $ids ) || array() === $ids ) {
			return null;
		}
		$first = reset( $ids );
		if ( $first instanceof \WC_Order ) {
			return $first->get_id();
		}
		return is_numeric( $first ) ? (int) $first : null;
	}

	/** Writes the state (and its code) to the order and saves it. */
	private function persist( \WC_Order $order, string $state, string $code ): void {
		$order->update_meta_data( self::META_STATE, $state );
		if ( '' !== $code ) {
			$order->update_meta_data( self::META_CODE, substr( preg_replace( '/[^A-Z_]/', '', $code ) ?? '', 0, 40 ) );
		} else {
			$order->delete_meta_data( self::META_CODE );
		}
		$order->save_meta_data();
	}

	/**
	 * A clear refusal: nothing was opened. The state is final (until the retry action), the note says why, and the
	 * claims are let go, because nothing is held by a card that does not exist.
	 *
	 * @param list<string> $claims The claims taken for this order.
	 */
	private function refuse( \WC_Order $order, array $claims, string $code, string $note ): void {
		$this->persist( $order, self::STATE_FAILED, $code );
		$order->add_order_note( $note, 0 );
		foreach ( $claims as $claim ) {
			$this->lock->release( $claim );
		}
	}

	private function unknown_note(): string {
		return __( 'Rewloy: no clear answer came, so the card may or may not have been opened. It is not sent again, so that two are not opened. Look for this customer by e-mail in the Rewloy panel.', 'rewloy-for-woocommerce' );
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
