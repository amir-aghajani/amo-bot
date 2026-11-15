SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';

DROP TABLE IF EXISTS `admin`;
CREATE TABLE `admin` (
  `id_admin` varchar(500) NOT NULL,
  `username` varchar(1000) NOT NULL,
  `password` varchar(1000) NOT NULL,
  `rule` varchar(500) NOT NULL,
  PRIMARY KEY (`id_admin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `admin` (`id_admin`,`username`,`password`,`rule`) VALUES
('1000000001','admin','$2y$10$FakeBcryptHashForFixturesOnlyAAAAAAAAAAAAAAAAAAAAAAAAA','administrator'),
('5012345672','sara','$2y$10$FakeBcryptHashForFixturesOnlyBBBBBBBBBBBBBBBBBBBBBBBBB','support'),
('5099999999','seller1','$2y$10$FakeBcryptHashForFixturesOnlyCCCCCCCCCCCCCCCCCCCCCCCCC','Seller');

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
  `webhook_secret` varchar(200) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `botsaz` (`id`,`id_user`,`bot_token`,`admin_ids`,`username`,`setting`,`hide_panel`,`time`,`webhook_secret`) VALUES
('1','5012345672','7012345678:AAFakeResellerTokenForFixturesOnly','[\"5012345672\"]','sara_vpn_bot','{\"minpricetime\":4000,\"pricetime\":4000,\"minpricevolume\":4000,\"pricevolume\":4000,\"support_username\":\"@sara_support\",\"Channel_Report\":0}','{}','1730200000','fake-webhook-secret-01');

DROP TABLE IF EXISTS `card_number`;
CREATE TABLE `card_number` (
  `cardnumber` varchar(500) NOT NULL,
  `namecard` varchar(1000) NOT NULL,
  PRIMARY KEY (`cardnumber`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `card_number` (`cardnumber`,`namecard`) VALUES
('6037997700001119','رضا کریمی - بانک ملی'),
('6219861012345675','بانک سامان');

DROP TABLE IF EXISTS `category`;
CREATE TABLE `category` (
  `id` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `remark` varchar(500) NOT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

INSERT INTO `category` (`id`,`remark`,`display_order`) VALUES
('1','ماهانه','1'),
('2','سه ماهه','2');

DROP TABLE IF EXISTS `channels`;
CREATE TABLE `channels` (
  `remark` varchar(200) NOT NULL,
  `linkjoin` varchar(200) NOT NULL,
  `link` varchar(200) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `channels` (`remark`,`linkjoin`,`link`) VALUES
('کانال اطلاع‌رسانی','https://t.me/mirzashop_news','@mirzashop_news'),
('کانال \"خصوصی\"','https://t.me/+AbCdEfGhIjKlMnOp','-1001234500002');

DROP TABLE IF EXISTS `config`;
CREATE TABLE `config` (
  `scope` varchar(20) NOT NULL,
  `name` varchar(191) NOT NULL,
  `value` mediumtext DEFAULT NULL,
  PRIMARY KEY (`scope`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `config` (`scope`,`name`,`value`) VALUES
('pay','cardnumber','6037997700001119'),
('pay','Cartstatus','oncard'),
('pay','maxbalance','{\"f\":\"1000000\",\"n\":\"1000000\",\"n2\":\"1000000\"}'),
('pay','minbalance','{\"f\":\"20000\",\"n\":\"20000\",\"n2\":\"20000\"}'),
('pay','namecard','رضا کریمی'),
('setting','Bot_Status','botstatuson'),
('setting','Channel_Report','-1001234500099'),
('setting','keyboardmain','{\"keyboard\":[[{\"text\":\"text_sell\"},{\"text\":\"text_extend\"}],[{\"text\":\"text_usertest\"},{\"text\":\"text_wheel_luck\"}]]}'),
('setting','statusterffh',NULL),
('shop','chashbackextend_agent','{\"n\":0,\"n2\":0}'),
('shop','statusextra','offextra'),
('topic','backupfile','12'),
('topic','buyreport','4');

DROP TABLE IF EXISTS `discount`;
CREATE TABLE `discount` (
  `id` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(2000) DEFAULT NULL,
  `price` varchar(200) DEFAULT NULL,
  `limituse` varchar(200) DEFAULT NULL,
  `limitused` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `discount` (`id`,`code`,`price`,`limituse`,`limitused`) VALUES
('1','GIFTONE','50000','1','0'),
('2','GIFTMANY','20000','100','64'),
('3','GIFTZERO','10000','0','0');

DROP TABLE IF EXISTS `discountsell`;
CREATE TABLE `discountsell` (
  `id` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `codeDiscount` varchar(1000) NOT NULL,
  `price` varchar(200) NOT NULL,
  `limitDiscount` varchar(500) NOT NULL,
  `agent` varchar(500) NOT NULL,
  `usefirst` varchar(100) NOT NULL,
  `useuser` varchar(100) NOT NULL,
  `code_product` varchar(100) NOT NULL,
  `code_panel` varchar(100) NOT NULL,
  `time` varchar(100) NOT NULL,
  `type` varchar(100) NOT NULL,
  `usedDiscount` varchar(500) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `discountsell` (`id`,`codeDiscount`,`price`,`limitDiscount`,`agent`,`usefirst`,`useuser`,`code_product`,`code_panel`,`time`,`type`,`usedDiscount`) VALUES
('1','NOROOZ1405','20','200','allusers','0','1','all','/all','0','all','37'),
('2','FIRST10','10','100','f','1','1','a1b2','7a1c','1798761600','buy','0'),
('3','AGENT15','15','50','n','0','2','all','/all','0','extend','3'),
('4','ZERO0','30','0','allusers','0','1','all','/all','0','all','0');

DROP TABLE IF EXISTS `giftcodeconsumed`;
CREATE TABLE `giftcodeconsumed` (
  `id` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(2000) DEFAULT NULL,
  `id_user` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `giftcodeconsumed` (`id`,`code`,`id_user`) VALUES
('1','GIFTMANY','5012345671'),
('2','GIFTMANY','5012345675');

DROP TABLE IF EXISTS `invoice`;
CREATE TABLE `invoice` (
  `id_invoice` varchar(200) NOT NULL,
  `id_user` varchar(200) DEFAULT NULL,
  `username` varchar(200) DEFAULT NULL,
  `Service_location` varchar(200) DEFAULT NULL,
  `time_sell` varchar(200) DEFAULT NULL,
  `name_product` varchar(200) DEFAULT NULL,
  `price_product` varchar(200) DEFAULT NULL,
  `Volume` varchar(200) DEFAULT NULL,
  `Service_time` varchar(200) DEFAULT NULL,
  `uuid` text DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  `user_info` text DEFAULT NULL,
  `bottype` varchar(200) DEFAULT NULL,
  `refral` varchar(100) DEFAULT NULL,
  `time_cron` varchar(100) DEFAULT NULL,
  `notifctions` text NOT NULL,
  `Status` varchar(200) DEFAULT NULL,
  `id_payment` varchar(20) DEFAULT NULL,
  `payable_amount` varchar(20) DEFAULT NULL,
  `discount_code` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id_invoice`),
  KEY `idx_invoice_id_user` (`id_user`(100)),
  KEY `idx_invoice_username` (`username`(150)),
  KEY `idx_invoice_status` (`Status`(100)),
  KEY `idx_invoice_location` (`Service_location`(150)),
  KEY `idx_invoice_id_payment` (`id_payment`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `invoice` (`id_invoice`,`id_user`,`username`,`Service_location`,`time_sell`,`name_product`,`price_product`,`Volume`,`Service_time`,`uuid`,`note`,`user_info`,`bottype`,`refral`,`time_cron`,`notifctions`,`Status`,`id_payment`,`payable_amount`,`discount_code`) VALUES
('a8f3k2','5012345671','5012345671_rz1','آلمان-مرزبان','1730500000','یک ماهه ۵۰ گیگ','180000','50','30',NULL,'گوشی خودم',NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','active',NULL,'144000','NOROOZ1405'),
('b7d1q9','5012345672','sara_x1','هلند-ثنایی','1730510000','یک ماهه ۵۰ گیگ همکار','140000','50','30',NULL,NULL,NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','end_of_volume',NULL,NULL,NULL),
('c6e2w8','5012345675','mina_test','PasarGuard-FI','1731010000','Test service','0','100','1',NULL,NULL,NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','active',NULL,NULL,NULL),
('d5r3e7','5012345673','agent2_9','PasarGuard-FI','1730520000','پلن ویژه همکاران','90000','40','30',NULL,'کار \"دفتر\"',NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','sendedwarn',NULL,NULL,NULL),
('e4t4r6','5012345671','rz_old','آلمان-مرزبان','1728000000','یک ماهه ۵۰ گیگ','180000','50','30',NULL,NULL,NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','removebyuser',NULL,NULL,NULL),
('f3y5t5','5012345674','blk_1','هلند-ثنایی','1730530000','یک ماهه ۵۰ گیگ','180000','50','30',NULL,NULL,NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','unpaid',NULL,NULL,NULL),
('g2u6y4','5012345676','res_1','هلند-ثنایی','1731510000','یک ماهه ۵۰ گیگ','180000','50','30',NULL,NULL,NULL,'7012345678:AAFakeResellerTokenForFixturesOnly',NULL,NULL,'{\"volume\":false,\"time\":false}','active',NULL,NULL,NULL),
('h1i7u3','5012345671','manual_cfg1','فروش دستی','1730540000','کانفیگ دستی','120000','0','30',NULL,NULL,NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','active',NULL,NULL,NULL),
('i9o8i2','5012345672','uk_sara','Hiddify-UK','1730550000','نامحدود یک ماهه','350000','0','30',NULL,NULL,NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','active',NULL,NULL,NULL),
('j8p9o1','5012345675','mina_test0','PasarGuard-FI','1730010000','سرویس تست','0','100','1',NULL,NULL,NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','disabled',NULL,NULL,NULL),
('k7a1p0','5012345673','agent2_old','Rebecca-Old','1725010000','پلن ویژه همکاران','90000','40','30',NULL,NULL,NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','active',NULL,NULL,NULL),
('l6s2a9','5012345671','rz_custom','آلمان-مرزبان','1731020000','⚙️ سرویس دلخواه','216000','50','30',NULL,'لپ‌تاپ',NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','disablebyadmin',NULL,NULL,NULL),
('m5d3s8','5012345672','sara_gone','هلند-ثنایی','1729000000','یک ماهه ۵۰ گیگ','140000','50','30',NULL,NULL,NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','disabledn',NULL,NULL,NULL),
('n4f4d7','5012345677','res_2','سوئد-ثنایی','1731710000','یک ماهه ۵۰ گیگ','180000','50','30',NULL,NULL,NULL,'7012345678:AAFakeResellerTokenForFixturesOnly',NULL,NULL,'{\"volume\":false,\"time\":false}','active',NULL,NULL,NULL),
('o3h5j2','5012345671','rz_disabled','آلمان-مرزبان','1729500000','یک ماهه ۵۰ گیگ','180000','50','30',NULL,NULL,NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','disabled',NULL,NULL,NULL),
('p2k6l1','1000000001','owner_gift','PasarGuard-FI','1726000000','یک ماهه ۵۰ گیگ','0','50','30',NULL,NULL,NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','removebyadmin',NULL,NULL,NULL),
('q1w2e3','5012345671','rz_tr','Rebecca-TR','1731030000','یک ماهه ۵۰ گیگ','180000','50','30',NULL,'تبلت',NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','active',NULL,NULL,NULL);

DROP TABLE IF EXISTS `manualsell`;
CREATE TABLE `manualsell` (
  `id` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `codepanel` varchar(100) NOT NULL,
  `codeproduct` varchar(100) NOT NULL,
  `namerecord` varchar(200) NOT NULL,
  `username` varchar(500) DEFAULT NULL,
  `contentrecord` text NOT NULL,
  `status` varchar(200) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_manualsell_codepanel` (`codepanel`(100))
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `manualsell` (`id`,`codepanel`,`codeproduct`,`namerecord`,`username`,`contentrecord`,`status`) VALUES
('1','d5e6','a1b2','کانفیگ ۱','manual_cfg1','vless://11111111-2222-4333-8444-555555555555@203.0.113.10:443?type=tcp&security=reality#manual1','used'),
('2','d5e6','a1b2','کانفیگ ۲',NULL,'vless://66666666-7777-4888-8999-000000000000@203.0.113.11:443?type=tcp&security=reality#manual2','active');

DROP TABLE IF EXISTS `marzban_panel`;
CREATE TABLE `marzban_panel` (
  `id` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `code_panel` varchar(200) DEFAULT NULL,
  `name_panel` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `status` varchar(500) DEFAULT NULL,
  `url_panel` varchar(2000) DEFAULT NULL,
  `username_panel` varchar(200) DEFAULT NULL,
  `password_panel` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
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
  `priceextravolume` varchar(500) DEFAULT NULL,
  `pricecustomvolume` varchar(500) DEFAULT NULL,
  `pricecustomtime` varchar(500) DEFAULT NULL,
  `priceextratime` varchar(500) DEFAULT NULL,
  `mainvolume` varchar(500) DEFAULT NULL,
  `maxvolume` varchar(500) DEFAULT NULL,
  `maintime` varchar(500) DEFAULT NULL,
  `maxtime` varchar(500) DEFAULT NULL,
  `status_extend` varchar(100) DEFAULT NULL,
  `datelogin` text DEFAULT NULL,
  `proxies` text DEFAULT NULL,
  `inbounds` text DEFAULT NULL,
  `subvip` varchar(60) DEFAULT NULL,
  `changeloc` varchar(60) DEFAULT NULL,
  `on_hold_test` varchar(60) NOT NULL,
  `customvolume` text DEFAULT NULL,
  `hide_user` text DEFAULT NULL,
  `version_panel` varchar(60) DEFAULT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_panel_name` (`name_panel`(150)),
  KEY `idx_panel_code` (`code_panel`(100))
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `marzban_panel` (`id`,`code_panel`,`name_panel`,`status`,`url_panel`,`username_panel`,`password_panel`,`agent`,`sublink`,`config`,`MethodUsername`,`TestAccount`,`limit_panel`,`namecustom`,`Methodextend`,`conecton`,`linksubx`,`inboundid`,`type`,`inboundstatus`,`hosts`,`inbound_deactive`,`time_usertest`,`val_usertest`,`secret_code`,`priceChangeloc`,`priceextravolume`,`pricecustomvolume`,`pricecustomtime`,`priceextratime`,`mainvolume`,`maxvolume`,`maintime`,`maxtime`,`status_extend`,`datelogin`,`proxies`,`inbounds`,`subvip`,`changeloc`,`on_hold_test`,`customvolume`,`hide_user`,`version_panel`,`display_order`) VALUES
('1','7a1c','آلمان-مرزبان','active','https://de.mirzashop.example.com:8000','admin','Secr3t-de','all','onsublink','offconfig','numericIdRandom','ONTestAccount','unlimted','vpn','resetVolumeTime','offconecton','https://de.mirzashop.example.com:8000','1','marzban','offinbounddisable',NULL,'0','1','100',NULL,'0','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"1000\",\"n\":\"1000\",\"n2\":\"1000\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"365\",\"n\":\"365\",\"n2\":\"365\"}','on_extend','{\"time\":\"1731800000\",\"access_token\":\"fake.jwt.token\"}','{\"vless\":{\"flow\":\"xtls-rprx-vision\"}}','{\"vless\":[\"VLESS TCP REALITY\"]}','offsubvip','offchangeloc','1','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}',NULL,'0','1'),
('2','3b9e','هلند-ثنایی','active','https://nl.mirzashop.example.com:2053/Xk9pQ','admin','xui-api-token-0123456789abcdef','all','onsublink','offconfig','numericIdRandom','ONTestAccount','150','vpn','resetVolumeTime','offconecton','https://nl.mirzashop.example.com:2096/sub','3','x-ui_single','offinbounddisable',NULL,'0','1','100',NULL,'0','{\"f\":\"5000\",\"n\":\"4000\",\"n2\":\"3500\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"1000\",\"n\":\"1000\",\"n2\":\"1000\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"365\",\"n\":\"365\",\"n2\":\"365\"}','on_extend',NULL,NULL,NULL,'onsubvip','offchangeloc','1','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}','[\"5012345674\"]','0','2'),
('3','c4d2','PasarGuard-FI','active','https://fi.mirzashop.example.com','pgadmin','pg-pass-2025','all','onsublink','offconfig','numericIdRandom','ONTestAccount','unlimited','vpn','resetVolumeTime','offconecton','https://fi.mirzashop.example.com','1','marzban','offinbounddisable',NULL,'0','1','100',NULL,'0','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"1000\",\"n\":\"1000\",\"n2\":\"1000\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"365\",\"n\":\"365\",\"n2\":\"365\"}','on_extend',NULL,NULL,NULL,'offsubvip','offchangeloc','1','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}',NULL,'1','3'),
('4','d5e6','فروش دستی','active','null','null','null','all','onsublink','offconfig','numericIdRandom','ONTestAccount','unlimited','vpn','resetVolumeTime','offconecton','null','1','Manualsale','offinbounddisable',NULL,'0','1','100',NULL,'0','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"1000\",\"n\":\"1000\",\"n2\":\"1000\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"365\",\"n\":\"365\",\"n2\":\"365\"}','on_extend',NULL,NULL,NULL,'offsubvip','offchangeloc','1','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}',NULL,'0','4'),
('5','e7f8','Hiddify-UK','active','https://uk.mirzashop.example.com/Pz7Qm','null','null','n','onsublink','offconfig','numericIdRandom','ONTestAccount','unlimited','vpn','resetVolumeTime','offconecton','https://uk.mirzashop.example.com/Pz7Qm','1','hiddify','offinbounddisable',NULL,'0','1','100','5b0c2f1e-1a2b-4c3d-8e9f-0a1b2c3d4e5f','0','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"1000\",\"n\":\"1000\",\"n2\":\"1000\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"365\",\"n\":\"365\",\"n2\":\"365\"}','on_extend',NULL,NULL,NULL,'offsubvip','offchangeloc','1','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}',NULL,'0','5'),
('6','f9a0','سوئد-ثنایی','active','https://se.mirzashop.example.com:2053/Lm3Nb','null','xui-api-token-se-4567','all','onsublink','offconfig','numericIdRandom','ONTestAccount','unlimited','vpn','resetVolumeTime','offconecton','https://se.mirzashop.example.com:2053/Lm3Nb','1','x-ui_single','offinbounddisable',NULL,'0','1','100',NULL,'0','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"1000\",\"n\":\"1000\",\"n2\":\"1000\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"365\",\"n\":\"365\",\"n2\":\"365\"}','on_extend',NULL,NULL,NULL,'offsubvip','offchangeloc','1','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}',NULL,'0','6'),
('7','a0b1','Rebecca-TR','active','https://tr.mirzashop.example.com','null','rk_MirzaFixtureRebeccaKeyTR_0123456789abcdefgh','all','onsublink','offconfig','numericIdRandom','ONTestAccount','unlimited','vpn','resetVolumeTime','offconecton','https://tr.mirzashop.example.com','1','rebecca','offinbounddisable',NULL,'0','1','100',NULL,'0','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"1000\",\"n\":\"1000\",\"n2\":\"1000\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"365\",\"n\":\"365\",\"n2\":\"365\"}','on_extend',NULL,NULL,NULL,'offsubvip','offchangeloc','1','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}',NULL,'0','7');

DROP TABLE IF EXISTS `payment_report`;
CREATE TABLE `payment_report` (
  `id` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `id_user` varchar(200) DEFAULT NULL,
  `id_order` varchar(80) DEFAULT NULL,
  `time` varchar(200) DEFAULT NULL,
  `at_updated` varchar(200) DEFAULT NULL,
  `price` varchar(200) DEFAULT NULL,
  `dec_not_confirmed` text DEFAULT NULL,
  `Payment_Method` varchar(400) DEFAULT NULL,
  `payment_Status` varchar(100) DEFAULT NULL,
  `bottype` varchar(300) DEFAULT NULL,
  `message_id` int(11) DEFAULT NULL,
  `id_invoice` varchar(1000) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_payreport_id_user` (`id_user`(100)),
  KEY `idx_payreport_id_order` (`id_order`),
  KEY `idx_payreport_status` (`payment_Status`(100))
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `payment_report` (`id`,`id_user`,`id_order`,`time`,`at_updated`,`price`,`dec_not_confirmed`,`Payment_Method`,`payment_Status`,`bottype`,`message_id`,`id_invoice`) VALUES
('1','5012345671','ord-1001','1730400000','1730400300','200000',NULL,'cart to cart','paid',NULL,'101','none'),
('2','5012345672','ord-1002','1730410000','1730410200','500000',NULL,'cart to cart','paid',NULL,'102','none'),
('3','5012345675','ord-1003','1731600000',NULL,'50000',NULL,'cart to cart','waiting',NULL,'103','none'),
('4','5012345677','ord-1004','1731705000',NULL,'100000',NULL,'cart to cart','waiting','7012345678:AAFakeResellerTokenForFixturesOnly','104','none');

DROP TABLE IF EXISTS `product`;
CREATE TABLE `product` (
  `id` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `code_product` varchar(200) DEFAULT NULL,
  `name_product` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `price_product` varchar(2000) DEFAULT NULL,
  `Volume_constraint` varchar(2000) DEFAULT NULL,
  `Location` varchar(200) DEFAULT NULL,
  `Service_time` varchar(200) DEFAULT NULL,
  `agent` varchar(100) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `data_limit_reset` varchar(200) DEFAULT NULL,
  `one_buy_status` varchar(20) NOT NULL,
  `inbounds` text DEFAULT NULL,
  `proxies` text DEFAULT NULL,
  `category` varchar(400) DEFAULT NULL,
  `hide_panel` text NOT NULL,
  `status_product` varchar(20) NOT NULL DEFAULT 'active',
  `display_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_product_code` (`code_product`(50)),
  KEY `idx_product_location` (`Location`(100))
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `product` (`id`,`code_product`,`name_product`,`price_product`,`Volume_constraint`,`Location`,`Service_time`,`agent`,`note`,`data_limit_reset`,`one_buy_status`,`inbounds`,`proxies`,`category`,`hide_panel`,`status_product`,`display_order`) VALUES
('1','a1b2','یک ماهه ۵۰ گیگ','180000','50','/all','30','f','مناسب استفاده روزمره','no_reset','0',NULL,NULL,'ماهانه','{}','active','1'),
('2','a1b3','یک ماهه ۵۰ گیگ','140000','50','/all','30','n','','no_reset','0',NULL,NULL,'ماهانه','{}','active','2'),
('3','c3d4','سه ماهه ۱۵۰ گیگ','450000','150','آلمان-مرزبان','90','f','','no_reset','0',NULL,NULL,'سه ماهه','{}','disable','6'),
('4','e5f6','نامحدود یک ماهه','350000','0','/all','30','f','بدون محدودیت حجم\nفقط یک کاربر \"همزمان\" - مناسب \'خانواده\'','no_reset','1',NULL,NULL,'ماهانه','[\"آلمان-مرزبان\",\"هلند-ثنایی\"]','active','3'),
('5','g7h8','پلن ویژه همکاران','90000','40','PasarGuard-FI','30','n2','','month','0',NULL,NULL,'ماهانه','{}','active','4');

DROP TABLE IF EXISTS `requestagent`;
CREATE TABLE `requestagent` (
  `id` varchar(500) NOT NULL,
  `username` varchar(500) NOT NULL,
  `time` varchar(500) NOT NULL,
  `Description` varchar(500) NOT NULL,
  `status` varchar(500) NOT NULL,
  `type` varchar(500) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


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
  `payable_amount` varchar(20) DEFAULT NULL,
  `id_payment` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_serviceother_username` (`username`(150)),
  KEY `idx_serviceother_type` (`type`(100)),
  KEY `idx_serviceother_id_payment` (`id_payment`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `service_other` (`id`,`id_user`,`username`,`value`,`time`,`price`,`type`,`status`,`output`,`payable_amount`,`id_payment`) VALUES
('1','5012345671','5012345671_rz1','10','1731000000','40000','extra_user','paid','{}',NULL,NULL),
('2','5012345672','sara_x1','30','1731100000','120000','extend_user','paid','{}',NULL,NULL);

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
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `support_message` (`id`,`Tracking`,`idsupport`,`iduser`,`name_departman`,`text`,`result`,`time`,`status`) VALUES
('1','48213','1000000001','5012345671','☎️ بخش عمومی','سلام، سرویس وصل نمیشه','سلام، لطفا دوباره امتحان کنید','1730600000','Answered'),
('2','48213','1000000001','5012345671','☎️ بخش عمومی','ممنون، درست شد','0','1730601000','Customerresponse'),
('3','59102','1000000001','5012345673','☎️ بخش عمومی','بدهی رو چطوری تسویه کنم؟','0','1731700000','Unseen');

DROP TABLE IF EXISTS `textbot`;
CREATE TABLE `textbot` (
  `id_text` varchar(600) NOT NULL,
  `text` text NOT NULL,
  PRIMARY KEY (`id_text`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `textbot` (`id_text`,`text`) VALUES
('text_start','سلام، به فروشگاه خوش آمدید\nبرای خرید از منوی زیر استفاده کنید'),
('text_usertest','🔑 اکانت تست');

DROP TABLE IF EXISTS `user`;
CREATE TABLE `user` (
  `id` varchar(500) NOT NULL,
  `limit_usertest` int(100) NOT NULL,
  `roll_Status` tinyint(1) NOT NULL,
  `username` varchar(500) NOT NULL,
  `Processing_value` text NOT NULL,
  `Processing_value_one` text NOT NULL,
  `Processing_value_tow` text NOT NULL,
  `Processing_value_four` text NOT NULL,
  `step` varchar(500) NOT NULL,
  `description_blocking` text DEFAULT NULL,
  `number` varchar(300) NOT NULL,
  `Balance` int(255) NOT NULL,
  `User_Status` varchar(500) NOT NULL,
  `pagenumber` int(10) NOT NULL,
  `message_count` varchar(100) NOT NULL,
  `last_message_time` varchar(100) NOT NULL,
  `agent` varchar(100) NOT NULL,
  `affiliatescount` varchar(100) NOT NULL,
  `affiliates` varchar(100) NOT NULL,
  `namecustom` varchar(300) NOT NULL,
  `number_username` varchar(300) NOT NULL,
  `register` varchar(100) NOT NULL,
  `verify` varchar(100) NOT NULL,
  `cardpayment` varchar(100) NOT NULL,
  `codeInvitation` varchar(100) DEFAULT NULL,
  `pricediscount` varchar(100) DEFAULT '0',
  `maxbuyagent` varchar(100) DEFAULT '0',
  `joinchannel` varchar(100) DEFAULT '0',
  `checkstatus` varchar(50) DEFAULT '0',
  `bottype` text DEFAULT NULL,
  `score` int(255) DEFAULT 0,
  `limitchangeloc` varchar(50) DEFAULT '0',
  `status_cron` varchar(20) DEFAULT '1',
  `expire` varchar(100) DEFAULT NULL,
  `token` varchar(100) DEFAULT NULL,
  `hide_mini_app_instruction` varchar(20) DEFAULT NULL,
  `lang` varchar(5) DEFAULT NULL,
  `low_balance_alert_sent` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_affiliates` (`affiliates`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `user` (`id`,`limit_usertest`,`roll_Status`,`username`,`Processing_value`,`Processing_value_one`,`Processing_value_tow`,`Processing_value_four`,`step`,`description_blocking`,`number`,`Balance`,`User_Status`,`pagenumber`,`message_count`,`last_message_time`,`agent`,`affiliatescount`,`affiliates`,`namecustom`,`number_username`,`register`,`verify`,`cardpayment`,`codeInvitation`,`pricediscount`,`maxbuyagent`,`joinchannel`,`checkstatus`,`bottype`,`score`,`limitchangeloc`,`status_cron`,`expire`,`token`,`hide_mini_app_instruction`,`lang`,`low_balance_alert_sent`) VALUES
('1000000001','1','1','shop_owner','none','','','','home',NULL,'none','0','Active','1','0','0','f','0','0','none','100','1725000000','1','1',NULL,'0','0','0','0','0','0','0','1',NULL,NULL,'0','fa','0'),
('5012345671','1','1','reza_karimi','none','','','','home',NULL,'+989121112233','85000','Active','1','12','1731800000','f','1','0','none','100','1730000000','1','1',NULL,'0','0','0','0','0','0','0','1',NULL,NULL,'0','fa','0'),
('5012345672','1','1','sara_agent','none','','','','home',NULL,'989351234567','420000','Active','1','40','1731850000','n','0','0','none','100','1730100000','1','1',NULL,'0','0','active','0','0','25','0','1',NULL,NULL,'0','fa','0'),
('5012345673','0','1','none','none','','','','home',NULL,'none','-125000','Active','1','3','1731700000','n2','0','0','none','100','1730200000','1','0',NULL,'0','0','0','0','0','0','0','1','1924992000',NULL,'0','fa','0'),
('5012345674','1','1','blocked_user','none','','','','home','اسپم در پشتیبانی\nبار دوم: \"تهدید\" و \'توهین\' (100\\%)','none','0','block','1','90','1731300000','f','0','0','none','100','1730300000','1','1',NULL,'0','0','0','0','0','0','0','1',NULL,NULL,'0','fa','0'),
('5012345675','0','1','mina_ref','none','','','','home',NULL,'confrim number by admin','12000','Active','1','5','1731900000','f','0','5012345671','none','100','1731000000','1','1',NULL,'15','0','0','0','0','0','0','1',NULL,NULL,'0','en','0'),
('5012345676','1','1','res_customer','none','','','','home',NULL,'none','30000','Active','1','2','1731600000','f','0','0','none','100','1731500000','1','1',NULL,'0','0','0','0','7012345678:AAFakeResellerTokenForFixturesOnly','0','0','1',NULL,NULL,'0','fa','0'),
('5012345677','1','1','res_only','none','','','','home',NULL,'none','0','Active','1','4','1731710000','f','0','0','none','100','1731690000','1','0',NULL,'0','0','active','0','7012345678:AAFakeResellerTokenForFixturesOnly','0','0','1',NULL,NULL,'0','fa','0');

SET FOREIGN_KEY_CHECKS=1;
