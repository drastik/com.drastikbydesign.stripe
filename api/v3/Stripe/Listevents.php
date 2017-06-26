<?php

/**
 * Stripe.ListEvents API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_stripe_ListEvents_spec(&$spec) {
  $spec['id']['title'] = ts("Payment Processor ID to use");
  $spec['id']['type'] = CRM_Utils_Type::T_INT; 
  $spec['type']['title'] = ts("The type of Stripe Events to limit to (default is all).");
  $spec['pk']['title'] = ts("The Stripe secret public key to use (overrides id value, don't use both id and public_key).");
  $spec['sk']['title'] = ts("The Stripe secret key to use (overrides id value, don't use both id and secret_key).");
  $spec['created']['title'] = ts("Array describing when the event was created, can include gt, gte, lt, lte");
}

/**
 * Stripe.VerifyEventType
 *
 * @param string $eventType
 * @return bolean True if valid type, false otherwise.
 */
function civicrm_api3_stripe_VerifyEventType($eventType) {

	return in_array($eventType, array(
			'account.external_account.created',
			'account.external_account.deleted',
			'account.external_account.updated',
			'application_fee.created',
			'application_fee.refunded',
			'application_fee.refund.updated',
			'balance.available',
			'bitcoin.receiver.created',
			'bitcoin.receiver.filled',
			'bitcoin.receiver.updated',
			'bitcoin.receiver.transaction.created',
			'charge.captured',
			'charge.failed',
			'charge.pending',
			'charge.refunded',
			'charge.succeeded',
			'charge.updated',
			'charge.dispute.closed',
			'charge.dispute.created',
			'charge.dispute.funds_reinstated',
			'charge.dispute.funds_withdrawn',
			'charge.dispute.updated',
			'charge.refund.updated',
			'coupon.created',
			'coupon.deleted',
			'coupon.updated',
			'customer.created',
			'customer.deleted',
			'customer.updated',
			'customer.discount.created',
			'customer.discount.deleted',
			'customer.discount.updated',
			'customer.source.created',
			'customer.source.deleted',
			'customer.source.updated',
			'customer.subscription.created',
			'customer.subscription.deleted',
			'customer.subscription.trial_will_end',
			'customer.subscription.updated',
			'invoice.created',
			'invoice.payment_failed',
			'invoice.payment_succeeded',
			'invoice.upcoming',
			'invoice.updated',
			'invoiceitem.created',
			'invoiceitem.deleted',
			'invoiceitem.updated',
			'order.created',
			'order.payment_failed',
			'order.payment_succeeded',
			'order.updated',
			'order_return.created',
			'payout.canceled',
			'payout.created',
			'payout.failed',
			'payout.paid',
			'payout.updated',
			'plan.created',
			'plan.deleted',
			'plan.updated',
			'product.created',
			'product.deleted',
			'product.updated',
			'recipient.created',
			'recipient.deleted',
			'recipient.updated',
			'review.closed',
			'review.opened',
			'sku.created',
			'sku.deleted',
			'sku.updated',
			'source.canceled',
			'source.chargeable',
			'source.failed',
			'source.transaction.created',
			'transfer.created',
			'transfer.reversed',
			'transfer.updated',
			'ping',
		)
	);
}

/**
 * Process parameters to determine pk and sk.
 *
 * @param array $params
 * @return array $pk and $sk
 */
function civicrm_api3_stripe_ProcessParams($params) {
  $sk = NULL;
  $pk = NULL;
  $id = NULL;
  $type = NULL;
  $created = NULL;

  if (array_key_exists('id', $params) ) {
    $id = $params['id'];
  }
  if (array_key_exists('pk', $params) ) {
    $pk = $params['pk'];
  }
  if (array_key_exists('sk', $params) ) {
    $sk = $params['sk'];
  }
  if (array_key_exists('created', $params) ) {
    $created = $params['created'];
  }

  // Determine which public key and secret key to use.
  if ($pk && $sk) {
    if ($id) {
      throw new API_Exception(/*errorMessage*/ "Pass either the id of the Stripe payment processor (d) OR the secret key and public key (sk and pk) but not both.", /*errorCode*/ 1234);
    }
  }
  else {
    // Select the right payment processor to use.
    if ($id) {
      $query_params = array('id' => $$id);
    }
    else {
      // By default, select the live stripe processor (we expect there to be
      // only one).
      $query_params = array('class_name' => 'Payment_Stripe', 'is_test' => 0);
    }
    try {
      $results = civicrm_api3('PaymentProcessor', 'getsingle', $params);
      // YES! I know, password and user are backwards. wtf??
      $sk = $results['user_name'];
      $pk = $results['password'];
    }
    catch (CiviCRM_API3_Exception $e) {
      if(preg_match('/Expected one PaymentProcessor but/', $e->getMessage())) {
        throw new API_Exception(/*errorMessage*/ "Expected one live Stripe payment processor, but found none or more than one. Please specify id= OR pk= and sk=.", /*errorCode*/ 1234);
      }
      else {
        throw new API_Exception(/*errorMessage*/ "Error getting the Stripe Payment Processor to use", /*errorCode*/ 1235);
      }
    }
  }

	// Check to see if we should filter by type.
  if (array_key_exists('type', $params) ) {
    // Validate - since we will be appending this to an URL.
    if (!civicrm_api3_stripe_VerifyEventType($params['type'])) {
      throw new API_Exception(/*errorMessage*/ "Unrecognized Event Type.", /*errorCode*/ 1236);
    }
    else {
      $type = $params['type'];
    }
  }

  // Created can only be passed in as an array
  if (array_key_exists('created', $params)) {
    $created = $params['created'];
    if (!is_array($created)) {
      throw new API_Exception(/*errorMessage*/ "Created can only be passed in programatically as an array", /*errorCode*/ 1237);
    }
  }
  return array('sk' => $sk, 'pk' => $pk, 'type' => $type, 'created' => $created);
}

/**
 * Stripe.ListEvents API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_stripe_Listevents($params) {
  $parsed = civicrm_api3_stripe_ProcessParams($params);
  $sk = $parsed['sk'];
  $pk = $parsed['pk'];
  $type = $parsed['type'];
  $created = $parsed['created'];

  $args = array();
  if ($type) {
    $args['type'] = $type;
  }
  if ($created) {
    $args['created'] = $created;
  }
  
  require_once ("packages/stripe-php/init.php");
  \Stripe\Stripe::setApiKey($sk);
  $data_list = \Stripe\Event::all($args);
  if (array_key_exists('error', $data_list)) {
    $err = $data_list['error'];
    throw new API_Exception(/*errorMessage*/ "Stripe returned an error: " . $err->message, /*errorCode*/ $err->type);
  }
  return civicrm_api3_create_success($data_list, $params);
}


