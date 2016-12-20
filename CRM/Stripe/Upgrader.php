<?php

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
    // Add is_live column to civicrm_stripe_plans and civicrm_stripe_customers tables.
    $live_column_check = mysql_query("SHOW COLUMNS FROM `civicrm_stripe_customers` LIKE 'is_live'");
    $live_column_exists = (mysql_num_rows($live_column_check)) ? TRUE : FALSE;
    if (!$live_column_exists) {
      $this->ctx->log->info('Applying civicrm_stripe update 1903.  Adding is_live to civicrm_stripe_plans and civicrm_stripe_customers tables.');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_customers ADD COLUMN `is_live` tinyint(4) NOT NULL COMMENT "Whether this is a live or test transaction"');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_plans ADD COLUMN `is_live` tinyint(4) NOT NULL COMMENT "Whether this is a live or test transaction"');
    }
    else {
      $this->ctx->log->info('Skipped civicrm_stripe update 1903.  Column is_live already present on civicrm_stripe_plans table.');
    }

    $key_column_check = mysql_query("SHOW INDEX FROM `civicrm_stripe_customers` WHERE Key_name = 'email'");
    $key_column_exists = (mysql_num_rows($key_column_check)) ? TRUE : FALSE;
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
    $procIdCheck = mysql_query("SHOW COLUMNS FROM `civicrm_stripe_customers` LIKE 'processor_id'");
    if (mysql_num_rows($procIdCheck)) {
      $this->ctx->log->info('Skipped civicrm_stripe update 4601.  Column processor_id already present on civicrm_stripe_customers and civicrm_stripe_plans table.');
    }
    else {
      $this->ctx->log->info('Applying civicrm_stripe update 4601.  Adding processor_id to civicrm_stripe_customers and civicrm_stripe_plans table.');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_customers ADD COLUMN `processor_id` int(10) DEFAULT NULL COMMENT "ID from civicrm_payment_processor"');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_plans ADD COLUMN `processor_id` int(10) DEFAULT NULL COMMENT "ID from civicrm_payment_processor"');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_subscriptions ADD COLUMN `processor_id` int(10) DEFAULT NULL COMMENT "ID from civicrm_payment_processor"');
      try {
        $params = array(
          'name' => "Stripe",
          'is_active' => 1,
          'api.PaymentProcessor.get' => array(),
        );
        $processorType = civicrm_api3('PaymentProcessorType', 'get', $params); 
        $test_pp_id = NULL;
        $live_pp_id = NULL;
        // We should only get one response - just one stripe pp type that is active.

        // Pop off the values and get possibly one or two actual setup payment
        // processors.
        $processors = array_pop($processorType['values']);
       
        foreach ($processors['api.PaymentProcessor.get']['values'] as $processor) {
          if ($processor['is_test'] == 1 && $processor['is_active'] == 1) {
            $test_id = $processor['id'];
          }
          elseif ($processor['is_test'] == 0 && $processor['is_active'] == 1) {
            $live_id = $processor['id'];
          }
        }
        if($test_id) {
          $params = array(1 => array($test_id, 'Integer'));
          CRM_Core_DAO::executeQuery('UPDATE civicrm_stripe_customers SET processor_id = %1 where processor_id IS NULL AND is_live = 0', $params);
          CRM_Core_DAO::executeQuery('UPDATE civicrm_stripe_plans SET processor_id = %1 where processor_id IS NULL AND is_live = 0', $params);
          CRM_Core_DAO::executeQuery('UPDATE civicrm_stripe_subscriptions SET processor_id = %1 where processor_id IS NULL AND is_live = 0', $params);
        }
        if($live_id) {
          $params = array(1 => array($live_id, 'Integer'));
          CRM_Core_DAO::executeQuery('UPDATE civicrm_stripe_customers SET processor_id = %1 where processor_id IS NULL AND is_live = 1', $params);
          CRM_Core_DAO::executeQuery('UPDATE civicrm_stripe_plans SET processor_id = %1 where processor_id IS NULL AND is_live = 1', $params);
          CRM_Core_DAO::executeQuery('UPDATE civicrm_stripe_subscriptions SET processor_id = %1 where processor_id IS NULL AND is_live = 1', $params);
        }
      }
      catch (CiviCRM_API3_Exception $e) {
        $msg = 'Exception thrown in ' . __METHOD__ . '. Problem setting the processor id for existing customers, plans and subscriptions.';
        CRM_Core_Error::debug_log_message($msg, TRUE, 'com.drastikbydesign.stripe');
        return TRUE;
      }
    }
    return TRUE;
  }

}
