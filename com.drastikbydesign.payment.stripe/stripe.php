<?php
 
require_once 'CRM/Core/Payment.php';
 
class com_drastikbydesign_payment_stripe extends CRM_Core_Payment {
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

    //Create database tables if they haven't been.
    if(!CRM_Core_DAO::checkTableExists('civicrm_stripe_customers')) {
      CRM_Core_DAO::executeQuery("
		CREATE TABLE IF NOT EXISTS `civicrm_stripe_customers` (
  			`email` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  			`id` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  			UNIQUE KEY `email` (`email`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
			");
      
      CRM_Core_DAO::executeQuery("
		CREATE TABLE IF NOT EXISTS `civicrm_stripe_plans` (
  			`plan_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  			UNIQUE KEY `plan_id` (`plan_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
		");
      
      CRM_Core_DAO::executeQuery("
		CREATE TABLE IF NOT EXISTS `civicrm_stripe_subscriptions` (
			`customer_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
			`invoice_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
			`end_time` int(11) NOT NULL DEFAULT '0',
			`is_live` tinyint(4) NOT NULL COMMENT 'Whether this is a live or test transaction',
			KEY `end_time` (`end_time`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
		");
      CRM_Core_Error::debug('Stripe Database tables created.  <br />This is the only time this message will be displayed.  You do not need to take any further actions.');
    }

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

  /*
   * CiviCRM extension uninstall()
   * Not functioning in <=CiviCRM 4.1
   */
  public function uninstall() {
    //Remove Stripe tables on uninstall
    require_once "CRM/Core/DAO.php";
    CRM_Core_DAO::executeQuery("DROP TABLE civicrm_stripe_customers");
    CRM_Core_DAO::executeQuery("DROP TABLE civicrm_stripe_plans");
    CRM_Core_DAO::executeQuery("DROP TABLE civicrm_stripe_subscriptions");
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

    //Stripe amount required in cents.
    $amount = $params['amount'] * 100;
    //It would require 3 digits after the decimal for one to make it this far, CiviCRM prevents this, but let's be redundant.
    $amount = number_format($amount, 0, '', '');

    //Check for existing customer, create new otherwise.
    $email = $params['email'];
    $customer_query = CRM_Core_DAO::singleValueQuery("SELECT id FROM civicrm_stripe_customers WHERE email = '$email'");

    //Use Stripe.js instead of raw card details.
    if(isset($params['stripe_token'])) {
      $card_details = $params['stripe_token'];
    } else {
      CRM_Core_Error::fatal(ts('Stripe.js token was not passed!  Have you turned on the CiviCRM-Stripe CMS module?'));
    }

    /****
     * If for some reason you cannot use Stripe.js and you are aware of PCI Compliance issues, here is the alternative to Stripe.js:
     ****/ 
    //Prepare Card details in advance to use for new Stripe Customer object if we need.
/*   
    $cc_name = $params['first_name'] . " ";
    if (strlen($params['middle_name']) > 0) {
      $cc_name .= $params['middle_name'] . " ";
    }
    $cc_name .= $params['last_name'];
    
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
    
    //Create a new Customer in Stripe
    if(!isset($customer_query)) {
      $stripe_customer = Stripe_Customer::create(array(
  		'description' => 'Payment from CiviCRM',
  		'card' => $card_details,
        'email' => $email,
      ));
      
      //Store the relationship between CiviCRM's email address for the Contact & Stripe's Customer ID
      if(isset($stripe_customer)) {
        CRM_Core_DAO::executeQuery("INSERT INTO civicrm_stripe_customers (email, id) VALUES ('$email', '$stripe_customer->id')");
      } else {
        CRM_Core_Error::fatal(ts('There was an error saving new customer within Stripe.  Is Stripe down?'));
      }
    } else {
      $stripe_customer = Stripe_Customer::retrieve($customer_query);
      if(!empty($stripe_customer)) {
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
          CRM_Core_DAO::executeQuery("DELETE FROM civicrm_stripe_customers WHERE email = '$email'");
          CRM_Core_DAO::executeQuery("INSERT INTO civicrm_stripe_customers (email, id) VALUES ('$email', '$stripe_customer->id')");
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
    if(!empty($stripe_customer->id)) {
      $stripe_charge['customer'] = $stripe_customer->id;
    } else {
      $stripe_charge['card'] = $card_details;
    }
    
    //Handle recurring payments in doRecurPayment().
    if (CRM_Utils_Array::value('is_recur', $params) && $params['contributionRecurID']) {
      return $this->doRecurPayment($params, $amount, $stripe_customer);
    }
       
    //Fire away!
    $stripe_response = Stripe_Charge::create($stripe_charge);
    $params['trxn_id'] = $stripe_response->id;

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
    switch($this->_mode) {
      case 'test':
        $transaction_mode = 0;
        break;
      case 'live':
        $transaction_mode = 1;
    }
    $frequency = $params['frequency_unit'];
    $installments = $params['installments'];
    $plan_id = "$frequency-$amount";
    
    $stripe_plan_query = CRM_Core_DAO::singleValueQuery("SELECT plan_id FROM civicrm_stripe_plans WHERE plan_id = '$plan_id'");

    if(!isset($stripe_plan_query)) {
      $formatted_amount =  "$" . number_format(($amount / 100), 2);
      //Create a new Plan
      $stripe_plan = Stripe_Plan::create(array( 
      	"amount" => $amount,
      	"interval" => $frequency,
      	"name" => "CiviCRM $frequency" . 'ly ' . $formatted_amount,
      	"currency" => "usd",
      	"id" => $plan_id));
      CRM_Core_DAO::executeQuery("INSERT INTO civicrm_stripe_plans (plan_id) VALUES ('$plan_id')");
    }
    
    //Attach the Subscription to the Stripe Customer
    $stripe_response = $stripe_customer->updateSubscription(array('prorate' => FALSE, 'plan' => $plan_id));
    
    $existing_subscription_query = CRM_Core_DAO::singleValueQuery("SELECT invoice_id FROM civicrm_stripe_subscriptions WHERE customer_id = '$stripe_customer->id'");
    if(!empty($existing_subscription_query)) {
      //Cancel existing Recurring Contribution in CiviCRM
      $cancel_date = date("Y-m-d H:i:s");
      CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution_recur SET cancel_date = '$cancel_date', contribution_status_id = '3' WHERE invoice_id = '$existing_subscription_query'");
      //Delete the Stripe Subscription from our cron watch list.
      CRM_Core_DAO::executeQuery("DELETE FROM civicrm_stripe_subscriptions WHERE invoice_id = '$existing_subscription_query'");
    }

    //Calculate timestamp for the last installment
    $end_time = strtotime("+$installments $frequency");
    $invoice_id = $params['invoiceID'];
    CRM_Core_DAO::executeQuery("INSERT INTO civicrm_stripe_subscriptions (customer_id, invoice_id, end_time, is_live) VALUES ('$stripe_customer->id', '$invoice_id', '$end_time', '$transaction_mode')");
    
    $params['trxn_id'] = $stripe_response->id;
    
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