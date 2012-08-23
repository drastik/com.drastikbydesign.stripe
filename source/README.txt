------------
Please Read:

There are 3 versions of this extension available.  This is:
source:  Pre-extension method, folder structure is in tact, manually place files accordingly.

You also need a corresponding module for your CMS.  Here is where the modules can be found:
Drupal:  git clone --recursive --branch master http://git.drupal.org/sandbox/drastik/1719796.git civicrm_stripe
Joomla:  TBD
WordPress:  TBD

IMPORTANT:
The Webhook.php file is in the 'extern' folder.  
You have to make a Webhook rule in your Stripe account and enter the path to Webhook.php for recurring charges to end!
-You will need to run the .sql file to make sure the database tables get created & Stripe is added as a payment processor option.

------------

Installation Instructions:

------------

Pre-extension (source) instructions:

Folder structure is left in tact.
Place Stripe.php in civicrm/CRM/Core/Payment/Stripe.php

Place civicrm_templates folder anywhere and inform CiviCRM of your "Custom Templates" location in this admin page:  site.com/civicrm/admin/setting/path

Copy files in extern to your CiviCRM extern folder  "civicrm/extern"
Make a Webhook rule in your Stripe account and enter the path to Webhook.php

Copy Stripe's PHP library folder 'stripe-php' to civicrm/packages/stripe-php  
You can get Stripe's PHP library here: https://github.com/stripe/stripe-php

Run the included SQL file "civicrm_stripe.sql" to handle the DB-related needs.

------------