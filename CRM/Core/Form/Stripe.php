<?php

/*
 * Form Class for Stripe
 */

class CRM_Core_Form_Stripe extends CRM_Core_Form {

  /**
   * Function to access protected payProcessors array in event registraion forms
   * to see if any of them are stripe processors.
   */
  public static function get_stripe_ppids(&$form) {
    $stripe_ppids = array();
    foreach($form->_processors as $k => $v) {
      $sql = "SELECT pp.id FROM civicrm_payment_processor pp JOIN 
        civicrm_payment_processor_type ppt ON pp.payment_processor_type_id =
        ppt.id AND ppt.name = 'Stripe' AND pp.id = %0";
      $dao = CRM_Core_DAO::executeQuery($sql, array(0 => array($k, 'Integer')));
      if($dao->N == 1) {
        $stripe_ppids[] = $k;
      }
    }
    return $stripe_ppids;
  }
}
