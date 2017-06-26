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
  protected $_total = '200';

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
  public function testDirectSuccess() {
    $this->setupTransaction();
    $this->doPayment();
    require_once('stripe-php/init.php');
    \Stripe\Stripe::setApiKey($this->_sk);
    $found = FALSE;
    try {
      $results = \Stripe\Charge::retrieve(array( "id" => $this->_trxn_id));
      $found = TRUE;
    }
    catch (Stripe_Error $e) {
      $found = FALSE;
    }
    
    $this->assertTrue($found, 'Direct payment succeeded');
  }

  /**
   * Submit to stripe
   */
  public function doPayment() {
    $mode = 'test';
    $pp = $this->_paymentProcessor;
    $stripe = new CRM_Core_Payment_Stripe($mode, $pp);
    $params = array(
      'amount' => $this->_total,
      'stripe_token' => array(
        'number' => '4111111111111111',
        'exp_month' => '12',
        'exp_year' => date('Y') + 1,
        'cvc' => '123',
        'name' => $this->contact->display_name,
        'address_line1' => '123 4th Street',
        'address_state' => 'NY',
        'address_zip' => '12345',
      ),
      'email' => $this->contact->email,
      'description' => 'Test from Stripe Test Code',
      'currencyID' => 'USD',
      'invoiceID' => $this->_invoiceID,
    );

    $ret = $stripe->doDirectPayment($params);
    $this->_trxn_id = $ret['trxn_id'];
    $this->assertNotEmpty($this->_trxn_id, 'Received transaction id from Stripe');
  }

  /**
   * Create contribition
   */
  public function setupTransaction($params = array()) {
     $contribution = civicrm_api3('contribution', 'create', array_merge(array(
      'contact_id' => $this->_contactID,
      'contribution_status_id' => 2,
      'payment_processor_id' => $this->_paymentProcessorID,
      // processor provided ID - use contact ID as proxy.
      'processor_id' => $this->_contactID,
      'invoice_id' => $this->_invoiceID,
      'total_amount' => $this->_total,
      'invoice_id' => $this->_invoiceID,
      'financial_type_id' => 1,
      'contribution_status_id' => 'Pending',
      'contact_id' => $this->_contactID,
      'contribution_page_id' => $this->_contributionPageID,
      'payment_processor_id' => $this->_paymentProcessorID,
      'is_test' => 1,
    ), $params));
		$this->assertEquals(0, $contribution['is_error']);
    $this->_contributionID = $contribution['id'];
  } 
}
