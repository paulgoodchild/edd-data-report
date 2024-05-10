# edd-data-report
Attempt to extract revenue/retention/churn data from Easy Digital Downloads

Example:

```php
$data = ( new \PaulGoodchild\EDD\ReportsData\BuildReportData( 12, 'my-options-key', true ) )->build();
```

* 1st Parameter is the number of months to go back and examine
* 2nd Parameter is a storage key to store data so you don't have to rebuild it
* 3rd Parameter is whether to force rebuild the data.

Resulting data array will look something like:

```php
array (
  '2024-03' =>
  array (
    'sub' =>
    array (
      'customers_active_start' => 100,
      'customers_active_end' => 150,
      'customers_new' => 75,
      'customers_lost' => 25,
      'customers_churn_rate' => 25.0,
      'mrr_start' => '1000.00',
      'mrr_new' => '2000.00',
      'mrr_lost' => '500.00',
      'mrr_end' => '2500.00',
      'mrr_churn' => 50,
    ),
    'lic' =>
    array (
      'customers_active_start' => 123,
      'customers_active_end' => 123,
      'customers_new' => 123,
      'customers_lost' => 123,
      'customers_churn_rate' => 2.28,
      'licenses_start' => 123,
      'licenses_new' => 123,
      'licenses_lost' => 123,
      'licenses_end' => 123,
      'licenses_churn' => 123.34,
    ),
  ),
  '2024-04' =>
  array (
    'sub' =>
    array (
        ...
    ),
    'lic' =>
    array (
        ...
    ),
  ),
)
```

Each key of the area is a calendar month, and it outlines the changes that took place in the month wrt subscriptions (`sub`) and licenses (`lic`).

It'll attempt breakdown subscriptions and identify pre-existing subscriptions, new subscriptions, and expired subscriptions, and calculate the MRR changes and churn from them.

It does something similar with licenses, but I'm not 100% convinced the processing on licenses is as accurate as that for subscriptions.

This raw data can then be fed into a chart to track monthly churn, for example.

Feedback / Corrections welcome. Please.