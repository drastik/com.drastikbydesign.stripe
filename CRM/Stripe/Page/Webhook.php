<?php
/*
 * @file
 * Handle Stripe Webhooks for recurring payments.
 */
                    
require_once 'CRM/Core/Page.php';

class CRM_Stripe_Page_Webhook extends CRM_Core_Page {
  function run() {
    function getOrigInvoice($subscription_id) {

        $query_params = array(
          1 => array($subscription_id, 'String'),
        );
        $sub_info_query = CRM_Core_DAO::executeQuery("SELECT invoice_id, end_time
          FROM civicrm_stripe_subscriptions
          WHERE subscription_id = %1",
          $query_params);

        if (!empty($sub_info_query)) {
          $sub_info_query->fetch();

          if(!empty($sub_info_query->invoice_id)) {
          $original_invoice->end_time = $sub_info_query->end_time;
          $original_invoice->id = $sub_info_query->invoice_id;
          }
          else {
          CRM_Core_Error::Fatal("Error relating this subscription id ($subscription_id) to the one in civicrm_stripe_subscriptions for customer with id ($customer_id) ");
          exit();
	  }
	}  
        return $original_invoice;         
    }
    // Get the data from Stripe.
    $data_raw = file_get_contents("php://input");
    $data = json_decode($data_raw);
     if (!$data) {
      CRM_Core_Error::Fatal("Stripe Callback: cannot json_decode data, exiting. ($data)");
     }

    $test_mode = (int)!$data->livemode;

    $stripe_key = CRM_Core_DAO::singleValueQuery("SELECT pp.user_name FROM civicrm_payment_processor pp INNER JOIN civicrm_payment_processor_type ppt on pp.payment_processor_type_id = ppt.id AND ppt.name  = 'Stripe' WHERE is_test = '$test_mode'");

    require_once ("packages/stripe-php/lib/Stripe.php");
    Stripe::setApiKey($stripe_key);

    // Retrieve Event from Stripe using ID even though we already have the values now.
    // This is for extra security precautions mentioned here: https://stripe.com/docs/webhooks
    $stripe_event_data = Stripe_Event::retrieve($data->id);
    $customer_id = $stripe_event_data->data->object->customer;
    $new_invoice_id = $stripe_event_data->data->object->id;

    switch($stripe_event_data->type) {
      // Successful recurring payment.
      case 'invoice.payment_succeeded':
        // Get the Stripe charge object.
        try {
          $charge = Stripe_Charge::retrieve($stripe_event_data->data->object->charge);
        }
        catch(Exception $e) {
          CRM_Core_Error::Fatal("Failed to retrieve Stripe charge.  Message: " . $e->getMessage());
          exit();
        }

        $subscription_id = $stripe_event_data->data->object->subscription;
        $balance_transaction_id = $charge->balance_transaction;
	$charge_id = $charge->id;

        // Find the original contribution and complete it or repeat it.

        // First, get the (original) invoice_id and end_time using subscription id or choke.
        $original_invoice = getOrigInvoice($subscription_id);

        // Compare against now + 24hrs to prevent charging 1 extra day.
        $time_compare = time() + 86400;

        // Fetch Civi's info about this recurring contribution
        $recurring_contribution = civicrm_api3('ContributionRecur', 'get', array(
            'sequential' => 1,
            'return' => array("id", "contribution_status_id"),
            'invoice_id' => $original_invoice->id,
	    'contribution_test' => $test_mode,
        ));

        if(!$recurring_contribution['id']) {
          CRM_Core_Error::Fatal("ERROR: Stripe triggered a Webhook on an invoice, ($original_invoice->id), ($customer_id) not found in civicrm_contribution_recur: " . $stripe_event_data);
          exit();
	} else {
	   $recurring_contribution_id = $recurring_contribution['id'];
	}	


        // Fetch the original contribution and find it's status in case it's pending. 
        $orig_contrib = civicrm_api3('Contribution', 'get', array(
            'sequential' => 1,
            'return' => "id,trxn_id,contribution_status_id",
            'invoice_id' => $original_invoice->id,
	    'contribution_test' => $test_mode,
       ));

        // Did we get a result from the original contributiuon query?  
        if (!empty($orig_contrib['values'][0]['contribution_id'])) {
	  $orig_contrib_id = $orig_contrib['values'][0]['contribution_id'];	

          // check if contrib is pending and complete it, set it's trxn_id and fee, then return
	  if ($orig_contrib['values'][0]['contribution_status_id'] == "2") {	
            // Get fee from Stripe.
            $balance_transaction = Stripe_BalanceTransaction::retrieve($balance_transaction_id);
            $fee = $balance_transaction->fee / 100; 

            $result = civicrm_api3('Contribution', 'completetransaction', array(
              'sequential' => 1,
              'id' => $orig_contrib_id,
              'trxn_id' => $charge_id,
	      'fee_amount' => $fee,
             ));

	    // Stash the subscription id in civicrm_contribution_recur for record keeping, since it's related
	    // data. Otherwise, invoice id is in two fields.  
	    $result = civicrm_api3('ContributionRecur', 'create', array(
	      'sequential' => 1,
	      'id' => $recurring_contribution_id,
	      'trxn_id' => $subscription_id,
	    ));

          return;
	  }

	} else {
            CRM_Core_Error::Fatal("ERROR: Stripe triggered a Webhook on an invoice, ($original_invoice->id) not found in civicrm_contribution: " . $stripe_event_data);
            exit();
        }

	// Get fee from Stripe.
	 $balance_transaction = Stripe_BalanceTransaction::retrieve($balance_transaction_id);
	 $fee = $balance_transaction->fee / 100;

	 // Repeat the original contribution ito update a membership.  api contribution repeattransaction 
	 // is how that is done. However, we add the amount and fee regardless of the original contribution
	 // because we may have upgraded or downgraded the membership, or recurring contribution level.
	 $amount = $charge->amount / 100;
	 
        $result = civicrm_api3('Contribution', 'repeattransaction', array(
            'original_contribution_id' => $orig_contrib_id,
            'contribution_status_id' => "Completed",
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

         if (!empty($original_invoice->end_time) && $time_compare > $original_invoice->end_time) {
            $end_date = date("Y-m-d H:i:s", $original_invoice->end_time);
            // Final payment.  Recurring contribution complete.
            $stripe_customer->cancelSubscription($subscription_id);

            $query_params = array(
              1 => array($subscription_id, 'String'),
            );
            CRM_Core_DAO::executeQuery("DELETE FROM civicrm_stripe_subscriptions
              WHERE subscription_id = %1", $query_params);
         //  Notate the cancel date now that the subscription is up.  
            $query_params = array(
              1 => array($end_date, 'String'),
              2 => array($original_invoice->id, 'String'),
            );
            CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution_recur 
              SET cancel_date = %1, contribution_status_id = '1'
              WHERE invoice_id = %2", $query_params);

            return;
          }
          // Successful charge & more to come
          //so check if this recurring contribution has a status different than In Progress
          if($recurring_contribution['values'][0]['contribution_status_id'] != 5) {

            //If so, set its status to In Progress
            $result = civicrm_api3('ContributionRecur', 'create', array(
                'sequential' => 1,
                'id' => $recurring_contribution['id'],
                'contribution_status_id' => "In Progress"
            ));
            return;
          }

        break;

      // Failed recurring payment.
      case 'invoice.payment_failed':
        // Get the Stripe charge object.
        try {
          $charge = Stripe_Charge::retrieve($stripe_event_data->data->object->charge);
        }
        catch(Exception $e) {
          CRM_Core_Error::Fatal("Failed to retrieve Stripe charge.  Message: " . $e->getMessage());
          exit();
        }

        $subscription_id = $stripe_event_data->data->object->subscription;

        // First, get the (original) invoice_id and end_time using subscription id or choke.
        $original_invoice = getOrigInvoice($subscription_id);

        // Fetch the original contribution and find it's status in case it's pending. 
        $orig_contrib = civicrm_api3('Contribution', 'get', array(
            'sequential' => 1,
            'return' => "id,trxn_id,contribution_status_id",
            'invoice_id' => $original_invoice->id,
	    'contribution_test' => $test_mode,
       ));

        if (!empty($orig_contrib)) {
          $orig_contrib_query->fetch();
        }
        else {
          CRM_Core_Error::Fatal("ERROR: Stripe triggered a Webhook on an invoice not found in civicrm_contribution: " . $stripe_event_data);
          exit();
        }


          // Failed charge.  Set to status to: Failed.
          if ($orig_contrib->contribution_status_id != 4) {
            
           $result = civicrm_api3('Contribution', 'repeattransaction', array(
            'sequential' => 1,
            'original_contribution_id' => $orig_contrib_query->id,
            'contribution_status_id' => "Failed",
            'trxn_id' => $charge_id,
         ));
         // Add invoice_id manually.          
          $query_params = array(
	    1 => array($new_invoice_id, 'String'),
	    2 => array($charge_id, 'String'),
          );
          CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution
              SET invoice_id = %1 WHERE trxn_id = %2", $query_params);
            return;
          }
          else {
            // This has failed more than once.  Now what?
          }

        break;


      //Subscription is cancelled
      case 'customer.subscription.deleted':
        $subscription_id = $stripe_event_data->data->object->id;

        // Find the recurring contribution in CiviCRM by mapping it from Stripe.
        $original_invoice = getOrigInvoice($subscription_id);

        // Fetch Civi's info about this recurring contribution
        $recur_contribution = civicrm_api3('ContributionRecur', 'get', array(
          'sequential' => 1,
          'return' => "id",
          'invoice_id' => $original_invoice->id
        ));

        if (!$recur_contribution['id']) {
          CRM_Core_Error::Fatal("ERROR: Stripe triggered a Webhook on an invoice not found in civicrm_contribution_recur: "
              . $stripe_event_data);
          exit();
        }

        //Cancel the recurring contribution
        $result = civicrm_api3('ContributionRecur', 'cancel', array(
            'sequential' => 1,
            'id' => $recur_contribution['id']
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
        return;
        break;


    }
    parent::run();
  }

}

