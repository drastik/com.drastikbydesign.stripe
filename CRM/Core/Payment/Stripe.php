<?php

/*
 * Payment Processor class for Stripe
 */

class CRM_Core_Payment_Stripe extends CRM_Core_Payment {

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = NULL;

  /**
   * Mode of operation: live or test.
   *
   * @var object
   * @static
   */
  static protected $_mode = NULL;

  /**
   * Constructor
   *
   * @param string $mode
   *   The mode of operation: live or test.
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {
    self::$_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = ts('Stripe');
  }

  /**
   * Singleton function used to manage this object.
   *
   * @param string $mode the mode of operation: live or test
   * @param object $paymentProcessor the details of the payment processor being invoked
   * @param object $paymentForm reference to the form object if available
   * @param boolean $force should we force a reload of this payment object
   *
   * @return object
   * @static
   *
   */
  static function &singleton($mode = 'test', &$paymentProcessor, &$paymentForm = NULL, $force = FALSE) {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === NULL || $force) {
      self::$_singleton[$processorName] = new self($mode, $paymentProcessor);
    }
    return self::$_singleton[$processorName];
  }

  /**
   * This function checks to see if we have the right config values.
   *
   * @return string
   *   The error message if any.
   *
   * @public
   */
  function checkConfig() {
    $config = CRM_Core_Config::singleton();
    $error = array();

    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('The "Secret Key" is not set in the Stripe Payment Processor settings.');
    }

    if (empty($this->_paymentProcessor['password'])) {
      $error[] = ts('The "Publishable Key" is not set in the Stripe Payment Processor settings.');
    }

    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  /**
   * Helper log function.
   *
   * @param string $op
   *   The Stripe operation being performed.
   * @param Exception $exception
   *   The error!
   */
  function logStripeException($op, $exception) {
    $body = print_r($exception->getJsonBody(), TRUE);
    CRM_Core_Error::debug_log_message("Stripe_Error {$op}:  <pre> {$body} </pre>");
  }

  /**
   * Run Stripe calls through this to catch exceptions gracefully.
   *
   * @param string $op
   *   Determine which operation to perform.
   * @param array $params
   *   Parameters to run Stripe calls on.
   *
   * @return varies
   *   Response from gateway.
   */
  function stripeCatchErrors($op = 'create_customer', $stripe_params, $params, $ignores = array()) {
    $error_url = $params['stripe_error_url'];
    $return = FALSE;
    // Check for errors before trying to submit.
    try {
      switch ($op) {
        case 'create_customer':
          $return = Stripe_Customer::create($stripe_params);
          break;

        case 'charge':
          $return = Stripe_Charge::create($stripe_params);
          break;

        case 'save':
          $return = $stripe_params->save();
          break;

        case 'create_plan':
          $return = Stripe_Plan::create($stripe_params);
          break;

        case 'retrieve_customer':
          $return = Stripe_Customer::retrieve($stripe_params);
          break;

        case 'retrieve_balance_transaction':
          $return = Stripe_BalanceTransaction::retrieve($stripe_params);
          break;

        default:
          $return = Stripe_Customer::create($stripe_params);
          break;
      }
    }
    catch (Stripe_CardError $e) {
      $this->logStripeException($op, $e);
      $error_message = '';
      // Since it's a decline, Stripe_CardError will be caught
      $body = $e->getJsonBody();
      $err = $body['error'];

      //$error_message .= 'Status is: ' . $e->getHttpStatus() . "<br />";
      ////$error_message .= 'Param is: ' . $err['param'] . "<br />";
      $error_message .= 'Type: ' . $err['type'] . '<br />';
      $error_message .= 'Code: ' . $err['code'] . '<br />';
      $error_message .= 'Message: ' . $err['message'] . '<br />';

      // Redirect to first page of form and present error.
      CRM_Core_Error::statusBounce("Oops!  Looks like there was an error.  Payment Response:
        <br /> {$error_message}", $error_url);
    }
    catch (Exception $e) {
      if (is_a($e, 'Stripe_Error')) {
        foreach ($ignores as $ignore) {
          if (is_a($e, $ignore['class'])) {
            $body = $e->getJsonBody();
            $error = $body['error'];
            if ($error['type'] == $ignore['type'] && $error['message'] == $ignore['message']) {
              return $return;
            }
          }
        }
        $this->logStripeException($op, $e);
      }
      // Something else happened, completely unrelated to Stripe
      $error_message = '';
      // Since it's a decline, Stripe_CardError will be caught
      $body = $e->getJsonBody();
      $err = $body['error'];

      //$error_message .= 'Status is: ' . $e->getHttpStatus() . "<br />";
      ////$error_message .= 'Param is: ' . $err['param'] . "<br />";
      $error_message .= 'Type: ' . $err['type'] . "<br />";
      $error_message .= 'Code: ' . $err['code'] . "<br />";
      $error_message .= 'Message: ' . $err['message'] . "<br />";

      // Redirect to first page of form and present error.
      CRM_Core_Error::statusBounce("Oops!  Looks like there was an error.  Payment Response:
        <br /> {$error_message}", $error_url);
    }

    return $return;
  }

  /**
   * Submit a payment using Stripe's PHP API:
   * https://stripe.com/docs/api?lang=php
   *
   * @param array $params
   *   Assoc array of input parameters for this transaction.
   *
   * @return array
   *   The result in a nice formatted array (or an error object).
   *
   * @public
   */
  function doDirectPayment(&$params) {
    // Let a $0 transaction pass.
    if (empty($params['amount']) || $params['amount'] == 0) {
      return $params;
    }

    // Get proper entry URL for returning on error.
    $qfKey = $params['qfKey'];
    $parsed_url = parse_url($params['entryURL']);
    $url_path = substr($parsed_url['path'], 1);
    $params['stripe_error_url'] = $error_url = CRM_Utils_System::url($url_path,
      $parsed_url['query'] . "&_qf_Main_display=1&qfKey={$qfKey}", FALSE, NULL, FALSE);

    // Include Stripe library & Set API credentials.
    require_once('stripe-php/lib/Stripe.php');
    Stripe::setApiKey($this->_paymentProcessor['user_name']);

    // Stripe amount required in cents.
    $amount = number_format($params['amount'], 2, '.', '');
    $amount = (int) preg_replace('/[^\d]/', '', strval($amount));

    // Use Stripe.js instead of raw card details.
    if (!empty($params['stripe_token'])) {
      $card_details = $params['stripe_token'];
    }
    else {
      CRM_Core_Error::fatal(ts('Stripe.js token was not passed!  Report this message to the site administrator.'));
    }

    // Check for existing customer, create new otherwise.
    if (!empty($params['email'])) {
      $email = $params['email'];
    }
    elseif (!empty($params['email-5'])) {
      $email = $params['email-5'];
    }
    elseif (!empty($params['email-Primary'])) {
      $email = $params['email-Primary'];
    }
    elseif (!empty($params['contact_id'])){
      $email = civicrm_api3('Contact', 'getvalue', array(
        'id' => $params['contact_id'],
        'return' => 'email',
      ));
    }

    // Prepare escaped query params.
    $query_params = array(
      1 => array($email, 'String'),
    );

    $customer_query = CRM_Core_DAO::singleValueQuery("SELECT id
      FROM civicrm_stripe_customers
      WHERE email = %1", $query_params);

    /****
     * If for some reason you cannot use Stripe.js and you are aware of PCI Compliance issues,
     * here is the alternative to Stripe.js:
     ****/

    /*
      // Get Cardholder's full name.
      $cc_name = $params['first_name'] . " ";
      if (strlen($params['middle_name']) > 0) {
        $cc_name .= $params['middle_name'] . " ";
      }
      $cc_name .= $params['last_name'];

      // Prepare Card details in advance to use for new Stripe Customer object if we need.
      $card_details = array(
        'number' => $params['credit_card_number'],
        'exp_month' => $params['month'],
        'exp_year' => $params['year'],
        'cvc' => $params['cvv2'],
        'name' => $cc_name,
        'address_line1' => $params['street_address'],
        'address_state' => $params['state_province'],
        'address_zip' => $params['postal_code'],
      );
    */

    // drastik - Uncomment this for Drupal debugging to dblog.
    /*
     $zz = print_r(get_defined_vars(), TRUE);
     $debug_code = '<pre>' . $zz . '</pre>';
     watchdog('Stripe', $debug_code);
    */

    // Customer not in civicrm_stripe database.  Create a new Customer in Stripe.
    if (!isset($customer_query)) {
      $sc_create_params = array(
        'description' => 'Donor from CiviCRM',
        'card' => $card_details,
        'email' => $email,
      );

      $stripe_customer = $this->stripeCatchErrors('create_customer', $sc_create_params, $params);

      // Store the relationship between CiviCRM's email address for the Contact & Stripe's Customer ID.
      if (isset($stripe_customer)) {
        // Prepare escaped query params.
        $query_params = array(
          1 => array($email, 'String'),
          2 => array($stripe_customer->id, 'String'),
        );

        CRM_Core_DAO::executeQuery("INSERT INTO civicrm_stripe_customers
          (email, id) VALUES (%1, %2)", $query_params);
      }
      else {
        CRM_Core_Error::fatal(ts('There was an error saving new customer within Stripe.  Is Stripe down?'));
      }
    }
    else {
      // Customer was found in civicrm_stripe database, fetch from Stripe.
      $stripe_customer = $this->stripeCatchErrors('retrieve_customer', $customer_query, $params);
      if (!empty($stripe_customer)) {
        // Avoid the 'use same token twice' issue while still using latest card.
        if (!empty($params['selectMembership'])
          && $params['selectMembership']
          && empty($params['contributionPageID'])
        ) {
          // This is a Contribution form w/ Membership option and charge is
          // coming through for the 2nd time.  Don't need to update customer again.
        }
        else {
          $stripe_customer->card = $card_details;
          $this->stripeCatchErrors('save', $stripe_customer, $params);
        }
      }
      else {
        // Customer was found in civicrm_stripe database, but unable to be
        // retrieved from Stripe.  Was he deleted?
        $sc_create_params = array(
          'description' => 'Donor from CiviCRM',
          'card' => $card_details,
          'email' => $email,
        );

        $stripe_customer = $this->stripeCatchErrors('create_customer', $sc_create_params, $params);

        // Somehow a customer ID saved in the system no longer pairs
        // with a Customer within Stripe.  (Perhaps deleted using Stripe interface?).
        // Store the relationship between CiviCRM's email address for the Contact & Stripe's Customer ID.
        if (isset($stripe_customer)) {
          // Delete whatever we have for this customer.
          $query_params = array(
            1 => array($email, 'String'),
          );
          CRM_Core_DAO::executeQuery("DELETE FROM civicrm_stripe_customers
            WHERE email = %1", $query_params);

          // Create new record for this customer.
          $query_params = array(
            1 => array($email, 'String'),
            2 => array($stripe_customer->id, 'String'),
          );
          CRM_Core_DAO::executeQuery("INSERT INTO civicrm_stripe_customers (email, id)
            VALUES (%1, %2)", $query_params);
        }
        else {
          // Customer was found in civicrm_stripe database, but unable to be
          // retrieved from Stripe, and unable to be created in Stripe.  What luck :(
          CRM_Core_Error::fatal(ts('There was an error saving new customer within Stripe.  Is Stripe down?'));
        }
      }
    }

    // Prepare the charge array, minus Customer/Card details.
    if (empty($params['description'])) {
      $params['description'] = ts('CiviCRM backend contribution');
    }
    else {
      $params['description'] = ts('CiviCRM # ') . $params['description'];
    }

    // Stripe charge.
    $stripe_charge = array(
      'amount' => $amount,
      'currency' => strtolower($params['currencyID']),
      'description' => $params['description'] . ' # Invoice ID: ' . CRM_Utils_Array::value('invoiceID', $params),
    );

    // Use Stripe Customer if we have a valid one.  Otherwise just use the card.
    if (!empty($stripe_customer->id)) {
      $stripe_charge['customer'] = $stripe_customer->id;
    }
    else {
      $stripe_charge['card'] = $card_details;
    }

    // Handle recurring payments in doRecurPayment().
    if (CRM_Utils_Array::value('is_recur', $params) && $params['contributionRecurID']) {
      return $this->doRecurPayment($params, $amount, $stripe_customer);
    }

    // Fire away!  Check for errors before trying to submit.
    $stripe_response = $this->stripeCatchErrors('charge', $stripe_charge, $params);
    if (!empty($stripe_response)) {
      // Success!  Return some values for CiviCRM.
      $params['trxn_id'] = $stripe_response->id;
      // Return fees & net amount for Civi reporting.
      // Uses new Balance Trasaction object.
      $balance_transaction = $this->stripeCatchErrors('retrieve_balance_transaction', $stripe_response->balance_transaction, $params);
      if (!empty($balance_transaction)) {
        $params['fee_amount'] = $balance_transaction->fee / 100;
        $params['net_amount'] = $balance_transaction->net / 100;
      }
    }
    else {
      // There was no response from Stripe on the create charge command.
      CRM_Core_Error::statusBounce('Stripe transaction response not recieved!  Check the Logs section of your stripe.com account.', $error_url);
    }

    return $params;
  }

  /**
   * Submit a recurring payment using Stripe's PHP API:
   * https://stripe.com/docs/api?lang=php
   *
   * @param array $params
   *   Assoc array of input parameters for this transaction.
   * @param int $amount
   *   Transaction amount in USD cents.
   * @param object $stripe_customer
   *   Stripe customer object generated by Stripe API.
   *
   * @return array
   *   The result in a nice formatted array (or an error object).
   *
   * @public
   */
  function doRecurPayment(&$params, $amount, $stripe_customer) {
    switch ($this->_mode) {
      case 'test':
        $transaction_mode = 0;
        break;
      case 'live':
        $transaction_mode = 1;
    }

    // Get recurring contrib properties.
    $frequency = $params['frequency_unit'];
    $installments = $params['installments'];
    $frequency_interval = (empty($params['frequency_interval']) ? 1 : $params['frequency_interval']);
    $plan_id = "every-{$frequency_interval}-{$frequency}-{$amount}";

    // Prepare escaped query params.
    $query_params = array(
      1 => array($plan_id, 'String'),
    );

    $stripe_plan_query = CRM_Core_DAO::singleValueQuery("SELECT plan_id
      FROM civicrm_stripe_plans
      WHERE plan_id = %1", $query_params);

    if (!isset($stripe_plan_query)) {
      $formatted_amount = '$' . number_format(($amount / 100), 2);
      // Create a new Plan.
      $stripe_plan = array(
        'amount' => $amount,
        'interval' => $frequency,
        'name' => "CiviCRM every {$frequency_interval} {$frequency}s {$formatted_amount}",
        'currency' => strtolower($params['currencyID']),
        'id' => $plan_id,
        'interval_count' => $frequency_interval,
      );

      $ignores = array(
        array(
          'class' => Stripe_InvalidRequestError,
          'type' => 'invalid_request_error',
          'message' => 'Plan already exists.',
        ),
      );
      $this->stripeCatchErrors('create_plan', $stripe_plan, $params, $ignores);
      // Prepare escaped query params.
      $query_params = array(
        1 => array($plan_id, 'String'),
      );
      CRM_Core_DAO::executeQuery("INSERT INTO civicrm_stripe_plans (plan_id)
        VALUES (%1)", $query_params);
    }

    // If a contact/customer has an existing active recurring
    // contribution/subscription, Stripe will update the existing subscription.
    // If only the amount has changed not the installments/frequency, Stripe
    // will not charge the card again until the next installment is due. This
    // does not work well for CiviCRM, since CiviCRM creates a new recurring
    // contribution along with a new initial contribution, so it expects the
    // card to be charged immediately.  So, since Stripe only supports one
    // subscription per customer, we have to cancel the existing active
    // subscription first.
    if (!empty($stripe_customer->subscription) && $stripe_customer->subscription->status == 'active') {
      $stripe_customer->cancelSubscription();
    }

    // Attach the Subscription to the Stripe Customer.
    $cust_sub_params = array(
      'prorate' => FALSE,
      'plan' => $plan_id,
    );
    $stripe_response = $stripe_customer->updateSubscription($cust_sub_params);
    // Prepare escaped query params.
    $query_params = array(
      1 => array($stripe_customer->id, 'String'),
    );

    $existing_subscription_query = CRM_Core_DAO::singleValueQuery("SELECT invoice_id
      FROM civicrm_stripe_subscriptions
      WHERE customer_id = %1", $query_params);

    if (!empty($existing_subscription_query)) {
      // Cancel existing Recurring Contribution in CiviCRM.
      $cancel_date = date('Y-m-d H:i:s');

      // Prepare escaped query params.
      $query_params = array(
        1 => array($existing_subscription_query, 'String'),
      );

      CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution_recur
        SET cancel_date = '$cancel_date', contribution_status_id = '3'
        WHERE invoice_id = %1", $query_params);

      // Delete the Stripe Subscription from our cron watch list.
      CRM_Core_DAO::executeQuery("DELETE FROM civicrm_stripe_subscriptions
        WHERE invoice_id = %1", $query_params);
    }

    // Calculate timestamp for the last installment.
    $end_time = strtotime("+{$installments} {$frequency}");
    $invoice_id = $params['invoiceID'];

    // Prepare escaped query params.
    $query_params = array(
      1 => array($stripe_customer->id, 'String'),
      2 => array($invoice_id, 'String'),
    );

    // Insert the new Stripe Subscription info.
    // Set end_time to NULL if installments are ongoing indefinitely
    if (empty($installments)) {
      CRM_Core_DAO::executeQuery("INSERT INTO civicrm_stripe_subscriptions
        (customer_id, invoice_id, is_live)
        VALUES (%1, %2, '$transaction_mode')", $query_params);
    }
    else {
      // Add the end time to the query params.
      $query_params[3] = array($end_time, 'Integer');
      CRM_Core_DAO::executeQuery("INSERT INTO civicrm_stripe_subscriptions
        (customer_id, invoice_id, end_time, is_live)
        VALUES (%1, %2, %3, '$transaction_mode')", $query_params);
    }

    $params['trxn_id'] = $stripe_response->id;
    $params['fee_amount'] = $stripe_response->fee / 100;
    $params['net_amount'] = $params['amount'] - $params['fee_amount'];

    return $params;
  }

  /**
   * Transfer method not in use.
   *
   * @param array $params
   *   Name value pair of contribution data.
   *
   * @return void
   *
   * @access public
   *
   */
  function doTransferCheckout(&$params, $component) {
    CRM_Core_Error::fatal(ts('Use direct billing instead of Transfer method.'));
  }
}
