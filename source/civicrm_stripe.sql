INSERT INTO `civicrm_payment_processor_type` (`id`, `name`, `title`, `description`, `is_active`, `is_default`, `user_name_label`, `password_label`, `signature_label`, `subject_label`, `class_name`, `url_site_default`, `url_api_default`, `url_recur_default`, `url_button_default`, `url_site_test_default`, `url_api_test_default`, `url_recur_test_default`, `url_button_test_default`, `billing_mode`, `is_recur`, `payment_type`) VALUES
('', 'Stripe', 'Stripe', NULL, 1, NULL, 'Secret Key', 'Publishable Key', NULL, NULL, 'Payment_Stripe', 'https://api.stripe.com/v1', NULL, 'https://api.stripe.com/v1', NULL, 'https://api.stripe.com/v1', NULL, 'https://api.stripe.com/v1', NULL, 1, 1, 1);


--
-- Table structure for table `civicrm_stripe_customers`
--

CREATE TABLE IF NOT EXISTS `civicrm_stripe_customers` (
  `email` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `id` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  UNIQUE KEY `email` (`email`)
) DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;


--
-- Table structure for table `civicrm_stripe_plans`
--

CREATE TABLE IF NOT EXISTS `civicrm_stripe_plans` (
  `plan_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  UNIQUE KEY `plan_id` (`plan_id`)
) DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;


--
-- Table structure for table `civicrm_stripe_subscriptions`
--

CREATE TABLE IF NOT EXISTS `civicrm_stripe_subscriptions` (
  `customer_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `invoice_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `end_time` int(11) NOT NULL DEFAULT '0',
  KEY `end_time` (`end_time`)
) DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
