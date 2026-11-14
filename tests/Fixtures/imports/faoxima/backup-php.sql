-- Database: `faoxima_bot`
-- Generated at: 2026-11-10T14:00:02+03:30

SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `admin`;
CREATE TABLE `admin` (
  `id_admin` varchar(500) NOT NULL,
  `username` varchar(1000) NOT NULL,
  `password` varchar(1000) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `iplogin` varchar(1000) DEFAULT NULL,
  `rule` varchar(500) NOT NULL,
  `last_ticket_seen` int(11) DEFAULT 0,
  `ad_modal_hidden_until` int(11) DEFAULT 0,
  PRIMARY KEY (`id_admin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `admin` (`id_admin`, `username`, `password`, `password_hash`, `iplogin`, `rule`, `last_ticket_seen`, `ad_modal_hidden_until`) VALUES ('1000000001', 'admin', 'f3a9c1d2e7', '$2y$10$abcdefghijklmnopqrstuuFakeHashForFixturesOnly0123456789', NULL, 'administrator', '0', '0');
INSERT INTO `admin` (`id_admin`, `username`, `password`, `password_hash`, `iplogin`, `rule`, `last_ticket_seen`, `ad_modal_hidden_until`) VALUES ('5012345672', 'sara', 'b7e2a4c9d1', NULL, NULL, 'support', '3', '0');

DROP TABLE IF EXISTS `botsaz`;
CREATE TABLE `botsaz` (
  `id` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `id_user` varchar(200) NOT NULL,
  `bot_token` varchar(200) NOT NULL,
  `admin_ids` text NOT NULL,
  `username` varchar(200) NOT NULL,
  `setting` text DEFAULT NULL,
  `hide_panel` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`hide_panel`)),
  `time` varchar(200) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `botsaz` (`id`, `id_user`, `bot_token`, `admin_ids`, `username`, `setting`, `hide_panel`, `time`) VALUES ('1', '5012345672', '7012345678:AAFakeResellerTokenForFixturesOnly', '[\"5012345672\"]', 'sara_vpn_bot', '{\"minpricetime\":4000,\"pricetime\":4000,\"minpricevolume\":4000,\"pricevolume\":4000,\"support_username\":\"@sara_support\",\"Channel_Report\":0,\"cart_info\":\"جهت پرداخت مبلغ را به شماره کارت زیر واریز نمایید\",\"show_product\":true}', '{}', '1405/07/20 12:00:00');

DROP TABLE IF EXISTS `card_number`;
CREATE TABLE `card_number` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cardnumber` varchar(500) NOT NULL,
  `namecard` varchar(1000) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_visible` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cardnumber` (`cardnumber`),
  KEY `idx_card_active` (`is_active`),
  KEY `idx_card_visible` (`is_visible`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `card_number` (`id`, `cardnumber`, `namecard`, `is_active`, `is_visible`, `created_at`) VALUES ('1', '6037997700001119', 'رضا محمدی - بانک ملی', '1', '1', '1730001000');
INSERT INTO `card_number` (`id`, `cardnumber`, `namecard`, `is_active`, `is_visible`, `created_at`) VALUES ('2', '6219861012345675', 'بانک سامان', '0', '1', '1730002000');

DROP TABLE IF EXISTS `category`;
CREATE TABLE `category` (
  `id` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `remark` varchar(500) NOT NULL,
  `position` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

INSERT INTO `category` (`id`, `remark`, `position`) VALUES ('1', 'ماهانه', '1');
INSERT INTO `category` (`id`, `remark`, `position`) VALUES ('2', 'سه ماهه', '2');

DROP TABLE IF EXISTS `channels`;
CREATE TABLE `channels` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `remark` varchar(200) NOT NULL,
  `linkjoin` varchar(200) NOT NULL,
  `link` varchar(200) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `channels` (`id`, `remark`, `linkjoin`, `link`) VALUES ('1', 'کانال اطلاع‌رسانی', 'https://t.me/vpnshop_news', '@vpnshop_news');

DROP TABLE IF EXISTS `Discount`;
CREATE TABLE `Discount` (
  `id` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(2000) DEFAULT NULL,
  `price` varchar(200) DEFAULT NULL,
  `limituse` varchar(200) DEFAULT NULL,
  `limitused` varchar(200) DEFAULT NULL,
  `target_user` varchar(64) DEFAULT NULL,
  `expire_at` varchar(20) DEFAULT NULL,
  `status` varchar(12) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `Discount` (`id`, `code`, `price`, `limituse`, `limitused`, `target_user`, `expire_at`, `status`) VALUES ('1', 'GIFT-WELCOME', '20000', '1', '0', NULL, NULL, 'active');
INSERT INTO `Discount` (`id`, `code`, `price`, `limituse`, `limitused`, `target_user`, `expire_at`, `status`) VALUES ('2', 'GIFT-YALDA', '15000', '100', '64', NULL, '1798761600', 'active');
INSERT INTO `Discount` (`id`, `code`, `price`, `limituse`, `limitused`, `target_user`, `expire_at`, `status`) VALUES ('3', 'GIFT-OLD', '10000', '1', '1', NULL, NULL, 'active');
INSERT INTO `Discount` (`id`, `code`, `price`, `limituse`, `limitused`, `target_user`, `expire_at`, `status`) VALUES ('4', 'GIFT-SARA', '50000', '1', '0', '5012345672', NULL, 'active');

DROP TABLE IF EXISTS `DiscountSell`;
CREATE TABLE `DiscountSell` (
  `id` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `codeDiscount` varchar(1000) NOT NULL,
  `price` varchar(200) NOT NULL,
  `limitDiscount` varchar(500) NOT NULL,
  `agent` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `usefirst` varchar(100) NOT NULL,
  `useuser` varchar(100) NOT NULL,
  `code_product` varchar(100) NOT NULL,
  `code_panel` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `time` varchar(100) NOT NULL,
  `type` varchar(100) NOT NULL,
  `usedDiscount` varchar(500) NOT NULL,
  `section` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `value_type` varchar(10) DEFAULT NULL,
  `target_user` varchar(64) DEFAULT NULL,
  `status` varchar(12) DEFAULT NULL,
  `targeting_mode` varchar(12) DEFAULT NULL,
  `code_category` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `DiscountSell` (`id`, `codeDiscount`, `price`, `limitDiscount`, `agent`, `usefirst`, `useuser`, `code_product`, `code_panel`, `time`, `type`, `usedDiscount`, `section`, `value_type`, `target_user`, `status`, `targeting_mode`, `code_category`) VALUES ('1', 'NOROOZ1405', '20', '200', 'allusers', '0', '1', 'all', '/all', '0', 'buy,extend', '37', 'buy,extend', 'percent', NULL, 'active', 'none', 'all');
INSERT INTO `DiscountSell` (`id`, `codeDiscount`, `price`, `limitDiscount`, `agent`, `usefirst`, `useuser`, `code_product`, `code_panel`, `time`, `type`, `usedDiscount`, `section`, `value_type`, `target_user`, `status`, `targeting_mode`, `code_category`) VALUES ('2', 'FIRST50K', '50000', '0', 'allusers', '1', '1', 'p01a', 'a7f3', '1798761600', 'buy', '12', 'buy', 'amount', NULL, 'active', 'none', 'all');
INSERT INTO `DiscountSell` (`id`, `codeDiscount`, `price`, `limitDiscount`, `agent`, `usefirst`, `useuser`, `code_product`, `code_panel`, `time`, `type`, `usedDiscount`, `section`, `value_type`, `target_user`, `status`, `targeting_mode`, `code_category`) VALUES ('3', 'AGENTFREE', '0', '5', 'n,n2', '0', '0', 'all', 'c5d1', '0', 'all', '5', 'all', 'free', NULL, 'active', 'panel', 'all');
INSERT INTO `DiscountSell` (`id`, `codeDiscount`, `price`, `limitDiscount`, `agent`, `usefirst`, `useuser`, `code_product`, `code_panel`, `time`, `type`, `usedDiscount`, `section`, `value_type`, `target_user`, `status`, `targeting_mode`, `code_category`) VALUES ('4', 'WALLET10', '10', '0', 'allusers', '0', '0', 'all', '/all', '0', 'all', '0', 'charge', 'percent', NULL, 'active', 'none', 'all');

DROP TABLE IF EXISTS `help`;
CREATE TABLE `help` (
  `id` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `name_os` varchar(500) NOT NULL,
  `Media_os` varchar(5000) NOT NULL,
  `type_Media_os` varchar(500) NOT NULL,
  `category` text NOT NULL,
  `Description_os` text NOT NULL,
  `app_title` varchar(255) DEFAULT NULL,
  `app_link` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `invoice`;
CREATE TABLE `invoice` (
  `id_invoice` varchar(200) NOT NULL,
  `id_user` varchar(200) DEFAULT NULL,
  `username` varchar(300) DEFAULT NULL,
  `Service_location` varchar(300) DEFAULT NULL,
  `time_sell` varchar(200) DEFAULT NULL,
  `name_product` varchar(200) DEFAULT NULL,
  `price_product` varchar(200) DEFAULT NULL,
  `Volume` varchar(200) DEFAULT NULL,
  `Volume_unit` varchar(10) NOT NULL DEFAULT 'GB',
  `Service_time` varchar(200) DEFAULT NULL,
  `uuid` text DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  `user_info` text DEFAULT NULL,
  `bottype` varchar(200) DEFAULT NULL,
  `refral` varchar(100) DEFAULT NULL,
  `time_cron` varchar(100) DEFAULT NULL,
  `notifctions` text NOT NULL,
  `Status` varchar(200) DEFAULT NULL,
  `ip_limit` varchar(20) NOT NULL DEFAULT '0',
  `hwid_limit` varchar(20) NOT NULL DEFAULT '0',
  `ip_last_seen` text DEFAULT NULL,
  `symbolic_limit_enabled` varchar(20) NOT NULL DEFAULT '0',
  `symbolic_limit_users` varchar(20) NOT NULL DEFAULT '0',
  `invalidated_at` int(10) unsigned DEFAULT NULL,
  `discount_code` varchar(200) DEFAULT NULL,
  `discount_amount` varchar(200) NOT NULL DEFAULT '0',
  `price_before_discount` varchar(200) DEFAULT NULL,
  `source_panel_code` varchar(191) DEFAULT NULL,
  PRIMARY KEY (`id_invoice`),
  KEY `idx_invoice_discount_code` (`discount_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `invoice` (`id_invoice`, `id_user`, `username`, `Service_location`, `time_sell`, `name_product`, `price_product`, `Volume`, `Volume_unit`, `Service_time`, `uuid`, `note`, `user_info`, `bottype`, `refral`, `time_cron`, `notifctions`, `Status`, `ip_limit`, `hwid_limit`, `ip_last_seen`, `symbolic_limit_enabled`, `symbolic_limit_users`, `invalidated_at`, `discount_code`, `discount_amount`, `price_before_discount`, `source_panel_code`) VALUES ('a1b2c3d4', '5012345671', '5012345671_rz1', 'آلمان-مرزبان', '1730500000', 'یک ماهه ۵۰ گیگ', '180000', '50', 'GB', '30', NULL, 'گوشی خودم', NULL, NULL, '0', NULL, '{\"volume\":false,\"time\":false}', 'active', '0', '0', NULL, '0', '0', NULL, NULL, '0', NULL, '');
INSERT INTO `invoice` (`id_invoice`, `id_user`, `username`, `Service_location`, `time_sell`, `name_product`, `price_product`, `Volume`, `Volume_unit`, `Service_time`, `uuid`, `note`, `user_info`, `bottype`, `refral`, `time_cron`, `notifctions`, `Status`, `ip_limit`, `hwid_limit`, `ip_last_seen`, `symbolic_limit_enabled`, `symbolic_limit_users`, `invalidated_at`, `discount_code`, `discount_amount`, `price_before_discount`, `source_panel_code`) VALUES ('b2c3d4e5', '5012345672', 'sara_x1', 'هلند-ثنایی', '1730500000', 'یک ماهه ۵۰ گیگ (نماینده)', '140000', '50', 'GB', '30', NULL, NULL, NULL, NULL, '0', NULL, '{\"volume\":false,\"time\":false}', 'end_of_time', '0', '0', NULL, '0', '0', NULL, NULL, '0', NULL, NULL);
INSERT INTO `invoice` (`id_invoice`, `id_user`, `username`, `Service_location`, `time_sell`, `name_product`, `price_product`, `Volume`, `Volume_unit`, `Service_time`, `uuid`, `note`, `user_info`, `bottype`, `refral`, `time_cron`, `notifctions`, `Status`, `ip_limit`, `hwid_limit`, `ip_last_seen`, `symbolic_limit_enabled`, `symbolic_limit_users`, `invalidated_at`, `discount_code`, `discount_amount`, `price_before_discount`, `source_panel_code`) VALUES ('c3d4e5f6', '5012345675', 'mina_test', 'PasarGuard-FI', '1730500000', 'سرویس تست', '0', '100', 'MB', '1', NULL, NULL, NULL, NULL, '0', NULL, '{\"volume\":false,\"time\":false}', 'active', '0', '0', NULL, '0', '0', NULL, NULL, '0', NULL, NULL);
INSERT INTO `invoice` (`id_invoice`, `id_user`, `username`, `Service_location`, `time_sell`, `name_product`, `price_product`, `Volume`, `Volume_unit`, `Service_time`, `uuid`, `note`, `user_info`, `bottype`, `refral`, `time_cron`, `notifctions`, `Status`, `ip_limit`, `hwid_limit`, `ip_last_seen`, `symbolic_limit_enabled`, `symbolic_limit_users`, `invalidated_at`, `discount_code`, `discount_amount`, `price_before_discount`, `source_panel_code`) VALUES ('d4e5f6a7', '5012345671', 'rz_stock', 'آلمان-مرزبان', '1730500000', 'یک ماهه ۵۰ گیگ', '180000', '50', 'GB', '30', NULL, NULL, 'vless://11111111-2222-3333-4444-555555555555@ir.vpnshop.example:443?type=tcp#rz_stock\nhttps://ir.vpnshop.example/sub/rz_stock', NULL, '0', NULL, '{\"volume\":false,\"time\":false}', 'active', '0', '0', NULL, '0', '0', NULL, NULL, '0', NULL, 'a7f3');
INSERT INTO `invoice` (`id_invoice`, `id_user`, `username`, `Service_location`, `time_sell`, `name_product`, `price_product`, `Volume`, `Volume_unit`, `Service_time`, `uuid`, `note`, `user_info`, `bottype`, `refral`, `time_cron`, `notifctions`, `Status`, `ip_limit`, `hwid_limit`, `ip_last_seen`, `symbolic_limit_enabled`, `symbolic_limit_users`, `invalidated_at`, `discount_code`, `discount_amount`, `price_before_discount`, `source_panel_code`) VALUES ('e5f6a7b8', '5012345671', 'rz_old', 'آلمان-مرزبان', '1730500000', 'یک ماهه ۵۰ گیگ', '180000', '50', 'GB', '30', NULL, NULL, NULL, NULL, '0', NULL, '{\"volume\":false,\"time\":false}', 'removebyuser', '0', '0', NULL, '0', '0', NULL, NULL, '0', NULL, NULL);
INSERT INTO `invoice` (`id_invoice`, `id_user`, `username`, `Service_location`, `time_sell`, `name_product`, `price_product`, `Volume`, `Volume_unit`, `Service_time`, `uuid`, `note`, `user_info`, `bottype`, `refral`, `time_cron`, `notifctions`, `Status`, `ip_limit`, `hwid_limit`, `ip_last_seen`, `symbolic_limit_enabled`, `symbolic_limit_users`, `invalidated_at`, `discount_code`, `discount_amount`, `price_before_discount`, `source_panel_code`) VALUES ('f6a7b8c9', '5012345673', 'agent2_9', 'PasarGuard-FI', '1730500000', 'نامحدود یک ماهه', '350000', '0', 'GB', '30', NULL, 'کار \"دفتر\"', NULL, NULL, '0', NULL, '{\"volume\":false,\"time\":false}', 'sendedwarn', '0', '0', NULL, '0', '0', NULL, NULL, '0', NULL, NULL);
INSERT INTO `invoice` (`id_invoice`, `id_user`, `username`, `Service_location`, `time_sell`, `name_product`, `price_product`, `Volume`, `Volume_unit`, `Service_time`, `uuid`, `note`, `user_info`, `bottype`, `refral`, `time_cron`, `notifctions`, `Status`, `ip_limit`, `hwid_limit`, `ip_last_seen`, `symbolic_limit_enabled`, `symbolic_limit_users`, `invalidated_at`, `discount_code`, `discount_amount`, `price_before_discount`, `source_panel_code`) VALUES ('a7b8c9d0', '5012345676', 'res_1', 'هلند-ثنایی', '1730500000', 'سه ماهه ۱۵۰ گیگ', '450000', '150', 'GB', '90', NULL, NULL, NULL, '7012345678:AAFakeResellerTokenForFixturesOnly', '0', NULL, '{\"volume\":false,\"time\":false}', 'active', '0', '0', NULL, '0', '0', NULL, NULL, '0', NULL, NULL);
INSERT INTO `invoice` (`id_invoice`, `id_user`, `username`, `Service_location`, `time_sell`, `name_product`, `price_product`, `Volume`, `Volume_unit`, `Service_time`, `uuid`, `note`, `user_info`, `bottype`, `refral`, `time_cron`, `notifctions`, `Status`, `ip_limit`, `hwid_limit`, `ip_last_seen`, `symbolic_limit_enabled`, `symbolic_limit_users`, `invalidated_at`, `discount_code`, `discount_amount`, `price_before_discount`, `source_panel_code`) VALUES ('b8c9d0e1', '5012345671', 'rz_uk', 'Rebecca-UK', '1730500000', 'یک ماهه ۵۰ گیگ', '180000', '50', 'GB', '30', NULL, NULL, NULL, NULL, '0', NULL, '{\"volume\":false,\"time\":false}', 'active', '0', '0', NULL, '0', '0', NULL, NULL, '0', NULL, NULL);
INSERT INTO `invoice` (`id_invoice`, `id_user`, `username`, `Service_location`, `time_sell`, `name_product`, `price_product`, `Volume`, `Volume_unit`, `Service_time`, `uuid`, `note`, `user_info`, `bottype`, `refral`, `time_cron`, `notifctions`, `Status`, `ip_limit`, `hwid_limit`, `ip_last_seen`, `symbolic_limit_enabled`, `symbolic_limit_users`, `invalidated_at`, `discount_code`, `discount_amount`, `price_before_discount`, `source_panel_code`) VALUES ('c9d0e1f2', '5012345672', 'manual_cfg', 'فروش دستی', '1730500000', 'یک ماهه ۵۰ گیگ', '180000', '50', 'GB', '30', NULL, NULL, 'vmess://eyJhZGQiOiJtYW51YWwuZXhhbXBsZSJ9', NULL, '0', NULL, '{\"volume\":false,\"time\":false}', 'active', '0', '0', NULL, '0', '0', NULL, NULL, '0', NULL, NULL);
INSERT INTO `invoice` (`id_invoice`, `id_user`, `username`, `Service_location`, `time_sell`, `name_product`, `price_product`, `Volume`, `Volume_unit`, `Service_time`, `uuid`, `note`, `user_info`, `bottype`, `refral`, `time_cron`, `notifctions`, `Status`, `ip_limit`, `hwid_limit`, `ip_last_seen`, `symbolic_limit_enabled`, `symbolic_limit_users`, `invalidated_at`, `discount_code`, `discount_amount`, `price_before_discount`, `source_panel_code`) VALUES ('d0e1f2a3', '5012345671', 'rz_unpaid', 'آلمان-مرزبان', '1730500000', 'سه ماهه ۱۵۰ گیگ', '180000', '50', 'GB', '30', NULL, NULL, NULL, NULL, '0', NULL, '{\"volume\":false,\"time\":false}', 'unpaid', '0', '0', NULL, '0', '0', NULL, NULL, '0', NULL, NULL);

DROP TABLE IF EXISTS `marzban_panel`;
CREATE TABLE `marzban_panel` (
  `id` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `position` int(11) NOT NULL DEFAULT 0,
  `code_panel` varchar(200) DEFAULT NULL,
  `name_panel` varchar(2000) DEFAULT NULL,
  `status` varchar(500) DEFAULT NULL,
  `url_panel` varchar(2000) DEFAULT NULL,
  `username_panel` varchar(200) DEFAULT NULL,
  `password_panel` varchar(200) DEFAULT NULL,
  `api_key` varchar(500) DEFAULT NULL,
  `pasarguard_auth_mode` varchar(20) NOT NULL DEFAULT 'api_key',
  `xui_api_token` text DEFAULT NULL,
  `xui_api_mode` varchar(20) NOT NULL DEFAULT 'legacy',
  `xui_monitor_state` text DEFAULT NULL,
  `ip_limit_guard` varchar(20) DEFAULT 'offipguard',
  `agent` varchar(200) DEFAULT NULL,
  `sublink` varchar(500) DEFAULT NULL,
  `config` varchar(500) DEFAULT NULL,
  `MethodUsername` varchar(700) DEFAULT NULL,
  `TestAccount` varchar(100) DEFAULT NULL,
  `limit_panel` varchar(100) DEFAULT NULL,
  `namecustom` varchar(100) DEFAULT NULL,
  `Methodextend` varchar(100) DEFAULT NULL,
  `conecton` varchar(100) DEFAULT NULL,
  `linksubx` varchar(1000) DEFAULT NULL,
  `inboundid` varchar(100) DEFAULT NULL,
  `type` varchar(100) DEFAULT NULL,
  `inboundstatus` varchar(100) DEFAULT NULL,
  `hosts` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`hosts`)),
  `inbound_deactive` varchar(100) DEFAULT NULL,
  `time_usertest` varchar(100) DEFAULT NULL,
  `val_usertest` varchar(100) DEFAULT NULL,
  `secret_code` varchar(200) DEFAULT NULL,
  `priceChangeloc` varchar(200) DEFAULT NULL,
  `priceextravolume` text DEFAULT NULL,
  `pricecustomvolume` text DEFAULT NULL,
  `pricecustomtime` text DEFAULT NULL,
  `priceextratime` text DEFAULT NULL,
  `mainvolume` text DEFAULT NULL,
  `maxvolume` text DEFAULT NULL,
  `maintime` text DEFAULT NULL,
  `maxtime` text DEFAULT NULL,
  `status_extend` varchar(100) DEFAULT NULL,
  `datelogin` text DEFAULT NULL,
  `proxies` text DEFAULT NULL,
  `inbounds` text DEFAULT NULL,
  `subvip` varchar(60) DEFAULT NULL,
  `changeloc` varchar(60) DEFAULT NULL,
  `on_hold_test` varchar(60) NOT NULL,
  `version_panel` varchar(60) NOT NULL DEFAULT '0',
  `customvolume` text DEFAULT NULL,
  `hide_user` text DEFAULT NULL,
  `guard_service_ids` text DEFAULT NULL,
  `guard_note` text DEFAULT NULL,
  `guard_auto_delete_days` int(11) DEFAULT NULL,
  `guard_auto_renewals` text DEFAULT NULL,
  `remna_api_token` text DEFAULT NULL,
  `remna_squad` varchar(200) DEFAULT NULL,
  `shop_features` text DEFAULT NULL,
  `remna_revoke` varchar(20) DEFAULT 'off',
  `remna_server_picker` varchar(20) DEFAULT 'off',
  `remna_usage_btn` varchar(20) DEFAULT 'on',
  `rebecca_service_id` varchar(100) DEFAULT NULL,
  `guard_version` varchar(10) DEFAULT NULL,
  `test_settings` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `marzban_panel` (`id`, `position`, `code_panel`, `name_panel`, `status`, `url_panel`, `username_panel`, `password_panel`, `api_key`, `pasarguard_auth_mode`, `xui_api_token`, `xui_api_mode`, `xui_monitor_state`, `ip_limit_guard`, `agent`, `sublink`, `config`, `MethodUsername`, `TestAccount`, `limit_panel`, `namecustom`, `Methodextend`, `conecton`, `linksubx`, `inboundid`, `type`, `inboundstatus`, `hosts`, `inbound_deactive`, `time_usertest`, `val_usertest`, `secret_code`, `priceChangeloc`, `priceextravolume`, `pricecustomvolume`, `pricecustomtime`, `priceextratime`, `mainvolume`, `maxvolume`, `maintime`, `maxtime`, `status_extend`, `datelogin`, `proxies`, `inbounds`, `subvip`, `changeloc`, `on_hold_test`, `version_panel`, `customvolume`, `hide_user`, `guard_service_ids`, `guard_note`, `guard_auto_delete_days`, `guard_auto_renewals`, `remna_api_token`, `remna_squad`, `shop_features`, `remna_revoke`, `remna_server_picker`, `remna_usage_btn`, `rebecca_service_id`, `guard_version`, `test_settings`) VALUES ('1', '1', 'a7f3', 'آلمان-مرزبان', 'active', 'https://de.vpnshop.example:8000', 'admin', 'Mz!pass 2024', NULL, 'api_key', NULL, 'legacy', NULL, 'offipguard', 'all', 'onsublink', 'offconfig', 'آیدی عددی + حروف و عدد رندوم', 'ONTestAccount', 'unlimted', 'none', 'ریست حجم و زمان', 'offconecton', 'https://de.vpnshop.example:8000', '1', 'marzban', 'offinbounddisable', NULL, '1', '1', '100', NULL, '0', '{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}', '{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}', '{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}', '{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}', '{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}', '{\"f\":\"1000\",\"n\":\"1000\",\"n2\":\"1000\"}', '{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}', '{\"f\":\"1000\",\"n\":\"1000\",\"n2\":\"1000\"}', 'on_extend', '{\"access_token\":\"fake.jwt.fixture\",\"time\":1730400000}', '{\"vless\":{\"flow\":\"\"}}', '{\"vless\":[\"VLESS TCP REALITY\"]}', 'offsubvip', 'offchangeloc', '1', '0', '{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}', NULL, NULL, NULL, '0', NULL, NULL, NULL, '{\"categorygeneral\":\"off\"}', 'off', 'off', 'on', NULL, NULL, NULL);
INSERT INTO `marzban_panel` (`id`, `position`, `code_panel`, `name_panel`, `status`, `url_panel`, `username_panel`, `password_panel`, `api_key`, `pasarguard_auth_mode`, `xui_api_token`, `xui_api_mode`, `xui_monitor_state`, `ip_limit_guard`, `agent`, `sublink`, `config`, `MethodUsername`, `TestAccount`, `limit_panel`, `namecustom`, `Methodextend`, `conecton`, `linksubx`, `inboundid`, `type`, `inboundstatus`, `hosts`, `inbound_deactive`, `time_usertest`, `val_usertest`, `secret_code`, `priceChangeloc`, `priceextravolume`, `pricecustomvolume`, `pricecustomtime`, `priceextratime`, `mainvolume`, `maxvolume`, `maintime`, `maxtime`, `status_extend`, `datelogin`, `proxies`, `inbounds`, `subvip`, `changeloc`, `on_hold_test`, `version_panel`, `customvolume`, `hide_user`, `guard_service_ids`, `guard_note`, `guard_auto_delete_days`, `guard_auto_renewals`, `remna_api_token`, `remna_squad`, `shop_features`, `remna_revoke`, `remna_server_picker`, `remna_usage_btn`, `rebecca_service_id`, `guard_version`, `test_settings`) VALUES ('2', '2', 'b2c9', 'هلند-ثنایی', 'active', 'https://nl.vpnshop.example:2053/Xk9pQ', 'null', 'null', NULL, 'api_key', 'xui-7c1e9a2b-token', 'token', NULL, 'offipguard', 'all', 'onsublink', 'offconfig', 'آیدی عددی + حروف و عدد رندوم', 'ONTestAccount', 'unlimted', 'none', 'ریست حجم و زمان', 'offconecton', 'https://nl.vpnshop.example:2096/sub', '3', 'x-ui_single', 'offinbounddisable', NULL, '1', '1', '100', NULL, '0', '{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}', '{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}', '{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}', '{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}', '{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}', '{\"f\":\"1000\",\"n\":\"1000\",\"n2\":\"1000\"}', '{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}', '{\"f\":\"1000\",\"n\":\"1000\",\"n2\":\"1000\"}', 'on_extend', NULL, NULL, NULL, 'onsubvip', 'offchangeloc', '1', '0', '{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}', NULL, NULL, NULL, '0', NULL, NULL, NULL, '{\"categorygeneral\":\"off\"}', 'off', 'off', 'on', NULL, NULL, NULL);
INSERT INTO `marzban_panel` (`id`, `position`, `code_panel`, `name_panel`, `status`, `url_panel`, `username_panel`, `password_panel`, `api_key`, `pasarguard_auth_mode`, `xui_api_token`, `xui_api_mode`, `xui_monitor_state`, `ip_limit_guard`, `agent`, `sublink`, `config`, `MethodUsername`, `TestAccount`, `limit_panel`, `namecustom`, `Methodextend`, `conecton`, `linksubx`, `inboundid`, `type`, `inboundstatus`, `hosts`, `inbound_deactive`, `time_usertest`, `val_usertest`, `secret_code`, `priceChangeloc`, `priceextravolume`, `pricecustomvolume`, `pricecustomtime`, `priceextratime`, `mainvolume`, `maxvolume`, `maintime`, `maxtime`, `status_extend`, `datelogin`, `proxies`, `inbounds`, `subvip`, `changeloc`, `on_hold_test`, `version_panel`, `customvolume`, `hide_user`, `guard_service_ids`, `guard_note`, `guard_auto_delete_days`, `guard_auto_renewals`, `remna_api_token`, `remna_squad`, `shop_features`, `remna_revoke`, `remna_server_picker`, `remna_usage_btn`, `rebecca_service_id`, `guard_version`, `test_settings`) VALUES ('3', '3', 'c5d1', 'PasarGuard-FI', 'active', 'https://fi.vpnshop.example', 'null', 'null', 'pg_key_0b5c2d6e-1f2a-4b3c-8d4e-5f6a7b8c9d0e', 'api_key', NULL, 'legacy', NULL, 'offipguard', 'all', 'onsublink', 'offconfig', 'آیدی عددی + حروف و عدد رندوم', 'ONTestAccount', 'unlimted', 'none', 'ریست حجم و زمان', 'offconecton', 'https://fi.vpnshop.example', '1', 'pasarguard', 'offinbounddisable', NULL, '1', '1', '100', NULL, '0', '{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}', '{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}', '{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}', '{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}', '{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}', '{\"f\":\"1000\",\"n\":\"1000\",\"n2\":\"1000\"}', '{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}', '{\"f\":\"1000\",\"n\":\"1000\",\"n2\":\"1000\"}', 'on_extend', NULL, NULL, NULL, 'offsubvip', 'offchangeloc', '1', '0', '{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}', NULL, NULL, NULL, '0', NULL, NULL, NULL, '{\"categorygeneral\":\"off\"}', 'off', 'off', 'on', NULL, NULL, NULL);
INSERT INTO `marzban_panel` (`id`, `position`, `code_panel`, `name_panel`, `status`, `url_panel`, `username_panel`, `password_panel`, `api_key`, `pasarguard_auth_mode`, `xui_api_token`, `xui_api_mode`, `xui_monitor_state`, `ip_limit_guard`, `agent`, `sublink`, `config`, `MethodUsername`, `TestAccount`, `limit_panel`, `namecustom`, `Methodextend`, `conecton`, `linksubx`, `inboundid`, `type`, `inboundstatus`, `hosts`, `inbound_deactive`, `time_usertest`, `val_usertest`, `secret_code`, `priceChangeloc`, `priceextravolume`, `pricecustomvolume`, `pricecustomtime`, `priceextratime`, `mainvolume`, `maxvolume`, `maintime`, `maxtime`, `status_extend`, `datelogin`, `proxies`, `inbounds`, `subvip`, `changeloc`, `on_hold_test`, `version_panel`, `customvolume`, `hide_user`, `guard_service_ids`, `guard_note`, `guard_auto_delete_days`, `guard_auto_renewals`, `remna_api_token`, `remna_squad`, `shop_features`, `remna_revoke`, `remna_server_picker`, `remna_usage_btn`, `rebecca_service_id`, `guard_version`, `test_settings`) VALUES ('4', '4', 'd8e2', 'Rebecca-UK', 'active', 'https://uk.vpnshop.example', 'null', 'null', 'rbc_9f8e7d6c5b4a3210', 'api_key', NULL, 'legacy', NULL, 'offipguard', 'all', 'onsublink', 'offconfig', 'آیدی عددی + حروف و عدد رندوم', 'ONTestAccount', 'unlimted', 'none', 'ریست حجم و زمان', 'offconecton', 'https://uk.vpnshop.example', '1', 'rebecca', 'offinbounddisable', NULL, '1', '1', '100', NULL, '0', '{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}', '{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}', '{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}', '{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}', '{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}', '{\"f\":\"1000\",\"n\":\"1000\",\"n2\":\"1000\"}', '{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}', '{\"f\":\"1000\",\"n\":\"1000\",\"n2\":\"1000\"}', 'on_extend', NULL, NULL, NULL, 'offsubvip', 'offchangeloc', '1', '0', '{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}', NULL, NULL, NULL, '0', NULL, NULL, NULL, '{\"categorygeneral\":\"off\"}', 'off', 'off', 'on', 'auto', NULL, NULL);
INSERT INTO `marzban_panel` (`id`, `position`, `code_panel`, `name_panel`, `status`, `url_panel`, `username_panel`, `password_panel`, `api_key`, `pasarguard_auth_mode`, `xui_api_token`, `xui_api_mode`, `xui_monitor_state`, `ip_limit_guard`, `agent`, `sublink`, `config`, `MethodUsername`, `TestAccount`, `limit_panel`, `namecustom`, `Methodextend`, `conecton`, `linksubx`, `inboundid`, `type`, `inboundstatus`, `hosts`, `inbound_deactive`, `time_usertest`, `val_usertest`, `secret_code`, `priceChangeloc`, `priceextravolume`, `pricecustomvolume`, `pricecustomtime`, `priceextratime`, `mainvolume`, `maxvolume`, `maintime`, `maxtime`, `status_extend`, `datelogin`, `proxies`, `inbounds`, `subvip`, `changeloc`, `on_hold_test`, `version_panel`, `customvolume`, `hide_user`, `guard_service_ids`, `guard_note`, `guard_auto_delete_days`, `guard_auto_renewals`, `remna_api_token`, `remna_squad`, `shop_features`, `remna_revoke`, `remna_server_picker`, `remna_usage_btn`, `rebecca_service_id`, `guard_version`, `test_settings`) VALUES ('5', '5', 'e4f6', 'فروش دستی', 'active', 'null', 'null', 'null', NULL, 'api_key', NULL, 'legacy', NULL, 'offipguard', 'all', 'onsublink', 'offconfig', 'آیدی عددی + حروف و عدد رندوم', 'ONTestAccount', '50', 'none', 'ریست حجم و زمان', 'offconecton', 'null', '1', 'Manualsale', 'offinbounddisable', NULL, '1', '1', '100', NULL, '0', '{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}', '{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}', '{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}', '{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}', '{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}', '{\"f\":\"1000\",\"n\":\"1000\",\"n2\":\"1000\"}', '{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}', '{\"f\":\"1000\",\"n\":\"1000\",\"n2\":\"1000\"}', 'on_extend', NULL, NULL, NULL, 'offsubvip', 'offchangeloc', '1', '0', '{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}', NULL, NULL, NULL, '0', NULL, NULL, NULL, '{\"categorygeneral\":\"off\"}', 'off', 'off', 'on', NULL, NULL, NULL);

DROP TABLE IF EXISTS `Payment_report`;
CREATE TABLE `Payment_report` (
  `id` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `id_user` varchar(200) DEFAULT NULL,
  `id_order` varchar(2000) DEFAULT NULL,
  `time` varchar(200) DEFAULT NULL,
  `at_updated` varchar(200) DEFAULT NULL,
  `price` varchar(200) DEFAULT NULL,
  `dec_not_confirmed` text DEFAULT NULL,
  `Payment_Method` varchar(400) DEFAULT NULL,
  `payment_Status` varchar(100) DEFAULT NULL,
  `bottype` varchar(300) DEFAULT NULL,
  `message_id` int(11) DEFAULT NULL,
  `id_invoice` varchar(1000) DEFAULT NULL,
  `card_photo_file_id` varchar(500) DEFAULT NULL,
  `card_last4` varchar(4) DEFAULT NULL,
  `report_chat_id` varchar(50) DEFAULT NULL,
  `report_message_id` int(11) DEFAULT NULL,
  `report_thread_id` int(11) DEFAULT NULL,
  `private_receipt_targets` text DEFAULT NULL,
  `direct_payment_done` tinyint(1) DEFAULT NULL,
  `charge_bonus` varchar(20) DEFAULT NULL,
  `discount_code` varchar(200) DEFAULT NULL,
  `discount_amount` varchar(200) DEFAULT NULL,
  `price_before_discount` varchar(200) DEFAULT NULL,
  `discount_consumed` varchar(1) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `Payment_report` (`id`, `id_user`, `id_order`, `time`, `at_updated`, `price`, `dec_not_confirmed`, `Payment_Method`, `payment_Status`, `bottype`, `message_id`, `id_invoice`, `card_photo_file_id`, `card_last4`, `report_chat_id`, `report_message_id`, `report_thread_id`, `private_receipt_targets`, `direct_payment_done`, `charge_bonus`, `discount_code`, `discount_amount`, `price_before_discount`, `discount_consumed`) VALUES ('1', '5012345671', 'ord_7f3a', '1404/08/10 18:20:11', NULL, '180000', NULL, 'cart to cart', 'paid', NULL, '812', 'a1b2c3d4', NULL, '1119', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO `Payment_report` (`id`, `id_user`, `id_order`, `time`, `at_updated`, `price`, `dec_not_confirmed`, `Payment_Method`, `payment_Status`, `bottype`, `message_id`, `id_invoice`, `card_photo_file_id`, `card_last4`, `report_chat_id`, `report_message_id`, `report_thread_id`, `private_receipt_targets`, `direct_payment_done`, `charge_bonus`, `discount_code`, `discount_amount`, `price_before_discount`, `discount_consumed`) VALUES ('2', '5012345672', 'ord_8c2d', '1404/08/11 09:02:45', NULL, '500000', NULL, 'cart to cart', 'paid', NULL, NULL, '0 | 500000', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO `Payment_report` (`id`, `id_user`, `id_order`, `time`, `at_updated`, `price`, `dec_not_confirmed`, `Payment_Method`, `payment_Status`, `bottype`, `message_id`, `id_invoice`, `card_photo_file_id`, `card_last4`, `report_chat_id`, `report_message_id`, `report_thread_id`, `private_receipt_targets`, `direct_payment_done`, `charge_bonus`, `discount_code`, `discount_amount`, `price_before_discount`, `discount_consumed`) VALUES ('3', '5012345675', 'ord_9e1b', '1404/08/12 21:15:00', NULL, '180000', 'رسید ناخوانا بود', 'cart to cart', 'reject', NULL, NULL, 'none', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

DROP TABLE IF EXISTS `product`;
CREATE TABLE `product` (
  `id` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `code_product` varchar(200) DEFAULT NULL,
  `name_product` varchar(2000) DEFAULT NULL,
  `price_product` varchar(2000) DEFAULT NULL,
  `Volume_constraint` varchar(2000) DEFAULT NULL,
  `Location` varchar(500) DEFAULT NULL,
  `Service_time` varchar(200) DEFAULT NULL,
  `agent` varchar(100) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `data_limit_reset` varchar(200) DEFAULT NULL,
  `one_buy_status` varchar(20) NOT NULL,
  `inbounds` text DEFAULT NULL,
  `proxies` text DEFAULT NULL,
  `category` varchar(500) DEFAULT NULL,
  `position` int(11) NOT NULL DEFAULT 0,
  `hide_panel` text NOT NULL,
  `ip_limit` varchar(20) NOT NULL DEFAULT '0',
  `hwid_limit` varchar(20) NOT NULL DEFAULT '0',
  `symbolic_limit_enabled` varchar(20) NOT NULL DEFAULT '0',
  `symbolic_limit_users` varchar(20) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `product` (`id`, `code_product`, `name_product`, `price_product`, `Volume_constraint`, `Location`, `Service_time`, `agent`, `note`, `data_limit_reset`, `one_buy_status`, `inbounds`, `proxies`, `category`, `position`, `hide_panel`, `ip_limit`, `hwid_limit`, `symbolic_limit_enabled`, `symbolic_limit_users`) VALUES ('1', 'p01a', 'یک ماهه ۵۰ گیگ', '180000', '50', '/all', '30', 'f', 'مناسب استفاده روزمره', 'no_reset', '0', NULL, NULL, 'ماهانه', '1', '{}', '2', '0', '0', '0');
INSERT INTO `product` (`id`, `code_product`, `name_product`, `price_product`, `Volume_constraint`, `Location`, `Service_time`, `agent`, `note`, `data_limit_reset`, `one_buy_status`, `inbounds`, `proxies`, `category`, `position`, `hide_panel`, `ip_limit`, `hwid_limit`, `symbolic_limit_enabled`, `symbolic_limit_users`) VALUES ('2', 'p01n', 'یک ماهه ۵۰ گیگ (نماینده)', '140000', '50', '/all', '30', 'n', '', 'no_reset', '0', NULL, NULL, 'ماهانه', '2', '{}', '0', '0', '0', '0');
INSERT INTO `product` (`id`, `code_product`, `name_product`, `price_product`, `Volume_constraint`, `Location`, `Service_time`, `agent`, `note`, `data_limit_reset`, `one_buy_status`, `inbounds`, `proxies`, `category`, `position`, `hide_panel`, `ip_limit`, `hwid_limit`, `symbolic_limit_enabled`, `symbolic_limit_users`) VALUES ('3', 'p01v', 'یک ماهه ۵۰ گیگ (پیشرفته)', '130000', '50', '/all', '30', 'n2', '', 'no_reset', '0', NULL, NULL, 'ماهانه', '3', '{}', '0', '0', '0', '0');
INSERT INTO `product` (`id`, `code_product`, `name_product`, `price_product`, `Volume_constraint`, `Location`, `Service_time`, `agent`, `note`, `data_limit_reset`, `one_buy_status`, `inbounds`, `proxies`, `category`, `position`, `hide_panel`, `ip_limit`, `hwid_limit`, `symbolic_limit_enabled`, `symbolic_limit_users`) VALUES ('4', 'p03a', 'سه ماهه ۱۵۰ گیگ', '450000', '150', 'آلمان-مرزبان,هلند-ثنایی', '90', 'all', '', 'no_reset', '0', NULL, NULL, 'سه ماهه', '4', '{}', '0', '0', '0', '0');
INSERT INTO `product` (`id`, `code_product`, `name_product`, `price_product`, `Volume_constraint`, `Location`, `Service_time`, `agent`, `note`, `data_limit_reset`, `one_buy_status`, `inbounds`, `proxies`, `category`, `position`, `hide_panel`, `ip_limit`, `hwid_limit`, `symbolic_limit_enabled`, `symbolic_limit_users`) VALUES ('5', 'p00u', 'نامحدود یک ماهه', '350000', '0', 'PasarGuard-FI', '30', 'f', 'بدون محدودیت حجم\nفقط یک کاربر \"همزمان\" - مناسب \'خانواده\'', 'no_reset', '0', NULL, NULL, 'ماهانه', '5', '{}', '1', '0', '0', '0');
INSERT INTO `product` (`id`, `code_product`, `name_product`, `price_product`, `Volume_constraint`, `Location`, `Service_time`, `agent`, `note`, `data_limit_reset`, `one_buy_status`, `inbounds`, `proxies`, `category`, `position`, `hide_panel`, `ip_limit`, `hwid_limit`, `symbolic_limit_enabled`, `symbolic_limit_users`) VALUES ('6', 'pagt', 'پلن ویژه همکاران', '90000', '40', 'PasarGuard-FI', '30', 'n,n2', '', 'no_reset', '0', NULL, NULL, 'ماهانه', '6', '{}', '0', '0', '0', '0');

DROP TABLE IF EXISTS `service_other`;
CREATE TABLE `service_other` (
  `id` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `id_user` varchar(500) NOT NULL,
  `username` varchar(1000) NOT NULL,
  `value` varchar(1000) NOT NULL,
  `time` varchar(200) NOT NULL,
  `price` varchar(200) NOT NULL,
  `type` varchar(1000) NOT NULL,
  `status` varchar(200) NOT NULL,
  `output` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `service_other` (`id`, `id_user`, `username`, `value`, `time`, `price`, `type`, `status`, `output`) VALUES ('1', '5012345671', '5012345671_rz1', '{\"volumebuy\":\"50\",\"Service_time\":\"30\",\"oldvolume\":53687091200,\"oldtime\":1733000000,\"code_product\":\"p01a\"}', '1404/08/10 18:25:00', '180000', 'extend_user', 'paid', '{\"status\":true}');
INSERT INTO `service_other` (`id`, `id_user`, `username`, `value`, `time`, `price`, `type`, `status`, `output`) VALUES ('2', '5012345672', 'sara_x1', '10', '1404/08/11 09:10:00', '40000', 'extra_user', 'paid', '{\"status\":true}');

DROP TABLE IF EXISTS `setting`;
CREATE TABLE `setting` (
  `Bot_Status` varchar(200) DEFAULT NULL,
  `roll_Status` varchar(200) DEFAULT NULL,
  `get_number` varchar(200) DEFAULT NULL,
  `iran_number` varchar(200) DEFAULT NULL,
  `NotUser` varchar(200) DEFAULT NULL,
  `Channel_Report` varchar(600) DEFAULT NULL,
  `limit_usertest_all` varchar(600) DEFAULT NULL,
  `affiliatesstatus` varchar(600) DEFAULT NULL,
  `affiliatespercentage` varchar(600) DEFAULT NULL,
  `showcard` varchar(200) DEFAULT NULL,
  `verifystart` varchar(200) DEFAULT NULL,
  `id_support` varchar(200) DEFAULT NULL,
  `Lottery_prize` text DEFAULT NULL,
  `wheelـluck` varchar(45) DEFAULT NULL,
  `wheelـluck_price` varchar(45) DEFAULT NULL,
  `keyboardmain` text NOT NULL,
  `statusnoteforf` varchar(45) NOT NULL,
  `statuscopycart` varchar(45) NOT NULL,
  `timeauto_not_verify` varchar(20) NOT NULL,
  `cron_status` text NOT NULL,
  `limitnumber` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `setting` (`Bot_Status`, `roll_Status`, `get_number`, `iran_number`, `NotUser`, `Channel_Report`, `limit_usertest_all`, `affiliatesstatus`, `affiliatespercentage`, `showcard`, `verifystart`, `id_support`, `Lottery_prize`, `wheelـluck`, `wheelـluck_price`, `keyboardmain`, `statusnoteforf`, `statuscopycart`, `timeauto_not_verify`, `cron_status`, `limitnumber`) VALUES ('botstatuson', 'rolleon', 'offAuthenticationphone', 'offAuthenticationiran', 'offnotuser', '-1009876543210', '1', 'onaffiliates', '10', '1', 'offverify', '0', '{\"one\":\"0\",\"tow\":\"0\",\"theree\":\"0\"}', '0', '0', '{\"keyboard\":[[{\"text\":\"text_sell\"},{\"text\":\"text_extend\"}],[{\"text\":\"text_usertest\"},{\"text\":\"text_wheel_luck\"}],[{\"text\":\"text_Purchased_services\"},{\"text\":\"accountwallet\"}],[{\"text\":\"text_affiliates\"},{\"text\":\"text_Tariff_list\"}],[{\"text\":\"text_support\"},{\"text\":\"text_help\"}]]}', '1', '0', '4', '{\"day\":true,\"volume\":true,\"remove\":false,\"remove_volume\":false,\"test\":false,\"on_hold\":false,\"uptime_node\":false,\"uptime_panel\":false}', '{\"free\":100,\"all\":100}');

DROP TABLE IF EXISTS `support_message`;
CREATE TABLE `support_message` (
  `id` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `Tracking` varchar(100) NOT NULL,
  `idsupport` varchar(100) NOT NULL,
  `iduser` varchar(100) NOT NULL,
  `name_departman` varchar(600) NOT NULL,
  `text` text NOT NULL,
  `result` text NOT NULL,
  `time` varchar(200) NOT NULL,
  `status` enum('Answered','Pending','Unseen','Customerresponse','close') NOT NULL,
  `seen_by_user` tinyint(1) NOT NULL DEFAULT 0,
  `seen_by_admin` tinyint(1) NOT NULL DEFAULT 0,
  `reply_to_id` int(6) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `support_message` (`id`, `Tracking`, `idsupport`, `iduser`, `name_departman`, `text`, `result`, `time`, `status`, `seen_by_user`, `seen_by_admin`, `reply_to_id`) VALUES ('1', '483920', '1000000001', '5012345671', '☎️ بخش عمومی', 'سلام\nسرویس من وصل نمی‌شود', 'لطفا برنامه را به‌روز کنید.', '1404/08/13 10:00:00', 'Answered', '1', '1', NULL);
INSERT INTO `support_message` (`id`, `Tracking`, `idsupport`, `iduser`, `name_departman`, `text`, `result`, `time`, `status`, `seen_by_user`, `seen_by_admin`, `reply_to_id`) VALUES ('2', '483920', '1000000001', '5012345671', '☎️ بخش عمومی', 'درست شد، ممنون', '0', '1404/08/13 11:30:00', 'close', '1', '1', '1');
INSERT INTO `support_message` (`id`, `Tracking`, `idsupport`, `iduser`, `name_departman`, `text`, `result`, `time`, `status`, `seen_by_user`, `seen_by_admin`, `reply_to_id`) VALUES ('3', '590114', '1000000001', '5012345675', '☎️ بخش عمومی', 'کد تخفیف عمل نمی‌کند', '0', '1404/08/14 16:45:00', 'Unseen', '0', '0', NULL);

DROP TABLE IF EXISTS `textbot`;
CREATE TABLE `textbot` (
  `id_text` varchar(600) NOT NULL,
  `text` text NOT NULL,
  PRIMARY KEY (`id_text`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `textbot` (`id_text`, `text`) VALUES ('text_start', 'سلام {first_name} 👋\nبه فروشگاه ما خوش آمدید.');
INSERT INTO `textbot` (`id_text`, `text`) VALUES ('text_sell', '🛍 خرید سرویس');
INSERT INTO `textbot` (`id_text`, `text`) VALUES ('text_help', '📚 آموزش');

DROP TABLE IF EXISTS `user`;
CREATE TABLE `user` (
  `id` varchar(500) NOT NULL,
  `limit_usertest` int(11) NOT NULL DEFAULT 1,
  `roll_Status` tinyint(1) NOT NULL DEFAULT 0,
  `username` varchar(500) NOT NULL DEFAULT '',
  `Processing_value` text DEFAULT NULL,
  `Processing_value_one` text DEFAULT NULL,
  `Processing_value_tow` text DEFAULT NULL,
  `Processing_value_four` text DEFAULT NULL,
  `step` varchar(500) NOT NULL DEFAULT 'home',
  `description_blocking` text DEFAULT NULL,
  `number` varchar(300) NOT NULL DEFAULT 'none',
  `Balance` int(11) NOT NULL DEFAULT 0,
  `User_Status` varchar(500) NOT NULL DEFAULT 'Active',
  `pagenumber` int(11) NOT NULL DEFAULT 0,
  `message_count` varchar(100) NOT NULL DEFAULT '0',
  `last_message_time` varchar(100) NOT NULL DEFAULT '0',
  `agent` varchar(100) NOT NULL DEFAULT 'f',
  `affiliatescount` varchar(100) NOT NULL DEFAULT '0',
  `affiliates` varchar(100) NOT NULL DEFAULT '0',
  `namecustom` varchar(300) NOT NULL DEFAULT 'none',
  `number_username` varchar(300) NOT NULL DEFAULT '100',
  `register` varchar(100) NOT NULL DEFAULT 'none',
  `verify` varchar(100) NOT NULL DEFAULT '1',
  `cardpayment` varchar(100) NOT NULL DEFAULT '1',
  `codeInvitation` varchar(100) DEFAULT NULL,
  `pricediscount` varchar(100) DEFAULT '0',
  `hide_mini_app_instruction` varchar(20) DEFAULT '0',
  `maxbuyagent` varchar(100) DEFAULT '0',
  `joinchannel` varchar(100) DEFAULT '0',
  `checkstatus` varchar(50) DEFAULT '0',
  `bottype` text DEFAULT NULL,
  `score` int(11) DEFAULT 0,
  `limitchangeloc` varchar(50) DEFAULT '0',
  `status_cron` varchar(20) DEFAULT '1',
  `expire` varchar(100) DEFAULT NULL,
  `token` varchar(100) DEFAULT NULL,
  `auth_exempt` varchar(20) DEFAULT '0',
  `antispam_window_start` varchar(20) DEFAULT '0',
  `antispam_window_count` varchar(20) DEFAULT '0',
  `antispam_muted_until` varchar(20) DEFAULT '0',
  `step_stack` text DEFAULT NULL,
  `card_verify_bypass` tinyint(1) DEFAULT NULL,
  `Processing_value_price` varchar(100) DEFAULT NULL,
  `last_seen_notification_id` int(11) DEFAULT NULL,
  `nav_state` varchar(64) DEFAULT NULL,
  `reply_kb_cleared` varchar(1) DEFAULT NULL,
  `reply_kb_cleanup_msg_id` varchar(20) DEFAULT NULL,
  `source` varchar(64) DEFAULT NULL,
  `ui_section` varchar(24) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `user` (`id`, `limit_usertest`, `roll_Status`, `username`, `Processing_value`, `Processing_value_one`, `Processing_value_tow`, `Processing_value_four`, `step`, `description_blocking`, `number`, `Balance`, `User_Status`, `pagenumber`, `message_count`, `last_message_time`, `agent`, `affiliatescount`, `affiliates`, `namecustom`, `number_username`, `register`, `verify`, `cardpayment`, `codeInvitation`, `pricediscount`, `hide_mini_app_instruction`, `maxbuyagent`, `joinchannel`, `checkstatus`, `bottype`, `score`, `limitchangeloc`, `status_cron`, `expire`, `token`, `auth_exempt`, `antispam_window_start`, `antispam_window_count`, `antispam_muted_until`, `step_stack`, `card_verify_bypass`, `Processing_value_price`, `last_seen_notification_id`, `nav_state`, `reply_kb_cleared`, `reply_kb_cleanup_msg_id`, `source`, `ui_section`) VALUES ('1000000001', '1', '1', 'shop_owner', '0', 'none', 'none', '0', 'home', NULL, 'none', '0', 'Active', '1', '0', '0', 'f', '0', '0', 'none', '100', '1725000000', '1', '1', '9a1b2c3d4e5f', '0', '0', '0', '0', '0', NULL, '0', '0', '1', NULL, NULL, '0', '0', '0', '0', '[]', '0', NULL, NULL, 'home', '0', '0', NULL, NULL);
INSERT INTO `user` (`id`, `limit_usertest`, `roll_Status`, `username`, `Processing_value`, `Processing_value_one`, `Processing_value_tow`, `Processing_value_four`, `step`, `description_blocking`, `number`, `Balance`, `User_Status`, `pagenumber`, `message_count`, `last_message_time`, `agent`, `affiliatescount`, `affiliates`, `namecustom`, `number_username`, `register`, `verify`, `cardpayment`, `codeInvitation`, `pricediscount`, `hide_mini_app_instruction`, `maxbuyagent`, `joinchannel`, `checkstatus`, `bottype`, `score`, `limitchangeloc`, `status_cron`, `expire`, `token`, `auth_exempt`, `antispam_window_start`, `antispam_window_count`, `antispam_muted_until`, `step_stack`, `card_verify_bypass`, `Processing_value_price`, `last_seen_notification_id`, `nav_state`, `reply_kb_cleared`, `reply_kb_cleanup_msg_id`, `source`, `ui_section`) VALUES ('5012345671', '1', '1', 'reza_mohammadi', '0', 'none', 'none', '0', 'home', NULL, '989121112233', '85000', 'Active', '1', '0', '0', 'f', '1', '0', 'none', '100', '1730000000', '1', '1', '1f2e3d4c5b6a', '0', '0', '0', '0', '0', NULL, '0', '0', '1', NULL, NULL, '0', '0', '0', '0', '[]', '0', NULL, NULL, 'home', '0', '0', NULL, NULL);
INSERT INTO `user` (`id`, `limit_usertest`, `roll_Status`, `username`, `Processing_value`, `Processing_value_one`, `Processing_value_tow`, `Processing_value_four`, `step`, `description_blocking`, `number`, `Balance`, `User_Status`, `pagenumber`, `message_count`, `last_message_time`, `agent`, `affiliatescount`, `affiliates`, `namecustom`, `number_username`, `register`, `verify`, `cardpayment`, `codeInvitation`, `pricediscount`, `hide_mini_app_instruction`, `maxbuyagent`, `joinchannel`, `checkstatus`, `bottype`, `score`, `limitchangeloc`, `status_cron`, `expire`, `token`, `auth_exempt`, `antispam_window_start`, `antispam_window_count`, `antispam_muted_until`, `step_stack`, `card_verify_bypass`, `Processing_value_price`, `last_seen_notification_id`, `nav_state`, `reply_kb_cleared`, `reply_kb_cleanup_msg_id`, `source`, `ui_section`) VALUES ('5012345672', '1', '1', 'sara_agent', '0', 'none', 'none', '0', 'home', NULL, '+989351234567', '420000', 'Active', '1', '0', '0', 'n', '0', '0', 'none', '100', '1730100000', '1', '1', '2a3b4c5d6e7f', '0', '0', '0', '0', '0', NULL, '0', '0', '1', NULL, NULL, '0', '0', '0', '0', '[]', '0', NULL, NULL, 'home', '0', '0', NULL, NULL);
INSERT INTO `user` (`id`, `limit_usertest`, `roll_Status`, `username`, `Processing_value`, `Processing_value_one`, `Processing_value_tow`, `Processing_value_four`, `step`, `description_blocking`, `number`, `Balance`, `User_Status`, `pagenumber`, `message_count`, `last_message_time`, `agent`, `affiliatescount`, `affiliates`, `namecustom`, `number_username`, `register`, `verify`, `cardpayment`, `codeInvitation`, `pricediscount`, `hide_mini_app_instruction`, `maxbuyagent`, `joinchannel`, `checkstatus`, `bottype`, `score`, `limitchangeloc`, `status_cron`, `expire`, `token`, `auth_exempt`, `antispam_window_start`, `antispam_window_count`, `antispam_muted_until`, `step_stack`, `card_verify_bypass`, `Processing_value_price`, `last_seen_notification_id`, `nav_state`, `reply_kb_cleared`, `reply_kb_cleanup_msg_id`, `source`, `ui_section`) VALUES ('5012345673', '1', '1', 'NOT_USERNAME', '0', 'none', 'none', '0', 'home', NULL, 'confrim number by admin', '-125000', 'Active', '1', '0', '0', 'n2', '0', '0', 'none', '100', '1730200000', '1', '1', '3b4c5d6e7f80', '0', '0', '300000', '0', '0', '0', '0', '0', '1', NULL, NULL, '0', '0', '0', '0', '[]', '0', NULL, NULL, 'home', '0', '0', NULL, NULL);
INSERT INTO `user` (`id`, `limit_usertest`, `roll_Status`, `username`, `Processing_value`, `Processing_value_one`, `Processing_value_tow`, `Processing_value_four`, `step`, `description_blocking`, `number`, `Balance`, `User_Status`, `pagenumber`, `message_count`, `last_message_time`, `agent`, `affiliatescount`, `affiliates`, `namecustom`, `number_username`, `register`, `verify`, `cardpayment`, `codeInvitation`, `pricediscount`, `hide_mini_app_instruction`, `maxbuyagent`, `joinchannel`, `checkstatus`, `bottype`, `score`, `limitchangeloc`, `status_cron`, `expire`, `token`, `auth_exempt`, `antispam_window_start`, `antispam_window_count`, `antispam_muted_until`, `step_stack`, `card_verify_bypass`, `Processing_value_price`, `last_seen_notification_id`, `nav_state`, `reply_kb_cleared`, `reply_kb_cleanup_msg_id`, `source`, `ui_section`) VALUES ('5012345674', '1', '1', 'blocked_user', '0', 'none', 'none', '0', 'home', 'اسپم', 'none', '0', 'block', '1', '0', '0', 'f', '0', '0', 'none', '100', '1730300000', '1', '1', '4c5d6e7f8091', '0', '0', '0', '0', '0', NULL, '0', '0', '1', NULL, NULL, '0', '0', '0', '0', '[]', '0', NULL, NULL, 'home', '0', '0', NULL, NULL);
INSERT INTO `user` (`id`, `limit_usertest`, `roll_Status`, `username`, `Processing_value`, `Processing_value_one`, `Processing_value_tow`, `Processing_value_four`, `step`, `description_blocking`, `number`, `Balance`, `User_Status`, `pagenumber`, `message_count`, `last_message_time`, `agent`, `affiliatescount`, `affiliates`, `namecustom`, `number_username`, `register`, `verify`, `cardpayment`, `codeInvitation`, `pricediscount`, `hide_mini_app_instruction`, `maxbuyagent`, `joinchannel`, `checkstatus`, `bottype`, `score`, `limitchangeloc`, `status_cron`, `expire`, `token`, `auth_exempt`, `antispam_window_start`, `antispam_window_count`, `antispam_muted_until`, `step_stack`, `card_verify_bypass`, `Processing_value_price`, `last_seen_notification_id`, `nav_state`, `reply_kb_cleared`, `reply_kb_cleanup_msg_id`, `source`, `ui_section`) VALUES ('5012345675', '1', '1', 'mina_ref', '0', 'none', 'none', '0', 'home', NULL, 'none', '12000', 'Active', '1', '0', '0', 'f', '0', '5012345671', 'none', '100', 'none', '1', '1', '5d6e7f8091a2', '15', '0', '0', '0', '0', NULL, '0', '0', '1', NULL, NULL, '0', '0', '0', '0', '[]', '0', NULL, NULL, 'home', '0', '0', NULL, NULL);
INSERT INTO `user` (`id`, `limit_usertest`, `roll_Status`, `username`, `Processing_value`, `Processing_value_one`, `Processing_value_tow`, `Processing_value_four`, `step`, `description_blocking`, `number`, `Balance`, `User_Status`, `pagenumber`, `message_count`, `last_message_time`, `agent`, `affiliatescount`, `affiliates`, `namecustom`, `number_username`, `register`, `verify`, `cardpayment`, `codeInvitation`, `pricediscount`, `hide_mini_app_instruction`, `maxbuyagent`, `joinchannel`, `checkstatus`, `bottype`, `score`, `limitchangeloc`, `status_cron`, `expire`, `token`, `auth_exempt`, `antispam_window_start`, `antispam_window_count`, `antispam_muted_until`, `step_stack`, `card_verify_bypass`, `Processing_value_price`, `last_seen_notification_id`, `nav_state`, `reply_kb_cleared`, `reply_kb_cleanup_msg_id`, `source`, `ui_section`) VALUES ('5012345676', '1', '1', 'reseller_buyer', '0', 'none', 'none', '0', 'home', NULL, 'none', '30000', 'Active', '1', '0', '0', 'f', '0', '0', 'none', '100', '1730600000', '1', '1', '6e7f8091a2b3', '0', '0', '0', '0', '0', '7012345678:AAFakeResellerTokenForFixturesOnly', '0', '0', '1', NULL, NULL, '0', '0', '0', '0', '[]', '0', NULL, NULL, 'home', '0', '0', NULL, NULL);

DROP TABLE IF EXISTS `wallet_ledger`;
CREATE TABLE `wallet_ledger` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_user` varchar(200) NOT NULL,
  `direction` enum('credit','debit') NOT NULL,
  `amount` bigint(20) NOT NULL,
  `balance_after` bigint(20) DEFAULT NULL,
  `category` varchar(50) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `id_order` varchar(64) DEFAULT NULL,
  `ref_table` varchar(50) DEFAULT NULL,
  `ref_id` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `fulfillment_key` varchar(191) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_wl_fulfillment_key` (`fulfillment_key`),
  KEY `idx_wl_user` (`id_user`,`created_at`),
  KEY `idx_wl_order` (`id_order`),
  KEY `idx_wl_category` (`category`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `wallet_ledger` (`id`, `id_user`, `direction`, `amount`, `balance_after`, `category`, `description`, `id_order`, `ref_table`, `ref_id`, `created_at`, `fulfillment_key`) VALUES ('1', '5012345671', 'credit', '265000', '265000', 'topup', 'افزایش موجودی', 'ord_7f3a', 'Payment_report', '1', '2026-11-01 14:50:11', 'pr:1');
INSERT INTO `wallet_ledger` (`id`, `id_user`, `direction`, `amount`, `balance_after`, `category`, `description`, `id_order`, `ref_table`, `ref_id`, `created_at`, `fulfillment_key`) VALUES ('2', '5012345671', 'debit', '180000', '85000', 'purchase', 'خرید سرویس', 'a1b2c3d4', 'invoice', 'a1b2c3d4', '2026-11-01 14:51:02', NULL);
INSERT INTO `wallet_ledger` (`id`, `id_user`, `direction`, `amount`, `balance_after`, `category`, `description`, `id_order`, `ref_table`, `ref_id`, `created_at`, `fulfillment_key`) VALUES ('3', '5012345673', 'debit', '350000', '-125000', 'purchase', 'خرید سرویس', 'f6a7b8c9', 'invoice', 'f6a7b8c9', '2026-11-02 08:05:44', NULL);

SET FOREIGN_KEY_CHECKS=1;
