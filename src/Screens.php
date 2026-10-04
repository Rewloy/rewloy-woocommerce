<?php
/**
 * The "Rewloy" menu's own screens (0.3.0, D37–D44): Özet (overview), Kartlar (watching the cards) and Kasa (the
 * till). The fourth tab, Ayarlar, is the connection and options screen of 0.2 (Admin.php), unchanged.
 *
 * Plain PHP in WordPress's own admin markup. What Rewloy does not let this site do (creating and designing cards,
 * campaigns, the team, billing, a customer's details) is a line that says "done in the Rewloy panel" with the exact
 * link, never a look-alike form. Every value from Rewloy is escaped where it is printed.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Screens {

	public function __construct( private Panel $panel, private Connection $connection ) {
	}

	/* ------------------------------------------------------------------ Özet */

	public function overview(): void {
		$s     = $this->panel->settings()->get();
		$a     = $this->panel->abilities();
		$links = $this->panel->links();

		echo '<h2>' . esc_html__( 'Connected card', 'rewloy-for-woocommerce' ) . '</h2>';
		echo '<table class="widefat striped rewloy-wc-facts"><tbody>';
		$this->row( __( 'Card', 'rewloy-for-woocommerce' ), $s['program_name'] . ( '' !== $s['program_type'] ? ' (' . Messages::type_label( $s['program_type'] ) . ')' : '' ) );
		if ( '' !== $a['business'] ) {
			$this->row( __( 'Business', 'rewloy-for-woocommerce' ), $a['business'] );
		}
		$this->row( __( 'Rule', 'rewloy-for-woocommerce' ), Messages::rule_text( $s['program_type'], $s['rule'], $s['per_amount_minor'], $s['step'], $s['currency'] ) );
		echo '</tbody></table>';
		$this->open_buttons(
			array(
				array( __( 'Open the card in the Rewloy panel', 'rewloy-for-woocommerce' ), $links->program( $s['program_id'] ) ),
				array( __( 'Open this shop link in the Rewloy panel', 'rewloy-for-woocommerce' ), $links->shop( $s['link_id'] ) ),
			)
		);
		if ( '' !== $a['error'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $a['error'] ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'The last 30 days', 'rewloy-for-woocommerce' ) . '</h2>';
		if ( $a['view'] ) {
			$n = $this->panel->numbers();
			if ( $n['ok'] ) {
				echo '<dl class="rewloy-wc-numbers">';
				foreach ( $n['numbers'] as $x ) {
					echo '<div><dt>' . esc_html( $x['label'] ) . '</dt><dd>' . esc_html( number_format_i18n( $x['value'] ) ) . '</dd></div>';
				}
				echo '</dl>';
				echo '<p class="description">' . esc_html__( 'Counted by Rewloy for this card only, at every branch: open cards today; cards given, visits and rewards used in the last 30 days.', 'rewloy-for-woocommerce' ) . '</p>';
			} elseif ( '' !== $n['error'] ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $n['error'] ) . '</p></div>';
			}
		} else {
			$this->ability_off( 'view' );
		}

		$health = $this->connection->health();
		echo '<h2>' . esc_html__( 'The link', 'rewloy-for-woocommerce' ) . '</h2>';
		$link = $health['link'];
		echo '<table class="widefat striped rewloy-wc-facts"><tbody>';
		$this->row( __( 'Link', 'rewloy-for-woocommerce' ), null === $link ? __( 'Unknown (see the message below)', 'rewloy-for-woocommerce' ) : ( ! empty( $link['enabled'] ) ? __( 'On', 'rewloy-for-woocommerce' ) : __( 'Off', 'rewloy-for-woocommerce' ) ) );
		$this->row( __( 'WooCommerce webhook', 'rewloy-for-woocommerce' ), $health['webhook']['exists'] ? ( 'active' === $health['webhook']['status'] ? __( 'active', 'rewloy-for-woocommerce' ) : __( 'not active: orders are not reaching Rewloy', 'rewloy-for-woocommerce' ) ) : __( 'Missing: orders are not reaching Rewloy', 'rewloy-for-woocommerce' ) );
		if ( null !== $link ) {
			$last = is_array( $link['lastDelivery'] ?? null ) ? $link['lastDelivery'] : null;
			$this->row(
				__( 'Last request from the shop', 'rewloy-for-woocommerce' ),
				null !== $last && is_string( $last['result'] ?? null ) ? Panel::when( is_string( $last['at'] ?? null ) ? $last['at'] : '' ) . ' · ' . Messages::delivery_label( $last['result'] ) : __( 'No signed request has come from the shop yet.', 'rewloy-for-woocommerce' )
			);
		}
		echo '</tbody></table>';
		if ( '' !== $health['error'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $health['error'] ) . '</p></div>';
		}
		echo '<p><a href="' . esc_url( Admin::tab_url( 'settings' ) ) . '">' . esc_html__( 'Connection details and settings', 'rewloy-for-woocommerce' ) . '</a></p>';

		echo '<h2>' . esc_html__( 'Last orders', 'rewloy-for-woocommerce' ) . '</h2>';
		$orders = array_slice( $health['orders'], 0, 5 );
		if ( array() === $orders ) {
			echo '<p>' . esc_html__( 'No order has arrived yet. The first paid order will show here with its outcome.', 'rewloy-for-woocommerce' ) . '</p>';
		} else {
			echo '<div class="rewloy-wc-scroll"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Order', 'rewloy-for-woocommerce' ) . '</th><th>' . esc_html__( 'Outcome', 'rewloy-for-woocommerce' ) . '</th><th>' . esc_html__( 'Time', 'rewloy-for-woocommerce' ) . '</th></tr></thead><tbody>';
			foreach ( $orders as $o ) {
				$id = is_scalar( $o['orderId'] ?? null ) ? (string) $o['orderId'] : '';
				echo '<tr><td>#' . esc_html( $id ) . '</td><td>' . esc_html( Messages::outcome_label( is_string( $o['outcome'] ?? null ) ? $o['outcome'] : '' ) ) . '</td><td>' . esc_html( Panel::when( is_string( $o['at'] ?? null ) ? $o['at'] : '' ) ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}

		$this->done_in_rewloy();
	}

	/* --------------------------------------------------------------- Kartlar */

	/** @param string $lookup A card number posted to the lookup ('' when none was). */
	public function cards( string $lookup ): void {
		$a     = $this->panel->abilities();
		$links = $this->panel->links();
		$s     = $this->panel->settings()->get();
		if ( ! $a['view'] ) {
			if ( '' !== $a['error'] ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $a['error'] ) . '</p></div>';
			}
			$this->ability_off( 'view' );
			return;
		}

		echo '<h2>' . esc_html__( 'Look up a card', 'rewloy-for-woocommerce' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( Admin::tab_url( 'cards' ) ) . '" class="rewloy-wc-lookup">';
		wp_nonce_field( 'rewloy_wc_card_lookup' );
		echo '<label for="rewloy_card">' . esc_html__( 'Card number', 'rewloy-for-woocommerce' ) . '</label> ';
		echo '<input type="text" name="rewloy_card" id="rewloy_card" class="regular-text" autocomplete="off" spellcheck="false" placeholder="ABCD-EFGH-JKLM" /> ';
		submit_button( __( 'Look up', 'rewloy-for-woocommerce' ), 'secondary', 'submit', false );
		echo '</form>';
		if ( '' !== $lookup ) {
			$r = $this->panel->card( $lookup );
			if ( ! $r['ok'] || null === $r['card'] ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $r['error'] ) . '</p></div>';
			} else {
				$this->card_state( $r['card'] );
				$serial = Serial::from_input( $lookup );
				$this->open_buttons(
					array(
						array( __( 'Open the customer in Rewloy', 'rewloy-for-woocommerce' ), $links->customer_of( $serial ) ),
						array( __( 'This card\'s activity in Rewloy', 'rewloy-for-woocommerce' ), $links->activity( '', $serial ) ),
					)
				);
				echo '<p class="description">' . esc_html__( 'The customer\'s name, e-mail and phone stay in Rewloy; they are not shown in WordPress.', 'rewloy-for-woocommerce' ) . '</p>';
			}
		}

		echo '<h2>' . esc_html__( 'Latest activity on customers\' cards', 'rewloy-for-woocommerce' ) . '</h2>';
		$act = $this->panel->activity( 20 );
		echo '<p class="description">' . esc_html__( 'What happened, when and where, newest first; a card by its last four characters. Refreshed every 30 seconds while this tab is open.', 'rewloy-for-woocommerce' ) . ' <span id="rewloy-wc-refreshed" aria-live="polite"></span></p>';
		echo '<div class="notice notice-error inline" id="rewloy-wc-activity-error"' . ( '' === $act['error'] ? ' hidden' : '' ) . '><p>' . esc_html( $act['error'] ) . '</p></div>';
		echo '<div class="rewloy-wc-scroll"><table class="widefat striped rewloy-wc-activity"><thead><tr>';
		foreach ( array( __( 'When', 'rewloy-for-woocommerce' ), __( 'What', 'rewloy-for-woocommerce' ), __( 'Card', 'rewloy-for-woocommerce' ), __( 'Where', 'rewloy-for-woocommerce' ), __( 'By', 'rewloy-for-woocommerce' ) ) as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody id="rewloy-wc-activity-rows">';
		if ( array() === $act['rows'] ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Nothing has happened on customers\' cards yet.', 'rewloy-for-woocommerce' ) . '</td></tr>';
		}
		foreach ( $act['rows'] as $r ) {
			echo '<tr><td>' . esc_html( $r['when'] ) . '</td><td>' . esc_html( $r['what'] ) . '</td><td class="rewloy-wc-mono">' . esc_html( $r['card'] ) . '</td><td>' . esc_html( $r['where'] ) . '</td><td>' . esc_html( $r['who'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		$this->open_buttons(
			array(
				array( __( 'All activity in the Rewloy panel', 'rewloy-for-woocommerce' ), $links->activity( $s['program_id'] ) ),
				array( __( 'Customers of this card in Rewloy', 'rewloy-for-woocommerce' ), $links->customers( $s['program_id'] ) ),
			)
		);
	}

	/* ------------------------------------------------------------------ Kasa */

	public function till(): void {
		$a = $this->panel->abilities();
		if ( ! $a['till'] ) {
			if ( '' !== $a['error'] ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $a['error'] ) . '</p></div>';
			}
			$this->ability_off( 'till' );
			return;
		}
		/* translators: %s: the branch's name. */
		echo '<p>' . esc_html( sprintf( __( 'Branch: %s. Every card read and every sale here is recorded in Rewloy at this branch, for this card only.', 'rewloy-for-woocommerce' ), '' !== $a['till_location_name'] ? $a['till_location_name'] : __( 'the till\'s branch', 'rewloy-for-woocommerce' ) ) ) . '</p>';
		echo '<noscript><div class="notice notice-warning inline"><p>' . esc_html__( 'The till needs JavaScript in this browser.', 'rewloy-for-woocommerce' ) . '</p></div></noscript>';
		echo '<form id="rewloy-wc-till-read" class="rewloy-wc-lookup" autocomplete="off">';
		echo '<label for="rewloy-wc-till-card">' . esc_html__( 'Card number or QR code', 'rewloy-for-woocommerce' ) . '</label> ';
		echo '<input type="text" id="rewloy-wc-till-card" class="regular-text" spellcheck="false" autocomplete="off" placeholder="ABCD-EFGH-JKLM" /> ';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Read the card', 'rewloy-for-woocommerce' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Type the number under the QR code, or scan the code with a USB or Bluetooth scanner into this field. Only the card number is kept from a scanned link; the rest is dropped.', 'rewloy-for-woocommerce' ) . '</p>';
		echo '</form>';
		echo '<div id="rewloy-wc-till-message" class="rewloy-wc-message" role="status" aria-live="polite"></div>';
		echo '<div id="rewloy-wc-till-card-state" hidden></div>';
		echo '<form id="rewloy-wc-till-sale" class="rewloy-wc-sale" hidden>';
		echo '<h3>' . esc_html__( 'Sale', 'rewloy-for-woocommerce' ) . '</h3>';
		echo '<p id="rewloy-wc-till-sale-writes" class="description"></p>';
		echo '<p><label for="rewloy-wc-till-amount">' . esc_html__( 'Paid total', 'rewloy-for-woocommerce' ) . '</label><br /><input type="text" id="rewloy-wc-till-amount" inputmode="decimal" size="12" placeholder="125,50" /> <span class="description">' . esc_html( $this->panel->settings()->get()['currency'] ) . '</span></p>';
		echo '<p><label for="rewloy-wc-till-reference">' . esc_html__( 'Receipt number (optional)', 'rewloy-for-woocommerce' ) . '</label><br /><input type="text" id="rewloy-wc-till-reference" maxlength="80" size="20" /></p>';
		echo '<p class="description">' . esc_html__( 'The receipt number is written on the card\'s record in Rewloy. Do not type the customer\'s personal details there.', 'rewloy-for-woocommerce' ) . '</p>';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Record the sale', 'rewloy-for-woocommerce' ) . '</button>';
		echo '</form>';
		echo '<div id="rewloy-wc-till-actions" class="rewloy-wc-actions" hidden></div>';
		$this->open_buttons(
			array(
				array( __( 'The panel\'s own till in Rewloy', 'rewloy-for-woocommerce' ), $this->panel->links()->scan() ),
			)
		);
	}

	/* --------------------------------------------------------------- helpers */

	/**
	 * A card's state as a small table (the watching screen: the number masked).
	 *
	 * @param array<string,mixed> $c Panel::card_view().
	 */
	private function card_state( array $c ): void {
		echo '<table class="widefat striped rewloy-wc-facts"><tbody>';
		$this->row( __( 'Card', 'rewloy-for-woocommerce' ), is_string( $c['shown'] ?? null ) ? $c['shown'] : '' );
		$this->row( __( 'Type', 'rewloy-for-woocommerce' ), is_string( $c['type_label'] ?? null ) ? $c['type_label'] : '' );
		$this->row( __( 'Status', 'rewloy-for-woocommerce' ), is_string( $c['status_label'] ?? null ) ? $c['status_label'] : '' );
		$this->row( is_string( $c['progress_label'] ?? null ) ? $c['progress_label'] : '', is_string( $c['progress'] ?? null ) ? $c['progress'] : '' );
		if ( is_string( $c['tier'] ?? null ) && '' !== $c['tier'] ) {
			$this->row( __( 'Level', 'rewloy-for-woocommerce' ), $c['tier'] );
		}
		$this->row( __( 'Reward', 'rewloy-for-woocommerce' ), ! empty( $c['reward_ready'] ) ? __( 'Ready', 'rewloy-for-woocommerce' ) : __( 'Not yet', 'rewloy-for-woocommerce' ) );
		$accepts = array();
		foreach ( is_array( $c['actions'] ?? null ) ? $c['actions'] : array() as $x ) {
			if ( is_array( $x ) && is_string( $x['label'] ?? null ) ) {
				$accepts[] = $x['label'] . ( ! empty( $x['ready'] ) ? '' : ' (' . __( 'not now', 'rewloy-for-woocommerce' ) . ')' );
			}
		}
		$this->row( __( 'Accepts at the till', 'rewloy-for-woocommerce' ), array() === $accepts ? '—' : implode( ' · ', $accepts ) );
		$this->row( __( 'A sale', 'rewloy-for-woocommerce' ), is_string( $c['sale'] ?? null ) ? $c['sale'] : '' );
		echo '</tbody></table>';
	}

	/** What an ability is, why it may be off, and the exact page where it is turned on. */
	private function ability_off( string $ability ): void {
		$s    = $this->panel->settings()->get();
		$link = $this->panel->links()->shop( $s['link_id'], true );
		echo '<div class="notice notice-info inline"><p>';
		if ( 'till' === $ability ) {
			echo '<strong>' . esc_html__( 'The till is off for this shop.', 'rewloy-for-woocommerce' ) . '</strong> ';
			echo esc_html__( 'With the till on, this screen reads a customer\'s card at one branch, records sales on it and uses its rewards and balance. It is off unless someone turns it on in the Rewloy panel, because then anyone who can manage WooCommerce on this site could spend customers\' balances at that branch. It is turned on, with the branch, on this shop link\'s page under "WordPress yetkileri".', 'rewloy-for-woocommerce' );
		} else {
			echo '<strong>' . esc_html__( '"Görüntüleme" is off for this shop.', 'rewloy-for-woocommerce' ) . '</strong> ';
			echo esc_html__( 'With it on, these screens show the card, its numbers and the latest activity on customers\' cards, without anyone\'s name, e-mail or phone. It is turned on in the Rewloy panel, on this shop link\'s page under "WordPress yetkileri". A shop connected before 0.3.0 has it off until then.', 'rewloy-for-woocommerce' );
		}
		echo '</p><p><a class="button" href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open in the Rewloy panel', 'rewloy-for-woocommerce' ) . '</a></p></div>';
	}

	/** The things done in the Rewloy panel, each with its exact page. */
	private function done_in_rewloy(): void {
		$s     = $this->panel->settings()->get();
		$links = $this->panel->links();
		echo '<h2>' . esc_html__( 'Done in the Rewloy panel', 'rewloy-for-woocommerce' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'This plugin shows and uses the card; it does not create or design cards. These are done in the Rewloy panel:', 'rewloy-for-woocommerce' ) . '</p><ul class="rewloy-wc-done">';
		$items = array(
			array( __( 'Create a new card', 'rewloy-for-woocommerce' ), $links->new_program() ),
			array( __( 'Design this card and change its rewards', 'rewloy-for-woocommerce' ), $links->program( $s['program_id'] ) ),
			array( __( 'Customers and their details', 'rewloy-for-woocommerce' ), $links->customers( $s['program_id'] ) ),
			array( __( 'Campaigns', 'rewloy-for-woocommerce' ), $links->campaigns() ),
			array( __( 'Analytics', 'rewloy-for-woocommerce' ), $links->analytics() ),
			array( __( 'Team and permissions', 'rewloy-for-woocommerce' ), $links->team() ),
			array( __( 'Branches', 'rewloy-for-woocommerce' ), $links->locations() ),
			array( __( 'API keys and webhooks', 'rewloy-for-woocommerce' ), $links->developers() ),
			array( __( 'Plan and billing', 'rewloy-for-woocommerce' ), $links->plan() ),
			array( __( 'What this shop\'s plugin may do ("WordPress yetkileri")', 'rewloy-for-woocommerce' ), $links->shop( $s['link_id'], true ) ),
		);
		foreach ( $items as $i ) {
			echo '<li><a href="' . esc_url( $i[1] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $i[0] ) . '</a></li>';
		}
		echo '</ul>';
	}

	/** @param list<array{0:string,1:string}> $buttons Label and link. */
	private function open_buttons( array $buttons ): void {
		echo '<p class="rewloy-wc-open">';
		foreach ( $buttons as $b ) {
			echo '<a class="button" href="' . esc_url( $b[1] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $b[0] ) . '</a> ';
		}
		echo '</p>';
	}

	private function row( string $label, string $value ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}
}
