<?php declare( strict_types=1 );

namespace PaulGoodchild\EDD\ReportsData;

use Carbon\Carbon;

class BuildReportData {

	private ?array $exchangesRates = null;

	public function __construct(
		private readonly int $months,
		private readonly string $storageKey = '',
		private bool $rebuildData = false,
	) {
		if ( empty( $this->storageKey ) ) {
			$this->rebuildData = true;
		}
	}

	public function build() :array {
		$this->preBuild();
		return $this->parseRaw();
	}

	private function buildSubsData() :array {
		$carb = $this->carbonNow();

		$data = $this->rebuildData ? [] : get_option( $this->storageKey );
		if ( !\is_array( $data ) ) {
			$data = [];
		}

		\bcscale( 2 );
		for ( $i = 1 ; $i <= $this->months ; $i++ ) {
			$start = ( clone $carb )->startOfMonth()->subMonths( $i );
			$data[ $start->format( 'Y-m' ) ] ??= [
				'sub' => ( new CollateSubscriptionsForPeriod() )->forPeriod( $start, $start->clone()->endOfMonth() ),
				'lic' => ( new CollateLicensesForPeriod() )->forPeriod( $start, $start->clone()->endOfMonth() ),
			];
		}

		\ksort( $data );
		if ( !empty( $this->storageKey ) ) {
			update_option( $this->storageKey, $data, false );
		}

		return $data;
	}

	private function parseRaw() :array {
		return \array_map(
			fn( array $data ) => [
				'sub' => $this->parseSubs( $data[ 'sub' ] ),
				'lic' => $this->parseLic( $data[ 'lic' ] ),
			],
			$this->buildSubsData()
		);
	}

	private function parseSubs( array $data ) :array {

		$startActive = $data[ 'period_start_active' ];
		$newActive = $data[ 'period_end_newly_active' ];
		$endInactive = $data[ 'period_end_inactive' ];

		$newCustomerIDs = \array_keys( \array_diff_key( $newActive[ 'customers' ], $startActive[ 'customers' ] ) );

		$lostCustomerIDs = [];
		foreach ( $endInactive[ 'customers' ] as $CID => $subsData ) {
			$totalActiveForCustomer = \count( $startActive[ 'customers' ][ $CID ] ?? [] ) + \count( $newActive[ 'customers' ][ $CID ] ?? [] );
			if ( \count( $subsData ) === $totalActiveForCustomer ) {
				$lostCustomerIDs[] = $CID;
			}
		}

		$parsed = [
			'customers_active_start' => \count( $startActive[ 'customers' ] ),
			'customers_active_end'   => \count( $startActive[ 'customers' ] ) + \count( $newCustomerIDs ) - \count( $lostCustomerIDs ),
			'customers_new'          => \count( $newCustomerIDs ),
			'customers_lost'         => \count( $lostCustomerIDs ),
			'customers_churn_rate'   => \round( 100*\count( $lostCustomerIDs )/\count( $startActive[ 'customers' ] ), 2 ),
		];

		$startMRR = $this->calcMRRForCollection( $startActive );
		$newMRR = $this->calcMRRForCollection( $newActive );
		$inactiveMRR = $this->calcMRRForCollection( $endInactive );

		$parsed[ 'mrr_start' ] = $startMRR;
		$parsed[ 'mrr_new' ] = $newMRR;
		$parsed[ 'mrr_lost' ] = $inactiveMRR;
		$parsed[ 'mrr_end' ] = \bcsub( \bcadd( $startMRR, $newMRR ), $inactiveMRR );
		$parsed[ 'mrr_churn' ] = \round( 100*$inactiveMRR/$startMRR, 2 );

		return $parsed;
	}

