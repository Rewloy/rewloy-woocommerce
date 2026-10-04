<?php
/**
 * The "Rewloy" admin menu (0.3.0): a top-level menu with four tabs — Özet, Kartlar, Kasa (Screens.php) and Ayarlar,
 * the connection and options screen that was WooCommerce › Rewloy until 0.2 and behaves as it did. The old
 * WooCommerce › Rewloy entry stays and opens Ayarlar, so existing users find it.
 *
 * Ayarlar has several independent actions (connect with a code, save a key, connect, pause, disconnect, save the
 * options), each with its own nonce: WooCommerce's settings tabs are one form with one Save button.
 *
 * Every action: `manage_woocommerce`, then the nonce, then sanitised input;
 * every output escaped. The API key and the connect code are never printed
 * back; the key only as a mask.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const PAGE       = 'rewloy-for-woocommerce';
	public const CAPABILITY = 'manage_woocommerce';
	/** The tabs, in their order. */
	public const TABS = array( 'overview', 'cards', 'till', 'settings' );

	/** @var callable(string): void */
	private $redirect;
	private ?Screens $screens;
	private ?Panel $panel;
	private ?CheckoutSettings $checkout;

	/**
	 * @param Settings                      $settings   The saved settings.
	 * @param Connection                    $connection What the Ayarlar tab does.
	 * @param (callable(string): void)|null $redirect   Replaces the redirect after an action (tests).
	 * @param Panel|null                    $panel      The reads of the other tabs; made from the settings when null.
	 * @param CheckoutSettings|null         $checkout   The checkout codes' settings; made from the settings when null.
	 */
	public function __construct( private Settings $settings, private Connection $connection, ?callable $redirect = null, ?Panel $panel = null, ?CheckoutSettings $checkout = null ) {
		$this->redirect = $redirect ?? static function ( string $url ): void {
			wp_safe_redirect( $url );
			exit;
		};
		$this->panel    = $panel;
		$this->screens  = null;
		$this->checkout = $checkout;
	}

	private function checkout(): CheckoutSettings {
		$this->checkout ??= new CheckoutSettings( $this->settings, Plugin::client_factory( $this->settings ) );
		return $this->checkout;
	}

	private function panel(): Panel {
		$this->panel ??= new Panel( $this->settings, Plugin::client_factory( $this->settings ), Links::for_site() );
		return $this->panel;
	}

	private function screens(): Screens {
		$this->screens ??= new Screens( $this->panel(), $this->connection );
		return $this->screens;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'submenu_file', array( $this, 'submenu_file' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( REWLOY_WC_FILE ), array( $this, 'action_links' ) );
		foreach ( array( 'connect_code', 'save_key', 'forget_key', 'connect', 'toggle', 'disconnect', 'reactivate', 'save_options', 'save_checkout' ) as $action ) {
			add_action( 'admin_post_rewloy_wc_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	/**
	 * The top-level "Rewloy" menu, its tabs as submenu entries, and the old WooCommerce › Rewloy entry, which now
	 * opens Ayarlar. The page keeps the slug it had as a submenu, so old links and bookmarks still land on it.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Rewloy', 'rewloy-for-woocommerce' ),
			__( 'Rewloy', 'rewloy-for-woocommerce' ),
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render' ),
			'dashicons-tickets-alt',
			56
		);
		add_submenu_page( self::PAGE, __( 'Rewloy', 'rewloy-for-woocommerce' ), self::tab_label( 'overview' ), self::CAPABILITY, self::PAGE, array( $this, 'render' ) );
		foreach ( array( 'cards', 'till', 'settings' ) as $tab ) {
			// A submenu entry whose slug is the tab's address, with no page of its own: WordPress links it as it is.
			add_submenu_page( self::PAGE, __( 'Rewloy', 'rewloy-for-woocommerce' ), self::tab_label( $tab ), self::CAPABILITY, 'admin.php?page=' . self::PAGE . '&tab=' . $tab );
		}
		add_submenu_page( 'woocommerce', __( 'Rewloy', 'rewloy-for-woocommerce' ), __( 'Rewloy', 'rewloy-for-woocommerce' ), self::CAPABILITY, 'admin.php?page=' . self::PAGE . '&tab=settings' );
	}

	/**
	 * Marks the open tab's entry in the Rewloy menu as current (WordPress would mark the first one on every tab).
	 *
	 * @param mixed $submenu_file The submenu entry WordPress marks.
	 * @param mixed $parent_file  The menu it belongs to.
	 * @return mixed
	 */
	public function submenu_file( $submenu_file, $parent_file = '' ) {
		if ( self::PAGE !== $parent_file ) {
			return $submenu_file;
		}
		$tab = $this->current_tab();
		return 'overview' === $tab ? $submenu_file : 'admin.php?page=' . self::PAGE . '&tab=' . $tab;
	}

	/** A tab's name. */
	public static function tab_label( string $tab ): string {
		switch ( $tab ) {
			case 'cards':
				return __( 'Cards', 'rewloy-for-woocommerce' );
			case 'till':
				return __( 'Till', 'rewloy-for-woocommerce' );
			case 'settings':
				return __( 'Settings', 'rewloy-for-woocommerce' );
			default:
				return __( 'Overview', 'rewloy-for-woocommerce' );
		}
	}

	/** A tab's address. */
	public static function tab_url( string $tab ): string {
		return admin_url( 'admin.php?page=' . self::PAGE . ( 'overview' === $tab ? '' : '&tab=' . ( in_array( $tab, self::TABS, true ) ? $tab : 'settings' ) ) );
	}

	/**
	 * The tab asked for: one of TABS. Without a connection only Ayarlar has anything to show; with one, Özet is first.
	 */
	public function current_tab(): string {
		if ( ! $this->settings->is_connected() ) {
			return 'settings';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which tab to show; it changes nothing.
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return in_array( $tab, self::TABS, true ) ? $tab : 'overview';
	}

	/**
	 * The two small scripts and the stylesheet, on this page only: the watching script on Kartlar, the till's on Kasa.
	 * No build step, no CDN.
	 *
	 * @param mixed $hook_suffix The admin page being loaded.
	 */
	public function enqueue( $hook_suffix ): void {
		if ( 'toplevel_page_' . self::PAGE !== $hook_suffix || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		wp_enqueue_style( 'rewloy-wc-panel', plugins_url( 'assets/panel.css', REWLOY_WC_FILE ), array(), Plugin::VERSION );
		$tab    = $this->current_tab();
		$script = array(
			'cards' => 'watch',
			'till'  => 'till',
		)[ $tab ] ?? '';
		if ( '' === $script || ( 'till' === $script && ! current_user_can( Capability::TILL ) ) ) {
			return;
		}
		wp_enqueue_script( 'rewloy-wc-' . $script, plugins_url( 'assets/' . $script . '.js', REWLOY_WC_FILE ), array(), Plugin::VERSION, true );
		wp_localize_script(
			'rewloy-wc-' . $script,
			'RewloyWc',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( Ajax::NONCE ),
				'every' => 30,
				'text'  => self::script_text(),
			)
		);
	}

	/**
	 * The scripts' words, translated here (the scripts carry none of their own).
	 *
	 * @return array<string,string>
	 */
	public static function script_text(): array {
		return array(
			'refreshed'   => __( 'Refreshed at', 'rewloy-for-woocommerce' ),
			'empty'       => __( 'Nothing has happened on customers\' cards yet.', 'rewloy-for-woocommerce' ),
			'offline'     => __( 'Rewloy could not be reached or its answer could not be read. Try again in a moment.', 'rewloy-for-woocommerce' ),
			'notCard'     => __( 'That is not a Rewloy card number. Type the 12 letters and digits under the QR code, or scan the code.', 'rewloy-for-woocommerce' ),
			'reading'     => __( 'Reading the card…', 'rewloy-for-woocommerce' ),
			'sending'     => __( 'Sending to Rewloy…', 'rewloy-for-woocommerce' ),
			'retry'       => __( 'Try again', 'rewloy-for-woocommerce' ),
			'retryNote'   => __( 'No clear answer came. "Try again" sends the same press again; Rewloy never writes it twice.', 'rewloy-for-woocommerce' ),
			'card'        => __( 'Card', 'rewloy-for-woocommerce' ),
			'type'        => __( 'Type', 'rewloy-for-woocommerce' ),
			'status'      => __( 'Status', 'rewloy-for-woocommerce' ),
			'reward'      => __( 'Reward', 'rewloy-for-woocommerce' ),
			'ready'       => __( 'Ready', 'rewloy-for-woocommerce' ),
			'notYet'      => __( 'Not yet', 'rewloy-for-woocommerce' ),
			'level'       => __( 'Level', 'rewloy-for-woocommerce' ),
			'notHere'     => __( 'This card is not valid at this branch.', 'rewloy-for-woocommerce' ),
			'operations'  => __( 'The card\'s own operations', 'rewloy-for-woocommerce' ),
			'noneNow'     => __( 'None of the card\'s operations can be done right now.', 'rewloy-for-woocommerce' ),
			'noView'      => __( 'The card\'s state and operations need "Görüntüleme", which is off for this shop; a sale can still be recorded.', 'rewloy-for-woocommerce' ),
			'amount'      => __( 'Amount', 'rewloy-for-woocommerce' ),
			'points'      => __( 'Points', 'rewloy-for-woocommerce' ),
			'rewardNo'    => __( 'Reward number (from 0)', 'rewloy-for-woocommerce' ),
			'confirm'     => __( 'This spends something of the customer\'s. Go ahead?', 'rewloy-for-woocommerce' ),
			'pendingNote' => __( 'An earlier press got no clear answer, so it may or may not have been recorded. Until a clear answer comes, the sale and the card\'s buttons are locked. "Try again" sends that same press; Rewloy never writes it twice.', 'rewloy-for-woocommerce' ),
			'pendingFirst' => __( 'An earlier press is still waiting for a clear answer. Press "Try again" for it first.', 'rewloy-for-woocommerce' ),
			'customer'    => __( 'Open the customer in Rewloy', 'rewloy-for-woocommerce' ),
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

	/** Where an Ayarlar action returns: the Ayarlar tab. */
	public function url(): string {
		return self::tab_url( 'settings' );
	}

	/* ------------------------------------------------------------------ actions */

	public function handle_connect_code(): void {
		$this->act( 'connect_code', fn() => $this->connection->connect_with_code( $this->post( 'rewloy_connect_code' ) ) );
	}

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
				// Who may use the till is an administrator's choice (D45): anyone else's form keeps what was saved.
				if ( ! current_user_can( 'manage_options' ) ) {
					$clean['till_shop_managers'] = $this->settings->get()['till_shop_managers'];
				}
				$this->settings->update( $clean );
				if ( $before !== $clean['account_tab'] ) {
					update_option( Plugin::FLUSH_OPTION, '1', false );
				}
				return Result::ok( __( 'Saved.', 'rewloy-for-woocommerce' ) );
			}
		);
	}

	/** The checkout codes' settings (0.4.0): saved in Rewloy; an administrator's to change (D45's pattern). */
	public function handle_save_checkout(): void {
		$this->act( 'save_checkout', fn(): Result => $this->checkout()->save( $this->posted(), current_user_can( 'manage_options' ) ) );
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
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
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
		return isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) ) : '';
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
		$tab = $this->current_tab();
		echo '<div class="wrap rewloy-wc">';
		echo '<h1>' . esc_html__( 'Rewloy', 'rewloy-for-woocommerce' ) . '</h1>';
		$this->tabs( $tab );
		$this->flash();
		switch ( $tab ) {
			case 'overview':
				$this->screens()->overview();
				break;
			case 'cards':
				$this->screens()->cards( $this->posted_card() );
				break;
			case 'till':
				$this->screens()->till();
				break;
			default:
				$this->render_settings();
		}
		echo '</div>';
	}

	/** The tabs, WordPress's own nav-tab markup; only Ayarlar until the shop is connected. */
	private function tabs( string $current ): void {
		$tabs = $this->settings->is_connected() ? self::TABS : array( 'settings' );
		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__( 'Rewloy', 'rewloy-for-woocommerce' ) . '">';
		foreach ( $tabs as $tab ) {
			$on = $tab === $current;
			echo '<a href="' . esc_url( self::tab_url( $tab ) ) . '" class="nav-tab' . ( $on ? ' nav-tab-active' : '' ) . '"' . ( $on ? ' aria-current="page"' : '' ) . '>' . esc_html( self::tab_label( $tab ) ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * A card number posted to the Kartlar lookup, after its nonce: '' when nothing was posted. A read, so a refused
	 * nonce only stops the lookup.
	 */
	private function posted_card(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked right below before the value is used.
		if ( ! isset( $_POST['rewloy_card'] ) || ! is_string( $_POST['rewloy_card'] ) ) {
			return '';
		}
		check_admin_referer( 'rewloy_wc_card_lookup' );
		return trim( sanitize_text_field( wp_unslash( $_POST['rewloy_card'] ) ) );
	}

	/** Ayarlar: the 0.2 screen, as it was. */
	private function render_settings(): void {
		echo '<p>' . esc_html__( 'Paid orders fill the loyalty cards of Rewloy customers.', 'rewloy-for-woocommerce' ) . '</p>';
		if ( $this->settings->is_connected() ) {
			$health = $this->connection->health();
			$this->section_connected( $health );
			$this->section_checkout( $health['link'] );
			$this->section_options();
		} elseif ( 'none' === $this->settings->key_source() ) {
			$this->section_code();
			$this->section_key();
		} else {
			$this->section_connect();
		}
		$this->section_privacy();
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
		if ( 'constant' === $source ) {
			$note = __( '(set by REWLOY_API_KEY in wp-config.php)', 'rewloy-for-woocommerce' );
		} elseif ( Settings::VIA_CODE === $this->settings->get()['via'] ) {
			$note = __( '(made by the connect code for this shop\'s link only; saved on this site and never shown again)', 'rewloy-for-woocommerce' );
		} else {
			$note = __( '(saved on this site; the key is never shown again)', 'rewloy-for-woocommerce' );
		}
		echo '<p><strong>' . esc_html__( 'API key', 'rewloy-for-woocommerce' ) . ':</strong> <code>' . esc_html( $mask ) . '</code> ';
		echo '<span class="description">' . esc_html( $note ) . '</span></p>';
		if ( $this->settings->is_test_key() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Test environment: this key belongs to your Rewloy test environment, so cards and orders here are not real and nothing reaches customers from Rewloy. A checkout invitation still e-mails the card link to the address typed at checkout, so use test orders only.', 'rewloy-for-woocommerce' ) . '</p></div>';
		}
	}

	private function section_code(): void {
		echo '<h2>' . esc_html__( 'Connect with a code', 'rewloy-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'In the Rewloy panel go to E-ticaret › Mağaza bağla › WooCommerce › "Rewloy eklentisiyle". Choose the card and the rule there. Rewloy gives a one-time code that works for 15 minutes; paste it here.', 'rewloy-for-woocommerce' ) . '</p>';
		$this->form_open( 'connect_code' );
		echo '<p><label for="rewloy_connect_code">' . esc_html__( 'Connect code', 'rewloy-for-woocommerce' ) . '</label><br />';
		echo '<input type="password" name="rewloy_connect_code" id="rewloy_connect_code" class="regular-text" autocomplete="off" spellcheck="false" placeholder="rwc_…" required /></p>';
		submit_button( __( 'Connect', 'rewloy-for-woocommerce' ) );
		echo '</form>';
		echo '<p class="description">' . esc_html__( 'Connecting creates the link in Rewloy, a WooCommerce webhook that this plugin keeps, and an API key for this shop only: it sees only this link and can issue cards on its card. The key is saved on this site, never shown again, and Rewloy revokes it when the link is deleted. You need no API key of your own.', 'rewloy-for-woocommerce' ) . '</p>';
	}

	private function section_key(): void {
		echo '<details style="margin-top:1.5em"><summary><strong>' . esc_html__( 'Advanced: connect with an API key instead', 'rewloy-for-woocommerce' ) . '</strong></summary>';
		echo '<p>' . esc_html__( 'Use this when nobody can make a code: a site set up from a script (WP-CLI, deployment tooling), a staging copy, or a key kept in wp-config.php. A code is single-use and lasts 15 minutes, and it needs someone signed in to the Rewloy panel. A key of your own stays on this site with whatever it may do, so make it with the E-ticaret role: cards, shop links and issuing cards, and nothing else. Rewloy checks the key, and only then is it saved.', 'rewloy-for-woocommerce' ) . '</p>';
		$this->form_open( 'save_key' );
		echo '<p><label for="rewloy_api_key">' . esc_html__( 'API key', 'rewloy-for-woocommerce' ) . '</label><br />';
		echo '<input type="password" name="rewloy_api_key" id="rewloy_api_key" class="regular-text" autocomplete="off" spellcheck="false" required /></p>';
		echo '<p class="description">' . esc_html__( 'You can also set it in wp-config.php with define( \'REWLOY_API_KEY\', \'rwk_…\' ); that one then wins.', 'rewloy-for-woocommerce' ) . '</p>';
		submit_button( __( 'Check and save the key', 'rewloy-for-woocommerce' ) );
		echo '</form></details>';
	}

	private function section_connect(): void {
		echo '<h2>' . esc_html__( 'Card and rule', 'rewloy-for-woocommerce' ) . '</h2>';
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
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'What an order does depends on the card:', 'rewloy-for-woocommerce' ) . '</p>';
		echo '<ul class="ul-disc">';
		$types = array_column( $programs, 'type' );
		if ( in_array( 'stamp', $types, true ) || in_array( 'points', $types, true ) ) {
			echo '<li>' . esc_html( Messages::stamp_points_note() ) . '</li>';
		}
		if ( in_array( 'vip', $types, true ) ) {
			echo '<li>' . esc_html( Messages::vip_note() ) . '</li>';
		}
		foreach ( $programs as $p ) {
			if ( 'cashback' === $p['type'] ) {
				echo '<li>' . esc_html( Messages::cashback_note( $p['name'], $p['cashback_rate'], $p['currency'] ) ) . '</li>';
			}
		}
		echo '</ul></td></tr>';
		if ( array() === array_intersect( $types, array( 'stamp', 'points' ) ) ) {
			// Only VIP and cashback cards: there is no rule to choose, so the fields are not shown (and need no script).
			echo '</tbody></table>';
			submit_button( __( 'Connect', 'rewloy-for-woocommerce' ) );
			echo '</form>';
			$this->connect_footnote();
			return;
		}
		echo '<tr><th scope="row">' . esc_html__( 'Rule (stamp and points cards only)', 'rewloy-for-woocommerce' ) . '</th><td>';
		echo '<p class="description">' . esc_html__( 'These two settings apply to stamp and points cards only. A VIP or cashback card ignores them, as the notes above say.', 'rewloy-for-woocommerce' ) . '</p>';
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
		$this->connect_footnote();
	}

	private function connect_footnote(): void {
		echo '<p class="description">' . esc_html__( 'Connecting creates the link in Rewloy and a WooCommerce webhook (topic "Order updated") that this plugin keeps.', 'rewloy-for-woocommerce' ) . '</p>';
	}

	/**
	 * @param array{link:?array<string,mixed>,orders:list<array<string,mixed>>,error:string,key_rejected:bool,webhook:array{exists:bool,status:string,failures:int,edit_url:string}} $health
	 */
	private function section_connected( array $health ): void {
		$s      = $this->settings->get();
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
			$this->row( __( 'Last request from the shop', 'rewloy-for-woocommerce' ), $this->last_delivery( $link['lastDelivery'] ?? null ) );
			$refusal = $this->last_refusal( $link['lastRefusal'] ?? null );
			if ( '' !== $refusal ) {
				$this->row( __( 'Last refused request', 'rewloy-for-woocommerce' ), $refusal );
			}
			$key = $this->plugin_key( $link['pluginKey'] ?? null );
			if ( '' !== $key ) {
				$this->row( __( 'Key in Rewloy', 'rewloy-for-woocommerce' ), $key );
			}
		}
		echo '</tbody></table>';
		if ( null !== $link && is_array( $link['lastRefusal'] ?? null ) ) {
			echo '<p class="description">' . esc_html__( 'A request whose signature did not match came to this shop\'s address and was refused; no order was affected. If it keeps appearing, check the settings of the webhook in WooCommerce.', 'rewloy-for-woocommerce' ) . '</p>';
		}

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
				echo '<li><strong>' . esc_html( Messages::outcome_label( $o ) ) . ':</strong> ' . esc_html( (string) $n ) . ' <span class="description">' . esc_html( Messages::outcome_why( $o, $s['program_type'] ) ) . '</span></li>';
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
		// Open when Rewloy refuses this site's key: then the removal above cannot work, and this is the way out.
		echo '<details style="margin-top:1em"' . ( $health['key_rejected'] ? ' open' : '' ) . '><summary>' . esc_html__( 'If Rewloy cannot be reached', 'rewloy-for-woocommerce' ) . '</summary>';
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

	/**
	 * Rewloy cards at the checkout (0.4.0): what the shop takes and how, each a setting with Rewloy's default (§12).
	 *
	 * @param array<string,mixed>|null $link The link as Rewloy has it (null when it could not be read).
	 */
	private function section_checkout( ?array $link ): void {
		echo '<h2 id="rewloy-checkout">' . esc_html__( 'Rewloy cards at the checkout', 'rewloy-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'A customer can use a Rewloy card when paying: on the card (its page or Rewloy Cüzdan) they make a one-time code, RW-XXXX-XXXX, and type it into the coupon field at checkout. The value is held when the order is placed, taken from the card when the order is paid, and given back when it is cancelled or fails.', 'rewloy-for-woocommerce' ) . '</p>';
		if ( function_exists( 'wc_coupons_enabled' ) && ! wc_coupons_enabled() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Coupons are turned off in WooCommerce, so the checkout has no coupon field and no Rewloy code can be typed. Turn them on in WooCommerce › Settings › General › "Enable the use of coupon codes".', 'rewloy-for-woocommerce' ) . '</p></div>';
		}
		if ( null === $link ) {
			echo '<p class="description">' . esc_html__( 'The settings could not be read from Rewloy just now (see the message above).', 'rewloy-for-woocommerce' ) . '</p>';
			return;
		}
		$v     = CheckoutSettings::view( $link );
		$s     = $this->settings->get();
		$admin = current_user_can( 'manage_options' );
		$off   = $admin ? '' : ' disabled="disabled"';
		if ( $v['unbacked'] > 0 ) {
			/* translators: %d: how many code uses were not backed. */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( '%d code uses were paid after their hold had run out, when the card no longer had the value. Rewloy lists them on the shop\'s page in the panel (E-ticaret › the shop).', 'rewloy-for-woocommerce' ), $v['unbacked'] ) ) . '</p></div>';
		}
		$this->form_open( 'save_checkout' );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Cards this shop takes', 'rewloy-for-woocommerce' ) . '</th><td><fieldset>';
		echo '<p><label><input type="checkbox" checked="checked" disabled="disabled" /> ' . esc_html( $s['program_name'] . ' (' . Messages::type_label( $s['program_type'] ) . ')' ) . '</label> <span class="description">' . esc_html__( 'this shop\'s own card: always', 'rewloy-for-woocommerce' ) . '</span></p>';
		if ( null === $v['ceiling'] ) {
			echo '<p class="description">' . esc_html__( 'This shop was connected with an API key of your own, so the cards it takes are what that key may use; change them in the Rewloy panel.', 'rewloy-for-woocommerce' ) . '</p>';
		} elseif ( array() === $v['ceiling'] ) {
			echo '<p class="description">' . esc_html__( 'No other card can be switched on here. A person with the rights decides which of the business\'s gift cards, cashback cards, coupons and discount cards this plugin may switch on, on the shop\'s page in the Rewloy panel ("Eklentinin açabileceği kartlar").', 'rewloy-for-woocommerce' ) . '</p>';
		} else {
			echo '<input type="hidden" name="accepts_shown" value="1" />';
			$names = $this->checkout()->names( $v['ceiling'] );
			foreach ( $v['ceiling'] as $id ) {
				$label = isset( $names[ $id ] )
					? $names[ $id ]['name'] . ( '' !== $names[ $id ]['type'] ? ' (' . Messages::any_type_label( $names[ $id ]['type'] ) . ')' : '' )
					/* translators: %s: the last characters of a card programme's id. */
					: sprintf( __( 'Card programme …%s', 'rewloy-for-woocommerce' ), substr( $id, -6 ) );
				echo '<p><label><input type="checkbox" name="accepts[]" value="' . esc_attr( $id ) . '"' . checked( in_array( $id, $v['accepted'], true ), true, false ) . $off . ' /> ' . esc_html( $label ) . '</label></p>';
			}
			echo '<p class="description">' . esc_html__( 'Off by default. The business\'s other gift cards, cashback cards, coupons and discount cards, among those a person allowed for this plugin in the Rewloy panel. A card switched off refuses new codes at once; orders already holding it finish as they are.', 'rewloy-for-woocommerce' ) . '</p>';
			if ( count( $names ) < count( $v['ceiling'] ) ) {
				echo '<p class="description">' . esc_html__( 'Rewloy does not tell this shop\'s key the names of cards other than its own; a card shows its name here once a code of it has been used on an order. The shop\'s page in the Rewloy panel lists them all.', 'rewloy-for-woocommerce' ) . '</p>';
			}
		}
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Tax', 'rewloy-for-woocommerce' ) . '</th><td>';
		echo '<p class="description">' . esc_html__( 'How a card\'s value goes on the order and the invoice. Which is right for your invoices is for your accountant to say; the plugin does not decide it.', 'rewloy-for-woocommerce' ) . '</p>';
		$kinds = array(
			'giftcard' => __( 'Gift card', 'rewloy-for-woocommerce' ),
			'cashback' => __( 'Cashback card', 'rewloy-for-woocommerce' ),
			'voucher'  => __( 'Coupon with a money value', 'rewloy-for-woocommerce' ),
		);
		foreach ( $kinds as $kind => $name ) {
			echo '<fieldset style="margin:.75em 0"><legend><strong>' . esc_html( $name ) . '</strong></legend>';
			foreach ( CheckoutSettings::TAX_MODES as $mode ) {
				$default = CheckoutSettings::DEFAULT_TAX[ $kind ] === $mode ? ' ' . __( '(default)', 'rewloy-for-woocommerce' ) : '';
				echo '<p><label><input type="radio" name="' . esc_attr( 'tax_' . $kind ) . '" value="' . esc_attr( $mode ) . '"' . checked( $v['tax'][ $kind ], $mode, false ) . $off . ' /> ' . esc_html( self::tax_words( $mode ) . $default ) . '</label></p>';
			}
			echo '</fieldset>';
		}
		echo '<p class="description">' . esc_html__( 'A discount card\'s percent is always a discount. WooCommerce lets a payment line take off at most the order\'s total before tax; any rest of the card\'s value stays on the card, and the order\'s tax is paid another way.', 'rewloy-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Refunded orders', 'rewloy-for-woocommerce' ) . '</th><td><fieldset>';
		$refund = array(
			'code_orders' => __( 'Take back what an order earned on the card when the order used a Rewloy code', 'rewloy-for-woocommerce' ),
			'all'         => __( 'Take back what any refunded order earned on the card', 'rewloy-for-woocommerce' ),
			'never'       => __( 'Never take back what an order earned', 'rewloy-for-woocommerce' ),
		);
		foreach ( $refund as $value => $words ) {
			$default = CheckoutSettings::DEFAULT_REFUND === $value ? ' ' . __( '(default)', 'rewloy-for-woocommerce' ) : '';
			echo '<p><label><input type="radio" name="refund_reverses" value="' . esc_attr( $value ) . '"' . checked( $v['refundReverses'], $value, false ) . $off . ' /> ' . esc_html( $words . $default ) . '</label></p>';
		}
		echo '<p class="description">' . esc_html__( 'Only an order refunded in full. What a card paid is always put back on it once; a stamp, point or cashback the order earned is taken back as chosen here, never below zero.', 'rewloy-for-woocommerce' ) . '</p>';
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row"><label for="rewloy_hold_days">' . esc_html__( 'Hold length', 'rewloy-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="number" name="hold_days" id="rewloy_hold_days" min="1" max="30" step="1" value="' . esc_attr( (string) $v['holdDays'] ) . '"' . $off . ' /> ' . esc_html__( 'days (default 7)', 'rewloy-for-woocommerce' );
		echo '<p class="description">' . esc_html__( 'An order that is placed but not paid gives the value back to the card after this many days at the latest (a bank transfer can take days). A cancelled or failed order gives it back at once.', 'rewloy-for-woocommerce' ) . '</p></td></tr>';

		echo '</tbody></table>';
		if ( $admin ) {
			submit_button( __( 'Save the checkout settings', 'rewloy-for-woocommerce' ) );
		} else {
			echo '<p class="description">' . esc_html__( 'Only an administrator can change these.', 'rewloy-for-woocommerce' ) . '</p>';
		}
		echo '</form>';
	}

	/** What a tax treatment does to the order and the invoice, in plain words. */
	private static function tax_words( string $mode ): string {
		return 'payment' === $mode
			? __( 'As a payment, after tax: a line of its own lowers what is paid; the KDV of the goods stays as it is', 'rewloy-for-woocommerce' )
			: __( 'As a discount, before tax: a coupon lowers the price; the KDV base goes down', 'rewloy-for-woocommerce' );
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
		$admin = current_user_can( 'manage_options' );
		echo '<tr><th scope="row">' . esc_html__( 'Till', 'rewloy-for-woocommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="till_shop_managers" value="1"' . checked( $s['till_shop_managers'], true, false ) . ( $admin ? '' : ' disabled="disabled"' ) . ' /> ' . esc_html__( 'Shop managers may use the till too', 'rewloy-for-woocommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'The till (Rewloy › Till) spends customers\' rewards and balances, so by default only administrators may use it, even when Rewloy has turned it on for this shop. Tick this to let shop managers use it as well. Only an administrator can change this.', 'rewloy-for-woocommerce' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save', 'rewloy-for-woocommerce' ) );
		echo '</form>';
	}

	private function section_privacy(): void {
		echo '<h2>' . esc_html__( 'What goes to Rewloy', 'rewloy-for-woocommerce' ) . '</h2><ul style="list-style:disc;margin-left:1.5em">';
		echo '<li>' . esc_html__( 'To connect with a code: the code and this site\'s title, which names the key in Rewloy\'s list of keys. To manage the connection afterwards: the key that came back (or your own API key), and the card and rule when you choose them.', 'rewloy-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'For each order update, the webhook sends the order\'s number, status, currency and total, signed with a secret only this shop and Rewloy have, and the billing e-mail once the order is processing or completed. Nothing else of the order (no names, addresses, phone numbers or items). Rewloy stores only the order number, its outcome and the time.', 'rewloy-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'If you turn on the invitation, the billing e-mail of an order whose box was ticked is sent once, to open the card.', 'rewloy-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'On the Cards and Till tabs: the card number typed or scanned (only the number: the rest of a scanned card link, its private key included, is dropped at once), and on the till the paid total and the receipt number typed there. Rewloy sends this site no customer\'s name, e-mail or phone, and the screens show none.', 'rewloy-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'When a customer types a Rewloy code at checkout: the code and the basket\'s currency (to ask what it gives), then the order\'s number and what the order took from the code (to hold, take, release or refund it). Rewloy answers with the card\'s programme, kind and last four characters, never the customer\'s name, e-mail or the card number.', 'rewloy-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'Nothing else, and no tracking. The My Account tab sends nothing.', 'rewloy-for-woocommerce' ) . '</li>';
		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'Deleting this plugin removes its settings, its key and its webhook from this site. It does not delete anything in Rewloy: the link, the key a connect code made and the cards stay until you delete them in the Rewloy panel (deleting the link revokes the key).', 'rewloy-for-woocommerce' ) . '</p>';
	}

	private function row( string $label, string $value ): void {
		echo '<tr><th scope="row" style="width:14rem">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	/**
	 * What Rewloy says became of the last request the shop signed (`lastDelivery`), in the panel's words.
	 *
	 * @param mixed $delivery `{at, result}`, or null when none has come.
	 */
	private function last_delivery( mixed $delivery ): string {
		if ( ! is_array( $delivery ) || ! is_string( $delivery['result'] ?? null ) ) {
			return __( 'No signed request has come from the shop yet.', 'rewloy-for-woocommerce' );
		}
		return $this->when( $delivery['at'] ?? null ) . ' · ' . Messages::delivery_label( $delivery['result'] );
	}

	/**
	 * The last request to the shop's address that was refused for its signature (`lastRefusal`); '' when there is none.
	 *
	 * @param mixed $refusal `{at, reason}`, or null.
	 */
	private function last_refusal( mixed $refusal ): string {
		if ( ! is_array( $refusal ) || ! is_string( $refusal['reason'] ?? null ) ) {
			return '';
		}
		return $this->when( $refusal['at'] ?? null ) . ' · ' . Messages::refusal_label( $refusal['reason'] );
	}

	/**
	 * The key a connect code made for this link, as Rewloy lists it (`pluginKey`): its name and public prefix.
	 *
	 * @param mixed $key `{id, prefix, name}`, or null.
	 */
	private function plugin_key( mixed $key ): string {
		if ( ! is_array( $key ) || ! is_string( $key['name'] ?? null ) || '' === $key['name'] ) {
			return '';
		}
		$prefix = is_string( $key['prefix'] ?? null ) ? (string) preg_replace( '/[^A-Za-z0-9]/', '', $key['prefix'] ) : '';
		return '' !== $prefix ? $key['name'] . ' (' . substr( $prefix, 0, 10 ) . '…)' : $key['name'];
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
