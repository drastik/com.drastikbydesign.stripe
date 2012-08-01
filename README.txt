Installing Stripe as a payment processor in CiviCRM 4.x

Folder structure is left in tact, but there is only 1 file and this is where it goes:  
Stripe.php in civicrm/CRM/Core/Payment/Stripe.php

Copy Stripe's PHP library folder 'stripe-php' to civicrm/packages/stripe-php  
You can get Stripe's PHP library here: https://github.com/stripe/stripe-php

Run the included SQL file "civicrm_stripe.sql" to:  
Insert Stripe into civicrm_payment_processor_type (makes it available as an option within CiviCRM's payment processor settings)
Create table civicrm_stripe_customers  
Create table civicrm_stripe_plans
Create table civicrm_stripe_subscriptions