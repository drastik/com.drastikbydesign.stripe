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

    if ($data->livemode) {
      $test_mode = 0;
    } else {
      $test_mode = 1;
    }

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
      CRM_Core_Error::fatal('Cannot find Stripe API key: ' . $e->getMessage());
    }

    require_once ("packages/stripe-php/lib/Stripe.php");
    Stripe::setApiKey($stripe_key);

    // Retrieve Event from Stripe using ID even though we already have the values now.
    // This is for extra security precautions mentioned here: https://stripe.com/docs/webhooks
    $stripe_event_data = Stripe_Event::retrieve($data->id);
    $customer_id = $stripe_event_data->data->object->customer;
    switch($stripe_event_data->type) {
      // Successful recurring payment.
      case 'invoice.payment_succeeded':
        // Get the Stripe charge object.
        try {
          $charge = Stripe_Charge::retrieve($stripe_event_data->data->object->charge);
        }
        catch(Exception $e) {
          CRM_Core_Error::Fatal("Failed to retrieve Stripe charge.  Message: " . $e->getMessage());
        }

        // Find the recurring contribution in CiviCRM by mapping it from Stripe.
        $query_params = array(
          1 => array($customer_id, 'String'),
        );
        $rel_info_query = CRM_Core_DAO::executeQuery("SELECT invoice_id, end_time
          FROM civicrm_stripe_subscriptions
          WHERE customer_id = %1",
          $query_params);

        if (!empty($rel_info_query)) {
          $rel_info_query->fetch();

          if(!empty($rel_info_query->invoice_id)) {
            $invoice_id = $rel_info_query->invoice_id;
            $end_time = $rel_info_query->end_time;
          } else {
            CRM_Core_Error::Fatal("Error relating this customer ($customer_id) to the one in civicrm_stripe_subscriptions");
          }
        }

        // Compare against now + 24hrs to prevent charging 1 extra day.
        $time_compare = time() + 86400;

        // Fetch Civi's info about this recurring contribution
        $recurring_contribution = civicrm_api3('ContributionRecur', 'get', array(
            'sequential' => 1,
            'return' => array("id", "contribution_status_id"),
            'invoice_id' => $invoice_id
        ));

        if(!$recurring_contribution['id']) {
          CRM_Core_Error::Fatal("ERROR: Stripe triggered a Webhook on an invoice not found in civicrm_contribution_recur: " . $stripe_event_data);
        }

        // Build some params.
        $stripe_customer = Stripe_Customer::retrieve($customer_id);
        $transaction_id = $charge->id;

        //get the balance_transaction object and retrieve the Stripe fee from it
        $balance_transaction_id = $charge->balance_transaction;
        $balance_transaction = Stripe_BalanceTransaction::retrieve($balance_transaction_id);
        $fee = $balance_transaction->fee / 100;

        //Currently (Oct 2015) contribution.repeattransaction does not
        //insert an invoice_id in the civicrm_contribution table
        //$new_invoice_id = $stripe_event_data->data->object->id;

        //Check whether there is a contribution instance with this invoice_id that is Pending
        $pending_contrib_check = civicrm_api3('Contribution', 'get', array(
            'sequential' => 1,
            'return' => "id",
            'invoice_id' => $invoice_id,
            'contribution_status_id' => "Pending",
            'contribution_test' => $test_mode
        ));

        //If there is, complete it, set its trxn_id and fee and then return
        if (!empty($pending_contrib_check['id'])) {
          $result = civicrm_api3('Contribution', 'completetransaction', array(
              'sequential' => 1,
              'id' => $pending_contrib_check['id'],
              'trxn_id' => $transaction_id,
              'fee_amount' => $fee
          ));

          CRM_Utils_System::civiExit();
        }

        //Get the original contribution with this invoice_id
        $original_contribution = civicrm_api3('Contribution', 'get', array(
            'sequential' => 1,
            'return' => "id",
            'invoice_id' => $invoice_id,
            'contribution_test' => $test_mode
        ));

        //Create a copy record of the original contribution and send out email receipt
        $result = civicrm_api3('Contribution', 'repeattransaction', array(
            'sequential' => 1,
            'original_contribution_id' => $original_contribution['id'],
            'contribution_status_id' => "Completed",
            'trxn_id' => $transaction_id //Insert new transaction ID
            //'invoice_id' => $new_invoice_id - contribution.repeattransaction doesn't support it currently
        ));

          if (!empty($end_time) && $time_compare > $end_time) {
            $end_date = date("Y-m-d H:i:s", $end_time);
            // Final payment.  Recurring contribution complete.
            $stripe_customer->cancelSubscription();

            $query_params = array(
              1 => array($invoice_id, 'String'),
            );
            CRM_Core_DAO::executeQuery("DELETE FROM civicrm_stripe_subscriptions
              WHERE invoice_id = %1", $query_params);

            $query_params = array(
              1 => array($end_date, 'String'),
              2 => array($invoice_id, 'String'),
            );
            CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution_recur
              SET end_date = %1, contribution_status_id = '1'
              WHERE invoice_id = %2", $query_params);

            CRM_Utils_System::civiExit();
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

            CRM_Utils_System::civiExit();
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
        }

        // Find the recurring contribution in CiviCRM by mapping it from Stripe.
        $query_params = array(
          1 => array($customer_id, 'String'),
        );
        $invoice_id = CRM_Core_DAO::singleValueQuery("SELECT invoice_id
          FROM civicrm_stripe_subscriptions
          WHERE customer_id = %1", $query_params);
        if (empty($invoice_id)) {
          CRM_Core_Error::Fatal("Error relating this customer ({$customer_id}) to the one in civicrm_stripe_subscriptions");
        }

        // Fetch Civi's info about this recurring object.
        $query_params = array(
          1 => array($invoice_id, 'String'),
        );
        $recur_contrib_query = CRM_Core_DAO::executeQuery("SELECT id, contact_id, currency, contribution_status_id, is_test, {$financial_field}, payment_instrument_id, campaign_id
          FROM civicrm_contribution_recur
          WHERE invoice_id = %1", $query_params);
        if (!empty($recur_contrib_query)) {
          $recur_contrib_query->fetch();
        }
        else {
          CRM_Core_Error::Fatal("ERROR: Stripe triggered a Webhook on an invoice not found in civicrm_contribution_recur: " . $stripe_event_data);
        }
        // Build some params.
        $recieve_date = date("Y-m-d H:i:s", $charge->created);
        $total_amount = $charge->amount / 100;
        $fee_amount = isset($charge->fee) ? ($charge->fee / 100) : 0;
        $net_amount = $total_amount - $fee_amount;
        $transaction_id = $charge->id;
        if (empty($recur_contrib_query->campaign_id)) {
          $recur_contrib_query->campaign_id = 'NULL';
        }

        // Create this instance of the contribution for accounting in CiviCRM.
        $query_params = array(
          1 => array($recur_contrib_query->contact_id, 'Integer'),
          2 => array($recur_contrib_query->{$financial_field}, 'Integer'),
          3 => array($recur_contrib_query->payment_instrument_id, 'Integer'),
          4 => array($recieve_date, 'String'),
          5 => array($total_amount, 'String'),
          6 => array($fee_amount, 'String'),
          7 => array($net_amount, 'String'),
          8 => array($transaction_id, 'String'),
          9 => array($invoice_id, 'String'),
          10 => array($recur_contrib_query->currency, 'String'),
          11 => array($recur_contrib_query->id, 'Integer'),
          12 => array($recur_contrib_query->is_test, 'Integer'),
          13 => array($recur_contrib_query->campaign_id, 'Integer'),
        );
        CRM_Core_DAO::executeQuery("INSERT INTO civicrm_contribution (
          contact_id, {$financial_field}, payment_instrument_id, receive_date,
          total_amount, fee_amount, net_amount, trxn_id, invoice_id, currency,
          contribution_recur_id, is_test, contribution_status_id, campaign_id
          ) VALUES (
          %1, %2, %3, %4,
          %5, %6, %7, %8, %9, %10,
          %11, %12, '4', %13)",
          $query_params);

          // Failed charge.  Set to status to: Failed.
          if ($recur_contrib_query->contribution_status_id != 4) {
            $query_params = array(
              1 => array($invoice_id, 'String'),
            );
            CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution_recur
              SET contribution_status_id = 4
              WHERE invoice_id = %1", $query_params);

            CRM_Utils_System::civiExit();
          }
          else {
            // This has failed more than once.  Now what?
          }

        break;

	  //Subscription is cancelled
      case 'customer.subscription.deleted':

        // Find the recurring contribution in CiviCRM by mapping it from Stripe.
        $query_params = array(
            1 => array($customer_id, 'String'),
        );
        $rel_info_query = CRM_Core_DAO::executeQuery("SELECT invoice_id
          FROM civicrm_stripe_subscriptions
          WHERE customer_id = %1",
            $query_params);

        if (!empty($rel_info_query)) {
          $rel_info_query->fetch();

          if (!empty($rel_info_query->invoice_id)) {
            $invoice_id = $rel_info_query->invoice_id;
          } else {
            CRM_Core_Error::Fatal("Error relating this customer ($customer_id) to the one in civicrm_stripe_subscriptions");
          }
        }

        // Fetch Civi's info about this recurring contribution
        $recur_contribution = civicrm_api3('ContributionRecur', 'get', array(
          'sequential' => 1,
          'return' => "id",
          'invoice_id' => $invoice_id
        ));

        if (!$recur_contribution['id']) {
          CRM_Core_Error::Fatal("ERROR: Stripe triggered a Webhook on an invoice not found in civicrm_contribution_recur: "
              . $stripe_event_data);
        }

        //Cancel the recurring contribution
        $result = civicrm_api3('ContributionRecur', 'cancel', array(
            'sequential' => 1,
            'id' => $recur_contribution['id']
        ));

        //Delete the record from Stripe's subscriptions table
        $query_params = array(
            1 => array($invoice_id, 'String'),
        );
        CRM_Core_DAO::executeQuery("DELETE FROM civicrm_stripe_subscriptions
              WHERE invoice_id = %1", $query_params);

        break;

      // One-time donation and per invoice payment.
      case 'charge.succeeded':
        // Not implemented.
        CRM_Utils_System::civiExit();
        break;

    }

    parent::run();
  }

}
