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
  static private $_singleton = null;

  /**
   * mode of operation: live or test
   *
   * @var object
   * @static
   */
  static protected $_mode = null;

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {
    $this->_mode             = $mode;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName    = ts('Stripe');
  }

  /**
   * Singleton function used to manage this object
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return object
   * @static
   *
   */
  static function &singleton($mode, &$paymentProcessor) {
      $processorName = $paymentProcessor['name'];
      if (self::$_singleton[$processorName] === NULL ) {
          self::$_singleton[$processorName] = new self($mode, $paymentProcessor);
      }
      return self::$_singleton[$processorName];
  }

  /**
   * This function checks to see if we have the right config values
   *
   * @return string the error message if any
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
   * Run Stripe calls through this to catch exceptions gracefully.
   * @param  string $op
   *   Determine which operation to perform.
   * @param  array $params
   *   Parameters to run Stripe calls on.
   * @return varies
   *   Response from gateway.
   */
  function stripeCatchErrors($op = 'create_customer', &$params, $qfKey = '') {
    // @TODO:  Handle all calls through this using $op switching for sanity.
    // Check for errors before trying to submit.
    try {
      switch ($op) {
        case 'create_customer':
          $return = Stripe_Customer::create($params);
        break;

        case 'charge':
          $return = Stripe_Charge::create($params);
        break;

        case 'save':
          $return = $params->save();
        break;

        case 'create_plan':
          $return = Stripe_Plan::create($params);
        break;

        default:
         $return = Stripe_Customer::create($params);
        break;
      }
    }
    catch(Stripe_CardError $e) {
      $error_message = '';
      // Since it's a decline, Stripe_CardError will be caught
      $body = $e->getJsonBody();
      $err  = $body['error'];

      //$error_message .= 'Status is: ' . $e->getHttpStatus() . "<br />";
      ////$error_message .= 'Param is: ' . $err['param'] . "<br />";
      $error_message .= 'Type: ' . $err['type'] . "<br />";
      $error_message .= 'Code: ' . $err['code'] . "<br />";
      $error_message .= 'Message: ' . $err['message'] . "<br />";

      // Check Event vs Contribution for redirect.  There must be a better way.
      if(empty($params['selectMembership'])
        && empty($params['contributionPageID'])) {
        $error_url = CRM_Utils_System::url('civicrm/event/register',
          "_qf_Main_display=1&cancel=1&qfKey=$qfKey", FALSE, NULL, FALSE);
      }
      else {
        $error_url = CRM_Utils_System::url('civicrm/contribute/transact',
          "_qf_Main_display=1&cancel=1&qfKey=$qfKey", FALSE, NULL, FALSE);
      }

      CRM_Core_Error::statusBounce("Oops!  Looks like there was an error.  Payment Response:
        <br /> $error_message", $error_url);
    }
    catch (Stripe_InvalidRequestError $e) {
      // Invalid parameters were supplied to Stripe's API
    }
    catch (Stripe_AuthenticationError $e) {
      // Authentication with Stripe's API failed
      // (maybe you changed API keys recently)
    }
    catch (Stripe_ApiConnectionError $e) {
      // Network communication with Stripe failed
    }
    catch (Stripe_Error $e) {
      // Display a very generic error to the user, and maybe send
      // yourself an email
    }
    catch (Exception $e) {
      // Something else happened, completely unrelated to Stripe
      $error_message = '';
      // Since it's a decline, Stripe_CardError will be caught
      $body = $e->getJsonBody();
      $err  = $body['error'];

      //$error_message .= 'Status is: ' . $e->getHttpStatus() . "<br />";
      ////$error_message .= 'Param is: ' . $err['param'] . "<br />";
      $error_message .= 'Type: ' . $err['type'] . "<br />";
      $error_message .= 'Code: ' . $err['code'] . "<br />";
      $error_message .= 'Message: ' . $err['message'] . "<br />";

      if(empty($params['selectMembership'])
        && empty($params['contributionPageID'])) {
        $error_url = CRM_Utils_System::url('civicrm/event/register',
          "_qf_Main_display=1&cancel=1&qfKey=$qfKey", FALSE, NULL, FALSE);
      }
      else {
        $error_url = CRM_Utils_System::url('civicrm/contribute/transact',
          "_qf_Main_display=1&cancel=1&qfKey=$qfKey", FALSE, NULL, FALSE);
      }

      CRM_Core_Error::statusBounce("Oops!  Looks like there was an error.  Payment Response:
        <br /> $error_message", $error_url);
    }

    return $return;
  }

  /**
   * Submit a payment using Stripe's PHP API:
   * https://stripe.com/docs/api?lang=php
   *
   * @param  array $params assoc array of input parameters for this transaction
   *
   * @return array the result in a nice formatted array (or an error object)
   * @public
   */
  function doDirectPayment(&$params) {
    // Let a $0 transaction pass.
    if (empty($params['amount']) || $params['amount'] == 0) {
      return $params;
    }

    // Include Stripe library & Set API credentials.
    require_once("stripe-php/lib/Stripe.php");
    Stripe::setApiKey($this->_paymentProcessor['user_name']);

    // Stripe amount required in cents.
    $amount = $params['amount'] * 100;
    // It would require 3 digits after the decimal for one to make it this far.
    // CiviCRM prevents this, but let's be redundant.
    $amount = number_format($amount, 0, '', '');

    // Get Cardholder's full name.
    /*
    $cc_name = $params['first_name'] . " ";
    if (strlen($params['middle_name']) > 0) {
      $cc_name .= $params['middle_name'] . " ";
    }
    $cc_name .= $params['last_name'];
    */

    // Check for existing customer, create new otherwise.
    if (isset($params['email'])) {
      $email = $params['email'];
    }
    elseif(isset($params['email-5'])) {
      $email = $params['email-5'];
    }
    elseif(isset($params['email-Primary'])) {
      $email = $params['email-Primary'];
    }
    // Prepare escaped query params.
    $query_params = array(
      1 => array($email, 'String'),
    );

    $customer_query = CRM_Core_DAO::singleValueQuery("SELECT id
      FROM civicrm_stripe_customers
      WHERE email = %1", $query_params);

    // Use Stripe.js instead of raw card details.
    if (isset($params['stripe_token'])) {
      $card_details = $params['stripe_token'];
    }
    else {
      CRM_Core_Error::fatal(ts('Stripe.js token was not passed!
        Have you turned on the CiviCRM-Stripe CMS module?'));
    }

    /****
     * If for some reason you cannot use Stripe.js and you are aware of PCI Compliance issues,
     * here is the alternative to Stripe.js:
     ****/

  /*
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
     watchdog('Stripe', $debug_code, array(), WATCHDOG_NOTICE);
    */

    // Create a new Customer in Stripe.
    if (!isset($customer_query)) {
      $sc_create_params = array(
        //'name' => $cc_name,
        'description' => 'Donor from CiviCRM',
        'card' => $card_details,
        'email' => $email,
      );

      $stripe_customer = CRM_Core_Payment_Stripe::stripeCatchErrors('create_customer', $sc_create_params, $params['qfKey']);

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
      $stripe_customer = Stripe_Customer::retrieve($customer_query);
      if (!empty($stripe_customer)) {
        // Avoid the 'use same token twice' issue while still using latest card.
        if(!empty($params['selectMembership'])
          && $params['selectMembership']
          && empty($params['contributionPageID'])) {
            // This is a Contribution form w/ Membership option and charge is
            // coming through for the 2nd time.  Don't need to update customer again.
        }
        else {
          $stripe_customer->card = $card_details;
          CRM_Core_Payment_Stripe::stripeCatchErrors('save', $stripe_customer, $params['qfKey']);
        }
      }
      else {
        $sc_create_params = array(
          //'name' => $cc_name,
          'description' => 'Donor from CiviCRM',
          'card' => $card_details,
          'email' => $email,
        );

        $stripe_customer = CRM_Core_Payment_Stripe::stripeCatchErrors(
          'create_customer', $sc_create_params, $params['qfKey']);

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
          CRM_Core_Error::fatal(ts('There was an error saving new customer within Stripe.  Is Stripe down?'));
        }
      }
    }

    // Prepare the charge array, minus Customer/Card details.
    $stripe_charge = array(
      'amount' => $amount,
      'currency' => strtolower($params['currencyID']),
      'description' => '# CiviCRM Donation Page # ' . $params['description'] .
        ' # Invoice ID # ' . $params['invoiceID'],
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
    $stripe_response = CRM_Core_Payment_Stripe::stripeCatchErrors(
      'charge', $stripe_charge, $params['qfKey']);

    // Success!  Return some values for CiviCRM.
    $params['trxn_id'] = $stripe_response->id;
    // Return fees & net amount for Civi reporting.  Thanks Kevin!
    $params['fee_amount'] = $stripe_response->fee / 100;
    $params['net_amount'] = $params['amount'] - $params['fee_amount'];

    return $params;
  }

  /**
   * Submit a recurring payment using Stripe's PHP API:
   * https://stripe.com/docs/api?lang=php
   *
   * @param  array $params assoc array of input parameters for this transaction
   * @param  int $amount transaction amount in USD cents
   * @param  object $stripe_customer Stripe customer object generated by Stripe API
   *
   * @return array the result in a nice formatted array (or an error object)
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
    $frequency = $params['frequency_unit'];
    $installments = $params['installments'];
    $plan_id = "$frequency-$amount";

    // Prepare escaped query params.
    $query_params = array(
      1 => array($plan_id, 'String'),
    );

    $stripe_plan_query = CRM_Core_DAO::singleValueQuery("SELECT plan_id
      FROM civicrm_stripe_plans
      WHERE plan_id = %1", $query_params);

    if (!isset($stripe_plan_query)) {
      $formatted_amount =  "$" . number_format(($amount / 100), 2);
      // Create a new Plan.
      $stripe_plan = array(
        "amount" => $amount,
        "interval" => $frequency,
        "name" => "CiviCRM $frequency" . 'ly ' . $formatted_amount,
        "currency" => "usd",
        "id" => $plan_id);

      CRM_Core_Payment_Stripe::stripeCatchErrors('create_plan', $stripe_plan, $params['qfKey']);

      // Prepare escaped query params.
      $query_params = array(
        1 => array($plan_id, 'String'),
      );
      CRM_Core_DAO::executeQuery("INSERT INTO civicrm_stripe_plans (plan_id)
        VALUES (%1)", $query_params);
    }

    // Attach the Subscription to the Stripe Customer.
    $stripe_response = $stripe_customer->updateSubscription(array(
      'prorate' => FALSE, 'plan' => $plan_id));

    // Prepare escaped query params.
    $query_params = array(
      1 => array($stripe_customer->id, 'String'),
    );

    $existing_subscription_query = CRM_Core_DAO::singleValueQuery("SELECT invoice_id
      FROM civicrm_stripe_subscriptions
      WHERE customer_id = %1", $query_params);

    if (!empty($existing_subscription_query)) {
      // Cancel existing Recurring Contribution in CiviCRM.
      $cancel_date = date("Y-m-d H:i:s");

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
    $end_time = strtotime("+$installments $frequency");
    $invoice_id = $params['invoiceID'];

    // Prepare escaped query params.
    $query_params = array(
      1 => array($stripe_customer->id, 'String'),
      2 => array($invoice_id, 'String'),
      3 => array($end_time, 'Integer'),
    );

    // Insert the new Stripe Subscription info.
    CRM_Core_DAO::executeQuery("INSERT INTO civicrm_stripe_subscriptions
      (customer_id, invoice_id, end_time, is_live)
      VALUES (%1, %2, %3, '$transaction_mode')", $query_params);

    $params['trxn_id'] = $stripe_response->id;
    $params['fee_amount'] = $stripe_response->fee / 100;
    $params['net_amount'] = $params['amount'] - $params['fee_amount'];

    return $params;
  }

  /**
   * Transfer method not in use
   *
   * @param array $params  name value pair of contribution data
   *
   * @return void
   * @access public
   *
   */
  function doTransferCheckout(&$params, $component) {
    CRM_Core_Error::fatal(ts('Use direct billing instead of Transfer method.'));
  }
}
