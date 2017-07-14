<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

define('STRIPE_PHPUNIT_TEST', 1);

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
class CRM_Stripe_BaseTest extends \PHPUnit_Framework_TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  protected $_contributionID;
  protected $_invoiceID = 'in_19WvbKAwDouDdbFCkOnSwAN7';
  protected $_financialTypeID = 1;
  protected $org;
  protected $_orgID;
  protected $contact;
  protected $_contactID;
  protected $_contributionPageID;
  protected $_paymentProcessorID;
  protected $_paymentProcessor;
  protected $_trxn_id;
  protected $_created_ts;
  protected $_subscriptionID;
  protected $_membershipTypeID;
  // Secret/public keys are PTP test keys.
  protected $_sk = 'sk_test_TlGdeoi8e1EOPC3nvcJ4q5UZ';
  protected $_pk = 'pk_test_k2hELLGpBLsOJr6jZ2z9RaYh';
  protected $_cc = NULL; 

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp() {
    parent::setUp();
    require_once('stripe-php/init.php');
    $this->createPaymentProcessor();
    $this->createContact();
    $this->createContributionPage();
    $this->_created_ts = time();
    $this->set_cc();
  }

  /**
   * Switch between test cc number that works and that fails
   *
   */
  public function set_cc($type = 'works') {
    // See https://stripe.com/docs/testing
    if ($type == 'works') {
      $this->_cc = '4111111111111111';
    }
    elseif ($type == 'fails') {
      $this->_cc = '4000000000000002';
    }
  }

  public function tearDown() {
    parent::tearDown();
  }

  /**
   * Create contact.
   */
  function createContact() {
    if (!empty($this->_contactID)) {
      return;
    }
    $results = civicrm_api3('Contact', 'create', array(
      'contact_type' => 'Individual',
      'first_name' => 'Jose',
      'last_name' => 'Lopez'
    ));;
    $this->_contactID = $results['id'];
    $this->contact = (Object) array_pop($results['values']);

    // Now we have to add an email address.
    $email = 'susie@example.org';
    civicrm_api3('email', 'create', array(
      'contact_id' => $this->_contactID,
      'email' => $email,
      'location_type_id' => 1
    ));
    $this->contact->email = $email;
  }

  /**
   * Create a stripe payment processor.
   *
   */
  function createPaymentProcessor($params = array()) {
    
    $result = civicrm_api3('Stripe', 'setuptest', $params);
    $processor = array_pop($result['values']);
    $this->_sk = $processor['user_name'];
    $this->_pk = $processor['password'];
    $this->_paymentProcessor = $processor; 
    $this->_paymentProcessorID = $result['id']; 
  }

  /**
   * Create a stripe contribution page.
   *
   */
  function createContributionPage($params = array()) {
    $params = array_merge(array(
      'title' => "Test Contribution Page",
      'financial_type_id' => $this->_financialTypeID,
      'currency' => 'USD',
      'payment_processor' => $this->_paymentProcessorID,
      'max_amount' => 1000,
      'receipt_from_email' => 'gaia@the.cosmos',
      'receipt_from_name' => 'Pachamama',
      'is_email_receipt' => FALSE,  
      ), $params);
    $result = civicrm_api3('ContributionPage', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->_contributionPageID = $result['id']; 
  }
  
  /**
   * Submit to stripe
   */
  public function doPayment($params = array()) {
    $mode = 'test';
    $pp = $this->_paymentProcessor;
    $stripe = new CRM_Core_Payment_Stripe($mode, $pp);
    $params = array_merge(array(
      'payment_processor_id' => $this->_paymentProcessorID,
      'amount' => $this->_total,
      'stripe_token' => array(
        'number' => $this->_cc,
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
    ), $params);

    $ret = $stripe->doDirectPayment($params);
    if (array_key_exists('trxn_id', $ret)) {
      $this->_trxn_id = $ret['trxn_id'];
    }
    if (array_key_exists('subscription_id', $ret)) {
      $this->_subscriptionID = $ret['subscription_id'];
    }
  }

  /**
   * Confirm that transaction id is legit and went through.
   *
   */
  public function assertValidTrxn() {
    $this->assertNotEmpty($this->_trxn_id, "A trxn id was assigned");

    \Stripe\Stripe::setApiKey($this->_sk);
    $found = FALSE;
    try {
      $results = \Stripe\Charge::retrieve(array( "id" => $this->_trxn_id));
      $found = TRUE;
    }
    catch (Stripe_Error $e) {
      $found = FALSE;
    }
    
    $this->assertTrue($found, 'Assigned trxn_id is valid.');

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
      'total_amount' => $this->_total,
      'invoice_id' => $this->_invoiceID,
      'financial_type_id' => $this->_financialTypeID,
      'contribution_status_id' => 'Pending',
      'contact_id' => $this->_contactID,
      'contribution_page_id' => $this->_contributionPageID,
      'payment_processor_id' => $this->_paymentProcessorID,
      'is_test' => 1,
    ), $params));
    $this->assertEquals(0, $contribution['is_error']);
    $this->_contributionID = $contribution['id'];
  } 

  public function createOrganization() {
    if (!empty($this->_orgID)) {
      return;
    }
    $results = civicrm_api3('Contact', 'create', array(
      'contact_type' => 'Organization',
      'organization_name' => 'My Great Group'
    ));;
    $this->_orgID = $results['id'];
  }

  public function createMembershipType() {
    CRM_Member_PseudoConstant::flush('membershipType');
    CRM_Core_Config::clearDBCache();
    $this->createOrganization();
    $params = array( 
      'name' => 'General',
      'duration_unit' => 'year',
      'duration_interval' => 1,
      'period_type' => 'rolling',
      'member_of_contact_id' => $this->_orgID,
      'domain_id' => 1,
      'financial_type_id' => 2,
      'is_active' => 1,
      'sequential' => 1,
      'visibility' => 'Public',
    );

    $result = civicrm_api3('MembershipType', 'Create', $params);

    $this->_membershipTypeID = $result['id'];

    CRM_Member_PseudoConstant::flush('membershipType');
    CRM_Utils_Cache::singleton()->flush();
  }

  
}
