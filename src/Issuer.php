<?php
/**
 * Opens the invited customer's card: `issuePass`, at most once per order.
 *
 * Rewloy's issuePass does not de-duplicate (every call opens a card) and does
 * not replay on an Idempotency-Key, so the guarantee is the plugin's own:
 *
 *  1. an atomic claim on the order (an options row added with INSERT IGNORE,
 *     which only one process can win; see Lock) before anything is sent;
 *  2. the order's own state meta, checked before and after the claim;
 *  3. the call is sent once and NEVER retried. When the answer is unclear (no
 *     answer, a 5xx), the state becomes "unknown" and nothing is sent again;
 *  4. an e-mail address already invited from another order is not invited again.
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

	public const STATE_QUEUED  = 'queued';
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
	 *
	 * @param int|string     $order_id The order's id.
	 * @param \WC_Order|null $order    The order, when WooCommerce passes it.
	 */
	public function on_paid( $order_id, $order = null ): void {
		if ( ! $this->is_active() ) {
			return;
		}
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order || ! $this->was_ticked( $order ) ) {
			return;
		}
		if ( '' !== (string) $order->get_meta( self::META_STATE ) ) {
			return; // Queued, done or failed: nothing to do again.
		}
		$order->update_meta_data( self::META_STATE, self::STATE_QUEUED );
		$order->save_meta_data();
		$id = $order->get_id();
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
		if ( ! $this->lock->acquire( Settings::CLAIM_PREFIX . $id ) ) {
			return 'claimed';
		}
		// Whoever held the claim before may have finished and dropped it: read the order again.
		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order ) {
			$this->lock->release( Settings::CLAIM_PREFIX . $id );
			return 'no-order';
		}
		if ( in_array( (string) $order->get_meta( self::META_STATE ), self::FINAL, true ) ) {
			$this->lock->release( Settings::CLAIM_PREFIX . $id );
			return 'done';
		}

		$email = trim( (string) $order->get_billing_email() );
		if ( ! is_email( $email ) ) {
			$this->finish( $order, self::STATE_FAILED, 'NO_EMAIL', __( 'Rewloy: no card was opened: the order has no valid billing e-mail.', 'rewloy-for-woocommerce' ) );
			return self::STATE_FAILED;
		}

		$earlier = $this->earlier_invitation( $email, $id );
		if ( null !== $earlier ) {
			$this->finish(
				$order,
				self::STATE_EXISTS,
				'',
				/* translators: %s: an order number. */
				sprintf( __( 'Rewloy: no new card was opened: order #%s already invited this e-mail address, and one e-mail gets one card from this shop.', 'rewloy-for-woocommerce' ), (string) $earlier )
			);
			return self::STATE_EXISTS;
		}

		$client = ( $this->client_factory )( '' );
		if ( null === $client ) {
			// Nothing was sent: no key. A definite, safe failure.
			$this->finish( $order, self::STATE_FAILED, 'NO_KEY', __( 'Rewloy: no card was opened: there is no API key.', 'rewloy-for-woocommerce' ) );
			return self::STATE_FAILED;
		}

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
				$this->finish(
					$order,
					self::STATE_UNKNOWN,
					$e->api_code,
					__( 'Rewloy: no clear answer came, so the card may or may not have been opened. It is not sent again, so that two are not opened. Look for this customer by e-mail in the Rewloy panel.', 'rewloy-for-woocommerce' )
				);
				return self::STATE_UNKNOWN;
			}
			$this->finish(
				$order,
				self::STATE_FAILED,
				$e->api_code,
				/* translators: %s: why Rewloy refused. */
				sprintf( __( 'Rewloy: no card was opened. %s', 'rewloy-for-woocommerce' ), Messages::for_error( $e ) )
			);
			return self::STATE_FAILED;
		}

		$mailed = $this->mail_card( $email, $order, $card['cardUrl'] );
		$order->update_meta_data( self::META_SERIAL, $card['serial'] );
		$this->finish(
			$order,
			self::STATE_ISSUED,
			'',
			$mailed
				/* translators: %s: the card's serial number. */
				? sprintf( __( 'Rewloy: card opened (%s). Its link was e-mailed to the customer.', 'rewloy-for-woocommerce' ), $card['serial'] )
				/* translators: %s: the card's serial number. */
				: sprintf( __( 'Rewloy: card opened (%s), but the e-mail with its link could not be sent. The link is private and is not kept here; find the card in the Rewloy panel.', 'rewloy-for-woocommerce' ), $card['serial'] )
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
		if ( ! is_array( $actions ) || ! $theorder instanceof \WC_Order ) {
			return $actions;
		}
		if ( self::STATE_FAILED === (string) $theorder->get_meta( self::META_STATE ) ) {
			$actions['rewloy_retry_card'] = __( 'Rewloy: try opening the card again', 'rewloy-for-woocommerce' );
		}
		return $actions;
	}

	/**
	 * The order action: only a "failed" state (Rewloy said no, nothing was opened) is cleared and run again.
	 *
	 * @param \WC_Order $order The order.
	 */
	public function retry( $order ): void {
		if ( ! $order instanceof \WC_Order || self::STATE_FAILED !== (string) $order->get_meta( self::META_STATE ) ) {
			return;
		}
		$order->delete_meta_data( self::META_STATE );
		$order->delete_meta_data( self::META_CODE );
		$order->save_meta_data();
		$this->run( $order->get_id() );
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

	/** Sets the final state, writes the note, and drops the claim. */
	private function finish( \WC_Order $order, string $state, string $code, string $note ): void {
		$order->update_meta_data( self::META_STATE, $state );
		if ( '' !== $code ) {
			$order->update_meta_data( self::META_CODE, substr( preg_replace( '/[^A-Z_]/', '', $code ) ?? '', 0, 40 ) );
		}
		$order->save_meta_data();
		$order->add_order_note( $note, 0 );
		$this->lock->release( Settings::CLAIM_PREFIX . $order->get_id() );
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
