<?php declare( strict_types=1 );

namespace PaulGoodchild\EDD\ReportsData;

use Carbon\Carbon;

readonly class CollateSubscriptionsForPeriod {

	public function forPeriod( Carbon $start, Carbon $end ) :array {

		$data = [
			'period_start_active'     => [],
			'period_end_newly_active' => [],
			'period_end_inactive'     => [],
		];
		foreach ( $data as &$datum ) {
			$datum = [
				'customers' => [],
				'products'  => [],
			];
		}

		foreach (
			$this->directQuerySubsPage( [
				[ 'created', '<=', $end->toDateTimeString() ],
				[ 'expiration', '>=', $start->toDateTimeString() ],
			] ) as $page
		) {
			foreach ( $page as $sub ) {
				/** @var \EDD_Subscription $sub */

				if ( $sub->price_id === null ) {
					continue;
				}

				// Subscription active from the beginning of the period.
				if ( $start->greaterThan( Carbon::parse( $sub->created ) ) ) {
					$this->addSubDataToCollection( $sub, $data[ 'period_start_active' ] );
				}
				// Subscription started during the period.
				if ( $start->lessThan( Carbon::parse( $sub->created ) ) ) {
					$this->addSubDataToCollection( $sub, $data[ 'period_end_newly_active' ] );
				}
				// Subscription ended during the period.
				if ( $end->greaterThan( Carbon::parse( $sub->expiration ) ) ) {
					$this->addSubDataToCollection( $sub, $data[ 'period_end_inactive' ] );
				}
			}
		}

		return $data;
	}

	private function addSubDataToCollection( \stdClass $sub, array &$collection ) :void {
		$productID = (int)$sub->product_id;
		$priceID = (int)$sub->price_id;
		$CID = (int)$sub->customer_id;
		$subID = (int)$sub->id;

		$collection[ 'customers' ][ $CID ] ??= [];
		$collection[ 'customers' ][ $CID ][ $subID ] = [
			\bcsub( $sub->recurring_amount, $sub->recurring_tax ),
			$sub->currency,
			$sub->period,
		];
		$collection[ 'products' ][ $productID ] ??= [];
		$collection[ 'products' ][ $productID ][ $priceID ] =
			( $collection[ 'products' ][ $productID ][ $priceID ] ?? 0 ) + 1;

		\ksort( $collection[ 'products' ] );
		\ksort( $collection[ 'products' ][ $productID ] );
		\ksort( $collection[ 'customers' ] );
		\ksort( $collection[ 'customers' ][ $CID ] );
	}

	private function directQuerySubsPage( array $wheres ) :\Generator {
		global $wpdb;

		$wheres[] = [ 'status', '!=', 'pending' ];

		$page = 0;
		$limit = 100;
		$query = sprintf( "SELECT 
					`sub`.`id`,
					`sub`.`customer_id`,
					`sub`.`created`,
					`sub`.`expiration`,
					`sub`.`period`,
					`sub`.`product_id`,
					`sub`.`price_id`,
					`sub`.`parent_payment_id`,
					`sub`.`recurring_amount`,
					`sub`.`recurring_tax`,
					`p`.`currency`
				FROM `%s` as `sub`
				INNER JOIN `%s` as `p` ON `p`.`id` = `sub`.`parent_payment_id`
				WHERE %s
				ORDER BY `sub`.`expiration` DESC
				LIMIT %s
				OFFSET %%s;
			",
			( new \EDD_Subscriptions_DB() )->table_name,
			$wpdb->edd_orders,
			\implode( ' AND ', \array_map( fn( array $w ) => sprintf( '`sub`.`%s`%s"%s"', $w[ 0 ], $w[ 1 ], $w[ 2 ] ), $wheres ) ),
			$limit
		);

		do {
			$results = $wpdb->get_results( sprintf( $query, $limit*$page++ ), OBJECT_K );
			yield $results;
		} while ( !empty( $results ) );
	}
}