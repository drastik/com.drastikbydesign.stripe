<?php

/**
 * Stripe.Ipn API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_stripe_Ipn_spec(&$spec) {
  $spec['json_input']['api.required'] = 1;
}

/**
 * Stripe.Ipn API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_stripe_Ipn($params) {
  if (array_key_exists('json_input', $params)) {
    $returnValues = array(
      // OK, return several data rows
      12 => array('id' => 12, 'name' => 'Twelve'),
      34 => array('id' => 34, 'name' => 'Thirty four'),
      56 => array('id' => 56, 'name' => 'Fifty six'),
    );
    // ALTERNATIVE: $returnValues = array(); // OK, success
    // ALTERNATIVE: $returnValues = array("Some value"); // OK, return a single value

    // Spec: civicrm_api3_create_success($values = 1, $params = array(), $entity = NULL, $action = NULL)
    $webhook = new CRM_Stripe_Page_Webhook();
    $webhook->run($params['json_input']);
    return civicrm_api3_create_success($returnValues);
  }
  else {
    throw new API_Exception(/*errorMessage*/ 'Please include the json_input to process', /*errorCode*/ 1234);
  }
}
