<?php
/*
 * @file
 * Handle Stripe Webhooks for recurring payments.
 */

require_once 'CRM/Core/Page.php';

class CRM_Stripe_Page_Webhook extends CRM_Core_Page {
  function run() {
    // Get the data from Stripe.
    $data_raw = file_get_contents("php://input");
    $data = json_decode($data_raw);
    if (!$data) {
      CRM_Core_Error::Fatal("Stripe Callback: cannot json_decode data, exiting. <br /> $data");
    }

    $test_mode = ! $data->livemode;

    $stripe_key = CRM_Core_DAO::singleValueQuery("SELECT pp.user_name FROM civicrm_payment_processor pp INNER JOIN civicrm_payment_processor_type ppt on pp.payment_processor_type_id = ppt.id AND ppt.name  = 'Stripe' WHERE is_test = '$test_mode'");

    require_once ("packages/stripe-php/lib/Stripe.php");
    Stripe::setApiKey($stripe_key);

    // Retrieve Event from Stripe using ID even though we already have the values now.
    // This is for extra security precautions mentioned here: https://stripe.com/docs/webhooks
    $stripe_event_data = Stripe_Event::retrieve($data->id);
    $customer_id = $stripe_event_data->data->object->customer;
    $new_invoice_id = $stripe_event_data->data->object->id;
    $trxn_id = $stripe_event_data->data->object->charge;

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
        // Find the original contribution. 
        // Before multiple subscriptions per customer were allowed, we could use customer_id or invoice_id in 
        // civicrm_strip_subscriptions interchangably to manipulate our subcription info.  Not so anymore if 
        // we wish to support multiple subs per customer.  Now we're paying attention to subscription_id.  
        // With that informaion we can find our arguments to pass to contribution.repeattransaction.   
        
        $subscription_id = $stripe_event_data->data->object->subscription;
        // Find end time using subscription id.  
        $query_params = array(
          1 => array($subscription_id, 'String'),
        );
        $rel_info_query = CRM_Core_DAO::executeQuery("SELECT invoice_id, end_time
          FROM civicrm_stripe_subscriptions
          WHERE subscription_id = %1",
          $query_params);

        if (!empty($rel_info_query)) {
          $rel_info_query->fetch();
          $end_time = $rel_info_query->end_time;
          $original_invoice_id = $rel_info_query->invoice_id;
        }
        else {
          CRM_Core_Error::Fatal("Error relating this subscription id ($subscription_id) to the one in civicrm_stripe_subscriptions. Customer id was ($customer_id) ");
          exit();
        }

        // Compare against now + 24hrs to prevent charging 1 extra day.
        $time_compare = time() + 86400;

        // Fetch the original contribution and find it's status in case it's pending. 
        
        $query_params = array(
          1 => array($original_invoice_id, 'String'),
        );
        $orig_contrib_query = CRM_Core_DAO::executeQuery("SELECT id, contribution_status_id
          FROM civicrm_contribution
          WHERE invoice_id = %1",
          $query_params);

        if (!empty($orig_contrib_query)) {
          $orig_contrib_query->fetch();
        }
        else {
          CRM_Core_Error::Fatal("ERROR: Stripe triggered a Webhook on an invoice not found in civicrm_contribution: " . $stripe_event_data);
          exit();
        }
        
        // Update a pending charge.

        if ($orig_contrib_query->contribution_status_id == '2' ) {
          $query_params = array(
            1 => array($orig_contirb_query->id, 'Integer'),
          );
          CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution
            SET contribution_status_id = '1'
            WHERE id = %1",
            $query_params);

          return;
        }

        // api contribution.repeattransaction is awesome.  It does possibly everthing we need, including updating the membershgip record. 
        //  Also, adds a record to the contribution_recur table.   Still insterting invoice_id manually.  :(
         
        $result = civicrm_api3('Contribution', 'repeattransaction', array(
            'original_contribution_id' => $orig_contrib_query->id,
            'contribution_status_id' => "Completed",
            'trxn_id' => $trxn_id,
            'is_email_receipt' => 1,
         ));  
 
