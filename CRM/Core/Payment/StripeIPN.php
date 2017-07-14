<?php
/*
 * @file
 * Handle Stripe Webhooks for recurring payments.
 */

require_once 'CRM/Core/Page.php';

class CRM_Core_Payment_StripeIPN extends CRM_Core_Payment_BaseIPN {
  var $ppid = NULL;
  var $secret_key = NULL;
  var $is_email_receipt = 1;
  // By default, always retrieve the event from stripe to ensure we are
  // not being fed garbage. However, allow an override so when we are 
  // testing, we can properly test a failed recurring contribution.
  var $verify_event = TRUE;

  // Properties of the event.
  var $test_mode;
  var $event_type = NULL;
  var $subscription_id = NULL;
  var $charge_id = NULL;
  var $previous_plan_id = NULL;
  var $plan_id = NULL;
  var $plan_amount = NULL;
  var $frequency_interval = NULL;
  var $frequency_unit = NULL;
  var $plan_name = NULL;
  var $plan_start = NULL;
       
  // Derived properties.
  var $contact_id = NULL;
  var $contribution_recur_id = NULL;
  var $membership_id = NULL;
  var $event_id = NULL;
  var $invoice_id = NULL;
  var $receive_date = NULL;
  var $amount = NULL;
  var $fee = NULL;
  var $net_amount = NULL;
  var $previous_contribution_id = NULL;
  var $previous_contribution_status_id = NULL;
  var $previous_contribution_total_amount = NULL;
  var $previous_completed_contribution_id = NULL;

  public function __construct($inputData, $verify = TRUE) {
    $this->verify_event = $verify;
    $this->setInputParameters($inputData);
    parent::__construct();
  }
  /**
   * Store input array on the class.
   *
   * We override base because our input parameter is an object
   *
   * @param array $parameters
   *
   * @throws CRM_Core_Exception
  */
  public function setInputParameters($parameters) {
    if (empty($parameters) || !is_object($parameters) || empty($parameters->id)) {
      throw new CRM_Core_Exception('Invalid input parameters. Should be an object with an id parameter.');
    }

    // Determine the proper Stripe Processor ID so we can get the secret key
    // and initialize Stripe.
    
    // The $_GET['processor_id'] value is set by CRM_Core_Payment::handlePaymentMethod.
    if (!array_key_exists('processor_id', $_GET) || empty($_GET['processor_id'])) {
      throw new CRM_Core_Exception('Cannot determine processor id.');
    }
    $this->ppid = $_GET['processor_id'];

    // Get the Stripe secret key.
    try {
      $params = array('return' => 'user_name', 'id' => $this->ppid);
      $this->secret_key = civicrm_api3('PaymentProcessor', 'getvalue', $params);
    }
    catch(Exception $e) {
      throw new CRM_Core_Exception('Failed to get Stripe secret key.');
    }

    // Now re-retrieve the data from Stripe to ensure it's legit.
    require_once ("packages/stripe-php/init.php");
    \Stripe\Stripe::setApiKey($this->secret_key);

    if ($this->verify_event) {
      $this->_inputParameters = \Stripe\Event::retrieve($parameters->id);
    }
    else {
      $this->_inputParameters = $parameters;
    }
  }
  /**
    * 
    * Get a parameter given to us by Stripe.
    * @param string $name
    * @param $type
    * @param bool $abort
    *
    * @return mixed
   */
  public function retrieve($name, $type, $abort = TRUE) {

    $class_name = get_class($this->_inputParameters->data->object);
    $value = NULL;
    switch ($name) {
      case 'subscription_id':
        if ($class_name == 'Stripe\Invoice') {
          $value = $this->_inputParameters->data->object->subscription;
        }
        elseif ($class_name == 'Stripe\Subscription') {
          $value = $this->_inputParameters->data->object->id;
        }
        break;
      case 'customer_id':
        $value = $this->_inputParameters->data->object->customer;
        break;
      case 'test_mode':
        $value = (int)!$this->_inputParameters->livemode;
        break;
      case 'invoice_id':
        if ($class_name == 'Stripe\Invoice') {
          $value = $this->_inputParameters->data->object->id;
        }
        break;
      case 'receive_date':
        if ($class_name == 'Stripe\Invoice') {
          $value = date("Y-m-d H:i:s", $this->_inputParameters->data->object->date);
        }
        break;
      case 'charge_id':
        if ($class_name == 'Stripe\Invoice') {
          $value = $this->_inputParameters->data->object->charge;
        }
        break;
      case 'event_type':
        $value = $this->_inputParameters->type;
        break;
      case 'plan_id': 
        if ($class_name == 'Stripe\Subscription') {
          $value = $this->_inputParameters->data->object->plan->id;
        }
        break;
      case 'previous_plan_id':
        if (preg_match('/\.updated$/', $this->_inputParameters->type)) {
          $value = $this->_inputParameters->data->previous_attributes->plan->id;
        }
        break;
      case 'plan_amount':
        if ($class_name == 'Stripe\Subscription') {
          $value = $this->_inputParameters->data->object->plan->amount / 100;
        }
        break;
      case 'frequency_interval':
        if ($class_name == 'Stripe\Subscription') {
          $value = $this->_inputParameters->data->object->plan->interval_count;
        }
        break;
      case 'frequency_unit':
        if ($class_name == 'Stripe\Subscription') {
          $value = $this->_inputParameters->data->object->plan->interval;
        }
        break;
      case 'plan_name':
        if ($class_name == 'Stripe\Subscription') {
          $value = $this->_inputParameters->data->object->plan->name;
        }
        break;
      case 'plan_start':
        if ($class_name == 'Stripe\Subscription') {
          $value = date("Y-m-d H:i:s", $this->_inputParameters->data->object->start);
        }
        break;
    }
    $value = CRM_Utils_Type::validate($value, $type, FALSE);
    if ($abort && $value === NULL) {
      throw new CRM_Core_Exception("Could not find an entry for '$name'.");
    }
    return $value;
  }

