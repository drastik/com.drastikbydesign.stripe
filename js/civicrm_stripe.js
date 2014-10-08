/**
 * @file
 * JS Integration between CiviCRM & Stripe.
 */
(function ($) {

  var $form, $submit, buttonText;

  // Response from Stripe.createToken.
  function stripeResponseHandler(status, response) {
    var submitButton = $("form.stripe-payment-form input[type='submit']:last");
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

    $form   = $('form.stripe-payment-form');
    $submit = $form.find('[type="submit"]');

    $submit.removeAttr('onclick');

    $form.unbind('submit');

    // Intercept form submission.
    $form.submit(function (event) {
      event.preventDefault();
      event.stopPropagation();

      // Disable the submit button to prevent repeated clicks, cache button text, restore if Stripe returns error
      buttonText = $submit.attr('value');
      $submit.prop('disabled', true).attr('value', 'Processing');

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
}(CRM.$));
