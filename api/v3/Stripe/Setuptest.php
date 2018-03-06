<?php

/**
 * This api sets up a Stripe Payment Processor with test credentials.
 *
 * This api should only be used for testing purposes.
 */

/**
 * Stripe.Setuptest API specification
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_stripe_Setuptest_spec(&$spec) {
  // Note: these test credentials belong to PTP and are contributed to
  // tests can be automated. If you are setting up your own testing
  // infrastructure, please use your own keys.
  $spec['sk']['api.default'] = 'sk_test_TlGdeoi8e1EOPC3nvcJ4q5UZ';
  $spec['pk']['api.default'] = 'pk_test_k2hELLGpBLsOJr6jZ2z9RaYh';
}

/**
 * Stripe.Setuptest API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_stripe_Setuptest($params) {
	$params = array(
		'name' => 'Stripe',
		'domain_id' => CRM_Core_Config::domainID(),
		'payment_processor_type_id' => 'Stripe',
		'title' => 'Stripe',
		'is_active' => 1,
		'is_default' => 0,
		'is_test' => 1,
		'is_recur' => 1,
		'user_name' => $params['sk'],
		'password' => $params['pk'],
		'url_site' => 'https://api.stripe.com/v1',
		'url_recur' => 'https://api.stripe.com/v1',
		'class_name' => 'Payment_Stripe',
		'billing_mode' => 1
	);
  // First see if it already exists.
  $result = civicrm_api3('PaymentProcessor', 'get', $params);
  if ($result['count'] != 1) {
    // Nope, create it.
    $result = civicrm_api3('PaymentProcessor', 'create', $params);
  }
  return civicrm_api3_create_success($result['values']);
}
