/**
 * @file
 * JS Integration between CiviCRM & Stripe.
 */
(function($, CRM) {

  var $form, $submit, buttonText;
  var isWebform = false;

  // Response from Stripe.createToken.
  function stripeResponseHandler(status, response) {
    if (response.error) {
      $('html, body').animate({scrollTop: 0}, 300);
      // Show the errors on the form.
      if ($(".messages.crm-error.stripe-message").length > 0) {
        $(".messages.crm-error.stripe-message").slideUp();
        $(".messages.crm-error.stripe-message:first").remove();
      }
      $form.prepend('<div class="messages crm-error stripe-message">'
      + '<strong>Payment Error Response:</strong>'
      + '<ul id="errorList">'
      + '<li>Error: ' + response.error.message + '</li>'
      + '</ul>'
      + '</div>');

      $submit.removeAttr('disabled').attr('value', buttonText);

    }
    else {
      var token = response['id'];
      // Update form with the token & submit.
      $form.find("input#stripe-token").val(token);
      $form.find("input#credit_card_number").removeAttr('name');
      $form.find("input#cvv2").removeAttr('name');
      $submit.prop('disabled', false);
      window.onbeforeunload = null;
      $form.get(0).submit();
    }
  }

  // Prepare the form.
  $(document).ready(function() {
    $.getScript('https://js.stripe.com/v2/', function () {
      Stripe.setPublishableKey($('#stripe-pub-key').val());
    });

    if ($('.webform-client-form').length) {
      isWebform = true;
      $('form.webform-client-form').addClass('stripe-payment-form');
    }
    else {
      if (!($('.stripe-payment-form').length)) {
        $('#crm-container>form').addClass('stripe-payment-form');
      }
    }
    $form   = $('form.stripe-payment-form');
    if (isWebform) {
      $submit = $form.find('.button-primary');
    }
    else {
      $submit = $form.find('input[type="submit"]');
    }

    if (isWebform) {
      if (!($('#action').length)) {
        $form.append('<input type="hidden" name="op" id="action" />');
      }
      $(document).keypress(function(event) {
        if (event.which == 13) {
          event.preventDefault();
          $submit.click();
        }
      });
      $(":submit").click(function() {
        $('#action').val(this.value);
      });
      $('#billingcheckbox:input').hide();
      $('label[for="billingcheckbox"]').hide();

      var webformPrevious = $('input.webform-previous').first().val();
    }
    else {
      // This is native civicrm form - check for existing token
      if ($form.find("input#stripe-token").val()) {
        $('.credit_card_info-group').hide();
        $('#billing-payment-block').append('<input type="button" value="Edit CC details" id="ccButton" />');
        $('#ccButton').click(function() {
          $('.credit_card_info-group').show();
          $('#ccButton').hide();
          $form.find('input#stripe-token').val('');
        });
      }
    }

    $submit.removeAttr('onclick');

    $form.unbind('submit');

    // Intercept form submission.
    $form.submit(function (event) {
      if (isWebform) {
        var $processorFields = $('.civicrm-enabled[name$="civicrm_1_contribution_1_contribution_payment_processor_id]"]');

        if ($('#action').attr('value') == webformPrevious) {
          return true;
        }
        if ($('#wf-crm-billing-total').length) {
          if ($('#wf-crm-billing-total').data('data-amount') == '0') {
            return true;
          }
        }
        if ($processorFields.length) {
          if ($processorFields.filter(':checked').val() == '0') {
            return true;
          }
          if (!($form.find('input[name="stripe_token"]').length)) {
            return true;
          }
        }
      }
      // Disable the submit button to prevent repeated clicks, cache button text, restore if Stripe returns error
      buttonText = $submit.attr('value');
      $submit.prop('disabled', true).attr('value', 'Processing');

      if ($('#priceset').length) {
      if ($form.find("#priceset input[type='radio']:checked").data('amount') == 0) {
        return true;
      }
      }

      // Handle multiple payment options and Stripe not being chosen.
      if ($form.find(".crm-section.payment_processor-section").length > 0) {
        if (!($form.find('input[name="hidden_processor"]').length > 0)) {
          return true;
        }
        if ($form.find('input[name="payment_processor"]:checked').length) {
          processorId=$form.find('input[name="payment_processor"]:checked').val();
          if (!($form.find('input[name="stripe_token"]').length) || ($('#stripe-id').length && $('#stripe-id').val() != processorId)) {
            return true;
          }
        }
      }

      // Handle pay later (option value '0' in payment_processor radio group)
      if ($form.find('input[name="payment_processor"]:checked').length && !parseInt($form.find('input[name="payment_processor"]:checked').val())) {
        return true;
      }

      // Handle reuse of existing token
      if ($form.find("input#stripe-token").val()) {
        $form.find("input#credit_card_number").removeAttr('name');
        $form.find("input#cvv2").removeAttr('name');
        return true;
      }

      event.preventDefault();
      event.stopPropagation();

      // Handle changes introduced in CiviCRM 4.3.
      if ($form.find('#credit_card_exp_date_M').length > 0) {
        var cc_month = $form.find('#credit_card_exp_date_M').val();
        var cc_year = $form.find('#credit_card_exp_date_Y').val();
      }
      else {
        var cc_month = $form.find('#credit_card_exp_date\\[M\\]').val();
        var cc_year = $form.find('#credit_card_exp_date\\[Y\\]').val();
      }
      Stripe.card.createToken({
        name:        $form.find('#billing_first_name').val() + ' ' + $form.find('#billing_last_name').val(),
        address_zip: $form.find('#billing_postal_code-5').val(),
        number:      $form.find('#credit_card_number').val(),
        cvc:         $form.find('#cvv2').val(),
        exp_month:   cc_month,
        exp_year:    cc_year
      }, stripeResponseHandler);

      return false;
    });
  });
}(cj, CRM));
