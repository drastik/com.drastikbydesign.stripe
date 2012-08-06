<?php
 
require_once 'CRM/Core/Payment.php';
 
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
          self::$_singleton[$processorName] = new CRM_Core_Payment_Stripe($mode, $paymentProcessor);
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
 
    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
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
    //Include Stripe library & Set API credentials.
    require_once("stripe-php/lib/Stripe.php");
    Stripe::setApiKey($this->_paymentProcessor['user_name']);
    
    $cc_name = $params['first_name'] . " ";
    if (strlen($params['middle_name']) > 0) {
      $cc_name .= $params['middle_name'] . " ";
    }
    $cc_name .= $params['last_name'];

    //Stripe amount required in cents.
    $amount = $params['amount'] * 100;
    //It would require 3 digits after the decimal for one to make it this far, CiviCRM prevents this, but let's be redundant.
    $amount = number_format($amount, 0, '', '');

    //Check for existing customer, create new otherwise.
    $stripe_customer_id = "";
    $email = $params['email'];
    $customer_query = "SELECT id FROM civicrm_stripe_customers WHERE email = '$email'";
    $customer_query_res = CRM_Core_DAO::singleValueQuery($customer_query);
    
    //Prepare Card details in advance to use for new Stripe Customer object if we need.
    $card_details = array(
  	  'number' => $params['credit_card_number'], 
  	  'exp_month' => $params['month'], 
  	  'exp_year' => $params['year'],
      'cvc' => $params['cvv2'],
      'name' => $cc_name,
      'address_line1' => $params['street_address'],
      'address_state' => $params['state_province'],
      'address_zip' => $params['postal_code'],
      //'address_country' => $params['country']
    );
    
    //Create a new Customer in Stripe
    if(!isset($customer_query_res)) {
      $stripe_customer = Stripe_Customer::create(array(
  		'description' => 'Donor from CiviCRM',
  		'card' => $card_details,
        'email' => $email,
      ));
      
      //Store the relationship between CiviCRM's email address for the Contact & Stripe's Customer ID
      if(isset($stripe_customer)) {
        $stripe_customer_id = $stripe_customer->id;
        $new_customer_insert = "INSERT INTO civicrm_stripe_customers (email, id) VALUES ('$email', '$stripe_customer_id')";
        CRM_Core_DAO::executeQuery($new_customer_insert);
      } else {
        CRM_Core_Error::fatal(ts('There was an error saving new customer within Stripe.  Is Stripe down?'));
      }
    } else {
      $stripe_customer = Stripe_Customer::retrieve($customer_query_res);
      if(!empty($stripe_customer)) {
        $stripe_customer_id = $customer_query_res;
        $stripe_customer->card = $card_details;
        $stripe_customer->save();
      } else {
        $stripe_customer = Stripe_Customer::create(array(
  		  'description' => 'Donor from CiviCRM',
  		  'card' => $card_details,
          'email' => $email,
        ));
        
        //Somehow a customer ID saved in the system no longer pairs with a Customer within Stripe.  (Perhaps deleted using Stripe interface?) 
        //Store the relationship between CiviCRM's email address for the Contact & Stripe's Customer ID
        if(isset($stripe_customer)) {
          $stripe_customer_id = $stripe_customer->id;
          $new_customer_insert = "INSERT INTO civicrm_stripe_customers (email, id) VALUES ('$email', '$stripe_customer_id')";
          CRM_Core_DAO::executeQuery($new_customer_insert);
        } else {
          CRM_Core_Error::fatal(ts('There was an error saving new customer within Stripe.  Is Stripe down?'));
        }
      }
    }
    
    //Prepare the charge array, minus Customer/Card details.
    $stripe_charge = array(
      'amount' => $amount, 
      'currency' => 'usd',
      'description' => '# CiviCRM Donation Page # ' . $params['description'] .  ' # Invoice ID # ' . $params['invoiceID'],
    );

    //Use Stripe Customer if we have a valid one.  Otherwise just use the card.
    if(!empty($stripe_customer_id)) {
      $stripe_charge['customer'] = $stripe_customer_id;
    } else {
      $stripe_charge['card'] = $card_details;
    }
    
    //Handle recurring payments in doRecurPayment().
    if (CRM_Utils_Array::value('is_recur', $params) && $params['contributionRecurID']) {
      return $this->doRecurPayment($params, $amount, $stripe_customer, $card_details);
    }
       
    //Fire away!
    $stripe_response = Stripe_Charge::create($stripe_charge);
    $params['trxn_id'] = $stripe_response->id;

    return $params;
  }
  
  function doRecurPayment(&$params, $amount, $stripe_customer, $card_details) {
    $frequency = $params['frequency_unit'];
    $installments = $params['installments'];
    $plan_id = "$frequency-$amount";
    
    $stripe_plan_query = "SELECT plan_id FROM civicrm_stripe_plans WHERE plan_id = '$plan_id'";
    $stripe_plan_query_res = CRM_Core_DAO::singleValueQuery($stripe_plan_query);
    
    if(!isset($stripe_plan_query_res)) {
      $formatted_amount =  "$" . number_format(($amount / 100), 2);
      //Create a new Plan
      $stripe_plan = Stripe_Plan::create(array( 
      	"amount" => $amount,
      	"interval" => $frequency,
      	"name" => "CiviCRM $frequency" . 'ly ' . $formatted_amount,
      	"currency" => "usd",
      	"id" => $plan_id));
      $new_plan_insert = "INSERT INTO civicrm_stripe_plans (plan_id) VALUES ('$plan_id')";
      CRM_Core_DAO::executeQuery($new_plan_insert);
    }
    
    //Attach the Subscription to the Stripe Customer
    $stripe_response = $stripe_customer->updateSubscription(array('prorate' => FALSE, 'plan' => $plan_id, 'card' => $card_details));
    
    //Calculate timestamp for the last installment
    $end_time = strtotime("+$installments $frequency");
    $new_subscription_insert = "INSERT INTO civicrm_stripe_subscriptions (customer_id, plan_id, end_time) VALUES ('$stripe_customer->id', '$plan_id', '$end_time')";
    CRM_Core_DAO::executeQuery($new_subscription_insert);
    
    $params['trxn_id'] = $plan_id . ' ' . $stripe_response->start;
    
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