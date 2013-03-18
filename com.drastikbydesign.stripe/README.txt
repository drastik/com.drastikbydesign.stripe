------------
Please Read:
There are 3 versions of this extension available.  This is:
extension-4.2:  Extension for CiviCRM 4.2 and earlier.

You do not need the CMS module for 4.2

IMPORTANT:
In 4.2 the Webhook.php file is registered to the path of civicrm/stripe/webhook
You have to make a Webhook rule in your Stripe account and enter the path to Webhook.php for recurring charges to end!

------------

Installation Instructions:

------------

For CiviCRM 4.2:

Install extension

Copy Stripe's PHP library folder 'stripe-php' to civicrm/packages/stripe-php  
You can get Stripe's PHP library here: https://github.com/stripe/stripe-php
