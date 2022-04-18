<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class CommissionController extends Controller
{
	const FILE_NAME = 'input.csv';
	const API_URL = 'https://developers.paysera.com/tasks/api/currency-exchange-rates';

	const FREE_WITHDRAWS_AMOUNT = 1000;
	const FREE_WITHDRAWS_COUNT = 3;
	const FREE_WITHDRAWS_RENEWAL_PERIOD = 7;
	const BASE = 'EUR';

	const DEPOSIT_OPERATION = 'deposit';
	const WITHDRAW_OPERATION = 'withdraw';
	const ALWAYS_TAXABLE = ['business'];
	const FLOATING_POINT_ACCURACY = 2;

	const COMMISSIONS = [
		['type' => 'private', 'operation' => 'withdraw', 'commission' => 0.3],
		['type' => 'business', 'operation' => 'withdraw', 'commission' => 0.5],
		['type' => 'private', 'operation' => 'deposit', 'commission' => 0.03],
		['type' => 'business', 'operation' => 'deposit', 'commission' => 0.03]
	];

	const INDEXES = [
		'date' => 0, 'user' => 1, 'type' => 2, 'operation' => 3, 'amount' => 4, 'currency' => 5
	];

    public function index() {
    	$results = [];
    	$file = public_path($this::FILE_NAME);
    	$data_array = $this->csvToArray($file);

    	if($data_array === false) {
    		dd('Error - no such file.');
    	}

    	usort($data_array, array('App\Http\Controllers\CommissionController', 'date_compare'));

    	$currencies_converting_info = file_get_contents($this::API_URL);
    	$currencies_converting_info = json_decode($currencies_converting_info, true);
    	$rates = $currencies_converting_info['rates'];
    	if(count($data_array) > 0) {
    		foreach ($data_array as $key => $operation_array) {
    			$currency = $operation_array[$this::INDEXES['currency']];
    			$amount = $operation_array[$this::INDEXES['amount']];
    			$base_currency_amount = $this->convertAmountEuros($currency, $amount, $rates[$currency]);
    			$operation = $operation_array[$this::INDEXES['operation']];
    			$type = $operation_array[$this::INDEXES['type']];
    			$commission = $this->getCommissionPercent($operation, $type);
    			$user = $operation_array[$this::INDEXES['user']];
				$results[] = $this->calculateComission($operation, $type, $commission, $amount, $user, $base_currency_amount, $data_array, $key, $currency, $rates[$currency], $rates);
    		}
    	}
    	
    	return view('index', compact('results'));
    }

    // transform csv data into array of arrays
    public function csvToArray($filename = '', $delimiter = ',') {
		if (!file_exists($filename) || !is_readable($filename)) {
			return false;
		}
		$header = null;
		$data = array();
		if (($handle = fopen($filename, 'r')) !== false) {
			while (($row = fgetcsv($handle, 1000, $delimiter)) !== false) {
				$data[] = $row;
			}
			fclose($handle);
		}
		return $data;
	}

	// convert the amount into euros
	public function convertAmountEuros($currency, $amount, $rate) {
		if($currency != $this::BASE) {
			$base_currency_amount = $amount / $this->round_up($rate, $this::FLOATING_POINT_ACCURACY);
		} else {
			$base_currency_amount = $amount;
		}
		return $this->round_up($base_currency_amount, $this::FLOATING_POINT_ACCURACY);
	}

	public function covertToOriginalCurrency($amount, $rate) {
		$original_currency_amount = $amount * $this->round_up($rate, $this::FLOATING_POINT_ACCURACY);
		return $this->round_up($original_currency_amount, $this::FLOATING_POINT_ACCURACY);
	}

	// take the percentage value
	public function getCommissionPercent($operation, $type) {
		$result = 0;
		foreach ($this::COMMISSIONS as $c) {
			if($c['type'] == $type && $c['operation'] == $operation) {
				$result = $c['commission'];
			}
		}
		return $result;
	}

	// calculate the outcome for the commission
	public function calculateComission($operation, $type, $commission, $amount, $user, $base_currency_amount, $data_array, $key, $currency, $rate, $rates) {
		$commission_sum = 0;
		switch ($operation) {
			case $this::DEPOSIT_OPERATION:
				$commission_sum = $amount * ($commission/100);
				break;
			case $this::WITHDRAW_OPERATION:
				if(in_array($type, $this::ALWAYS_TAXABLE)) {
					$commission_sum = $amount * ($commission/100);
				} else {
					$commission_basis = $this->getCommissionBasis($data_array, $user, $amount, $base_currency_amount, $key, $rates);
					if($commission_basis > 0 && $currency != $this::BASE) {
						// when I get the basis which is going to be calculated for the commission
						// I must bring the value back to the original currency
						$commission_sum = $this->covertToOriginalCurrency($commission_basis, $rate) * ($commission/100);
					} else {
						$commission_sum = $commission_basis * ($commission/100);
					}
				}
				break;
			default:
				dd("Error - no such operation.");
		}
		$commission_sum = $this->round_up($commission_sum, $this::FLOATING_POINT_ACCURACY);
		return $commission_sum;
	}

	public function getCommissionBasis($data_array, $user, $amount, $base_currency_amount, $key, $rates) {
		$basis = 0;

		// here I filter the operations for the concrete user and especially only the withdraw operations.
		$filtered_array = array_filter($data_array, function ($var) use ($user) {
		    return ($var[$this::INDEXES['user']] == $user && $var[$this::INDEXES['operation']] == $this::WITHDRAW_OPERATION);
		});

		$week_transaction_count = 0;
		$week_amount_covered = 0;
		foreach ($filtered_array as $k => $o) {

			$exploded_current_row_date = explode('-', $o[$this::INDEXES['date']]);
			$current_date_timestamp = mktime(0, 0, 0, filter_var($exploded_current_row_date[1], FILTER_SANITIZE_NUMBER_INT), filter_var($exploded_current_row_date[2], FILTER_SANITIZE_NUMBER_INT), filter_var($exploded_current_row_date[0], FILTER_SANITIZE_NUMBER_INT));

			$day_of_week = date('N', $current_date_timestamp); // Monday - 1; Sunday - 7;
			
			// if there is previous operation for this user we must consider the number of times he has withdrawed and the amount if it is up to 1000.
			if(isset($previous_value)) {
				$exploded_prev_date = explode('-', $previous_value[$this::INDEXES['date']]);
				
				$prev_date_timestamp = mktime(0, 0, 0, filter_var($exploded_prev_date[1], FILTER_SANITIZE_NUMBER_INT), filter_var($exploded_prev_date[2], FILTER_SANITIZE_NUMBER_INT), filter_var($exploded_prev_date[0], FILTER_SANITIZE_NUMBER_INT));

				$prev_day_of_week = date('N', $prev_date_timestamp);

				$date_difference = $current_date_timestamp - $prev_date_timestamp;
				$date_difference = round($date_difference / (60 * 60 * 24));

				// if the difference in the days is less or equal to 7 - then it is possible to be from the same week
				// and if the difference between this day and the day of the previous operation is 0 and above it is very likely to be in the same week
				if(($date_difference <= $this::FREE_WITHDRAWS_RENEWAL_PERIOD) && ($day_of_week - $prev_day_of_week) >= 0) {
					// the only chance the date difference to be 0 is that it is exactly the same day
					if((($day_of_week - $prev_day_of_week) == 0 && ($date_difference == 0)) || (($day_of_week - $prev_day_of_week) != 0)) {
						$week_transaction_count++;

						// here i convert the amount into euros because i must compare it to the none taxable amount of 1000
						$current_row_converted = $this->convertAmountEuros($filtered_array[$k][$this::INDEXES['currency']], $filtered_array[$k][$this::INDEXES['amount']], $rates[$filtered_array[$k][$this::INDEXES['currency']]]);
						$week_amount_covered += $current_row_converted;

						// here I check if the user has exceeded 1000 euros
						if($week_amount_covered > $this::FREE_WITHDRAWS_AMOUNT) {
							if(($week_amount_covered - $this::FREE_WITHDRAWS_AMOUNT) >= $current_row_converted) {
								// here I take the whole amount of the operation for a basis
								$basis = $current_row_converted;
							} else {
								// here I need only the difference for basis
								$basis = $week_amount_covered - $this::FREE_WITHDRAWS_AMOUNT;
							}
						} else {
							$basis = 0;
						}
					} else {
						$week_transaction_count = 1;
						$week_amount_covered = $this->convertAmountEuros($filtered_array[$k][$this::INDEXES['currency']], $filtered_array[$k][$this::INDEXES['amount']], $rates[$filtered_array[$k][$this::INDEXES['currency']]]);
						$basis = $this->convertAmountEuros($filtered_array[$k][$this::INDEXES['currency']], $filtered_array[$k][$this::INDEXES['amount']], $rates[$filtered_array[$k][$this::INDEXES['currency']]]); - $this::FREE_WITHDRAWS_AMOUNT;
					}

				} else {
					$week_transaction_count = 1;
					$week_amount_covered = $base_currency_amount;
					$basis = $base_currency_amount - $this::FREE_WITHDRAWS_AMOUNT;
				}

			} else {
				$week_transaction_count = 1;
				$week_amount_covered = $this->convertAmountEuros($filtered_array[$k][$this::INDEXES['currency']], $filtered_array[$k][$this::INDEXES['amount']], $rates[$filtered_array[$k][$this::INDEXES['currency']]]);
				$basis = $week_amount_covered - $this::FREE_WITHDRAWS_AMOUNT;
			}

			$previous_value = $o;
			$previous_key = $k;
			if($basis < 0) {
				$basis = 0;
			}

			if($k == $key) {
				// when I call this function I call it for all the operations. So, when called exactly for a specific operation the loop should stop until the exact operation.
				break;
			}
		}

		return $basis;
	}

	function date_compare($element1, $element2) {
	    $datetime1 = strtotime($element1[$this::INDEXES['date']]);
	    $datetime2 = strtotime($element2[$this::INDEXES['date']]);
	    return $datetime1 - $datetime2;
	}

	public function round_up($value, $precision) { 
	    $pow = pow(10, $precision); 
	    return (ceil($pow * $value) + ceil($pow * $value - ceil($pow * $value))) / $pow; 
	}
}
