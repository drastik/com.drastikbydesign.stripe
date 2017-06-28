<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * FIXME - Add test description.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
require ('BaseTest.php');
class CRM_Stripe_IpnTest extends CRM_Stripe_BaseTest {
  protected $_total = '200';
  protected $_contributionRecurID;
  protected $_installments = 5;
  protected $_frequency_unit = 'month';
  protected $_frequency_interval = 1;

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp() {
    parent::setUp();
  }

  public function tearDown() {
    parent::tearDown();
  }

  /**
   * Test making a recurring contribution.
   */
  public function testIPNRecurSuccess() {
    $this->setupRecurringTransaction();
    $payment_extra_params = array(
      'is_recur' => 1,
      'contributionRecurID' => $this->_contributionRecurID,
      'frequency_unit' => $this->_frequency_unit,
      'frequency_interval' => $this->_frequency_interval,
      'installments' => $this->_installments
    );
    $this->doPayment($payment_extra_params);

    // Now check to see if an event was triggered and if so, process it.
    // Get all events of the type invoice.payment_succeeded that have 
    // happened since this code was invoked.
		$params['sk'] = $this->_sk;
		$params['created'] = array('gte' => $this->_created_ts);
    $params['type'] = 'invoice.payment_succeeded';

		// Now try to retrieve this transaction.
		$transactions = civicrm_api3('Stripe', 'listevents', $params );		
    $payment_object = NULL;
    foreach($transactions['values']['data'] as $transaction) {
      if ($transaction->data->object->subscription == $this->_subscriptionID) {
        // This is the one.
        $payment_object = $transaction;
        break;
      }
    }

    $contribution_status_id = NULL;
    if ($payment_object) {
      $stripe = new CRM_Stripe_Page_Webhook();
      $stripe->run($payment_object);
      $contribution = civicrm_api3('contribution', 'getsingle', array('id' => $this->_contributionID));
      $contribution_status_id = $contribution['contribution_status_id'];
    }
    $this->assertEquals(1, $contribution_status_id, "Recurring payment was properly processed via a stripe event.");
  }

  /**
   * Create recurring contribition
   */
  public function setupRecurringTransaction($params = array()) {
     $contributionRecur = civicrm_api3('contribution_recur', 'create', array_merge(array(
      'financial_type_id' => $this->_financialTypeID,
      'payment_instrument_id' => CRM_Core_OptionGroup::getValue('payment_instrument', 'Credit Card', 'name'),
      'contact_id' => $this->_contactID,
      'amount' => $this->_total,
      'sequential' => 1,
      'installments' => $this->_installments,
      'frequency_unit' => $this->_frequency_unit, 
      'frequency_interval' => $this->_frequency_interval,
      'invoice_id' => $this->_invoiceID,
      'contribution_status_id' => 2,
      'payment_processor_id' => $this->_paymentProcessorID,
      // processor provided ID - use contact ID as proxy.
      'processor_id' => $this->_contactID,
      'api.contribution.create' => array(
        'total_amount' => $this->_total,
        'invoice_id' => $this->_invoiceID,
        'financial_type_id' => $this->_financialTypeID,
        'contribution_status_id' => 'Pending',
        'contact_id' => $this->_contactID,
        'contribution_page_id' => $this->_contributionPageID,
        'payment_processor_id' => $this->_paymentProcessorID,
        'is_test' => 1,
      ),
    ), $params));
		$this->assertEquals(0, $contributionRecur['is_error']);
    $this->_contributionRecurID = $contributionRecur['id'];
    $this->_contributionID = $contributionRecur['values']['0']['api.contribution.create']['id'];
  } 
}
