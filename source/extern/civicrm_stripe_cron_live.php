<?php 
/*
 * Cron function for CiviCRM 4.1 and below to cancel recurring contributions.
 */

session_start( );

require_once '../civicrm.config.php';
require_once 'CRM/Core/Config.php';

$config =& CRM_Core_Config::singleton();

$stripe_key = CRM_Core_DAO::singleValueQuery("SELECT user_name FROM civicrm_payment_processor WHERE payment_processor_type = 'Stripe' AND is_test = '0'");
require_once("packages/stripe-php/lib/Stripe.php");
Stripe::setApiKey($stripe_key);

$time = time();
$query = "
  SELECT  customer_id, invoice_id 
  FROM    civicrm_stripe_subscriptions 
  WHERE   end_time <= '$time' 
";

$end_date = date("Y-m-d H:i:s");
$end_recur_query = CRM_Core_DAO::executeQuery($query);

while($end_recur_query->fetch()) {
  $stripe_customer = Stripe_Customer::retrieve($end_recur_query->customer_id);
  if(isset($stripe_customer)) {
    $stripe_customer->cancelSubscription();
    CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution_recur SET end_date = '$end_date', contribution_status_id = '1' WHERE invoice_id = '$end_recur_query->invoice_id'");
    //Delete the Stripe Subscription from our cron watch list.
    CRM_Core_DAO::executeQuery("DELETE FROM civicrm_stripe_subscriptions WHERE invoice_id = '$end_recur_query->invoice_id'");
  }
}
