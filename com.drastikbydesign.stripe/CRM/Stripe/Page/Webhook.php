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

    $stripe_key = CRM_Core_DAO::singleValueQuery("SELECT user_name FROM civicrm_payment_processor WHERE payment_processor_type = 'Stripe' AND is_test = '$test_mode'");
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
          exit();
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
          $invoice_id = $rel_info_query->invoice_id;
          $end_time = $rel_info_query->end_time;
        }
        else {
          CRM_Core_Error::Fatal("Error relating this customer ($customer_id) to the one in civicrm_stripe_subscriptions");
          exit();
        }

        // Compare against now + 24hrs to prevent charging 1 extra day.
        $time_compare = time() + 86400;

        // As of 4.3, contribution_type_id column renamed to financial_type_id.
        $financial_field = 'contribution_type_id';
        $civi_version = CRM_Utils_System::version();
        if ($civi_version >= 4.3) {
          $financial_field = 'financial_type_id';
        }
        // Fetch Civi's info about this recurring object.
        $query_params = array(
          1 => array($invoice_id, 'String'),
        );
        $recur_contrib_query = CRM_Core_DAO::executeQuery("SELECT id, contact_id, currency, contribution_status_id, is_test, {$financial_field}, payment_instrument_id, campaign_id
          FROM civicrm_contribution_recur
          WHERE invoice_id = %1",
          $query_params);

        if (!empty($recur_contrib_query)) {
          $recur_contrib_query->fetch();
        }
        else {
          CRM_Core_Error::Fatal("ERROR: Stripe triggered a Webhook on an invoice not found in civicrm_contribution_recur: " . $stripe_event_data);
          exit();
        }
        // Build some params.
        $stripe_customer = Stripe_Customer::retrieve($customer_id);
        $recieve_date = date("Y-m-d H:i:s", $charge->created);
        $total_amount = $charge->amount / 100;
        $fee_amount = $charge->fee / 100;
        $net_amount = $total_amount - $fee_amount;
        $transaction_id = $charge->id;
        $new_invoice_id = $stripe_event_data->data->object->id;
        if (empty($recur_contrib_query->campaign_id)) {
          $recur_contrib_query->campaign_id = 'NULL';
        }

        $query_params = array(
          1 => array($invoice_id, 'String'),
        );
        $first_contrib_check = CRM_Core_DAO::singleValueQuery("SELECT id
          FROM civicrm_contribution
          WHERE invoice_id = %1
          AND contribution_status_id = '2'", $query_params);

        if (!empty($first_contrib_check)) {
          $query_params = array(
            1 => array($first_contrib_check, 'Integer'),
          );
          CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution
            SET contribution_status_id = '1'
            WHERE id = %1",
            $query_params);

          return;
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
          9 => array($new_invoice_id, 'String'),
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
          %11, %12, '1', %13)",
          $query_params);

          if ($time_compare > $end_time) {
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

            return;
          }

          // Successful charge & more to come so set recurring contribution status to In Progress.
          $query_params = array(
            1 => array($invoice_id, 'String'),
          );
          if ($recur_contrib_query->contribution_status_id != 5) {
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

        // Find the recurring contribution in CiviCRM by mapping it from Stripe.
        $query_params = array(
          1 => array($customer_id, 'String'),
        );
        $invoice_id = CRM_Core_DAO::singleValueQuery("SELECT invoice_id
          FROM civicrm_stripe_subscriptions
          WHERE customer_id = %1", $query_params);
        if (empty($invoice_id)) {
          CRM_Core_Error::Fatal("Error relating this customer ({$customer_id}) to the one in civicrm_stripe_subscriptions");
          exit();
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
          exit();
        }
        // Build some params.
        $recieve_date = date("Y-m-d H:i:s", $charge->created);
        $total_amount = $charge->amount / 100;
        $fee_amount = $charge->fee / 100;
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

            return;
          }
          else {
            // This has failed more than once.  Now what?
          }

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
