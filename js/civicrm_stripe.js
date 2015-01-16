/**
 * @file
 * JS Integration between CiviCRM & Stripe.
 */
(function($, CRM) {

  var $form, $submit, buttonText;

  /**
   * The type of form currently being used. This will help when declaring different behaviour depending on whether the
   * form is a native CiviCRM form or embedded within a Webform (e.g., using webform_civicrm module).
   */
  var formType;

  /**
   * Current status of the form
   * @type {boolean}
   */
  var submitting = false;

  /**
   * Constants
   *
   * @readonly
   * @type {object}
   */
  var constants = {
    FORM_TYPE_CIVI: 'civi',
    FORM_TYPE_WEBFORM: 'webform'
  };

  /**
   * Initialisation various things
   */
  function init() {
    initStripe();
    initForm();
  }

  /**
   * Initialise things related to Stripe
   */
  function initStripe() {
    $.getScript('https://js.stripe.com/v2/', function () {
      Stripe.setPublishableKey($('#stripe-pub-key').val());
    });
  }

  /**
   * Initialise things related to the payment form
   */
  function initForm() {
    var civiStripeForm = $('.stripe-payment-form');
    var webformStripeForm = $('.webform-client-form');

    if (civiStripeForm.length) {
      $form = civiStripeForm;
      formType = constants.FORM_TYPE_CIVI;
    } else if (webformStripeForm.length) {
      $form = webformStripeForm;
      formType = constants.FORM_TYPE_WEBFORM;
    }
  }

  /**
   * Show a message indicating that the form is being submitted
   */
  function showSubmissionMessage() {
    var submissionMessage = 'Processing, please wait...';
    if (formType === constants.FORM_TYPE_WEBFORM) {
      $form.append('<h3 style="text-align: center; margin: 20px 0; background-color: lightyellow; border: 1px dashed orange;">' + submissionMessage + '</h3>');
    }
  }

  /**
   * Handle response from Stripe.createToken.
   */
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

      $submit.attr('value', buttonText);
    }
    else {
      var token = response['id'];
      // Update form with the token & submit.
      $form.find("input#stripe-token").val(token);
      window.onbeforeunload = null;

      $submit.click();
    }
  }

  // Prepare the form.
  $(document).ready(function() {
    init();

    if ($form) {
      $form.on('click', 'input[type="submit"]', function (event) {
        // Using this logic, instead of disabling the submit button, because we need to be able to click it using JS
        if (submitting) return false;

        $submit  = $(this);

        // Webform specific behaviour
        if (formType == constants.FORM_TYPE_WEBFORM) {
          // Previous button clicked on the webform - let the click event submit the form in order to go back to the previous page
          if ($submit.hasClass('webform-previous')) {
            return true;
          }
        }

        // Disable the submit button to prevent repeated clicks, cache button text, restore if Stripe returns error
        buttonText = $submit.attr('value');

        // Only change submit button value for CiviCRM's form, since changing it for Webform makes it fail!
        if (formType === constants.FORM_TYPE_CIVI) $submit.prop('value', 'Processing');

        // Token hasn't yet been retrieved - simply block the click event as we don't want to submit the form just yet
        if (!$form.find('#stripe-token').val()) {
          event.preventDefault();
          event.stopPropagation();
        }
        // Token exists - let the click event submit the form as usual
        else {
          submitting = true;
          showSubmissionMessage();
          return true;
        }

        if ($form.find("#priceset input[type='radio']:checked").data('amount') == 0) {
          return true;
        }

        // Handle multiple payment options and Stripe not being chosen.
        if ($form.find(".crm-section.payment_processor-section").length > 0) {
          if (!($form.find('input[name="hidden_processor"]').length > 0)) {
            return true;
          }
        }

        // Handle pay later (option value '0' in payment_processor radio group)
        if ($form.find('input[name="payment_processor"]:checked').length && !parseInt($form.find('input[name="payment_processor"]:checked').val())) {
          return true;
        }

        // Handle changes introduced in CiviCRM 4.3.
        var cc_month, cc_year;
        if ($form.find('#credit_card_exp_date_M').length > 0) {
          cc_month = $form.find('#credit_card_exp_date_M').val();
          cc_year = $form.find('#credit_card_exp_date_Y').val();
        }
        else {
          cc_month = $form.find('#credit_card_exp_date\\[M\\]').val();
          cc_year = $form.find('#credit_card_exp_date\\[Y\\]').val();
        }

        Stripe.card.createToken({
          name:        $form.find('#billing_first_name').val() + ' ' + $form.find('#billing_last_name').val(),
          address_zip: $form.find('#billing_postal_code-5').val(),
          number:      $form.find('#credit_card_number').val(),
          cvc:         $form.find('#cvv2').val(),
          exp_month:   cc_month,
          exp_year:    cc_year
        }, stripeResponseHandler);
      });
    } else {
      console.error('Stripe payment form not found!');
      return false;
    }
  });
}(cj, CRM));
