CiviCRM Stripe Payment Processor
--------------------------------
Version 1.8+ of this extension *must* use Stripe's latest API version (at least 2013-12-03).  
Go to _Account Settings_ -> _API Keys_ tab -> click _Upgrade available_ button.  
More info on how to change:  https://stripe.com/docs/upgrades#how-can-i-upgrade-my-api  

CONFIGURATION
-------------
All configuration is in the standard Payment Processors settings area in CiviCRM admin.  
You will enter your "Publishable" & "Secret" key given by stripe.com.  

WEBHOOK & RECURRING PAYMENTS
---------
The Webhook.php file is registered to the path of civicrm/stripe/webhook  
You will have to make a Webhook rule in your Stripe.com account and enter this path for recurring charges to end!  
For Drupal:  https://example.com/civicrm/stripe/webhook  
For Joomla:  https://example.com/index.php/component/civicrm/?task=civicrm/stripe/webhook  
For Wordpress:  https://example.com/?page=CiviCRM&q=civicrm/stripe/webhook  

If you have multiple Stripe accounts on your site, you will need to specify the payment processor ID in the webhook URL.
To find the ID, look at the URL when you are editing the payment processor in CiviCRM: it should include `id=XX`, where `XX` is your payment processor ID.
Add a URL parameter of `ppid=XX` to the webhook URL.
For example, for a payment processor ID of 3, use the following:
For Drupal:  https://example.com/civicrm/stripe/webhook?ppid=3
For Joomla:  https://example.com/index.php/component/civicrm/?task=civicrm/stripe/webhook&ppid=3
For Wordpress:  https://example.com/?page=CiviCRM&q=civicrm/stripe/webhook&ppid=3

INSTALLATION
------------
For CiviCRM 4.4 & up:  
1)  Your CiviCRM 'Resource URLs' must be set to the extensions directory  
    relative to Drupal/CRM base.  Example: /sites/all/civicrm_extensions/  
    *NOT the full server path like /var/www/sites/all/civicrm_extensions/*  
    The admin page for Resource URLs is:  /civicrm/admin/setting/url  

2)  Install extension via CiviCRM's "Manage Extensions" page.  

CANCELLING RECURRING CONTRIBUTIONS
------------
You can cancel a recurring contribution from the Stripe.com dashboard. Go to Customers and then to the specific customer.
Inside the customer you will see a Subscriptions section. Click Cancel on the subscription you want to cancel.
Stripe.com will cancel the subscription and will send a webhook to your site (if you have set the webhook options correctly).
 Then the stripe_civicrm extension will process the webhook and cancel the Civi recurring contribution.

GOOD TO KNOW
------------
* The stripe-php package has been added to this project & no longer needs to be  
downloaded separately.  
* You do not need the separate civicrm_stripe CMS module for 4.2 & up  
* There will no longer be branches for each version.  The branches will be:  
  * Civi's major.minor-dev, and we will create releases (tags) for each new release version.  
    * Example: 4.6-dev.  

AUTHOR INFO
-----------
Joshua Walker  
http://drastikbydesign.com  
https://drupal.org/user/433663  

MAINTAINER INFO
---------------
Peter Hartmann
https://blog.hartmanncomputer.com

OTHER CREDITS
-------------
For bug fixes, new features, and documentiation, thanks to:
rgburton, Swingline0, BorislavZlatanov, agh1, & jmcclelland
