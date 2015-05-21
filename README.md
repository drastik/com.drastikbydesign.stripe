CiviCRM Stripe Payment Processor
--------------------------------
Version 1.8+ of this extension *must* use Stripe's latest API version (at least 2013-12-03).  
Go to _Account Settings_ -> _API Keys_ tab -> click _Upgrade available_ button.  
More info on how to change:  https://stripe.com/docs/upgrades#how-can-i-upgrade-my-api  

CONFIGURATION
-------------
All configuration is in the standard Payment Processors settings area in CiviCRM admin.  

WEBHOOK
---------
The Webhook.php file is registered to the path of civicrm/stripe/webhook  
You will have to make a Webhook rule in your Stripe.com account and enter this path for recurring charges to end!  
For Drupal:  https://example.com/civicrm/stripe/webhook  
For Joomla:  https://example.com/index.php/component/civicrm/?task=civicrm/stripe/webhook  
For Wordpress:  https://example.com/?page=CiviCRM&q=civicrm/stripe/webhook  

INSTALLATION
------------
For CiviCRM 4.4 & up:  
1)  Your CiviCRM 'Resource URLs' must be set to the extensions directory  
    relative to Drupal/CRM base.  Example: /sites/all/civicrm_extensions/  
    *NOT the full server path like /var/www/sites/all/civicrm_extensions/*  
    The admin page for Resource URLs is:  /civicrm/admin/setting/url  

2)  Install extension via CiviCRM's "Manage Extensions" page.  

GOOD TO KNOW
------------
* The stripe-php package has been added to this project & no longer needs to be  
downloaded separately.  
* You do not need the civicrm_stripe CMS module for 4.2 & up  
* There will no longer be branches for each version.  THe branches will be:  
  * Civi's major.minor-dev, and we will create releases (tags) for each new release version.  
    * Example: 4.6-dev.  

AUTHOR INFO
-----------
Joshua Walker  
http://drastikbydesign.com  
https://drupal.org/user/433663  

OTHER CREDITS
-------------
Big thanks to rgburton & Swingline0 for adding wonderful new features to the project.
