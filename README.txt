------------
Important Note:

This version is for CiviCRM 4.1 and prior.
It will work for CiviCRM 4.2+ but there will be a new version to utilize all the new features surrounding Payment Processors in CiviCRM 4.2.
This currently includes everything you need minus a cron file to cancel recurring contributions.  Do not allow recurring just yet!

You also need a corresponding module for your CMS.  Here is where the modules can be found:
Drupal:  git clone --recursive --branch master http://git.drupal.org/sandbox/drastik/1719796.git civicrm_stripe
Joomla:  TBD
WordPress:  TBD 

------------

Installing Stripe as a payment processor in CiviCRM 4.x

Folder structure is left in tact.  
Place Stripe.php in civicrm/CRM/Core/Payment/Stripe.php

Place civicrm_templates folder anywhere and inform CiviCRM of your "Custom Templates" location in this admin page:  site.com/civicrm/admin/setting/path

Copy Stripe's PHP library folder 'stripe-php' to civicrm/packages/stripe-php  
You can get Stripe's PHP library here: https://github.com/stripe/stripe-php

Run the included SQL file "civicrm_stripe.sql" to handle the DB-related needs.  It will:  
Insert Stripe into civicrm_payment_processor_type (makes it available as an option within CiviCRM's payment processor settings)
It will create the required tables:
civicrm_stripe_customers
civicrm_stripe_plans
civicrm_stripe_subscriptions

------------
Note:

This will be packaged as a "CiviCRM Extension" shortly for an alternative installation method.
In <CiviCRM 4.2, you will need to create a cron job in order for recurring contributions to be properly ended.

------------