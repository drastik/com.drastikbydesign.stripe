<?php
/*
 * @file
 * Handle Stripe Webhooks for recurring payments.
 */

require_once 'CRM/Core/Page.php';

class CRM_Stripe_Page_Webhook extends CRM_Core_Page {
  function run() {
    function getRecurInfo($subscription_id,$test_mode) {

        $query_params = array(
          1 => array($subscription_id, 'String'),
        );
        $sub_info_query = CRM_Core_DAO::executeQuery("SELECT contribution_recur_id
          FROM civicrm_stripe_subscriptions
          WHERE subscription_id = %1",
          $query_params);

        if (!empty($sub_info_query)) {
          $sub_info_query->fetch();

          if(!empty($sub_info_query->contribution_recur_id)) {
          $recurring_info = new StdClass;
          $recurring_info->id = $sub_info_query->contribution_recur_id;
          }
          else {
            header('HTTP/1.1 400 Bad Request');
            CRM_Core_Error::Fatal("Error relating this subscription id ($subscription_id) to the one in civicrm_stripe_subscriptions");
            CRM_Utils_System::civiExit();
          }
        }
        // Same approach as api repeattransaction. Find last contribution ascociated
        // with our recurring contribution.
        $recurring_info->previous_contribution_id = civicrm_api3('contribution', 'getvalue', array(
         'return' => 'id',
         'contribution_recur_id' => $recurring_info->id,
         'options' => array('limit' => 1, 'sort' => 'id DESC'),
         'contribution_test' => $test_mode,
        ));
        // Workaround for CRM-19945.
        try {
          $recurring_info->previous_completed_contribution_id = civicrm_api3('contribution', 'getvalue', array(
           'return' => 'id',
           'contribution_recur_id' => $recurring_info->id,
           'contribution_status_id' => array('IN' => array('Completed')),
           'options' => array('limit' => 1, 'sort' => 'id DESC'),
           'contribution_test' => $test_mode,
          ));
        } catch (Exception $e) {
         // This is fine....could only be a pending in the db.
        }
        if (!empty($recurring_info->previous_contribution_id)) {
         //$previous_contribution_query->fetch();
         }
        else {
          header('HTTP/1.1 400 Bad Request');
          CRM_Core_Error::Fatal("ERROR: Stripe could not find contribution ($recurring_info->previous_contribution_id)  in civicrm_contribution: " . $stripe_event_data);
          CRM_Utils_System::civiExit();
        }
        $current_recurring_contribution = civicrm_api3('ContributionRecur', 'get', array(
          'sequential' => 1,
          'return' => "payment_processor_id, financial_type_id, payment_instrument_id",
          'id' => $recurring_info->id,
         ));
        $recurring_info->payment_processor_id = $current_recurring_contribution['values'][0]['payment_processor_id'];
        $recurring_info->financial_type_id = $current_recurring_contribution['values'][0]['financial_type_id'];
        $recurring_info->payment_instrument_id = $current_recurring_contribution['values'][0]['payment_instrument_id'];
        $recurring_info->contact_id = civicrm_api3('Contribution', 'getvalue', array(
         'sequential' => 1,
         'return' => "contact_id",
         'id' => $recurring_info->previous_contribution_id,
        ));

        return $recurring_info;
    }
    // Get the data from Stripe.
    $data_raw = file_get_contents("php://input");
    $data = json_decode($data_raw);
    if (!$data) {
      header('HTTP/1.1 406 Not acceptable');
      CRM_Core_Error::Fatal("Stripe Callback: cannot json_decode data, exiting. <br /> $data");
      CRM_Utils_System::civiExit();
    }

    // Test mode is the opposite of live mode.
    $test_mode = (int)!$data->livemode;

    $processorId = CRM_Utils_Request::retrieve('ppid', 'Integer');
    try {
      if (empty($processorId)) {
        $stripe_key = civicrm_api3('PaymentProcessor', 'getvalue', array(
          'return' => 'user_name',
          'payment_processor_type_id' => 'Stripe',
          'is_test' => $test_mode,
          'is_active' => 1,
          'options' => array('limit' => 1),
        ));
      }
      else {
        $stripe_key = civicrm_api3('PaymentProcessor', 'getvalue', array(
          'return' => 'user_name',
          'id' => $processorId,
        ));
      }
    }
    catch (CiviCRM_API3_Exception $e) {
      header('HTTP/1.1 400 Bad Request');
      CRM_Core_Error::fatal('Cannot find Stripe API key: ' . $e->getMessage());
      CRM_Utils_System::civiExit();
    }

    require_once ("packages/stripe-php/init.php");
    \Stripe\Stripe::setAppInfo('CiviCRM', CRM_Utils_System::version(), CRM_Utils_System::baseURL());
    \Stripe\Stripe::setApiKey($stripe_key);

    // Retrieve Event from Stripe using ID even though we already have the values now.
    // This is for extra security precautions mentioned here: https://stripe.com/docs/webhooks
    $stripe_event_data = \Stripe\Event::retrieve($data->id);
    $customer_id = $stripe_event_data->data->object->customer;

    switch($stripe_event_data->type) {
      // Successful recurring payment.
      case 'invoice.payment_succeeded':
        $subscription_id = $stripe_event_data->data->object->subscription;
        $new_invoice_id = $stripe_event_data->data->object->id;
        $receive_date = date("Y-m-d H:i:s", $stripe_event_data->data->object->date);
        $charge_id = $stripe_event_data->data->object->charge;

        // Get the Stripe charge object if one exists. Null charge still needs processing.
        if ( $charge_id !== null ) {
          try {
            $charge = \Stripe\Charge::retrieve($charge_id);
            $balance_transaction_id = $charge->balance_transaction;
            $balance_transaction = \Stripe\BalanceTransaction::retrieve($balance_transaction_id);
	    $amount = $charge->amount / 100;
	    $fee = $balance_transaction->fee / 100;
          }
          catch(Exception $e) {
            header('HTTP/1.1 400 Bad Request');
            CRM_Core_Error::Fatal("Failed to retrieve Stripe charge.  Message: " . $e->getMessage());
            CRM_Utils_System::civiExit();
          }
        } else {
        // The customer had a credit on their subscription from a downgrade or gift card.
        $amount = 0;
        $fee = 0;
        }

        // First, get the recurring contribution id and previous contribution id.
        $recurring_info = getRecurInfo($subscription_id,$test_mode);

        // Fetch the previous contribution's status.
        $previous_contribution = civicrm_api3('Contribution', 'get', array(
          'sequential' => 1,
          'return' => "contribution_status_id,invoice_id",
          'id' => $recurring_info->previous_contribution_id,
	  'contribution_test' => $test_mode,
         ));
        $previous_contribution_status = $previous_contribution['values'][0]['contribution_status_id'];

        // Check if the previous contribution's status is pending and update it
        // using create and then complete it, else repeat it if not pending.
        // When a member upgrades/downgrades mid-term, (or recurring contributor
        // changes levels), we are in a unique situation not knowing ahead of time
        // what the contribution amount really is. completetransaction can't modify
        // our amounts (except for fee). We'll need to update the contribution amounts
        // to the actual values from Stripe for accounting.

        if ($previous_contribution_status == "2") {
          // Note: using create contribution to edit won't recalculate the net_amount.
          // We need to calculate and explicitly change it.
          $net_amount = $amount - $fee;
          $pending_contribution = civicrm_api3('Contribution', 'create', array(
           'id' => $recurring_info->previous_contribution_id,
 	    'total_amount' => $amount,
            'fee_amount' => $fee,
            'net_amount' => $net_amount,
            'receive_date' => $receive_date,
           ));
          // Leave some indication that this is legitimately supposed to be a $0 contribution,
          // by not leaving trxn_id empty.
          if ( $amount == 0 ) {
            $charge_id = $previous_contribution['values'][0]['invoice_id'];
          }
          // Now complete it.
          $result = civicrm_api3('Contribution', 'completetransaction', array(
            'sequential' => 1,
            'id' => $recurring_info->previous_contribution_id,
            'trxn_date' => $receive_date,
            'trxn_id' => $charge_id,
            'total_amount' => $amount,
            'fee_amount' => $fee,
           ));

          return;

	 } else {

	 // api contribution repeattransaction repeats the appropriate contribution if it is given
	 // simply the recurring contribution id. It also updates the membership for us. However,
         // we add the amount and fee regardless of the expected amounts because we may have
         // upgraded or downgraded the membership, or recurring contribution level.  This means
         // prorated invoices.

         $result = civicrm_api3('Contribution', 'repeattransaction', array(
           // Actually, don't use contribution_recur_id until CRM-19945 patches make it in to 4.6/4.7
           // and we have a way to require a minimum minor CiviCRM version.
	   //'contribution_recur_id' => $recurring_info->id,
	   'original_contribution_id' => $recurring_info->previous_completed_contribution_id,
           'contribution_status_id' => "Completed",
           'receive_date' => $receive_date,
           'trxn_id' => $charge_id,
 	   'total_amount' => $amount,
	   'fee_amount' => $fee,
	  //'invoice_id' => $new_invoice_id - contribution.repeattransaction doesn't support it currently
	   'is_email_receipt' => 1,
         ));

        // Update invoice_id manually. repeattransaction doesn't return the new contrib id either, so we update the db.
        $query_params = array(
          1 => array($new_invoice_id, 'String'),
          2 => array($charge_id, 'String'),
         );
        CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution
          SET invoice_id = %1
          WHERE trxn_id = %2",
         $query_params);


        // Successful charge & more to come
        $result = civicrm_api3('ContributionRecur', 'create', array(
          'sequential' => 1,
          'id' => $recurring_info->id,
          'failure_count' => 0,
          'contribution_status_id' => "In Progress"
         ));

        CRM_Utils_System::civiExit();
        }
        break;

      // Failed recurring payment.
      case 'invoice.payment_failed':
        // Get the Stripe charge object.
        try {
          $charge = \Stripe\Charge::retrieve($stripe_event_data->data->object->charge);
        }
        catch(Exception $e) {
          header('HTTP/1.1 400 Bad Request');
          CRM_Core_Error::Fatal("Failed to retrieve Stripe charge.  Message: " . $e->getMessage());
          CRM_Utils_System::civiExit();
        }

        // Build some params.
        $subscription_id = $stripe_event_data->data->object->subscription;
        $new_invoice_id = $stripe_event_data->data->object->id;
        $charge_id = $stripe_event_data->data->object->charge;
        $attempt_count = $stripe_event_data->data->object->attempt_count;
        $fail_date = date("Y-m-d H:i:s");
        $amount = $charge->amount / 100;
        $fee_amount = isset($charge->fee) ? ($charge->fee / 100) : 0;
        $transaction_id = $charge->id;

        // First, get the recurring contribution id and previous contribution id.
        $recurring_info = getRecurInfo($subscription_id,$test_mode);

        // Fetch the previous contribution's status.
        $previous_contribution_status = civicrm_api3('Contribution', 'getvalue', array(
          'sequential' => 1,
          'return' => "contribution_status_id",
          'id' => $recurring_info->previous_contribution_id,
	  'contribution_test' => $test_mode,
         ));

          if ($previous_contribution_status == 2) {
          // If this contribution is Pending, set it to Failed.
          $result = civicrm_api3('Contribution', 'create', array(
            'id' => $recurring_info->previous_contribution_id,
	    'contribution_recur_id' => $recurring_info->id,
            'contribution_status_id' => "Failed",
            'contact_id' => $recurring_info->contact_id,
            'financial_type_id' => $recurring_info->financial_type_id,
            'receive_date' => $fail_date,
            'total_amount' => $amount,
	    'is_email_receipt' => 1,
	    'is_test' => $test_mode,
          ));

          }
          else {
          // Record a Failed contribution. Use repeattransaction for this when CRM-19984
          // patch makes it in 4.6/4.7.
          $result = civicrm_api3('Contribution', 'create', array(
	    'contribution_recur_id' => $recurring_info->id,
            'contribution_status_id' => "Failed",
            'contact_id' => $recurring_info->contact_id,
            'financial_type_id' => $recurring_info->financial_type_id,
            'receive_date' => $fail_date,
            'total_amount' => $amount,
	    'is_email_receipt' => 1,
	    'is_test' => $test_mode,
          ));
         }

          $failure_count = civicrm_api3('ContributionRecur', 'getvalue', array(
            'sequential' => 1,
            'id' => $recurring_info->id,
            'return' => 'failure_count',
            ));
          $failure_count++;
          //  Change the status of the Recurring and update failed attempts.
          $result = civicrm_api3('ContributionRecur', 'create', array(
            'sequential' => 1,
            'id' => $recurring_info->id,
            'contribution_status_id' => "Failed",
            'failure_count' => $failure_count,
            'modified_date' => $fail_date,
            'is_test' => $test_mode,
            ));

          return;
        break;


      //Subscription is cancelled
      case 'customer.subscription.deleted':
        $subscription_id = $stripe_event_data->data->object->id;

        // First, get the recurring contribution id and previous contribution id.
        $recurring_info = getRecurInfo($subscription_id,$test_mode);

        //Cancel the recurring contribution
        $result = civicrm_api3('ContributionRecur', 'cancel', array(
            'sequential' => 1,
            'id' => $recurring_info->id,
        ));

        //Delete the record from Stripe's subscriptions table
        $query_params = array(
            1 => array($subscription_id, 'String'),
        );
        CRM_Core_DAO::executeQuery("DELETE FROM civicrm_stripe_subscriptions
              WHERE subscription_id = %1", $query_params);

        break;

      // One-time donation and per invoice payment.
      case 'charge.succeeded':
        // Not implemented.
        CRM_Utils_System::civiExit();
        break;


      // Subscription is updated. Delete existing recurring contribution and start a fresh one.
      // This tells a story to site admins over editing a recurring contribution record.
     case 'customer.subscription.updated':
         if (empty($stripe_event_data->data->previous_attributes->plan->id)) {
           // Not a plan change...don't care.
           CRM_Utils_System::civiExit();
         }
         $subscription_id = $stripe_event_data->data->object->id;
         $new_amount = $stripe_event_data->data->object->plan->amount / 100;
         $new_frequency_interval = $stripe_event_data->data->object->plan->interval_count;
         $new_frequency_unit = $stripe_event_data->data->object->plan->interval;
         $plan_id = $stripe_event_data->data->object->plan->id;
         $plan_name = $stripe_event_data->data->object->plan->name;
         $plan_elements = explode("-", $plan_id);
         $plan_name_elements = explode("-", $plan_name);
         $created_date = date("Y-m-d H:i:s", $stripe_event_data->data->object->start);
         $new_civi_invoice = md5(uniqid(rand(), TRUE));

         // First, get the recurring contribution id and previous contribution id.
         $recurring_info = getRecurInfo($subscription_id,$test_mode);

         // Is there a pending charge due to a subcription change?  Make up your mind!!
         $previous_contribution = civicrm_api3('Contribution', 'get', array(
          'sequential' => 1,
          'return' => "contribution_status_id,invoice_id",
          'id' => $recurring_info->previous_contribution_id,
	  'contribution_test' => $test_mode,
         ));
         if ($previous_contribution['values'][0]['contribution_status_id'] == "2") {
           // Cancel the pending contribution.
           $result = civicrm_api3('Contribution', 'delete', array(
            'sequential' => 1,
            'id' => $recurring_info->previous_contribution_id,
           ));
         }

         // Cancel the old recurring contribution.
         $result = civicrm_api3('ContributionRecur', 'cancel', array(
          'sequential' => 1,
          'id' => $recurring_info->id
         ));

         $new_recurring_contribution = civicrm_api3('ContributionRecur', 'create', array(
          'sequential' => 1,
          'contact_id' => $recurring_info->contact_id,
          'invoice_id' => $new_civi_invoice,
          'amount' => $new_amount,
          'auto_renew' => 1,
          'created_date' => $created_date,
          'frequency_unit' => $new_frequency_unit,
          'frequency_interval' => $new_frequency_interval,
          'contribution_status_id' => "In Progress",
          'payment_processor_id' =>  $recurring_info->payment_processor_id,
          'financial_type_id' => $recurring_info->financial_type_id,
          'payment_instrument_id' => $recurring_info->payment_instrument_id,
          'is_test' => $test_mode,
          ));
         $new_recurring_contribution_id = $new_recurring_contribution['values'][0]['id'];
         $new_contribution = civicrm_api3('Contribution', 'create', array(
          'sequential' => 1,
          'contact_id' => $recurring_info->contact_id,
          'invoice_id' => $new_civi_invoice,
          'total_amount' => $new_amount,
          'contribution_recur_id' => $new_recurring_contribution_id,
          'contribution_status_id' => "Pending",
          'financial_type_id' => $recurring_info->financial_type_id,
          'payment_instrument_id' => $recurring_info->payment_instrument_id,
          'note' => "Created by Stripe webhook.",
          'is_test' => $test_mode,
          ));

          // Prepare escaped query params.
      $query_params = array(
        1 => array($new_recurring_contribution_id, 'Integer'),
        2 => array($subscription_id, 'String'),
      );
      CRM_Core_DAO::executeQuery("UPDATE civicrm_stripe_subscriptions
        SET contribution_recur_id  = %1 where subscription_id = %2", $query_params);

       // Find out if the plan is ascociated with a membership and if so
       // adjust it to the new level.

          $membership_result = civicrm_api3('Membership', 'get', array(
           'sequential' => 1,
           'return' => "membership_type_id,id",
           'contribution_recur_id' => $recurring_info->id,
          ));

          if ("membertype_" == substr($plan_elements[0],0,11)) {
            $new_membership_type_id = substr($plan_elements[0],strrpos($plan_elements[0],'_') + 1);
          } else if  ("membertype_" == substr($plan_name_elements[0],0,11)) {
             $new_membership_type_id = substr($plan_name_elements[0],strrpos($plan_name_elements[0],'_') + 1);
          }

          // Adjust to the new membership level.
          if (!empty($new_membership_type_id)) {
            $membership_id = $membership_result['values'][0]['id'];
            $result = civicrm_api3('Membership', 'create', array(
             'sequential' => 1,
             'id' => $membership_id,
             'membership_type_id' => $new_membership_type_id,
             'contact_id' => $recurring_info->contact_id,
             'contribution_recur_id' => $new_recurring_contribution_id,
             'num_terms' => 0,
            ));

          // Create a new membership payment record.
          $result = civicrm_api3('MembershipPayment', 'create', array(
           'sequential' => 1,
           'membership_id' => $membership_id,
           'contribution_id' => $new_contribution['values'][0]['id'],
          ));
           }

          break;

      // Keep plans table in sync with Stripe when a plan is deleted.
     case 'plan.deleted':
       $plan_id = $stripe_event_data->data->object->id;
       // Prepare escaped query params.
        $query_params = array(
          1 => array($plan_id, 'String'),
          2 => array($processorId, 'Integer'),
        );
       CRM_Core_DAO::executeQuery("DELETE FROM civicrm_stripe_plans WHERE
         plan_id = %1 AND  processor_id = %2", $query_params);

       break;

       return;

    }

    parent::run();
  }

}
