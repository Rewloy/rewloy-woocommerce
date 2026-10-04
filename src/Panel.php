<?php
/**
 * What the "Rewloy" admin screens read from Rewloy (0.3.0, D37–D44): who this site's key is and what it may do,
 * the connected programme and its numbers, the latest activity on its cards, one card's state.
 *
 * Everything here is a read. What the key may read is Rewloy's to say: `GET /v1/me` names its abilities
 * (`view`: "Görüntüleme"; `till`: one branch's "Kasa"), and the screens ask on every load, so a change made in the
 * Rewloy panel shows here at once without connecting again. No customer's name, e-mail or phone ever comes this way
 * (Rewloy sends none to this plugin's key), and the screens show a card number by its last four characters only.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Panel {

	/** @var callable(string=): ?Client */
	private $client_factory;
	/** @var array{ok:bool,error:string,key_rejected:bool,view:bool,till:bool,till_location_id:string,till_location_name:string,business:string}|null */
	private ?array $me = null;

	/**
	 * @param Settings                   $settings       The saved settings.
	 * @param callable(string=): ?Client $client_factory A client for the saved key; null without a key.
	 * @param Links                      $links          The Rewloy panel's pages.
	 */
	public function __construct( private Settings $settings, callable $client_factory, private Links $links ) {
		$this->client_factory = $client_factory;
	}

	public function links(): Links {
		return $this->links;
	}

	public function settings(): Settings {
		return $this->settings;
	}

	public function client(): ?Client {
		return ( $this->client_factory )();
	}

	/**
	 * What this site's key may do, from `GET /v1/me`, once per request. Off on any doubt: without an answer, nothing
	 * beyond the settings is offered.
	 *
	 * @return array{ok:bool,error:string,key_rejected:bool,view:bool,till:bool,till_location_id:string,till_location_name:string,business:string}
	 */
	public function abilities(): array {
		if ( null !== $this->me ) {
			return $this->me;
		}
		$out    = array(
			'ok'                 => false,
			'error'              => '',
			'key_rejected'       => false,
			'view'               => false,
			'till'               => false,
			'till_location_id'   => '',
			'till_location_name' => '',
			'business'           => '',
		);
		$client = $this->client();
		if ( ! $this->settings->is_connected() || null === $client ) {
			$this->me = $out;
			return $out;
		}
		try {
			$me = $client->me();
		} catch ( RewloyException $e ) {
			$out['error']        = Messages::for_error( $e );
			$out['key_rejected'] = $e instanceof ApiError && in_array( $e->api_code, array( 'INVALID_API_KEY', 'UNAUTHENTICATED' ), true );
			$this->me            = $out;
			return $out;
		}
		$key       = is_array( $me['key'] ?? null ) ? $me['key'] : array();
		$abilities = is_array( $key['abilities'] ?? null ) ? $key['abilities'] : array();
		$location  = is_string( $key['tillLocationId'] ?? null ) ? $key['tillLocationId'] : '';
		$business  = is_array( $me['business'] ?? null ) && is_string( $me['business']['name'] ?? null ) ? $me['business']['name'] : '';
		$out['ok']   = true;
		$out['view'] = in_array( 'view', $abilities, true );
		// The till needs its one branch: an ability without a branch is no till.
		$out['till']               = in_array( 'till', $abilities, true ) && 1 === preg_match( '/^[0-9a-f-]{36}$/i', $location );
		$out['till_location_id']   = $out['till'] ? strtolower( $location ) : '';
		$out['till_location_name'] = $out['till'] && is_string( $key['tillLocationName'] ?? null ) ? $key['tillLocationName'] : '';
		$out['business']           = $business;
		$this->me                  = $out;
		return $out;
	}

	/**
	 * The programme's numbers over the last 30 days, each named by what it counts (`getAnalytics` for the programme).
	 *
	 * @return array{ok:bool,error:string,numbers:list<array{label:string,value:int}>}
	 */
	public function numbers(): array {
		$program = $this->settings->get()['program_id'];
		$client  = $this->client();
		if ( null === $client || '' === $program ) {
			return array(
				'ok'      => false,
				'error'   => '',
				'numbers' => array(),
			);
		}
		try {
			$a = $client->analytics( $program, 30 );
		} catch ( RewloyException $e ) {
			return array(
				'ok'      => false,
				'error'   => Messages::for_error( $e ),
				'numbers' => array(),
			);
		} catch ( \InvalidArgumentException $e ) {
			unset( $e );
			return array(
				'ok'      => false,
				'error'   => '',
				'numbers' => array(),
			);
		}
		$k   = is_array( $a['kpis'] ?? null ) ? $a['kpis'] : array();
		$int = static fn( string $name ): int => is_numeric( $k[ $name ] ?? null ) ? max( 0, (int) $k[ $name ] ) : 0;
		return array(
			'ok'      => true,
			'error'   => '',
			'numbers' => array(
				array(
					'label' => __( 'Open cards now', 'rewloy-for-woocommerce' ),
					'value' => $int( 'activeCards' ),
				),
				array(
					'label' => __( 'Cards given in the last 30 days', 'rewloy-for-woocommerce' ),
					'value' => $int( 'newCards' ),
				),
				array(
					'label' => __( 'Visits in the last 30 days', 'rewloy-for-woocommerce' ),
					'value' => $int( 'visits' ),
				),
				array(
					'label' => __( 'Rewards used in the last 30 days', 'rewloy-for-woocommerce' ),
					'value' => $int( 'redeems' ),
				),
			),
		);
	}

	/**
	 * The latest happenings on the programme's cards (`listActivity`), as the screen shows them: what happened, when,
	 * the card by its last four characters, where, and who in one word (a teammate, a key, the system). Never a name or
	 * an address, even if one came.
	 *
	 * @return array{ok:bool,error:string,rows:list<array{at:string,when:string,what:string,card:string,where:string,who:string}>}
	 */
	public function activity( int $limit = 20 ): array {
		$program = $this->settings->get()['program_id'];
		$client  = $this->client();
		$out     = array(
			'ok'    => false,
			'error' => '',
			'rows'  => array(),
		);
		if ( null === $client || '' === $program ) {
			return $out;
		}
		try {
			$rows = $client->list_activity( $program, $limit );
		} catch ( RewloyException $e ) {
			$out['error'] = Messages::for_error( $e );
			return $out;
		} catch ( \InvalidArgumentException $e ) {
			unset( $e );
			return $out;
		}
		foreach ( $rows as $r ) {
			$kind  = is_string( $r['kind'] ?? null ) ? $r['kind'] : '';
			$delta = is_int( $r['delta'] ?? null ) || is_float( $r['delta'] ?? null ) ? (float) $r['delta'] : null;
			$unit  = is_string( $r['unit'] ?? null ) ? $r['unit'] : '';
			$cur   = is_string( $r['currency'] ?? null ) ? $r['currency'] : '';
			$at    = is_string( $r['at'] ?? null ) ? $r['at'] : '';
			$out['rows'][] = array(
				'at'    => $at,
				'when'  => self::when( $at ),
				'what'  => Messages::activity_text( $kind, $delta, $unit, $cur ),
				'card'  => Serial::mask( is_string( $r['serial'] ?? null ) ? $r['serial'] : '' ),
				'where' => is_string( $r['location'] ?? null ) ? $r['location'] : '',
				'who'   => Messages::actor_kind( is_string( $r['actorKind'] ?? null ) ? $r['actorKind'] : '' ),
			);
		}
		$out['ok'] = true;
		return $out;
	}

	/**
	 * One card, looked up by its number (or a scanned card link) on the watching screen: its state and what it
	 * accepts. The number is shown masked here; the full number only on the till, after a scan.
	 *
	 * @return array{ok:bool,error:string,card:?array<string,mixed>}
	 */
	public function card( #[\SensitiveParameter] string $raw ): array {
		$serial = Serial::from_input( $raw );
		if ( '' === $serial ) {
			return array(
				'ok'    => false,
				'error' => __( 'That is not a Rewloy card number. It has 12 letters and digits (ABCD-EFGH-JKLM), or scan the card\'s QR code.', 'rewloy-for-woocommerce' ),
				'card'  => null,
			);
		}
		$client = $this->client();
		if ( null === $client ) {
			return array(
				'ok'    => false,
				'error' => __( 'This shop is not connected.', 'rewloy-for-woocommerce' ),
				'card'  => null,
			);
		}
		try {
			$pass = $client->get_pass( $serial );
		} catch ( RewloyException $e ) {
			return array(
				'ok'    => false,
				'error' => Messages::for_card_error( $e ),
				'card'  => null,
			);
		}
		if ( ( $pass['programId'] ?? '' ) !== $this->settings->get()['program_id'] ) {
			// Rewloy already refuses another programme's card to this key; a key made by hand might see it.
			return array(
				'ok'    => false,
				'error' => __( 'This card belongs to another Rewloy card than the one this shop is linked to.', 'rewloy-for-woocommerce' ),
				'card'  => null,
			);
		}
		return array(
			'ok'    => true,
			'error' => '',
			'card'  => self::card_view( $pass, $serial, false ),
		);
	}

	/**
	 * A card's state in the screen's words. `$full` shows the whole number (the till, after a scan); otherwise the
	 * last four characters only.
	 *
	 * @param array<string,mixed> $pass The answer of `getPass`.
	 * @return array{serial:string,shown:string,type:string,type_label:string,status:string,status_label:string,progress_label:string,progress:string,reward_ready:bool,rewards_ready:int,tier:string,actions:list<array{action:string,label:string,needs:list<string>,ready:bool,spends:bool}>,sale:string}
	 */
	public static function card_view( array $pass, string $serial, bool $full ): array {
		$type    = is_string( $pass['type'] ?? null ) ? $pass['type'] : '';
		$status  = is_string( $pass['status'] ?? null ) ? $pass['status'] : '';
		$actions = array();
		foreach ( is_array( $pass['actions'] ?? null ) ? $pass['actions'] : array() as $a ) {
			$verb = is_array( $a ) && is_string( $a['action'] ?? null ) ? $a['action'] : '';
			// `load` (a gift card top-up) needs a permission this plugin's key never has: it is not offered.
			if ( '' === $verb || 'load' === $verb || '' === Messages::action_label( $verb ) ) {
				continue;
			}
			$needs = array();
			foreach ( is_array( $a['needs'] ?? null ) ? $a['needs'] : array() as $n ) {
				if ( is_string( $n ) && in_array( $n, array( 'amountMinor', 'points', 'rewardIndex' ), true ) ) {
					$needs[] = $n;
				}
			}
			$actions[] = array(
				'action' => $verb,
				'label'  => Messages::action_label( $verb ),
				'needs'  => $needs,
				'ready'  => true === ( $a['ready'] ?? false ),
				'spends' => Messages::action_spends( $verb ),
			);
		}
		$sale = is_array( $pass['sale'] ?? null ) && is_string( $pass['sale']['writes'] ?? null ) ? $pass['sale']['writes'] : 'none';
		return array(
			'serial'         => $full ? $serial : '',
			'shown'          => $full ? $serial : Serial::mask( $serial ),
			'type'           => $type,
			'type_label'     => Messages::any_type_label( $type ),
			'status'         => $status,
			'status_label'   => Messages::status_label( $status ),
			'progress_label' => Messages::progress_label( is_string( $pass['progressLabel'] ?? null ) ? $pass['progressLabel'] : '' ),
			'progress'       => is_string( $pass['progressValue'] ?? null ) ? $pass['progressValue'] : ( is_numeric( $pass['balance'] ?? null ) ? (string) $pass['balance'] : '' ),
			'reward_ready'   => true === ( $pass['rewardReady'] ?? false ),
			'rewards_ready'  => is_numeric( $pass['rewardsReady'] ?? null ) ? max( 0, (int) $pass['rewardsReady'] ) : 0,
			'tier'           => is_string( $pass['tier'] ?? null ) ? $pass['tier'] : '',
			'actions'        => $actions,
			'sale'           => Messages::sale_writes( $sale ),
		);
	}

	/** A date from the API in the site's own format and time zone ('' when there is none). */
	public static function when( string $iso ): string {
		$ts = '' === $iso ? false : strtotime( $iso );
		if ( false === $ts ) {
			return '';
		}
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}
}
