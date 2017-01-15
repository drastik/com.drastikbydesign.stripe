<?php
require_once('packages/stripe-php/init.php');
/**
 * Collection of upgrade steps.
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

    $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %1 AND TABLE_NAME = 'civicrm_stripe_customers' AND COLUMN_KEY = 'MUL'";
    $dao = CRM_Core_DAO::executeQuery($sql, array(1 => array($dbName, 'String')));
    $key_column_exists = $dao->N == 0 ? FALSE : TRUE;
    if ($key_column_exists) {
      $this->ctx->log->info('Applying civicrm_stripe update 1903.  Setting unique key from email to id on civicrm_stripe_plans table.');
      CRM_Core_DAO::executeQuery('ALTER TABLE `civicrm_stripe_customers` DROP INDEX email');
      CRM_Core_DAO::executeQuery('ALTER TABLE `civicrm_stripe_customers` ADD UNIQUE (id)');
    }
    else {
      $this->ctx->log->info('Skipped civicrm_stripe update 1903.  Unique key already changed from email to id on civicrm_stripe_plans table.');
    }
    return TRUE;
  }

  /**
   * Add processor_id column to civicrm_stripe_customers table.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_4_6_01() {
    $config = CRM_Core_Config::singleton();
    $dbName = DB::connect($config->dsn)->_db;

    $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %1 AND TABLE_NAME = 'civicrm_stripe_customers' AND COLUMN_NAME = 'processor_id'";
    $dao = CRM_Core_DAO::executeQuery($sql, array(1 => array($dbName, 'String')));

    if ($dao->N) {
      $this->ctx->log->info('Skipped civicrm_stripe update 4601.  Column processor_id already present on civicrm_stripe_customers and civicrm_stripe_plans table.');
    }
    else {
      $this->ctx->log->info('Applying civicrm_stripe update 4601.  Adding processor_id to civicrm_stripe_customers and civicrm_stripe_plans table.');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_customers ADD COLUMN `processor_id` int(10) DEFAULT NULL COMMENT "ID from civicrm_payment_processor"');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_plans ADD COLUMN `processor_id` int(10) DEFAULT NULL COMMENT "ID from civicrm_payment_processor"');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_subscriptions ADD COLUMN `processor_id` int(10) DEFAULT NULL COMMENT "ID from civicrm_payment_processor"');
      try {
        // Set processor ID if there's only one.
        $processorCount = civicrm_api3('PaymentProcessorType', 'get', array(
          'name' => "Stripe",
          'api.PaymentProcessor.getcount' => array('is_test' => 0),
        ));
        foreach ($processorCount['values'] as $processorType) {
          if (!empty($processorType['api.PaymentProcessor.get']['id'])) {
            $p = array(
              1 => array($processorType['api.PaymentProcessor.get']['id'], 'Integer'),
            );
            CRM_Core_DAO::executeQuery('UPDATE civicrm_stripe_customers SET processor_id = %1 where processor_id IS NULL', $p);
            CRM_Core_DAO::executeQuery('UPDATE civicrm_stripe_plans SET processor_id = %1 where processor_id IS NULL', $p);
            CRM_Core_DAO::executeQuery('UPDATE civicrm_stripe_subscriptions SET processor_id = %1 where processor_id IS NULL', $p);
          }
        }
      }
      catch (CiviCRM_API3_Exception $e) {
        return TRUE;
      }
    }
    return TRUE;
  }

 /**
   * Add subscription_id column to civicrm_stripe_subscriptions table and populate.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_4_6_02() {
    $config = CRM_Core_Config::singleton();
    $dbName = DB::connect($config->dsn)->_db;

    $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %1 AND TABLE_NAME = 'civicrm_stripe_subscriptions' AND COLUMN_NAME = 'subscription_id'";
    $dao = CRM_Core_DAO::executeQuery($sql, array(1 => array($dbName, 'String')));

    if ($dao->N) {
      $this->ctx->log->info('Skipped civicrm_stripe update 4602.  Column  subscription_id already present in civicrm_stripe_subscriptions table.');
    }
    else {
      $this->ctx->log->info('Applying civicrm_stripe update 4602.  Adding subscription_id to civicrm_stripe_subscriptions.');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_subscriptions ADD COLUMN `subscription_id` varchar(255) DEFAULT NULL COMMENT "Subscription ID from Stripe" FIRST');
      CRM_Core_DAO::executeQuery('ALTER TABLE `civicrm_stripe_subscriptions` ADD UNIQUE KEY(`subscription_id`)');
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
          // CRM_Core_Error::fatal('Cannot find Stripe API key: ' . $e->getMessage());
            return TRUE;
          }
          try {
            \Stripe\Stripe::setApiKey($stripe_key);
            $subscription = \Stripe\Subscription::all(array(
             'customer'=> $customer_id,
             'limit'=>1,
            ));
            if (!empty($subscription)) {
              $query_params = array(
                1 => array($subscription['data'][0]['id'], 'String'),
                2 => array($customer_id, 'String'),
               );
              CRM_Core_DAO::executeQuery('UPDATE civicrm_stripe_subscriptions SET subscription_id = %1 where customer_id = %2;', $query_params);
            }
           } catch (Exception $e) {
             return TRUE;
           }
        }
     }
    return TRUE;
    }


 /**
   * Add contribution_recur_id column to civicrm_stripe_subscriptions table and populate.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_4_6_03() {
    $config = CRM_Core_Config::singleton();
    $dbName = DB::connect($config->dsn)->_db;

    $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %1 AND TABLE_NAME = 'civicrm_stripe_subscriptions' AND COLUMN_NAME = 'contribution_recur_id'";
    $dao = CRM_Core_DAO::executeQuery($sql, array(1 => array($dbName, 'String')));

    if ($dao->N) {
      $this->ctx->log->info('Skipped civicrm_stripe update 4603.  Column contribution_recur_id already present in civicrm_stripe_subscriptions table.');
    }
    else {
      $this->ctx->log->info('Applying civicrm_stripe update 4603.  Adding contribution_recur_id to civicrm_stripe_subscriptions table.');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_subscriptions 
       ADD COLUMN `contribution_recur_id` int(10) UNSIGNED DEFAULT NULL 
       COMMENT "FK ID from civicrm_contribution_recur" AFTER `customer_id`');
      CRM_Core_DAO::executeQuery('ALTER TABLE `civicrm_stripe_subscriptions` ADD INDEX(`contribution_recur_id`);');
      CRM_Core_DAO::executeQuery('ALTER TABLE `civicrm_stripe_subscriptions` ADD CONSTRAINT `FK_civicrm_stripe_contribution_recur_id` FOREIGN KEY (`contribution_recur_id`) REFERENCES `civicrm_contribution_recur`(`id`) ON DELETE SET NULL ON UPDATE RESTRICT;');
      // Method 1: An approach to populate the recurring id column that works if 
      // there have never been any subscription changes. 
          
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
          if (!empty($recur_id)) {
             $p = array(
              1 => array($recur_id, 'Integer'),
              2 => array($subscriptions->invoice_id, 'String'),
            );
            CRM_Core_DAO::executeQuery('UPDATE civicrm_stripe_subscriptions SET contribution_recur_id = %1 WHERE invoice_id = %2;', $p);
          }
        } 
        catch (CiviCRM_API3_Exception $e) {
        return TRUE;
        }
       }
      // End Method 1. 
/*
      //  Method 2: for installs where the have been subscription edits. 

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
        }
      }
     // End Method 2
*/
      }
    return TRUE;
  }
}
