<?php
/**
 * The settings screen: WooCommerce › Rewloy.
 *
 * It is a submenu of WooCommerce, not a tab of WooCommerce › Settings, because
 * the screen has several independent actions (save the key, connect, pause,
 * disconnect, save the options), each with its own nonce, and WooCommerce's
 * settings tabs are one form with one Save button.
 *
 * Every action: `manage_woocommerce`, then the nonce, then sanitised input;
 * every output escaped. The API key is never printed back, only a mask.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const PAGE       = 'rewloy-for-woocommerce';
	public const CAPABILITY = 'manage_woocommerce';

	/** @var callable(string): void */
	private $redirect;

	/**
	 * @param Settings                      $settings   The saved settings.
	 * @param Connection                    $connection What the screen does.
	 * @param (callable(string): void)|null $redirect   Replaces the redirect after an action (tests).
	 */
	public function __construct( private Settings $settings, private Connection $connection, ?callable $redirect = null ) {
		$this->redirect = $redirect ?? static function ( string $url ): void {
			wp_safe_redirect( $url );
			exit;
		};
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( REWLOY_WC_FILE ), array( $this, 'action_links' ) );
		foreach ( array( 'save_key', 'forget_key', 'connect', 'toggle', 'disconnect', 'reactivate', 'save_options' ) as $action ) {
			add_action( 'admin_post_rewloy_wc_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Rewloy for WooCommerce', 'rewloy-for-woocommerce' ),
			__( 'Rewloy', 'rewloy-for-woocommerce' ),
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * @param mixed $links The plugin's row links.
	 * @return mixed
	 */
	public function action_links( $links ) {
		if ( is_array( $links ) ) {
			array_unshift( $links, '<a href="' . esc_url( $this->url() ) . '">' . esc_html__( 'Settings', 'rewloy-for-woocommerce' ) . '</a>' );
		}
		return $links;
	}

	public function url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/* ------------------------------------------------------------------ actions */

	public function handle_save_key(): void {
		$this->act( 'save_key', fn() => $this->connection->save_key( $this->post( 'rewloy_api_key' ) ) );
	}

	public function handle_forget_key(): void {
		$this->act( 'forget_key', fn() => $this->connection->forget_key() );
	}

	public function handle_connect(): void {
		$this->act(
			'connect',
			fn() => $this->connection->connect(
				array(
					'program_id' => $this->post( 'program_id' ),
					'rule'       => $this->post( 'rule' ),
					'per_amount' => $this->post( 'per_amount' ),
					'step'       => $this->post( 'step' ),
				)
			)
		);
	}

	public function handle_toggle(): void {
		$this->act( 'toggle', fn() => $this->connection->set_paused( '1' === $this->post( 'pause' ) ) );
	}

	public function handle_disconnect(): void {
		$this->act( 'disconnect', fn() => $this->connection->disconnect( '1' === $this->post( 'local_only' ) ) );
	}

	public function handle_reactivate(): void {
		$this->act( 'reactivate', fn() => $this->connection->reactivate_webhook() );
	}

	public function handle_save_options(): void {
		$this->act(
			'save_options',
			function (): Result {
				$clean     = $this->settings->sanitize_options( $this->posted() );
				$connected = $this->settings->is_connected();
				$before    = $this->settings->get()['account_tab'];
				$clean['invite']      = $clean['invite'] && $connected;
				$clean['account_tab'] = $clean['account_tab'] && $connected;
				$this->settings->update( $clean );
				if ( $before !== $clean['account_tab'] ) {
					update_option( Plugin::FLUSH_OPTION, '1', false );
				}
				return Result::ok( __( 'Saved.', 'rewloy-for-woocommerce' ) );
			}
		);
	}

	/**
	 * The one door of every action: capability first, then the nonce, then the work, then a redirect back with a message.
	 *
	 * @param callable(): Result $work What to do.
	 */
	private function act( string $action, callable $work ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'rewloy-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		// These forms are POSTs: a nonce in a link (a GET) must not turn the connection on, off or away.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : '';
		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'rewloy-for-woocommerce' ), '', array( 'response' => 405 ) );
		}
		check_admin_referer( 'rewloy_wc_' . $action );
		$result = $work();
		set_transient(
			'rewloy_wc_flash_' . get_current_user_id(),
			array(
				'ok'   => $result->ok,
				'text' => $result->message,
			),
			120
		);
		( $this->redirect )( $this->url() );
	}

	/** One posted value, unslashed and cleaned of tags and line breaks. */
	private function post( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- act() checks the nonce first.
		$value = $_POST[ $name ] ?? '';
		return is_string( $value ) ? trim( sanitize_text_field( wp_unslash( $value ) ) ) : '';
	}

	/**
	 * Every posted field, unslashed (for sanitize_options, which cleans each by its own rules).
	 *
	 * @return array<string,mixed>
	 */
	private function posted(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- act() checks the nonce first.
		$all = wp_unslash( $_POST );
		return is_array( $all ) ? $all : array();
	}

	/* ------------------------------------------------------------------ screen */

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'rewloy-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		echo '<div class="wrap rewloy-wc">';
		echo '<h1>' . esc_html__( 'Rewloy for WooCommerce', 'rewloy-for-woocommerce' ) . '</h1>';
		echo '<p>' . esc_html__( 'Paid orders fill the loyalty cards of Rewloy customers.', 'rewloy-for-woocommerce' ) . '</p>';
		$this->flash();

		if ( $this->settings->is_connected() ) {
			$this->section_connected();
			$this->section_options();
		} elseif ( 'none' === $this->settings->key_source() ) {
			$this->section_key();
		} else {
			$this->section_connect();
		}
		$this->section_privacy();
		echo '</div>';
	}

	private function flash(): void {
		$key   = 'rewloy_wc_flash_' . get_current_user_id();
		$flash = get_transient( $key );
		if ( ! is_array( $flash ) ) {
			return;
		}
		delete_transient( $key );
		$class = ! empty( $flash['ok'] ) ? 'notice-success' : 'notice-error';
		$text  = is_string( $flash['text'] ?? null ) ? $flash['text'] : '';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}

	/** A small POST form to admin-post.php with its nonce. */
	private function form_open( string $action ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( 'rewloy_wc_' . $action ) . '" />';
		wp_nonce_field( 'rewloy_wc_' . $action );
	}

	private function key_row(): void {
		$source = $this->settings->key_source();
		$mask   = $this->settings->mask( $this->settings->api_key() );
		echo '<p><strong>' . esc_html__( 'API key', 'rewloy-for-woocommerce' ) . ':</strong> <code>' . esc_html( $mask ) . '</code> ';
		echo '<span class="description">' . esc_html( 'constant' === $source ? __( '(set by REWLOY_API_KEY in wp-config.php)', 'rewloy-for-woocommerce' ) : __( '(saved on this site; the key is never shown again)', 'rewloy-for-woocommerce' ) ) . '</span></p>';
	}

	private function section_key(): void {
		echo '<h2>' . esc_html__( '1. API key', 'rewloy-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Create an API key in the Rewloy panel, under Developer, and paste it here. It must be allowed to see cards and settings, to manage API keys and shop links, and to issue cards. Rewloy checks the key, and only then is it saved.', 'rewloy-for-woocommerce' ) . '</p>';
		$this->form_open( 'save_key' );
		echo '<p><label for="rewloy_api_key">' . esc_html__( 'API key', 'rewloy-for-woocommerce' ) . '</label><br />';
		echo '<input type="password" name="rewloy_api_key" id="rewloy_api_key" class="regular-text" autocomplete="off" spellcheck="false" required /></p>';
		echo '<p class="description">' . esc_html__( 'You can also set it in wp-config.php with define( \'REWLOY_API_KEY\', \'rwk_…\' ); that one then wins.', 'rewloy-for-woocommerce' ) . '</p>';
		submit_button( __( 'Check and save the key', 'rewloy-for-woocommerce' ) );
		echo '</form>';
	}

	private function section_connect(): void {
		echo '<h2>' . esc_html__( '2. Card and rule', 'rewloy-for-woocommerce' ) . '</h2>';
		$this->key_row();
		if ( 'option' === $this->settings->key_source() ) {
			$this->form_open( 'forget_key' );
			submit_button( __( 'Remove the key', 'rewloy-for-woocommerce' ), 'link-delete small', 'submit', false );
			echo '</form>';
		}
		try {
			$programs = $this->connection->programs();
		} catch ( RewloyException $e ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( Messages::for_error( $e ) ) . '</p></div>';
			return;
		}
		if ( array() === $programs ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No card can be filled by orders yet. In the Rewloy panel, create an active stamp, points, VIP or cashback card. Gift cards, coupons and discount cards are given by code.', 'rewloy-for-woocommerce' ) . '</p></div>';
			return;
		}
		$this->form_open( 'connect' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="rewloy_program">' . esc_html__( 'Card', 'rewloy-for-woocommerce' ) . '</label></th><td><select name="program_id" id="rewloy_program" required>';
		foreach ( $programs as $p ) {
			echo '<option value="' . esc_attr( $p['id'] ) . '">' . esc_html( $p['name'] . ' (' . Messages::type_label( $p['type'] ) . ')' ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Rule', 'rewloy-for-woocommerce' ) . '</th><td>';
		echo '<p class="description">' . esc_html__( 'For stamp and points cards. A VIP card counts one visit per paid order; a cashback card applies its own rate to the order total.', 'rewloy-for-woocommerce' ) . '</p>';
		echo '<p><label><input type="radio" name="rule" value="order" checked /> ' . esc_html__( 'For every order, whatever the amount', 'rewloy-for-woocommerce' ) . '</label></p>';
		echo '<p><label><input type="radio" name="rule" value="amount" /> ' . esc_html__( 'By the order total: for every amount of', 'rewloy-for-woocommerce' ) . '</label> ';
		echo '<input type="text" name="per_amount" value="100" size="8" inputmode="decimal" aria-label="' . esc_attr__( 'Amount threshold', 'rewloy-for-woocommerce' ) . '" /></p>';
		echo '<p class="description">' . esc_html__( 'Example: 100 means a 250 order counts twice. The order must be in the card\'s currency.', 'rewloy-for-woocommerce' ) . '</p>';
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="rewloy_step">' . esc_html__( 'How many stamps or points', 'rewloy-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="number" name="step" id="rewloy_step" value="1" min="1" max="100" /></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Connect', 'rewloy-for-woocommerce' ) );
		echo '</form>';
		echo '<p class="description">' . esc_html__( 'Connecting creates the link in Rewloy and a WooCommerce webhook (topic "Order updated") that this plugin keeps.', 'rewloy-for-woocommerce' ) . '</p>';
	}

	private function section_connected(): void {
		$s      = $this->settings->get();
		$health = $this->connection->health();
		$link   = $health['link'];
		$on     = null === $link ? null : ! empty( $link['enabled'] );

		echo '<h2>' . esc_html__( 'Connection', 'rewloy-for-woocommerce' ) . '</h2>';
		$this->key_row();
		echo '<table class="widefat striped" style="max-width:48rem"><tbody>';
		$this->row( __( 'Card', 'rewloy-for-woocommerce' ), $s['program_name'] . ' (' . Messages::type_label( $s['program_type'] ) . ')' );
		$this->row( __( 'Rule', 'rewloy-for-woocommerce' ), Messages::rule_text( $s['program_type'], $s['rule'], $s['per_amount_minor'], $s['step'], $s['currency'] ) );
		$this->row( __( 'Link', 'rewloy-for-woocommerce' ), null === $on ? __( 'Unknown (see the message below)', 'rewloy-for-woocommerce' ) : ( $on ? __( 'On', 'rewloy-for-woocommerce' ) : __( 'Off', 'rewloy-for-woocommerce' ) ) );
		$w = $health['webhook'];
		if ( ! $w['exists'] ) {
			$this->row( __( 'WooCommerce webhook', 'rewloy-for-woocommerce' ), __( 'Missing. It was deleted; orders no longer reach Rewloy. Remove the connection and connect again.', 'rewloy-for-woocommerce' ) );
		} else {
			/* translators: 1: webhook status, 2: number of failed deliveries. */
			$this->row( __( 'WooCommerce webhook', 'rewloy-for-woocommerce' ), sprintf( __( '%1$s, failed deliveries: %2$d', 'rewloy-for-woocommerce' ), $this->webhook_status( $w['status'] ), $w['failures'] ) );
		}
		if ( null !== $link ) {
			$this->row( __( 'Last order seen', 'rewloy-for-woocommerce' ), $this->when( $link['lastOrderAt'] ?? null ) );
		}
		echo '</tbody></table>';

		if ( '' !== $health['error'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $health['error'] ) . '</p></div>';
		}
		if ( $w['exists'] && 'active' !== $w['status'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The webhook is not active, so orders are not reaching Rewloy. WooCommerce turns a webhook off after repeated failed deliveries.', 'rewloy-for-woocommerce' ) . '</p>';
			$this->form_open( 'reactivate' );
			submit_button( __( 'Turn the webhook on again', 'rewloy-for-woocommerce' ), 'secondary', 'submit', false );
			echo '</form></div>';
		}
		if ( $w['exists'] && '' !== $w['edit_url'] ) {
			echo '<p><a href="' . esc_url( $w['edit_url'] ) . '">' . esc_html__( 'Open the webhook in WooCommerce (delivery log)', 'rewloy-for-woocommerce' ) . '</a></p>';
		}

		if ( null !== $link && is_array( $link['orders'] ?? null ) ) {
			echo '<h3>' . esc_html__( 'Orders by outcome', 'rewloy-for-woocommerce' ) . '</h3><ul>';
			foreach ( Messages::OUTCOMES as $o ) {
				$n = is_numeric( $link['orders'][ $o ] ?? null ) ? (int) $link['orders'][ $o ] : 0;
				echo '<li><strong>' . esc_html( Messages::outcome_label( $o ) ) . ':</strong> ' . esc_html( (string) $n ) . ' <span class="description">' . esc_html( Messages::outcome_why( $o ) ) . '</span></li>';
			}
			echo '</ul>';
		}
		$this->orders_table( $health['orders'] );

		echo '<h3>' . esc_html__( 'Manage', 'rewloy-for-woocommerce' ) . '</h3>';
		if ( null !== $on ) {
			$this->form_open( 'toggle' );
			echo '<input type="hidden" name="pause" value="' . esc_attr( $on ? '1' : '0' ) . '" />';
			submit_button( $on ? __( 'Turn the link off', 'rewloy-for-woocommerce' ) : __( 'Turn the link on', 'rewloy-for-woocommerce' ), 'secondary', 'submit', false );
			echo '</form><p class="description">' . esc_html__( 'While off, Rewloy records orders but does not add them to cards, now or later. The webhook stays.', 'rewloy-for-woocommerce' ) . '</p>';
		}
		$confirm = __( 'Remove the connection? The link in Rewloy and the webhook are deleted. Cards keep what they already got.', 'rewloy-for-woocommerce' );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(' . esc_attr( (string) wp_json_encode( $confirm ) ) . ');">';
		echo '<input type="hidden" name="action" value="rewloy_wc_disconnect" />';
		wp_nonce_field( 'rewloy_wc_disconnect' );
		submit_button( __( 'Remove the connection', 'rewloy-for-woocommerce' ), 'delete', 'submit', false );
		echo '</form>';
		echo '<details style="margin-top:1em"><summary>' . esc_html__( 'If Rewloy cannot be reached', 'rewloy-for-woocommerce' ) . '</summary>';
		echo '<p class="description">' . esc_html__( 'Forget the connection on this site only: the webhook is deleted here, but the link in Rewloy stays until you delete it in the Rewloy panel.', 'rewloy-for-woocommerce' ) . '</p>';
		$this->form_open( 'disconnect' );
		echo '<input type="hidden" name="local_only" value="1" />';
		submit_button( __( 'Forget the connection on this site only', 'rewloy-for-woocommerce' ), 'secondary', 'submit', false );
		echo '</form></details>';
	}

	/**
	 * @param list<array<string,mixed>> $orders The link's last recorded orders.
	 */
	private function orders_table( array $orders ): void {
		echo '<h3>' . esc_html__( 'Last orders', 'rewloy-for-woocommerce' ) . '</h3>';
		if ( array() === $orders ) {
			echo '<p>' . esc_html__( 'No order has arrived yet. The first paid order will show here with its outcome. Unpaid orders are not recorded.', 'rewloy-for-woocommerce' ) . '</p>';
			return;
		}
		echo '<div style="overflow-x:auto"><table class="widefat striped" style="max-width:48rem"><thead><tr><th>' . esc_html__( 'Order', 'rewloy-for-woocommerce' ) . '</th><th>' . esc_html__( 'Outcome', 'rewloy-for-woocommerce' ) . '</th><th>' . esc_html__( 'Time', 'rewloy-for-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( $orders as $o ) {
			$id      = is_scalar( $o['orderId'] ?? null ) ? (string) $o['orderId'] : '';
			$outcome = is_string( $o['outcome'] ?? null ) ? $o['outcome'] : '';
			echo '<tr><td>#' . esc_html( $id ) . '</td><td>' . esc_html( Messages::outcome_label( $outcome ) ) . '</td><td>' . esc_html( $this->when( $o['at'] ?? null ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private function section_options(): void {
		$s = $this->settings->get();
		echo '<h2>' . esc_html__( 'On your shop', 'rewloy-for-woocommerce' ) . '</h2>';
		$this->form_open( 'save_options' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Invitation at checkout', 'rewloy-for-woocommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="invite" value="1"' . checked( $s['invite'], true, false ) . ' /> ' . esc_html__( 'Show an unticked box at checkout that invites the buyer to a loyalty card', 'rewloy-for-woocommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'When the order is paid and the box was ticked, a card is opened once for the billing e-mail and its link is e-mailed to the buyer. Only the e-mail address is sent to Rewloy. Works on the classic checkout and, from WooCommerce 8.9, on the block checkout.', 'rewloy-for-woocommerce' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="rewloy_controller_name">' . esc_html__( 'Data controller', 'rewloy-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="text" name="controller_name" id="rewloy_controller_name" class="regular-text" maxlength="120" value="' . esc_attr( $s['controller_name'] ) . '" placeholder="' . esc_attr( (string) get_bloginfo( 'name' ) ) . '" /> ';
		echo '<input type="email" name="controller_email" class="regular-text" maxlength="254" value="' . esc_attr( $s['controller_email'] ) . '" aria-label="' . esc_attr__( 'Contact e-mail', 'rewloy-for-woocommerce' ) . '" placeholder="' . esc_attr__( 'Contact e-mail (optional)', 'rewloy-for-woocommerce' ) . '" />';
		echo '<p class="description">' . esc_html__( 'Named in the notice beside the checkout box and in the card e-mail: the business that answers for the buyer\'s data. Your legal name, if you have one.', 'rewloy-for-woocommerce' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'My Account tab', 'rewloy-for-woocommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="account_tab" value="1"' . checked( $s['account_tab'], true, false ) . ' /> ' . esc_html__( 'Add a "My loyalty card" tab to My Account', 'rewloy-for-woocommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'The tab does not show card data. WooCommerce does not verify that an account\'s e-mail is the person\'s own, so showing a card by e-mail would let anyone who registers with someone else\'s address see their card. The tab sends the customer to Rewloy Cüzdan, where they prove their address with a code, and shows your join link. It makes no call to Rewloy.', 'rewloy-for-woocommerce' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save', 'rewloy-for-woocommerce' ) );
		echo '</form>';
	}

	private function section_privacy(): void {
		echo '<h2>' . esc_html__( 'What goes to Rewloy', 'rewloy-for-woocommerce' ) . '</h2><ul style="list-style:disc;margin-left:1.5em">';
		echo '<li>' . esc_html__( 'For each order update, the webhook sends the order\'s number, status, currency and total, signed with a secret only this shop and Rewloy have, and the billing e-mail once the order is processing or completed. Nothing else of the order (no names, addresses, phone numbers or items). Rewloy stores only the order number, its outcome and the time.', 'rewloy-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'If you turn on the invitation, the billing e-mail of an order whose box was ticked is sent once, to open the card.', 'rewloy-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'Nothing else, and no tracking. The My Account tab sends nothing.', 'rewloy-for-woocommerce' ) . '</li>';
		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'Deleting this plugin removes its settings and its webhook from this site. It does not delete anything in Rewloy: the link and the cards stay until you delete them in the Rewloy panel.', 'rewloy-for-woocommerce' ) . '</p>';
	}

	private function row( string $label, string $value ): void {
		echo '<tr><th scope="row" style="width:14rem">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	private function webhook_status( string $status ): string {
		switch ( $status ) {
			case 'active':
				return __( 'active', 'rewloy-for-woocommerce' );
			case 'paused':
				return __( 'paused', 'rewloy-for-woocommerce' );
			case 'disabled':
				return __( 'disabled', 'rewloy-for-woocommerce' );
			default:
				return $status;
		}
	}

	/** A date from the API (ISO 8601) in the site's own format and time zone, or "none yet". */
	private function when( mixed $iso ): string {
		if ( ! is_string( $iso ) || '' === $iso ) {
			return __( 'none yet', 'rewloy-for-woocommerce' );
		}
		$ts = strtotime( $iso );
		if ( false === $ts ) {
			return __( 'none yet', 'rewloy-for-woocommerce' );
		}
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		return (string) wp_date( $format, $ts );
	}
}
