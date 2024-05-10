<?php declare( strict_types=1 );

namespace PaulGoodchild\EDD\ReportsData;

use Carbon\Carbon;

readonly class CollateLicensesForPeriod {

	public function forPeriod( Carbon $start, Carbon $end ) :array {

		$data = [
			'period_start_active'     => [],
			'period_end_newly_active' => [],
			'period_end_inactive'     => [],
		];

		foreach (
			$this->directQuerySubsPage( [
				[ 'date_created', '<=', sprintf( "'%s'", $end->toDateTimeString() ) ],
				[ 'expiration', '>=', $start->timestamp ],
			] ) as $page
		) {
			foreach ( $page as $lic ) {
				/** @var \EDD_SL_License $lic */

				// License active from before the beginning of the period.
				if ( $start->greaterThan( Carbon::parse( $lic->date_created ) ) ) {
					$this->addSubDataToCollection( $lic, $data[ 'period_start_active' ] );
				}
				// License created during the period.
				if ( $start->lessThan( Carbon::parse( $lic->date_created ) ) ) {
					$this->addSubDataToCollection( $lic, $data[ 'period_end_newly_active' ] );
				}
				// License expired during the period.
				if ( $end->greaterThan( $end->clone()->setTimestamp( $lic->expiration ) ) ) {
					$this->addSubDataToCollection( $lic, $data[ 'period_end_inactive' ] );
				}
			}
		}

		return $data;
	}

	private function addSubDataToCollection( \stdClass $lic, array &$collection ) :void {
		$CID = (int)$lic->customer_id;
		$collection[ 'customers' ][ $CID ] ??= [];
		$collection[ 'customers' ][ $CID ][] = (int)$lic->id;
		\ksort( $collection[ 'customers' ][ $CID ] );
	}

	private function directQuerySubsPage( array $wheres ) :\Generator {
		global $wpdb;

		$page = 0;
		$limit = 100;
		$query = sprintf( "SELECT 
					`lic`.`id`,
					`lic`.`customer_id`,
					`lic`.`date_created`,
					`lic`.`expiration`
				FROM `%s` as `lic`
				WHERE %s
				ORDER BY `lic`.`expiration` DESC
				LIMIT %s
				OFFSET %%s;
			",
			edd_software_licensing()->licenses_db->table_name,
			\implode( ' AND ', \array_map( fn( array $w ) => sprintf( '`lic`.`%s`%s%s', $w[ 0 ], $w[ 1 ], $w[ 2 ] ), $wheres ) ),
			$limit
		);

		do {
			$results = $wpdb->get_results( sprintf( $query, $limit*$page++ ), OBJECT_K );
			yield $results;
		} while ( !empty( $results ) );
	}
}