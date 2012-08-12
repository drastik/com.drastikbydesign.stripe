------------
Please Read:
There are 3 versions of this extension available.  This is:
extension-4.1:  Extension for CiviCRM 4.1 and earlier.

You also need a corresponding module for your CMS.  Here is where the modules can be found:
Drupal:  git clone --recursive --branch master http://git.drupal.org/sandbox/drastik/1719796.git civicrm_stripe
Joomla:  TBD
WordPress:  TBD

IMPORTANT:
-You will need to create a cron job in order for recurring contributions to be properly ended.
The cron files are the files in the 'extern' folder.  There is one file each for live & test mode and files are named accordingly.

------------

Installation Instructions:

------------

For CiviCRM 4.1:

Install extension

Place civicrm_templates folder anywhere and inform CiviCRM of your "Custom Templates" location in this admin page:  site.com/civicrm/admin/setting/path

Copy files in extern to your CiviCRM extern folder  "civicrm/extern"
Make cron entry to hit the file(s) (daily preferred).

Copy Stripe's PHP library folder 'stripe-php' to civicrm/packages/stripe-php  
You can get Stripe's PHP library here: https://github.com/stripe/stripe-php

------------