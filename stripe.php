<?php

require_once 'stripe.civix.php';

/**
 * Implementation of hook_civicrm_config().
 */
function stripe_civicrm_config(&$config) {
  _stripe_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu().
 *
 * @param $files array(string)
 */
function stripe_civicrm_xmlMenu(&$files) {
  _stripe_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install().
 */
function stripe_civicrm_install() {
  // Create required tables for Stripe.
  require_once "CRM/Core/DAO.php";
  CRM_Core_DAO::executeQuery("
  CREATE TABLE IF NOT EXISTS `civicrm_stripe_customers` (
    `email` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
    `id` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
    UNIQUE KEY `email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
  ");

  CRM_Core_DAO::executeQuery("
  CREATE TABLE IF NOT EXISTS `civicrm_stripe_plans` (
    `plan_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
    UNIQUE KEY `plan_id` (`plan_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
  ");

  CRM_Core_DAO::executeQuery("
  CREATE TABLE IF NOT EXISTS `civicrm_stripe_subscriptions` (
    `customer_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
    `invoice_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
    `end_time` int(11) NOT NULL DEFAULT '0',
    `is_live` tinyint(4) NOT NULL COMMENT 'Whether this is a live or test transaction',
    KEY `end_time` (`end_time`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
  ");

  return _stripe_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall().
 */
function stripe_civicrm_uninstall() {
  // Remove Stripe tables on uninstall.
  require_once "CRM/Core/DAO.php";
  CRM_Core_DAO::executeQuery("DROP TABLE civicrm_stripe_customers");
  CRM_Core_DAO::executeQuery("DROP TABLE civicrm_stripe_plans");
  CRM_Core_DAO::executeQuery("DROP TABLE civicrm_stripe_subscriptions");

  return _stripe_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable().
 */
function stripe_civicrm_enable() {
  CRM_Core_Session::setStatus("Stripe Payment Processor Message:
    <br />Don't forget to set up Webhooks in Stripe so that recurring contributions are ended!
    <br />Webhook path to enter in Stripe: <strong>yoursite.com/civicrm/stripe/webhook</strong>
    <br />");

  return _stripe_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable().
 */
function stripe_civicrm_disable() {
  return _stripe_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function stripe_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _stripe_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_buildForm().
 *
 * @param $formName - the name of the form
 * @param $form - reference to the form object
 */
function stripe_civicrm_buildForm($formName, &$form) {
  if (isset($form->_paymentProcessor['payment_processor_type'])
    && $form->_paymentProcessor['payment_processor_type'] == 'Stripe') {
    if (!stristr($formName, '_Confirm') && !stristr($formName, '_ThankYou')) {
      if (empty($_GET['type'])) {
        if (!isset($form->_elementIndex['stripe_token'])) {
          $form->addElement('hidden', 'stripe_token', NULL, array('id'=> 'stripe-token'));
        }
      }
    }
  }
}

/**
 * Implementation of hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function stripe_civicrm_managed(&$entities) {
  $entities[] = array(
    'module' => 'com.drastikbydesign.stripe',
    'name' => 'Stripe',
    'entity' => 'PaymentProcessorType',
    'params' => array(
      'version' => 3,
      'name' => 'Stripe',
      'title' => 'Stripe',
      'description' => 'Stripe Payment Processor',
      'class_name' => 'Payment_Stripe',
      'billing_mode' => 'form',
      'user_name_label' => 'Secret Key',
      'password_label' => 'Publishable Key',
      'url_site_default'=> 'https://api.stripe.com/v1',
      'url_recur_default' => 'https://api.stripe.com/v1',
      'url_site_test_default' => 'https://api.stripe.com/v1',
      'url_recur_test_default' => 'https://api.stripe.com/v1',
      'is_recur' => 1,
      'payment_type' => 1
    ),
  );

  return _stripe_civix_civicrm_managed($entities);
}
