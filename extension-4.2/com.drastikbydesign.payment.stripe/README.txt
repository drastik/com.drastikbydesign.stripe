------------
Please Read:
There are 3 versions of this extension available.  This is:
extension-4.2:  Extension for CiviCRM 4.2.

You also need a corresponding module for your CMS.  Here is where the modules can be found:
Drupal:  git clone --recursive --branch master http://git.drupal.org/sandbox/drastik/1719796.git civicrm_stripe
Joomla:  TBD
WordPress:  TBD

IMPORTANT:
In CiviCRM 4.2, just make sure you have the correct "Job Scheduler" cron entry set up.

------------

Installation Instructions:

------------

For CiviCRM 4.2:

Install extension

Place civicrm_templates folder anywhere and inform CiviCRM of your "Custom Templates" location in this admin page:  site.com/civicrm/admin/setting/path
(custom template soon to be removed as a requirement)

Make sure you have a cron entry for CiviCRM's Job Scheduler!

Copy Stripe's PHP library folder 'stripe-php' to civicrm/packages/stripe-php  
You can get Stripe's PHP library here: https://github.com/stripe/stripe-php

------------