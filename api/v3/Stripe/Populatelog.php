<?php

/**
 * Populate the CiviCRM civicrm_system_log with Stripe events.
 *
 * This api will take all stripe events known to Stripe that are of the type
 * invoice.payment_succeeded and add them * to the civicrm_system_log table.
 * It will not add an event that has already been added, so it can be run
 * multiple times. Once added, they can be replayed using the Stripe.Ipn
 * api call.
 */

/**
 * Stripe.Populatelog API specification
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_stripe_Populatelog_spec(&$spec) {
  $spec['ppid']['title'] = ts("The id of the payment processor."); 
}

/**
 * Stripe.Populatelog API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_stripe_Populatelog($params) {
  $ppid = NULL;
  if (array_key_exists('ppid', $params)) {
    $ppid = $params['ppid'];
  }
  else {
    // By default, select the live stripe processor (we expect there to be
    // only one).
    $query_params = array('class_name' => 'Payment_Stripe', 'is_test' => 0, 'return' => 'id');
    try {
      $ppid = civicrm_api3('PaymentProcessor', 'getvalue', $params);
    }
    catch (CiviCRM_API3_Exception $e) {
      throw new API_Exception("Expected one live Stripe payment processor, but found none or more than one. Please specify ppid=.", 2234);
    }
  }
  
  $params = array('limit' => 100, 'type' => 'invoice.payment_succeeded');
  if ($ppid) { 
    $params['ppid'] = $ppid;
  }
 
  $items = array();
  $last_item = NULL;
  $more = TRUE;
  while(1) {
    if ($last_item) {
      $params['starting_after'] = $last_item->id;
    }
    $objects = civicrm_api3('Stripe', 'Listevents', $params);
    
    if (count($objects['values']['data']) == 0) {
      // No more!
      break;
    }
    $items = array_merge($items, $objects['values']['data']);
    $last_item = end($objects['values']['data']);
  }
  $results = array();
  foreach($items as $item) {
    $id = $item->id;
    // Insert into System Log if it doesn't exist.
    $like_event_id = '%event_id=' . addslashes($id);
    $sql = "SELECT id FROM civicrm_system_log WHERE message LIKE '$like_event_id'";
    $dao= CRM_Core_DAO::executeQuery($sql);
    if ($dao->N == 0) {
      $message = "payment_notification processor_id=${ppid} event_id=${id}";
      $contact_id = civicrm_api3_stripe_cid_for_trxn($item->data->object->charge);
      if ($contact_id) {
        $item['contact_id'] = $contact_id;
      }
      $log = new CRM_Utils_SystemLogger();
      $log->alert($message, $item);
      $results[] = $id;
    }
  }
  return civicrm_api3_create_success($results);

}

function civcrm_api3_stripe_cid_for_trxn($trxn) {
  $params = array('trxn_id' => $trxn, 'return' => 'contact_id');
  $result = civicrm_api3('Contribution', 'getvalue', $params);
  return $result;
}
