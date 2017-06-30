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

API
------------
This extension comes with several APIs to help you troubleshoot problems. These can be run via /civicrm/api or via drush if you are using Drupal (drush cvapi Stripe.XXX).

The api commands are:

 * Listevents: Events are the notifications that Stripe sends to the Webhook. Listevents will list all notifications that have been sent. You can further restrict them with the following parameters:
  * ppid - Use the given Payment Processor ID. By default, uses the saved, live Stripe payment processor and throws an error if there is more than one.
  * type - Limit to the given Stripe events type. By default, show all. Optinally limit to, for example, invoice.payment_succeeded.
  * limit - Limit number of results returned (100 is max, 10 is default).
  * starting_after - Only return results after this event id. This can be used for paging purposes - if you want to retreive more than 100 results.
 * Populatelog: If you are running a version of CiviCRM that supports the SystemLog - then this API call will populate your SystemLog with all of your past Stripe Events. You can safely re-run and not create duplicates. With a populated SystemLog - you can selectively replay events that may have caused errors the first time or otherwise not been properly recorded. Parameters:
  * ppid - Use the given Payment Processor ID. By default, uses the saved, live Stripe payment processor and throws an error if there is more than one.
 * Ipn: Replay a given Stripe Event. Parameters. This will always fetch the chosen Event from Stripe before replaying.
  * id - The id from the SystemLog of the event to replay.
  * evtid - The Event ID as provided by Stripe.
  * ppid - Use the given Payment Processor ID. By default, uses the saved, live Stripe payment processor and throws an error if there is more than one.

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
