WARNING:
-------
Version 1.8+ of this extension *must* use Stripe's latest API version (at least 2013-12-03).
This is the API setting within your stripe.com account settings (just click a button)
More info on how to change:  https://stripe.com/docs/upgrades#how-can-i-upgrade-my-api

Also, your CiviCRM 'Resource URLs' must be set to the extensions directory
relative to Drupal/CRM base.  Example: /sites/all/civicrm_extensions/
*NOT the full server path like /var/www/sites/all/civicrm_extensions/*
This is the admin page for Resource URLs:  /civicrm/admin/setting/url

PLEASE READ:
-----------
There are 3 versions of this extension available.  This is:
4.4:  Extension for CiviCRM 4.4.
You do not need the CMS module for 4.4

IMPORTANT:
---------
The Webhook.php file is registered to the path of civicrm/stripe/webhook
You have to make a Webhook rule in your Stripe.com account and enter the path to Webhook.php for recurring charges to end!

INSTALLATION INSTRUCTIONS:
-------------------------
For CiviCRM 4.4:
1)  Install extension
2)  Copy Stripe's PHP library folder 'stripe-php' to civicrm/packages/stripe-php
You can get Stripe's PHP library here: https://github.com/stripe/stripe-php
