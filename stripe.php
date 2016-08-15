<?php

require_once 'stripe.civix.php';

/**
 * Implementation of hook_civicrm_config().
 */
function stripe_civicrm_config(&$config) {
  _stripe_civix_civicrm_config($config);
  $extRoot = dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'packages' . DIRECTORY_SEPARATOR;
  $include_path = $extRoot . PATH_SEPARATOR . get_include_path( );
  set_include_path( $include_path );
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
    `is_live` tinyint(4) NOT NULL COMMENT 'Whether this is a live or test transaction',
    `processor_id` int(10) DEFAULT NULL COMMENT 'ID from civicrm_payment_processor',
    UNIQUE KEY `id` (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
  ");

  CRM_Core_DAO::executeQuery("
  CREATE TABLE IF NOT EXISTS `civicrm_stripe_plans` (
    `plan_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
    `is_live` tinyint(4) NOT NULL COMMENT 'Whether this is a live or test transaction',
    `processor_id` int(10) DEFAULT NULL COMMENT 'ID from civicrm_payment_processor',
    UNIQUE KEY `plan_id` (`plan_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
  ");

  CRM_Core_DAO::executeQuery("
  CREATE TABLE IF NOT EXISTS `civicrm_stripe_subscriptions` (
    `customer_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
    `invoice_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
    `end_time` int(11) NOT NULL DEFAULT '0',
    `is_live` tinyint(4) NOT NULL COMMENT 'Whether this is a live or test transaction',
    `processor_id` int(10) DEFAULT NULL COMMENT 'ID from civicrm_payment_processor',
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
  $UF_webhook_paths = array(
    "Drupal"    => "/civicrm/stripe/webhook",
    "Drupal6"   => "/civicrm/stripe/webhook",
    "Joomla"    => "/index.php/component/civicrm/?task=civicrm/stripe/webhook",
    "WordPress" => "/?page=CiviCRM&q=civicrm/stripe/webhook"
  );
  // Use Drupal path as default if the UF isn't in the map above
  $webookhook_path = (array_key_exists(CIVICRM_UF, $UF_webhook_paths)) ?
    CIVICRM_UF_BASEURL . $UF_webhook_paths[CIVICRM_UF] :
    CIVICRM_UF_BASEURL . "civicrm/stripe/webhook";

  CRM_Core_Session::setStatus("Stripe Payment Processor Message:
    <br />Don't forget to set up Webhooks in Stripe so that recurring contributions are ended!
    <br />Webhook path to enter in Stripe:<br/><em>$webookhook_path</em>
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
 * Implementation of hook_civicrm_validateForm().
 *
 * Prevent server validation of cc fields
 *
 * @param $formName - the name of the form
 * @param $fields - Array of name value pairs for all 'POST'ed form values
 * @param $files - Array of file properties as sent by PHP POST protocol
 * @param $form - reference to the form object
 * @param $errors - Reference to the errors array.
 */
function stripe_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors) {
  if (empty($form->_paymentProcessor['payment_processor_type'])) {
    return;
  }
  // If Stripe is active here.
  if (isset($form->_elementIndex['stripe_token'])) {
    if ($form->elementExists('credit_card_number')) {
      $cc_field = $form->getElement('credit_card_number');
      $form->removeElement('credit_card_number', true);
      $form->addElement($cc_field);
    }
    if ($form->elementExists('cvv2')) {
      $cvv2_field = $form->getElement('cvv2');
      $form->removeElement('cvv2', true);
      $form->addElement($cvv2_field);
    }
  }
}

/**
 * Implementation of hook_civicrm_buildForm().
 *
 * @param $formName - the name of the form
 * @param $form - reference to the form object
 */
function stripe_civicrm_buildForm($formName, &$form) {
  $stripe_key = stripe_get_key($form);
  // If this is not a form Stripe is involved in, do nothing.
  if (empty($stripe_key)) {
    return;
  }
  $params = $form->get('params');
  // Contrib forms store this in $params, Event forms in $params[0].
  if (!empty($params[0]['stripe_token'])) {
    $params = $params[0];
  }
  $stripe_token = (empty($params['stripe_token']) ? NULL : $params['stripe_token']);

  // Add some hidden fields for Stripe.
  if (!$form->elementExists('stripe_token')) {
    $form->setAttribute('class', $form->getAttribute('class') . ' stripe-payment-form');
    $form->addElement('hidden', 'stripe_token', $stripe_token, array('id' => 'stripe-token'));
  }
  stripe_add_stripe_js($stripe_key, $form);

  // Add email field as it would usually be found on donation forms.
  if (!isset($form->_elementIndex['email']) && !empty($form->userEmail)) {
    $form->addElement('hidden', 'email', $form->userEmail, array('id' => 'user-email'));
  }
}

/**
 * Return the stripe api public key (aka password)
 *
 * If this form could conceiveably now or at any time in the future
 * contain a Stripe payment processor, return the api public key for
 * that processor.
 */
function stripe_get_key($form) {
  if (empty($form->_paymentProcessor)) {
    return;
  }
  // Only return first value if Stripe is the only/default.
  if ($form->_paymentProcessor['payment_processor_type'] == 'Stripe') {
    if (isset($form->_paymentProcessor['password'])) {
      return $form->_paymentProcessor['password'];
    }
  }

  // Otherwise we need to look through all active payprocs and find Stripe.
  $is_test = 0;
  if (isset($form->_mode)) {
    $is_test = $form->_mode == 'live' ? 0 : 1;
  }

  // The _paymentProcessors array seems to be the most reliable way to find
  // if the form is using Stripe.
  if (!empty($form->_paymentProcessors)) {
    foreach ($form->_paymentProcessors as $pp) {
      if ($pp['payment_processor_type'] == 'Stripe') {
        if (!empty($pp['password'])) {
          return $pp['password'];
        }
        // We have a match.
        return stripe_get_key_for_name($pp['name'], $is_test);
      }
    }
  }
  // Return NULL if this is not a form with Stripe involved.
  return NULL;
}

/**
 * Given a payment processor name, return the pub key.
 */
function stripe_get_key_for_name($name, $is_test) {
  try {
    $params = array('name' => $name, 'is_test' => $is_test);
    $results = civicrm_api3('PaymentProcessor', 'get', $params);
    if ($results['count'] == 1) {
      $result = array_pop($results['values']);
      return $result['password'];
    }
  }
  catch (CiviCRM_API3_Exception $e) {
    return NULL;
  }
}

/**
 * Add publishable key and event bindings for Stripe.js.
 */
function stripe_add_stripe_js($stripe_key, $form) {
  $form->addElement('hidden', 'stripe_pub_key', $stripe_key, array('id' => 'stripe-pub-key'));
  CRM_Core_Resources::singleton()->addScriptFile('com.drastikbydesign.stripe', 'js/civicrm_stripe.js', 0);
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
      'url_site_default' => 'https://api.stripe.com/v2',
      'url_recur_default' => 'https://api.stripe.com/v2',
      'url_site_test_default' => 'https://api.stripe.com/v2',
      'url_recur_test_default' => 'https://api.stripe.com/v2',
      'is_recur' => 1,
      'payment_type' => 1
    ),
  );

  return _stripe_civix_civicrm_managed($entities);
}