        // Update invoice_id manually.  
        $query_params = array(
          1 => array($new_invoice_id, 'String'),
          2 => array($trxn_id, 'String'),
        );
        CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution
          SET invoice_id = %1 
          WHERE trxn_id = %2", 
          $query_params);

          if (!empty($end_time) && $time_compare > $end_time) {
            $end_date = date("Y-m-d H:i:s", $end_time);
            // Final payment.  Recurring contribution complete.
            $stripe_customer->cancelSubscription();

            $query_params = array(
              1 => array($subscription_id, 'String'),
            );
            CRM_Core_DAO::executeQuery("DELETE FROM civicrm_stripe_subscriptions
              WHERE subscription_id = %1", $query_params);
         //  Notate the cancel date now that the subscription is up.  
            $query_params = array(
              1 => array($end_date, 'String'),
              2 => array($original_invoice_id, 'String'),
            );
            CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution_recur 
              SET cancel_date = %1, contribution_status_id = '1'
              WHERE invoice_id = %2", $query_params);

            return;
          }

          // Successful charge & more to come so set recurring contribution status to In Progress.
          $query_params = array(
            1 => array($original_invoice_id, 'String'),
          );
          if ($orig_contrib_query->contribution_status_id != 5) {
            CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution_recur
              SET contribution_status_id = 5
              WHERE invoice_id = %1", $query_params);

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

       // Fetch the original contribution and find it's status.
        $query_params = array(
          1 => array($original_invoice_id, 'String'),
        );
        $orig_contrib_query = CRM_Core_DAO::executeQuery("SELECT id, contribution_status_id
          FROM civicrm_contribution
          WHERE invoice_id = %1",
          $query_params);

        if (!empty($orig_contrib_query)) {
          $orig_contrib_query->fetch();
        }
        else {
          CRM_Core_Error::Fatal("ERROR: Stripe triggered a Webhook on an invoice not found in civicrm_contribution: " . $stripe_event_data);
          exit();
        }


          // Failed charge.  Set to status to: Failed.
          if ($orig_contrib_query->contribution_status_id != 4) {
            
           $result = civicrm_api3('Contribution', 'repeattransaction', array(
            'sequential' => 1,
            'original_contribution_id' => $orig_contrib_query->id,
            'contribution_status_id' => "Failed",
            'trxn_id' => $trxn_id,
         ));
         // Add invoice_id manually.          
          $query_params = array(
	    1 => array($new_invoice_id, 'String'),
	    2 => array($trxn_id, 'String'),
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
        // Find the recurring contribution in CiviCRM by mapping it from stripe_subscriptions.
        $query_params = array(
            1 => array($subscription_id, 'String'),
        );
        $rel_info_query = CRM_Core_DAO::executeQuery("SELECT invoice_id
          FROM civicrm_stripe_subscriptions
          WHERE subscription_id = %1",
            $query_params);

        if (!empty($rel_info_query)) {
          $rel_info_query->fetch();

          if (!empty($rel_info_query->invoice_id)) {
            $original_invoice_id = $rel_info_query->invoice_id;
          } else {
            CRM_Core_Error::Fatal("Error relating this subscription ($subscription_id) to the one in civicrm_stripe_subscriptions");
            exit();
          }
        }

        // Fetch Civi's info about this recurring contribution
        $recurring_contribution = civicrm_api3('ContributionRecur', 'get', array(
          'sequential' => 1,
          'return' => "id",
          'invoice_id' => $original_invoice_id
        ));

        if (!$recurring_contribution['id']) {
          CRM_Core_Error::Fatal("ERROR: Stripe triggered a Webhook on an invoice not found in civicrm_contribution_recur: "
              . $stripe_event_data);
          exit();
        }

        //Cancel the recurring contribution
        $result = civicrm_api3('ContributionRecur', 'cancel', array(
            'sequential' => 1,
            'id' => $recurring_contribution['id']
        ));

        //Delete the record from Stripe's subscriptions table
        $query_params = array(
            1 => array($subscription_id, 'String'),
        );
        CRM_Core_DAO::executeQuery("DELETE FROM civicrm_stripe_subscriptions
              WHERE subscription_id = %1", $query_params);

        break;


      //  Update subscription id in civicrm_stripe_subscriptions
      case 'customer.subscription.created':
      $subscription_id = $stripe_event_data->data->object->id;

        // Update any customer subscription that has a placeholder (invoice_id).   
        $query_params = array(
          1 => array($customer_id, 'String'), 
          2 => array($subscription_id, 'String'),
        );
        CRM_Core_DAO::executeQuery("UPDATE civicrm_stripe_subscriptions
           SET subscription_id = %2
           WHERE subscription_id = invoice_id AND customer_id = %1",
           $query_params);
      return;
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