  function main() {
    // Collect and determine all data about this event.
    $this->setInfo();

    switch($this->event_type) {
      // Successful recurring payment.
      case 'invoice.payment_succeeded':
        // Lets do a check to make sure this payment has the amount same as that of first contribution.
        // If it's not a match, something is wrong (since when we update a plan, we generate a whole
        // new recurring contribution).
        if ($this->previous_contribution_total_amount != $this->amount) {
          throw new CRM_Core_Exception("Subscription amount mismatch. I have " . $this->amount . " and I expect " . $this->previous_contribution_total_amount . ".");
          return FALSE;
        }

        if ($this->previous_contribution_status_id == 2) {
          // Update the contribution to include the fee.
          civicrm_api3('Contribution', 'create', array(
            'id' => $this->previous_contribution_id,
 	          'total_amount' => $this->amount,
            'fee_amount' => $this->fee,
            'net_amount' => $this->net_amount,
          ));
          // The last one was not completed, so complete it.
          $result = civicrm_api3('Contribution', 'completetransaction', array(
            'sequential' => 1,
            'id' => $this->previous_contribution_id,
            'trxn_date' => $this->receive_date,
            'trxn_id' => $this->charge_id,
            'total_amount' => $this->amount,
            'net_amount' => $this->net_amount,
            'fee_amount' => $this->fee,
            'payment_processor_id' => $this->ppid,
            'is_email_receipt' => $this->is_email_receipt,
           ));
        } else {
          // The first contribution was completed, so create a new one.
          
          // api contribution repeattransaction repeats the appropriate contribution if it is given
          // simply the recurring contribution id. It also updates the membership for us. 

          $result = civicrm_api3('Contribution', 'repeattransaction', array(
            // Actually, don't use contribution_recur_id until CRM-19945 patches make it in to 4.6/4.7
            // and we have a way to require a minimum minor CiviCRM version.
            //'contribution_recur_id' => $this->recurring_info->id,
            'original_contribution_id' => $this->previous_completed_contribution_id,
            'contribution_status_id' => "Completed",
            'receive_date' => $this->receive_date,
            'trxn_id' => $this->charge_id,
            'total_amount' => $this->amount,
            'fee_amount' => $this->fee,
            //'invoice_id' => $new_invoice_id - contribution.repeattransaction doesn't support it currently
            'is_email_receipt' => $this->is_email_receipt,
          ));

          // Update invoice_id manually. repeattransaction doesn't return the new contrib id either, so we update the db.
          $query_params = array(
            1 => array($this->invoice_id, 'String'),
            2 => array($this->charge_id, 'String'),
           );
          CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution
            SET invoice_id = %1
            WHERE trxn_id = %2",
          $query_params);
        }

        // Successful charge & more to come. 
        $result = civicrm_api3('ContributionRecur', 'create', array(
          'sequential' => 1,
          'id' => $this->contribution_recur_id,
          'failure_count' => 0,
          'contribution_status_id' => "In Progress"
        ));

        return TRUE;

      // Failed recurring payment.
      case 'invoice.payment_failed':
        $fail_date = date("Y-m-d H:i:s");

        if ($this->previous_contribution_status_id == 2) {
          // If this contribution is Pending, set it to Failed.
          $result = civicrm_api3('Contribution', 'create', array(
            'id' => $this->previous_contribution_id,
            'contribution_status_id' => "Failed",
            'receive_date' => $fail_date,
            'is_email_receipt' => $this->is_email_receipt,
          ));
        }
        else {
          civicrm_api3('Contribution', 'create', array(
            'contribution_recur_id' => $this->contribution_recur_id,
            'contribution_status_id' => "Failed",
            'contact_id' => $this->contact_id,
            'financial_type_id' => $this->financial_type_id,
            'receive_date' => $fail_date,
            'total_amount' => $this->amount,
            'is_email_receipt' => $this->is_email_receipt,
            'is_test' => $this->test_mode,
          ));  
        }

        $failure_count = civicrm_api3('ContributionRecur', 'getvalue', array(
         'sequential' => 1,
         'id' => $this->contribution_recur_id,
         'return' => 'failure_count',
        ));
        $failure_count++;

        //  Change the status of the Recurring and update failed attempts.
        $result = civicrm_api3('ContributionRecur', 'create', array(
          'sequential' => 1,
          'id' => $this->contribution_recur_id,
          'contribution_status_id' => "Failed",
          'failure_count' => $failure_count,
          'modified_date' => $fail_date,
        ));

        return TRUE;

      //Subscription is cancelled
      case 'customer.subscription.deleted':
        //Cancel the recurring contribution
        $result = civicrm_api3('ContributionRecur', 'cancel', array(
          'id' => $this->contribution_recur_id,
        ));

        //Delete the record from Stripe's subscriptions table
        $query_params = array(
          1 => array($this->subscription_id, 'String'),
        );
        CRM_Core_DAO::executeQuery("DELETE FROM civicrm_stripe_subscriptions
          WHERE subscription_id = %1", $query_params);

        return TRUE;

      // One-time donation and per invoice payment.
      case 'charge.succeeded':
        // Not implemented.
        return TRUE;

     // Subscription is updated. Delete existing recurring contribution and start a fresh one.
     // This tells a story to site admins over editing a recurring contribution record.
     case 'customer.subscription.updated':
       if (empty($this->previous_plan_id)) {
         // Not a plan change...don't care.
         return TRUE;
       }
       
       $new_civi_invoice = md5(uniqid(rand(), TRUE));

       if ($this->previous_contribution_status_id == 2) {
         // Cancel the pending contribution.
         $result = civicrm_api3('Contribution', 'delete', array(
           'sequential' => 1,
           'id' => $this->previous_contribution_id,
         ));
       }

       // Cancel the old recurring contribution.
       $result = civicrm_api3('ContributionRecur', 'cancel', array(
         'sequential' => 1,
         'id' => $this->contribution_recur_id
       ));

       $new_contribution_recur = civicrm_api3('ContributionRecur', 'create', array(
          'sequential' => 1,
          'contact_id' => $this->contact_id,
          'invoice_id' => $new_civi_invoice,
          'amount' => $this->plan_amount,
          'auto_renew' => 1,
          'created_date' => $this->plan_start,
          'frequency_unit' => $this->frequency_unit,
          'frequency_interval' => $this->frequency_interval,
          'contribution_status_id' => "In Progress",
          'payment_processor_id' =>  $this->ppid,
          'financial_type_id' => $this->financial_type_id,
          'payment_instrument_id' => $this->payment_instrument_id,
          'is_test' => $this->test_mode,
       ));
       $new_contribution_recur_id = $new_contribution_recur['id'];

       $new_contribution = civicrm_api3('Contribution', 'create', array(
          'sequential' => 1,
          'contact_id' => $this->contact_id,
          'invoice_id' => $new_civi_invoice,
          'total_amount' => $this->plan_amount,
          'contribution_recur_id' => $new_contribution_recur_id,
          'contribution_status_id' => "Pending",
          'financial_type_id' => $this->financial_type_id,
          'payment_instrument_id' => $this->payment_instrument_id,
          'note' => "Created by Stripe webhook.",
          'is_test' => $this->test_mode,
        ));

        $new_contribution_id = $new_contribution['id'];

        // Prepare escaped query params.
        $query_params = array(
          1 => array($new_contribution_recur_id, 'Integer'),
          2 => array($this->subscription_id, 'String'),
        );
        CRM_Core_DAO::executeQuery("UPDATE civicrm_stripe_subscriptions
          SET contribution_recur_id  = %1 where subscription_id = %2",
          $query_params
        );

        if ($this->membership_id) { 
          $plan_elements = explode("-", $this->plan_id);
          $plan_name_elements = explode("-", $this->plan_name);
          $new_membership_type_id = NULL;
          if ("membertype_" == substr($plan_elements[0],0,11)) {
            $new_membership_type_id = substr($plan_elements[0],strrpos($plan_elements[0],'_') + 1);
          } else if  ("membertype_" == substr($plan_name_elements[0],0,11)) {
             $new_membership_type_id = substr($plan_name_elements[0],strrpos($plan_name_elements[0],'_') + 1);
          }

          // Adjust to the new membership level.
          if (!empty($new_membership_type_id)) {
            $result = civicrm_api3('Membership', 'create', array(
              'sequential' => 1,
              'id' => $this->membership_id,
              'membership_type_id' => $new_membership_type_id,
              'contribution_recur_id' => $new_contribution_recur_id,
              'num_terms' => 0,
            ));

            // Create a new membership payment record.
            $result = civicrm_api3('MembershipPayment', 'create', array(
              'sequential' => 1,
              'membership_id' => $this->membership_id,
              'contribution_id' => $new_contribution_id,
            ));
          }
        }
        return TRUE;

      // Keep plans table in sync with Stripe when a plan is deleted.
     case 'plan.deleted':
       $is_live = $this->test_mode == 1 ? 0 : 1;
       $query_params = array(
         1 => array($this->plan_id, 'String'),
         2 => array($this->ppid, 'Integer'),
         3 => array($is_live, 'Integer')
       );
       CRM_Core_DAO::executeQuery("DELETE FROM civicrm_stripe_plans WHERE
         plan_id = %1 AND  processor_id = %2 and is_live = %3", $query_params);

       return TRUE;
    }
    // Unhandled event type.
    return TRUE;
  }

  /**
   * Gather and set info as class properties.
   *
   * Given the data passed to us via the Stripe Event, try to determine
   * as much as we can about this event and set that information as 
   * properties to be used later.
   */
  function setInfo() {

    $this->event_type = $this->retrieve('event_type', 'String');
    $this->customer_id = $this->retrieve('customer_id', 'String');
    $this->test_mode = $this->retrieve('test_mode', 'Integer');

    $abort = FALSE;
    $this->subscription_id = $this->retrieve('subscription_id', 'String', $abort);
    $this->invoice_id = $this->retrieve('invoice_id', 'String', $abort);
    $this->receive_date = $this->retrieve('receive_date', 'String', $abort);
    $this->charge_id = $this->retrieve('charge_id', 'String', $abort);
    $this->plan_id = $this->retrieve('plan_id', 'String', $abort);
    $this->previous_plan_id = $this->retrieve('previous_plan_id', 'String', $abort);
    $this->plan_amount = $this->retrieve('plan_amount', 'String', $abort);
    $this->frequency_interval = $this->retrieve('frequency_interval', 'String', $abort);
    $this->frequency_unit = $this->retrieve('frequency_unit', 'String', $abort);
    $this->plan_name = $this->retrieve('plan_name', 'String', $abort);
    $this->plan_start = $this->retrieve('plan_start', 'String', $abort);

    // Gather info about the amount and fee.
    // Get the Stripe charge object if one exists. Null charge still needs processing.
    if ( $this->charge_id !== null ) {
      try {
        $charge = \Stripe\Charge::retrieve($this->charge_id);
        $balance_transaction_id = $charge->balance_transaction;
        $balance_transaction = \Stripe\BalanceTransaction::retrieve($balance_transaction_id);
        $this->amount = $charge->amount / 100;
        $this->fee = $balance_transaction->fee / 100;
      }
      catch(Exception $e) {
        throw new CRM_Core_Exception('Cannot get contribution_recur_id from Stripe table.');
      }
    } else {
      // The customer had a credit on their subscription from a downgrade or gift card.
      $this->amount = 0;
      $this->fee = 0;
    }

    $this->net_amount = $this->amount - $this->fee;

    // Get info related to recurring contributions.
    $sql = "SELECT contribution_recur_id,
      financial_type_id, payment_instrument_id, contact_id
      FROM civicrm_stripe_subscriptions s JOIN civicrm_contribution_recur r
      ON s.contribution_recur_id = r.id
      WHERE subscription_id = %1
      AND s.processor_id = %2";
     $query_params = array(
      1 => array($this->subscription_id, 'String'),
      2 => array($this->ppid, 'Integer'),
    );
    $dao = CRM_Core_DAO::executeQuery($sql, $query_params);
    $dao->fetch();
    if ($dao->N == 1) {
      $this->contribution_recur_id = $dao->contribution_recur_id;
      $this->financial_type_id = $dao->financial_type_id;
      $this->payment_instrument_id = $dao->payment_instrument_id;
      $this->contact_id = $dao->contact_id;

      // Same approach as api repeattransaction. Find last contribution ascociated 
      // with our recurring contribution.
      $results = civicrm_api3('contribution', 'getsingle', array(
       'return' => array('id', 'contribution_status_id', 'total_amount'),
       'contribution_recur_id' => $this->contribution_recur_id,
       'options' => array('limit' => 1, 'sort' => 'id DESC'),
       'contribution_test' => $this->test_mode,
      ));
      $this->previous_contribution_id = $results['contribution_id'];
      $this->previous_contribution_status_id = $results['contribution_status_id'];
      $this->previous_contribution_total_amount = $results['total_amount'];

      // Workaround for CRM-19945.
      try {
        $this->previous_completed_contribution_id = civicrm_api3('contribution', 'getvalue', array(
          'return' => 'id',
          'contribution_recur_id' => $this->contribution_recur_id_id,
          'contribution_status_id' => array('IN' => array('Completed')),
          'options' => array('limit' => 1, 'sort' => 'id DESC'),
          'contribution_test' => $test_mode,
        ));
      } catch (Exception $e) {
        // This is fine....could only be a pending in the db.
      }

      // Check for membership id.
      $membership = civicrm_api3('Membership', 'get', array(
        'contribution_recur_id' => $this->contribution_recur_id,
      ));
      if ($membership['count'] == 1) {
        $this->membership_id = $membership['id'];
      }

    }
  }
}
