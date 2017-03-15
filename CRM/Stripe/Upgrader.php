<?php
require_once('packages/stripe-php/init.php');
/**
 * Collection of upgrade steps.
 * DO NOT USE a naming scheme other than upgrade_N, where N is an integer.  
 * Naming scheme upgrade_X_Y_Z is offically wrong!  
 * https://chat.civicrm.org/civicrm/pl/usx3pfjzjbrhzpewuggu1e6ftw
 */
class CRM_Stripe_Upgrader extends CRM_Stripe_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Standard: run an install sql script
   */
  public function install() {
  }

  /**
   * Standard: run an uninstall script
   */
  public function uninstall() {
  }

  /**
   * Add is_live column to civicrm_stripe_plans and civicrm_stripe_customers tables.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_1_9_003() {
    $config = CRM_Core_Config::singleton();
    $dbName = DB::connect($config->dsn)->_db;

    // Add is_live column to civicrm_stripe_plans and civicrm_stripe_customers tables.
    $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %1 AND TABLE_NAME = 'civicrm_stripe_customers' AND COLUMN_NAME = 'is_live'";
    $dao = CRM_Core_DAO::executeQuery($sql, array(1 => array($dbName, 'String')));
    $live_column_exists = $dao->N == 0 ? FALSE : TRUE;
    if (!$live_column_exists) {
      $this->ctx->log->info('Applying civicrm_stripe update 1903.  Adding is_live to civicrm_stripe_plans and civicrm_stripe_customers tables.');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_customers ADD COLUMN `is_live` tinyint(4) NOT NULL COMMENT "Whether this is a live or test transaction"');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_plans ADD COLUMN `is_live` tinyint(4) NOT NULL COMMENT "Whether this is a live or test transaction"');
    }
    else {
      $this->ctx->log->info('Skipped civicrm_stripe update 1903.  Column is_live already present on civicrm_stripe_plans table.');
    }
    return TRUE;
  }

  /**
   * Add processor_id column to civicrm_stripe_customers table.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_5001() {
    $config = CRM_Core_Config::singleton();
    $dbName = DB::connect($config->dsn)->_db;

    $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %1 AND TABLE_NAME = 'civicrm_stripe_customers' AND COLUMN_NAME = 'processor_id'";
    $dao = CRM_Core_DAO::executeQuery($sql, array(1 => array($dbName, 'String')));
    if ($dao->N) {
      $this->ctx->log->info('Skipped civicrm_stripe update 5001.  Column processor_id already present on our customers, plans and subscriptions tables.');
    }
    else {
      $this->ctx->log->info('Applying civicrm_stripe update 5001.  Adding processor_id to the civicrm_stripe_customers, civicrm_stripe_plans and civicrm_stripe_subscriptions tables.');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_customers ADD COLUMN `processor_id` int(10) DEFAULT NULL COMMENT "ID from civicrm_payment_processor"');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_plans ADD COLUMN `processor_id` int(10) DEFAULT NULL COMMENT "ID from civicrm_payment_processor"');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_subscriptions ADD COLUMN `processor_id` int(10) DEFAULT NULL COMMENT "ID from civicrm_payment_processor"');
    }
     return TRUE;
  }


  /**
   * Populate processor_id column in civicrm_stripe_customers, civicrm_stripe_plans and civicrm_stripe_subscriptions tables.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_5002() {
    $config = CRM_Core_Config::singleton();
    $dbName = DB::connect($config->dsn)->_db;

    $null_count =  CRM_Core_DAO::executeQuery('SELECT COUNT(*) FROM civicrm_stripe_customers where processor_id IS NULL') + 
      CRM_Core_DAO::executeQuery('SELECT COUNT(*) FROM civicrm_stripe_plans where processor_id IS NULL') +
      CRM_Core_DAO::executeQuery('SELECT COUNT(*) FROM civicrm_stripe_subscriptions where processor_id IS NULL');
    if ( $null_count == 0 ) {
      $this->ctx->log->info('Skipped civicrm_stripe update 5002.  No nulls found in column processor_id in our tables.');
      return TRUE;
    } 
    else { 
      try {
        // Set processor ID if there's only one.
        $processorCount = civicrm_api3('PaymentProcessorType', 'get', array(
          'name' => "Stripe",
          'api.PaymentProcessor.get' => array('is_test' => 0),
        ));
        foreach ($processorCount['values'] as $processorType) {
          if (!empty($processorType['api.PaymentProcessor.get']['id'])) {
            $stripe_live =$processorType['api.PaymentProcessor.get']['id'];
            $stripe_test = $stripe_live + 1;
            $p = array(
              1 => array($stripe_live, 'Integer'),
              2 => array($stripe_test, 'Integer'),
            );
            CRM_Core_DAO::executeQuery('UPDATE `civicrm_stripe_customers` SET processor_id = %1 where processor_id IS NULL and is_live = 1', $p);
            CRM_Core_DAO::executeQuery('UPDATE `civicrm_stripe_customers` SET processor_id = %2 where processor_id IS NULL and is_live = 0', $p);
            CRM_Core_DAO::executeQuery('UPDATE `civicrm_stripe_plans` SET processor_id = %1 where processor_id IS NULL and is_live = 1', $p);
            CRM_Core_DAO::executeQuery('UPDATE `civicrm_stripe_plans` SET processor_id = %2 where processor_id IS NULL and is_live = 0', $p);
            CRM_Core_DAO::executeQuery('UPDATE `civicrm_stripe_subscriptions` SET processor_id = %1 where processor_id IS NULL and is_live = 1', $p);
            CRM_Core_DAO::executeQuery('UPDATE `civicrm_stripe_subscriptions` SET processor_id = %2 where processor_id IS NULL and is_live = 0', $p);
          }
        }
      }
      catch (CiviCRM_API3_Exception $e) {
        CRM_Core_Error::debug_log_message("Cannot find a PaymentProcessorType named Stripe.", $out = false);
        return;
      }
    }
    return TRUE;
  }

 
 /**
   * Add subscription_id column to civicrm_stripe_subscriptions table.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_5003() {
    $config = CRM_Core_Config::singleton();
    $dbName = DB::connect($config->dsn)->_db;

    $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %1 AND TABLE_NAME = 'civicrm_stripe_subscriptions' AND COLUMN_NAME = 'subscription_id'";
    $dao = CRM_Core_DAO::executeQuery($sql, array(1 => array($dbName, 'String')));

    if ($dao->N) {
      $this->ctx->log->info('Skipped civicrm_stripe update 5003.  Column  subscription_id already present in civicrm_stripe_subscriptions table.');
    }
    else {
      $this->ctx->log->info('Applying civicrm_stripe update 5003.  Adding subscription_id to civicrm_stripe_subscriptions.');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_subscriptions ADD COLUMN `subscription_id` varchar(255) DEFAULT NULL COMMENT "Subscription ID from Stripe" FIRST');
      CRM_Core_DAO::executeQuery('ALTER TABLE `civicrm_stripe_subscriptions` ADD UNIQUE KEY(`subscription_id`)');

        }
      return TRUE;
    }
   
 /**
   * Populates the subscription_id column in table civicrm_stripe_subscriptions.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_5004() {
    $config = CRM_Core_Config::singleton();
    $dbName = DB::connect($config->dsn)->_db;

    $null_count =  CRM_Core_DAO::executeQuery('SELECT COUNT(*) FROM civicrm_stripe_subscriptions where subscription_id IS NULL'); 
    if ( $null_count == 0 ) {
      $this->ctx->log->info('Skipped civicrm_stripe update 5004.  No nulls found in column subscription_id in our civicrm_stripe_subscriptions table.');
    } 
    else { 
    $customer_infos = CRM_Core_DAO::executeQuery("SELECT customer_id,processor_id
      FROM `civicrm_stripe_subscriptions`;");
      while ( $customer_infos->fetch() ) {
        $processor_id = $customer_infos->processor_id;
        $customer_id = $customer_infos->customer_id;
          try {
            $stripe_key = civicrm_api3('PaymentProcessor', 'getvalue', array(
             'return' => 'user_name',
             'id' => $processor_id,
             ));
          }
          catch (Exception $e) { 
            CRM_Core_Error::debug_log_message('Update 5004 failed. Has Stripe been removed as a payment processor?', $out = false);
            return;
          }
          try {
            \Stripe\Stripe::setApiKey($stripe_key);
            $subscription = \Stripe\Subscription::all(array(
             'customer'=> $customer_id,
             'limit'=>1,
            ));
          } 
          catch (Exception $e) {
            // Don't quit here.  A missing customer in Stipe is OK.  They don't exist, so they can't have a subscription.
            $debug_code = 'Cannot find Stripe API key: ' . $e->getMessage();
            CRM_Core_Error::debug_log_message($debug_code, $out = false);
          }
          if (!empty($subscription['data'][0]['id'])) {
            $query_params = array(
              1 => array($subscription['data'][0]['id'], 'String'),
              2 => array($customer_id, 'String'),
            );
            CRM_Core_DAO::executeQuery('UPDATE civicrm_stripe_subscriptions SET subscription_id = %1 where customer_id = %2;', $query_params);
            unset($subscription);
          }
      }
    }
       return TRUE;
  }

 /**
   * Add contribution_recur_id column to civicrm_stripe_subscriptions table.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_5005() {
    $config = CRM_Core_Config::singleton();
    $dbName = DB::connect($config->dsn)->_db;

    $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %1 AND TABLE_NAME = 'civicrm_stripe_subscriptions' AND COLUMN_NAME = 'contribution_recur_id'";
    $dao = CRM_Core_DAO::executeQuery($sql, array(1 => array($dbName, 'String')));

    if ($dao->N) {
      $this->ctx->log->info('Skipped civicrm_stripe update 5005.  Column contribution_recur_id already present in civicrm_stripe_subscriptions table.');
    }
    else {
      $this->ctx->log->info('Applying civicrm_stripe update 5005.  Adding contribution_recur_id to civicrm_stripe_subscriptions table.');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_subscriptions 
       ADD COLUMN `contribution_recur_id` int(10) UNSIGNED DEFAULT NULL 
       COMMENT "FK ID from civicrm_contribution_recur" AFTER `customer_id`');
      CRM_Core_DAO::executeQuery('ALTER TABLE `civicrm_stripe_subscriptions` ADD INDEX(`contribution_recur_id`);');
      CRM_Core_DAO::executeQuery('ALTER TABLE `civicrm_stripe_subscriptions` ADD CONSTRAINT `FK_civicrm_stripe_contribution_recur_id` FOREIGN KEY (`contribution_recur_id`) REFERENCES `civicrm_contribution_recur`(`id`) ON DELETE SET NULL ON UPDATE RESTRICT;');
    }
      return TRUE;
  }

 /**
   *  Method 1 for populating the contribution_recur_id column in the civicrm_stripe_subscriptions table.
   *  ( A simple approach if that works if there have never been any susbcription edits in the Stripe UI. )

   * @return TRUE on success
   * @throws Exception
   */

  public function upgrade_5006() {
    $config = CRM_Core_Config::singleton();
    $dbName = DB::connect($config->dsn)->_db;

    $subscriptions  = CRM_Core_DAO::executeQuery("SELECT invoice_id,is_live
      FROM `civicrm_stripe_subscriptions`;");
      while ( $subscriptions->fetch() ) {
        $test_mode = (int)!$subscriptions->is_live;
        try {
          // Fetch the recurring contribution Id. 
          $recur_id = civicrm_api3('Contribution', 'getvalue', array(
            'sequential' => 1,
            'return' => "contribution_recur_id",
            'invoice_id' => $subscriptions->invoice_id,
            'contribution_test' => $test_mode,
          ));
        } 
        catch (CiviCRM_API3_Exception $e) {
          // Don't quit here. If we can't find the recurring ID for a single customer, make a note in the error log and carry on.
          $debug_code = 'Recurring contribution search: ' . $e->getMessage();
          CRM_Core_Error::debug_log_message($debug_code, $out = false);
        }
        if (!empty($recur_id)) {
          $p = array(
            1 => array($recur_id, 'Integer'),
            2 => array($subscriptions->invoice_id, 'String'),
          );
          CRM_Core_DAO::executeQuery('UPDATE civicrm_stripe_subscriptions SET contribution_recur_id = %1 WHERE invoice_id = %2;', $p);
        }
      }
        return TRUE;
  }


 /**
   *  Method 2 for populating the contribution_recur_id column in the  civicrm_stripe_subscriptions table. Uncomment this and comment 5006.
   *  ( A more convoluted approach that works if there HAVE been susbcription edits in the Stripe UI. )
   * @return TRUE on success.  Please let users uncomment this as needed and increment past 5007 for the next upgrade.
   * @throws Exception
   */
/*
  public function upgrade_5007() {
    $config = CRM_Core_Config::singleton();
    $dbName = DB::connect($config->dsn)->_db;

    $subscriptions  = CRM_Core_DAO::executeQuery("SELECT customer_id,is_live,processor_id
      FROM `civicrm_stripe_subscriptions`;");
      while ( $subscriptions->fetch() ) {
        $test_mode = (int)!$subscriptions->is_live;
        $p = array(
          1 => array($subscriptions->customer_id, 'String'),
          2 => array($subscriptions->is_live, 'Integer'),
        );
        $customer = CRM_Core_DAO::executeQuery("SELECT email
          FROM `civicrm_stripe_customers` WHERE id = %1 AND is_live = %2;", $p);
        $customer->fetch();
        //  Try the billing email first, since that's what we send to Stripe.
        try {
          $contact = civicrm_api3('Email', 'get', array(
           'sequential' => 1,
           'return' => "contact_id",
           'is_billing' => 1,
           'email' => $customer->email, 
           'api.ContributionRecur.get' => array('return' => "id", 'contact_id' => "\$value.contact_id", 'contribution_status_id' => "In Progress"),
          ));
         } 
        catch (CiviCRM_API3_Exception $e) { 
        // Uh oh, that didn't work.  Try to retrieve the recurring id using the primary email.
          $contact = civicrm_api3('Contact', 'get', array(
           'sequential' => 1,
           'return' => "id",
           'email' => $customer->email,
           'api.ContributionRecur.get' => array('sequential' => 1, 'return' => "id", 'contact_id' => "\$values.id", 'contribution_status_id' => "In Progress"),
           ));
        }

        if (!empty($contact['values'][0]['api.ContributionRecur.get']['values'][0]['id'])) {
         $recur_id = $contact['values'][0]['api.ContributionRecur.get']['values'][0]['id']; 
             $p = array(
              1 => array($recur_id, 'Integer'),
              2 => array($subscriptions->customer_id, 'String'),
            );
            CRM_Core_DAO::executeQuery('UPDATE civicrm_stripe_subscriptions SET contribution_recur_id = %1 WHERE customer_id = %2;', $p);
         } else {
            // Crap.
            $this->ctx->log->info('Update 5007 failed.  Consider adding recurring IDs manuallly to civicrm_stripe_subscriptions. ');
            return;
        }
      }
       return TRUE;
  }
*/

 /**
   * Add change default NOT NULL to NULL in vestigial invoice_id column in civicrm_stripe_subscriptions table if needed. (issue #192)
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_5008() {
    $config = CRM_Core_Config::singleton();
    $dbName = DB::connect($config->dsn)->_db;

    $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %1 AND TABLE_NAME = 'civicrm_stripe_subscriptions' AND COLUMN_NAME = 'invoice_id'";
    $dao = CRM_Core_DAO::executeQuery($sql, array(1 => array($dbName, 'String')));

    if (!$dao->N) {
      $this->ctx->log->info('Skipped civicrm_stripe update 5008. Column not present.');
    }
    else {
      $this->ctx->log->info('Applying civicrm_stripe update 5008. Altering invoice_id to be default NULL.');
      CRM_Core_DAO::executeQuery('ALTER TABLE `civicrm_stripe_subscriptions`
        MODIFY COLUMN `invoice_id` varchar(255) NULL default ""
        COMMENT "Safe to remove this column if the update retrieving subscription IDs completed satisfactorily."');
    }
      return TRUE;
  }

  /**
   * Add remove unique from email and add to customer in civicrm_stripe_customers tables. (issue #191)
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_5009() {
    $config = CRM_Core_Config::singleton();
    $dbName = DB::connect($config->dsn)->_db;

    $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = %1
      AND TABLE_NAME = 'civicrm_stripe_customers'
      AND COLUMN_NAME = 'id'
      AND COLUMN_KEY = 'UNI'";
    $dao = CRM_Core_DAO::executeQuery($sql, array(1 => array($dbName, 'String')));
    if ($dao->N) {
      $this->ctx->log->info('id is already unique in civicrm_stripe_customers table, no need for civicrm_stripe update 5009.');
    }
    else {
      $this->ctx->log->info('Applying civicrm_stripe update 5009.  Setting unique key from email to id on civicrm_stripe_plans table.');
      CRM_Core_DAO::executeQuery('ALTER TABLE `civicrm_stripe_customers` DROP INDEX email');
      CRM_Core_DAO::executeQuery('ALTER TABLE `civicrm_stripe_customers` ADD UNIQUE (id)');
    }
    return TRUE;
  }
}
