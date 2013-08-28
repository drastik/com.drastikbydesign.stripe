PLEASE READ:
-----------
There are 3 versions of this extension available.  This is:
4.3:  Extension for CiviCRM 4.3.
You do not need the CMS module for 4.3

IMPORTANT:
---------
In 4.3 the Webhook.php file is registered to the path of civicrm/stripe/webhook
You have to make a Webhook rule in your Stripe account and enter the path to Webhook.php for recurring charges to end!

INSTALLATION INSTRUCTIONS:
-------------------------
For CiviCRM 4.3:
1)  Install extension
2)  Copy Stripe's PHP library folder 'stripe-php' to civicrm/packages/stripe-php
You can get Stripe's PHP library here: https://github.com/stripe/stripe-php
