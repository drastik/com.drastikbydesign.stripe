<?php

/*
 * Form Class for Stripe
 */

class CRM_Core_Form_Stripe extends CRM_Core_Form {

  /**
   * Function to access protected payProcessors array in event registraion forms
   * to see if any of them are stripe processors.
   */
  public static function get_stripe_ppids(&$form, $live = 1) {
    $is_test = $live == 1 ? 0 : 1;
    $stripe_ppids = array();
    // On Contribution pages, this property is public, on membership pages it is
    // private. Check if it is available.
    if(isset($form->_paymentProcessors)) {
      foreach($form->_paymentProcessors as $k => $v) {
        if($v['object'] && $v['object']->_processorName == 'Stripe') {
          if($v['object']->_islive == $live) {
            $stripe_ppids[] = $k;
          }
        }
      }
    }
    if(isset($form->_processors)) {
      foreach($form->_processors as $k => $v) {
        $sql = "SELECT pp.id FROM civicrm_payment_processor pp JOIN 
          civicrm_payment_processor_type ppt ON pp.payment_processor_type_id =
          ppt.id AND ppt.name = 'Stripe' AND pp.is_active = 1 AND ppt.is_active = 1 
          AND pp.id = %0 AND is_test = %1";
        $params = array(0 => array($k, 'Integer'), 1 => array($is_test, 'Integer'));
        $dao = CRM_Core_DAO::executeQuery($sql, $params);
        if($dao->N == 1) {
          $stripe_ppids[] = $k;
        }
      }
    }
    return $stripe_ppids;
  }
}
