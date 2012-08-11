------------
Please Read:

There are 3 versions included by directory.  Installation instructions for each further below:
extension-4.1:  Extension for CiviCRM 4.1 and earlier.
extension-4.2:  Extension for CiviCRM 4.2.
source:  Pre-extension method, folder structure is in tact, place files accordingly.


You also need a corresponding module for your CMS.  Here is where the modules can be found:
Drupal:  git clone --recursive --branch master http://git.drupal.org/sandbox/drastik/1719796.git civicrm_stripe
Joomla:  TBD
WordPress:  TBD

IMPORTANT:
In all versions except extension-4.2 (CiviCRM 4.2+):
-You will need to create a cron job in order for recurring contributions to be properly ended.
The cron files are the files in the 'extern' folder.  There is one file each for live & test mode and files are named accordingly.
In CiviCRM 4.2, just make sure you have the correct "Job Scheduler" cron entry.

For Pre-Extension (source) method:
-You will need to run the .sql file to make sure the database tables get created & Stripe is added as a payment processor option.

------------

Installation Instructions:

------------

For CiviCRM 4.2
extension-4.2 instructions:

Install extension

Place civicrm_templates folder anywhere and inform CiviCRM of your "Custom Templates" location in this admin page:  site.com/civicrm/admin/setting/path
(custom template soon to be removed as a requirement)

Make sure you have a cron entry for CiviCRM's Job Scheduler!

Copy Stripe's PHP library folder 'stripe-php' to civicrm/packages/stripe-php  
You can get Stripe's PHP library here: https://github.com/stripe/stripe-php

------------

For CiviCRM 4.1
extension-4.1 instructions:

Install extension

Place civicrm_templates folder anywhere and inform CiviCRM of your "Custom Templates" location in this admin page:  site.com/civicrm/admin/setting/path

Copy files in extern to your CiviCRM extern folder  "civicrm/extern"
Make cron entry to hit the file(s) (daily preferred).

Copy Stripe's PHP library folder 'stripe-php' to civicrm/packages/stripe-php  
You can get Stripe's PHP library here: https://github.com/stripe/stripe-php

------------

Pre-extension (source) instructions:

Folder structure is left in tact.
Place Stripe.php in civicrm/CRM/Core/Payment/Stripe.php

Place civicrm_templates folder anywhere and inform CiviCRM of your "Custom Templates" location in this admin page:  site.com/civicrm/admin/setting/path

Copy files in extern to your CiviCRM extern folder  "civicrm/extern"
Make cron entry to hit the file(s) (daily preferred). 

Copy Stripe's PHP library folder 'stripe-php' to civicrm/packages/stripe-php  
You can get Stripe's PHP library here: https://github.com/stripe/stripe-php

Run the included SQL file "civicrm_stripe.sql" to handle the DB-related needs.  It will:  
Insert Stripe into civicrm_payment_processor_type (makes it available as an option within CiviCRM's payment processor settings)
It will create the required tables:
civicrm_stripe_customers
civicrm_stripe_plans
civicrm_stripe_subscriptions

------------