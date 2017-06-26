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

  protected $_contributionRecurID;

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
    $this->setupRecurringPaymentProcessorTransaction();

		$params['sk'] = $this->_sk;
		$params['created'] = array('gte' => $this->_created_ts - 86400);
		// Now try to retrieve this transaction.
		$transactions = civicrm_api3('Stripe', 'listevents', $params );		
		// print_r($params);
    // print_r($transactions);
		return;
		$stripe = new CRM_Stripe_Page_Webhook();
		$data = new stdClass();
		$data->id = $this->_invoiceID;
		$data->livemode = FALSE;
		$stripe->run($data);
    $contribution = civicrm_api3('contribution', 'getsingle', array('id' => $this->_contributionID));
    $this->assertEquals(1, $contribution['contribution_status_id']);
  }

  /**
   * Create recurring contribition
   */
  public function setupRecurringPaymentProcessorTransaction($params = array()) {
     $contributionRecur = civicrm_api3('contribution_recur', 'create', array_merge(array(
      'contact_id' => $this->_contactID,
      'amount' => 1000,
      'sequential' => 1,
      'installments' => 5,
      'frequency_unit' => 'Month',
      'frequency_interval' => 1,
      'invoice_id' => $this->_invoiceID,
      'contribution_status_id' => 2,
      'payment_processor_id' => $this->_paymentProcessorID,
      // processor provided ID - use contact ID as proxy.
      'processor_id' => $this->_contactID,
      'api.contribution.create' => array(
        'total_amount' => '200',
        'invoice_id' => $this->_invoiceID,
        'financial_type_id' => 1,
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
