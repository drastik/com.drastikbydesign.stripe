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

}