	private function parseLic( array $data ) :array {

		$startActive = $data[ 'period_start_active' ];
		$newActive = $data[ 'period_end_newly_active' ];
		$endInactive = $data[ 'period_end_inactive' ];

		$newCustomerIDs = \array_keys( \array_diff_key( $newActive[ 'customers' ], $startActive[ 'customers' ] ) );

		$lostCustomerIDs = [];
		foreach ( $endInactive[ 'customers' ] as $CID => $subsData ) {
			$totalActiveForCustomer = \count( $startActive[ 'customers' ][ $CID ] ?? [] ) + \count( $newActive[ 'customers' ][ $CID ] ?? [] );
			if ( \count( $subsData ) === $totalActiveForCustomer ) {
				$lostCustomerIDs[] = $CID;
			}
		}

		$parsed = [
			'customers_active_start' => \count( $startActive[ 'customers' ] ),
			'customers_active_end'   => \count( $startActive[ 'customers' ] ) + \count( $newCustomerIDs ) - \count( $lostCustomerIDs ),
			'customers_new'          => \count( $newCustomerIDs ),
			'customers_lost'         => \count( $lostCustomerIDs ),
			'customers_churn_rate'   => \round( 100*\count( $lostCustomerIDs )/\count( $startActive[ 'customers' ] ), 2 ),
		];

		$startLicenses = $this->countLicensesForCollection( $startActive );
		$newLicenses = $this->countLicensesForCollection( $newActive );
		$inactiveLicenses = $this->countLicensesForCollection( $endInactive );

		$parsed[ 'licenses_start' ] = $startLicenses;
		$parsed[ 'licenses_new' ] = $newLicenses;
		$parsed[ 'licenses_lost' ] = $inactiveLicenses;
		$parsed[ 'licenses_end' ] = $startLicenses + $newLicenses - $inactiveLicenses;
		$parsed[ 'licenses_churn' ] = \round( 100*$inactiveLicenses/$startLicenses, 2 );

		return $parsed;
	}

	private function calcMRRForCollection( array $collection ) :string {
		$mrr = '0';
		foreach ( $collection[ 'customers' ] as $subs ) {
			foreach ( $subs as $subData ) {
				$mrr = \bcadd( $mrr, $this->calcMRRForSub( $subData ) );
			}
		}
		return $mrr;
	}

	private function calcMRRForSub( array $subData ) :string {
		[ $val, $currency, $period ] = $subData;
		$rev = $period === 'year' ? \bcdiv( $val, '12' ) : $val;
		return \bcdiv( $rev, $this->getExchangeRates()[ $currency ], 2 );
	}

	private function countLicensesForCollection( array $collection ) :int {
		$count = 0;
		foreach ( $collection[ 'customers' ] as $licenses ) {
			$count += \count( $licenses );
		}
		return $count;
	}

	public function getExchangeRates() :array {
		if ( $this->exchangesRates === null ) {
			$this->exchangesRates = [];
			if ( \class_exists( '\EDD_Multi_Currency\Models\Currency' ) ) {
				foreach ( \EDD_Multi_Currency\Models\Currency::all() as $currency ) {
					/** @var \EDD_Multi_Currency\Models\Currency $currency */
					$this->exchangesRates[ $currency->currency ] = \strval( $currency->rate );
				}
			}
			else {
				$this->exchangesRates[ edd_get_currency() ] = '1';
			}
		}
		return $this->exchangesRates;
	}

	/**
	 * Because EDD, the license status field is static, and only correctly determined if you load the record and query
	 * its status. You cannot directly rely on the DB record data of its status, particularly for expired or disabled
	 * licenses.
	 *
	 * "status" should ideally be dynamically by other timestamp-based (for example) characteristics, such as
	 * activation date, expiration date, etc.
	 *
	 * So, we must load all licenses and query their status for any edge cases. Edge cases include:
	 * 1) Expiration date reached, but status column isn't up-to-date (i.e. licenses status remains as 'active' or
	 * 'inactive')
	 */
	private function preBuild() :void {
		\array_map(
			fn( \EDD_SL_License $license ) => $license->get_display_status(),
			edd_software_licensing()->licenses_db->get_licenses( [
				'status'     => [ 'active', 'inactive' ],
				'expiration' => [ 'end' => $this->carbonNow()->timestamp ],
				'number'     => -1,
			] )
		);
	}

	private function carbonNow() :Carbon {
		$carb = Carbon::now();
		$TZ = get_option( 'timezone_string' );
		if ( !empty( $TZ ) ) {
			$carb->setTimezone( $TZ );
		}
		return $carb;
	}
}