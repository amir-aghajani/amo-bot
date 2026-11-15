-- MySQL dump 10.13  Distrib 8.0.40, for Linux (x86_64)
--
-- Host: localhost    Database: mirzabot
-- ------------------------------------------------------
-- Server version	8.0.40-0ubuntu0.22.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `admin`
--

DROP TABLE IF EXISTS `admin`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin` (
  `id_admin` varchar(500) NOT NULL,
  `username` varchar(1000) NOT NULL,
  `password` varchar(1000) NOT NULL,
  `rule` varchar(500) NOT NULL,
  PRIMARY KEY (`id_admin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin`
--

LOCK TABLES `admin` WRITE;
/*!40000 ALTER TABLE `admin` DISABLE KEYS */;
INSERT INTO `admin` VALUES ('1000000001','admin','f3a9c1d2e7','administrator'),('5012345672','sara','b7e2a4c9d1','support'),('5099999999','seller1','c1d2e3f4a5','Seller');
/*!40000 ALTER TABLE `admin` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `botsaz`
--

DROP TABLE IF EXISTS `botsaz`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `botsaz` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_user` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bot_token` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `admin_ids` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting` text COLLATE utf8mb4_unicode_ci,
  `hide_panel` json NOT NULL,
  `time` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `botsaz`
--

LOCK TABLES `botsaz` WRITE;
/*!40000 ALTER TABLE `botsaz` DISABLE KEYS */;
INSERT INTO `botsaz` VALUES (1,'5012345672','7012345678:AAFakeResellerTokenForFixturesOnly','[\"5012345672\"]','sara_vpn_bot','{\"minpricetime\":4000,\"pricetime\":4000,\"minpricevolume\":4000,\"pricevolume\":4000,\"support_username\":\"@sara_support\",\"Channel_Report\":0}','{}','1730200000');
/*!40000 ALTER TABLE `botsaz` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `card_number`
--

DROP TABLE IF EXISTS `card_number`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `card_number` (
  `cardnumber` varchar(500) NOT NULL,
  `namecard` varchar(1000) NOT NULL,
  PRIMARY KEY (`cardnumber`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `card_number`
--

LOCK TABLES `card_number` WRITE;
/*!40000 ALTER TABLE `card_number` DISABLE KEYS */;
INSERT INTO `card_number` VALUES ('6037997700001119','رضا کریمی - بانک ملی'),('6219861012345675','بانک سامان');
/*!40000 ALTER TABLE `card_number` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `category`
--

DROP TABLE IF EXISTS `category`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `category` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `remark` varchar(500) COLLATE utf8mb4_bin NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `category`
--

LOCK TABLES `category` WRITE;
/*!40000 ALTER TABLE `category` DISABLE KEYS */;
INSERT INTO `category` VALUES (1,'ماهانه'),(2,'سه ماهه');
/*!40000 ALTER TABLE `category` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `channels`
--

DROP TABLE IF EXISTS `channels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `channels` (
  `remark` varchar(200) NOT NULL,
  `linkjoin` varchar(200) NOT NULL,
  `link` varchar(200) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `channels`
--

LOCK TABLES `channels` WRITE;
/*!40000 ALTER TABLE `channels` DISABLE KEYS */;
INSERT INTO `channels` VALUES ('کانال اطلاع‌رسانی','https://t.me/mirzashop_news','@mirzashop_news'),('کانال \"خصوصی\"','https://t.me/+AbCdEfGhIjKlMnOp','-1001234500002');
/*!40000 ALTER TABLE `channels` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `Discount`
--

DROP TABLE IF EXISTS `Discount`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `Discount` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(2000) DEFAULT NULL,
  `price` varchar(200) DEFAULT NULL,
  `limituse` varchar(200) DEFAULT NULL,
  `limitused` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `Discount`
--

LOCK TABLES `Discount` WRITE;
/*!40000 ALTER TABLE `Discount` DISABLE KEYS */;
INSERT INTO `Discount` VALUES (1,'GIFTONE','50000','1','0'),(2,'GIFTMANY','20000','100','64'),(3,'GIFTZERO','10000','0','0');
/*!40000 ALTER TABLE `Discount` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `DiscountSell`
--

DROP TABLE IF EXISTS `DiscountSell`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `DiscountSell` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `codeDiscount` varchar(1000) NOT NULL,
  `price` varchar(200) NOT NULL,
  `limitDiscount` varchar(500) NOT NULL,
  `usefirst` varchar(100) NOT NULL,
  `useuser` varchar(100) NOT NULL,
  `code_product` varchar(100) NOT NULL,
  `code_panel` varchar(100) NOT NULL,
  `time` varchar(100) NOT NULL,
  `type` varchar(100) NOT NULL,
  `usedDiscount` varchar(500) NOT NULL,
  `agent` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `DiscountSell`
--

LOCK TABLES `DiscountSell` WRITE;
/*!40000 ALTER TABLE `DiscountSell` DISABLE KEYS */;
INSERT INTO `DiscountSell` VALUES (1,'NOROOZ1405','20','200','0','1','all','/all','0','all','37','allusers'),(2,'FIRST10','10','100','1','1','a1b2','7a1c','1798761600','buy','0','f'),(3,'AGENT15','15','50','0','2','all','/all','0','extend','3','n'),(4,'ZERO0','30','0','0','1','all','/all','0','all','0','allusers'),(5,'OLDCODE','25','10','0','1','all','/all','0','all','2',NULL);
/*!40000 ALTER TABLE `DiscountSell` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `Giftcodeconsumed`
--

DROP TABLE IF EXISTS `Giftcodeconsumed`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `Giftcodeconsumed` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(2000) DEFAULT NULL,
  `id_user` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `Giftcodeconsumed`
--

LOCK TABLES `Giftcodeconsumed` WRITE;
/*!40000 ALTER TABLE `Giftcodeconsumed` DISABLE KEYS */;
INSERT INTO `Giftcodeconsumed` VALUES (1,'GIFTMANY','5012345671'),(2,'GIFTMANY','5012345675');
/*!40000 ALTER TABLE `Giftcodeconsumed` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `invoice`
--

DROP TABLE IF EXISTS `invoice`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice` (
  `id_invoice` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_user` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `username` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Service_location` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `time_sell` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name_product` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price_product` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Volume` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Service_time` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uuid` text COLLATE utf8mb4_unicode_ci,
  `note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_info` text COLLATE utf8mb4_unicode_ci,
  `bottype` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `refral` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `time_cron` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notifctions` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `Status` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id_invoice`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invoice`
--

LOCK TABLES `invoice` WRITE;
/*!40000 ALTER TABLE `invoice` DISABLE KEYS */;
INSERT INTO `invoice` VALUES ('a8f3k2','5012345671','5012345671_rz1','آلمان-مرزبان','1730500000','یک ماهه ۵۰ گیگ','180000','50','30',NULL,'گوشی خودم',NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','active'),('b7d1q9','5012345672','sara_x1','هلند-ثنایی','1730510000','یک ماهه ۵۰ گیگ همکار','140000','50','30',NULL,NULL,NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','end_of_volume'),('c6e2w8','5012345675','mina_test','PasarGuard-FI','1731010000','سرویس تست','0','100','1',NULL,NULL,NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','active'),('d5r3e7','5012345673','agent2_9','PasarGuard-FI','1730520000','پلن ویژه همکاران','90000','40','30',NULL,'کار \"دفتر\"',NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','sendedwarn'),('e4t4r6','5012345671','rz_old','آلمان-مرزبان','1728000000','یک ماهه ۵۰ گیگ','180000','50','30',NULL,NULL,NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','removebyuser'),('f3y5t5','5012345674','blk_1','هلند-ثنایی','1730530000','یک ماهه ۵۰ گیگ','180000','50','30',NULL,NULL,NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','unpaid'),('g2u6y4','5012345676','res_1','هلند-ثنایی','1731510000','یک ماهه ۵۰ گیگ','180000','50','30',NULL,NULL,NULL,'7012345678:AAFakeResellerTokenForFixturesOnly',NULL,NULL,'{\"volume\":false,\"time\":false}','active'),('h1i7u3','5012345671','manual_cfg1','فروش دستی','1730540000','کانفیگ دستی','120000','0','30',NULL,NULL,NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','active'),('i9o8i2','5012345672','uk_sara','Hiddify-UK','1730550000','نامحدود یک ماهه','350000','0','30',NULL,NULL,NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','active'),('j8p9o1','5012345675','mina_test0','PasarGuard-FI','1730010000','سرویس تست','0','100','1',NULL,NULL,NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','disabled'),('k7a1p0','5012345673','agent2_old','Rebecca-Old','1725010000','پلن ویژه همکاران','90000','40','30',NULL,NULL,NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','active'),('l6s2a9','5012345671','rz_custom','آلمان-مرزبان','1731020000','⚙️ سرویس دلخواه','216000','50','30',NULL,'لپ‌تاپ',NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','disablebyadmin'),('m5d3s8','5012345672','sara_gone','هلند-ثنایی','1729000000','یک ماهه ۵۰ گیگ','140000','50','30',NULL,NULL,NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','disabledn'),('n4f4d7','5012345677','res_2','سوئد-ثنایی','1731710000','یک ماهه ۵۰ گیگ','180000','50','30',NULL,NULL,NULL,'7012345678:AAFakeResellerTokenForFixturesOnly',NULL,NULL,'{\"volume\":false,\"time\":false}','active'),('o3h5j2','5012345671','rz_disabled','آلمان-مرزبان','1729500000','یک ماهه ۵۰ گیگ','180000','50','30',NULL,NULL,NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','disabled'),('p2k6l1','1000000001','owner_gift','PasarGuard-FI','1726000000','یک ماهه ۵۰ گیگ','0','50','30',NULL,NULL,NULL,NULL,NULL,NULL,'{\"volume\":false,\"time\":false}','removebyadmin');
/*!40000 ALTER TABLE `invoice` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `manualsell`
--

DROP TABLE IF EXISTS `manualsell`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `manualsell` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `codepanel` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `codeproduct` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `namerecord` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contentrecord` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `manualsell`
--

LOCK TABLES `manualsell` WRITE;
/*!40000 ALTER TABLE `manualsell` DISABLE KEYS */;
INSERT INTO `manualsell` VALUES (1,'d5e6','a1b2','کانفیگ ۱','manual_cfg1','vless://11111111-2222-4333-8444-555555555555@203.0.113.10:443?type=tcp&security=reality#manual1','used'),(2,'d5e6','a1b2','کانفیگ ۲',NULL,'vless://66666666-7777-4888-8999-000000000000@203.0.113.11:443?type=tcp&security=reality#manual2','active');
/*!40000 ALTER TABLE `manualsell` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `marzban_panel`
--

DROP TABLE IF EXISTS `marzban_panel`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marzban_panel` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code_panel` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name_panel` varchar(255) COLLATE utf8mb4_bin DEFAULT NULL,
  `status` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url_panel` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `username_panel` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_panel` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agent` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sublink` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `config` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `MethodUsername` varchar(700) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `TestAccount` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `limit_panel` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `namecustom` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Methodextend` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `conecton` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `linksubx` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inboundid` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inboundstatus` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hosts` json,
  `inbound_deactive` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `time_usertest` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `val_usertest` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `secret_code` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `priceChangeloc` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `priceextravolume` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pricecustomvolume` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pricecustomtime` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `priceextratime` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mainvolume` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `maxvolume` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `maintime` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `maxtime` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status_extend` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `datelogin` text COLLATE utf8mb4_unicode_ci,
  `proxies` text COLLATE utf8mb4_unicode_ci,
  `inbounds` text COLLATE utf8mb4_unicode_ci,
  `subvip` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `changeloc` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `on_hold_test` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customvolume` text COLLATE utf8mb4_unicode_ci,
  `hide_user` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `marzban_panel`
--

LOCK TABLES `marzban_panel` WRITE;
/*!40000 ALTER TABLE `marzban_panel` DISABLE KEYS */;
INSERT INTO `marzban_panel` VALUES (1,'7a1c','آلمان-مرزبان','active','https://de.mirzashop.example.com:8000','admin','Secr3t-de','all','onsublink','offconfig','آیدی عددی + حروف و عدد رندوم','ONTestAccount','unlimted','vpn','ریست حجم و زمان','offconecton','https://de.mirzashop.example.com:8000','1','marzban','offinbounddisable',NULL,'0','1','100',NULL,'0','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"1000\",\"n\":\"1000\",\"n2\":\"1000\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"365\",\"n\":\"365\",\"n2\":\"365\"}','on_extend','{\"time\":\"1731800000\",\"access_token\":\"fake.jwt.token\"}','{\"vless\":{\"flow\":\"xtls-rprx-vision\"}}','{\"vless\":[\"VLESS TCP REALITY\"]}','offsubvip','offchangeloc','1','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}',NULL),(2,'3b9e','هلند-ثنایی','active','https://nl.mirzashop.example.com:2053/Xk9pQ','admin','xui\'pass\\2024','all','onsublink','offconfig','آیدی عددی + حروف و عدد رندوم','ONTestAccount','150','vpn','ریست حجم و زمان','offconecton','https://nl.mirzashop.example.com:2096/sub','3','x-ui_single','offinbounddisable',NULL,'0','1','100',NULL,'0','{\"f\":\"5000\",\"n\":\"4000\",\"n2\":\"3500\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"1000\",\"n\":\"1000\",\"n2\":\"1000\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"365\",\"n\":\"365\",\"n2\":\"365\"}','on_extend',NULL,NULL,NULL,'onsubvip','offchangeloc','1','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}','[\"5012345674\"]'),(3,'c4d2','PasarGuard-FI','active','https://fi.mirzashop.example.com','pgadmin','pg-pass-2025','all','onsublink','offconfig','آیدی عددی + حروف و عدد رندوم','ONTestAccount','unlimited','vpn','ریست حجم و زمان','offconecton','https://fi.mirzashop.example.com','1','marzban','offinbounddisable',NULL,'0','1','100',NULL,'0','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"1000\",\"n\":\"1000\",\"n2\":\"1000\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"365\",\"n\":\"365\",\"n2\":\"365\"}','on_extend',NULL,NULL,NULL,'offsubvip','offchangeloc','1','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}',NULL),(4,'d5e6','فروش دستی','active','null','null','null','all','onsublink','offconfig','آیدی عددی + حروف و عدد رندوم','ONTestAccount','unlimited','vpn','ریست حجم و زمان','offconecton','null','1','Manualsale','offinbounddisable',NULL,'0','1','100',NULL,'0','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"1000\",\"n\":\"1000\",\"n2\":\"1000\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"365\",\"n\":\"365\",\"n2\":\"365\"}','on_extend',NULL,NULL,NULL,'offsubvip','offchangeloc','1','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}',NULL),(5,'e7f8','Hiddify-UK','active','https://uk.mirzashop.example.com/Pz7Qm','null','null','n','onsublink','offconfig','آیدی عددی + حروف و عدد رندوم','ONTestAccount','unlimited','vpn','ریست حجم و زمان','offconecton','https://uk.mirzashop.example.com/Pz7Qm','1','hiddify','offinbounddisable',NULL,'0','1','100','5b0c2f1e-1a2b-4c3d-8e9f-0a1b2c3d4e5f','0','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"1000\",\"n\":\"1000\",\"n2\":\"1000\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"365\",\"n\":\"365\",\"n2\":\"365\"}','on_extend',NULL,NULL,NULL,'offsubvip','offchangeloc','1','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}',NULL),(6,'f9a0','سوئد-ثنایی','active','https://se.mirzashop.example.com:2053/Lm3Nb','null','xui-api-token-se-4567','all','onsublink','offconfig','آیدی عددی + حروف و عدد رندوم','ONTestAccount','unlimited','vpn','ریست حجم و زمان','offconecton','https://se.mirzashop.example.com:2053/Lm3Nb','1','x-ui_single','offinbounddisable',NULL,'0','1','100',NULL,'0','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"4000\",\"n\":\"4000\",\"n2\":\"4000\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"1000\",\"n\":\"1000\",\"n2\":\"1000\"}','{\"f\":\"1\",\"n\":\"1\",\"n2\":\"1\"}','{\"f\":\"365\",\"n\":\"365\",\"n2\":\"365\"}','on_extend',NULL,NULL,NULL,'offsubvip','offchangeloc','1','{\"f\":\"0\",\"n\":\"0\",\"n2\":\"0\"}',NULL);
/*!40000 ALTER TABLE `marzban_panel` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `Payment_report`
--

DROP TABLE IF EXISTS `Payment_report`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `Payment_report` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_user` varchar(200) DEFAULT NULL,
  `id_order` varchar(2000) DEFAULT NULL,
  `time` varchar(200) DEFAULT NULL,
  `at_updated` varchar(200) DEFAULT NULL,
  `price` varchar(200) DEFAULT NULL,
  `dec_not_confirmed` text,
  `Payment_Method` varchar(400) DEFAULT NULL,
  `payment_Status` varchar(100) DEFAULT NULL,
  `bottype` varchar(300) DEFAULT NULL,
  `message_id` int DEFAULT NULL,
  `id_invoice` varchar(1000) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `Payment_report`
--

LOCK TABLES `Payment_report` WRITE;
/*!40000 ALTER TABLE `Payment_report` DISABLE KEYS */;
INSERT INTO `Payment_report` VALUES (1,'5012345671','ord-1001','1730400000','1730400300','200000',NULL,'cart to cart','paid',NULL,101,'none'),(2,'5012345672','ord-1002','1730410000','1730410200','500000',NULL,'cart to cart','paid',NULL,102,'none'),(3,'5012345675','ord-1003','1731600000',NULL,'50000',NULL,'cart to cart','waiting',NULL,103,'none'),(4,'5012345677','ord-1004','1731705000',NULL,'100000',NULL,'cart to cart','waiting','7012345678:AAFakeResellerTokenForFixturesOnly',104,'none');
/*!40000 ALTER TABLE `Payment_report` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `PaySetting`
--

DROP TABLE IF EXISTS `PaySetting`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `PaySetting` (
  `NamePay` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ValuePay` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`NamePay`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `PaySetting`
--

LOCK TABLES `PaySetting` WRITE;
/*!40000 ALTER TABLE `PaySetting` DISABLE KEYS */;
INSERT INTO `PaySetting` VALUES ('cardnumber','6037997700001119'),('CartDirect','@mirzashop_support'),('Cartstatus','oncard'),('maxbalance','{\"f\":\"1000000\",\"n\":\"1000000\",\"n2\":\"1000000\"}'),('minbalance','{\"f\":\"20000\",\"n\":\"20000\",\"n2\":\"20000\"}'),('namecard','رضا کریمی'),('zarinpalstatus','offzarinpal');
/*!40000 ALTER TABLE `PaySetting` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product`
--

DROP TABLE IF EXISTS `product`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code_product` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name_product` varchar(255) COLLATE utf8mb4_bin DEFAULT NULL,
  `price_product` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Volume_constraint` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Location` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Service_time` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agent` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `data_limit_reset` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `one_buy_status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `inbounds` text COLLATE utf8mb4_unicode_ci,
  `proxies` text COLLATE utf8mb4_unicode_ci,
  `category` varchar(400) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hide_panel` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product`
--

LOCK TABLES `product` WRITE;
/*!40000 ALTER TABLE `product` DISABLE KEYS */;
INSERT INTO `product` VALUES (1,'a1b2','یک ماهه ۵۰ گیگ','180000','50','/all','30','f','مناسب استفاده روزمره','no_reset','0',NULL,NULL,'ماهانه','{}'),(2,'a1b3','یک ماهه ۵۰ گیگ','140000','50','/all','30','n','','no_reset','0',NULL,NULL,'ماهانه','{}'),(3,'c3d4','سه ماهه ۱۵۰ گیگ','450000','150','آلمان-مرزبان','90','f','','no_reset','0',NULL,NULL,'سه ماهه','{}'),(4,'e5f6','نامحدود یک ماهه','350000','0','/all','30','f','بدون محدودیت حجم\nفقط یک کاربر \"همزمان\" - مناسب \'خانواده\'','no_reset','1',NULL,NULL,'ماهانه','[\"آلمان-مرزبان\",\"هلند-ثنایی\"]'),(5,'g7h8','پلن ویژه همکاران','90000','40','PasarGuard-FI','30','n2','','month','0',NULL,NULL,'ماهانه','{}');
/*!40000 ALTER TABLE `product` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `Requestagent`
--

DROP TABLE IF EXISTS `Requestagent`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `Requestagent` (
  `id` varchar(500) NOT NULL,
  `username` varchar(500) NOT NULL,
  `time` varchar(500) NOT NULL,
  `Description` varchar(500) NOT NULL,
  `status` varchar(500) NOT NULL,
  `type` varchar(500) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `Requestagent`
--

LOCK TABLES `Requestagent` WRITE;
/*!40000 ALTER TABLE `Requestagent` DISABLE KEYS */;
/*!40000 ALTER TABLE `Requestagent` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_other`
--

DROP TABLE IF EXISTS `service_other`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_other` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_user` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `time` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `output` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_other`
--

LOCK TABLES `service_other` WRITE;
/*!40000 ALTER TABLE `service_other` DISABLE KEYS */;
INSERT INTO `service_other` VALUES (1,'5012345671','5012345671_rz1','10','1731000000','40000','extra_user','paid','{}'),(2,'5012345672','sara_x1','30','1731100000','120000','extend_user','paid','{}');
/*!40000 ALTER TABLE `service_other` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `setting`
--

DROP TABLE IF EXISTS `setting`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `setting` (
  `Bot_Status` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `roll_Status` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `get_number` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `iran_number` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `NotUser` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Channel_Report` varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `limit_usertest_all` varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `affiliatesstatus` varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `affiliatespercentage` varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `removedayc` varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `showcard` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numbercount` varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `statusnewuser` varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `statusagentrequest` varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `statuscategory` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `statusterffh` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `volumewarn` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inlinebtnmain` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verifystart` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_support` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `statusnamecustom` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `statuscategorygenral` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `statussupportpv` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agentreqprice` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bulkbuy` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `on_hold_day` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cronvolumere` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verifybucodeuser` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scorestatus` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Lottery_prize` text COLLATE utf8mb4_unicode_ci,
  `wheelـluck` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wheelـluck_price` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `btn_status_extned` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `daywarn` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `categoryhelp` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `linkappstatus` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `iplogin` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wheelagent` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Lotteryagent` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `languageen` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `languageru` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `statusfirstwheel` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `statuslimitchangeloc` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Debtsettlement` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Dice` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `keyboardmain` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `statusnoteforf` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `statuscopycart` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `timeauto_not_verify` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status_keyboard_config` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cron_status` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `limitnumber` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `setting`
--

LOCK TABLES `setting` WRITE;
/*!40000 ALTER TABLE `setting` DISABLE KEYS */;
INSERT INTO `setting` VALUES ('botstatuson','rolleon','offAuthenticationphone','offAuthenticationiran','offnotuser','-1001234500099','1','offaffiliates','0','0','1','0','onnewuser','onrequestagent','offcategory',NULL,'2','offinline','offverify','0','offnamecustom','offcategorys','offpvsupport','0','onbulk','4','5','offverify','0','{\"one\":\"0\",\"tow\":\"0\",\"theree\":\"0\"}','0','0',NULL,'2','0','0','0','1','1','0','0','0','0','1','0','{\"keyboard\":[[{\"text\":\"text_sell\"},{\"text\":\"text_extend\"}],[{\"text\":\"text_usertest\"},{\"text\":\"text_wheel_luck\"}]]}','1','0','4','1','{\"day\":true,\"volume\":true,\"remove\":false,\"remove_volume\":false,\"test\":false,\"on_hold\":false,\"uptime_node\":false,\"uptime_panel\":false}','{\"free\":100,\"all\":100}');
/*!40000 ALTER TABLE `setting` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shopSetting`
--

DROP TABLE IF EXISTS `shopSetting`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shopSetting` (
  `Namevalue` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`Namevalue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shopSetting`
--

LOCK TABLES `shopSetting` WRITE;
/*!40000 ALTER TABLE `shopSetting` DISABLE KEYS */;
INSERT INTO `shopSetting` VALUES ('chashbackextend_agent','{\"n\":0,\"n2\":0}'),('customvolmef','4000'),('statusextra','offextra');
/*!40000 ALTER TABLE `shopSetting` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `support_message`
--

DROP TABLE IF EXISTS `support_message`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_message` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `Tracking` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `idsupport` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `iduser` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_departman` varchar(600) COLLATE utf8mb4_unicode_ci NOT NULL,
  `text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `result` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `time` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('Answered','Pending','Unseen','Customerresponse','close') COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `support_message`
--

LOCK TABLES `support_message` WRITE;
/*!40000 ALTER TABLE `support_message` DISABLE KEYS */;
INSERT INTO `support_message` VALUES (1,'48213','1000000001','5012345671','☎️ بخش عمومی','سلام، سرویس وصل نمیشه','سلام، لطفا دوباره امتحان کنید','1730600000','Answered'),(2,'48213','1000000001','5012345671','☎️ بخش عمومی','ممنون، درست شد','0','1730601000','Customerresponse'),(3,'59102','1000000001','5012345673','☎️ بخش عمومی','بدهی رو چطوری تسویه کنم؟','0','1731700000','Unseen');
/*!40000 ALTER TABLE `support_message` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `textbot`
--

DROP TABLE IF EXISTS `textbot`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `textbot` (
  `id_text` varchar(600) COLLATE utf8mb4_unicode_ci NOT NULL,
  `text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id_text`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `textbot`
--

LOCK TABLES `textbot` WRITE;
/*!40000 ALTER TABLE `textbot` DISABLE KEYS */;
INSERT INTO `textbot` VALUES ('text_start','سلام، به فروشگاه خوش آمدید\nبرای خرید از منوی زیر استفاده کنید'),('text_usertest','🔑 اکانت تست');
/*!40000 ALTER TABLE `textbot` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `topicid`
--

DROP TABLE IF EXISTS `topicid`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `topicid` (
  `report` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `idreport` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`report`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `topicid`
--

LOCK TABLES `topicid` WRITE;
/*!40000 ALTER TABLE `topicid` DISABLE KEYS */;
INSERT INTO `topicid` VALUES ('backupfile','12'),('buyreport','4');
/*!40000 ALTER TABLE `topicid` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user`
--

DROP TABLE IF EXISTS `user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user` (
  `id` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `limit_usertest` int NOT NULL,
  `roll_Status` tinyint(1) NOT NULL,
  `username` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `Processing_value` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `Processing_value_one` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `Processing_value_tow` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `Processing_value_four` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `step` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description_blocking` text COLLATE utf8mb4_unicode_ci,
  `number` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `Balance` int NOT NULL,
  `User_Status` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pagenumber` int NOT NULL,
  `message_count` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_message_time` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `agent` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `affiliatescount` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `affiliates` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `namecustom` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `number_username` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `register` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `verify` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cardpayment` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `codeInvitation` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pricediscount` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '0',
  `maxbuyagent` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '0',
  `joinchannel` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '0',
  `checkstatus` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '0',
  `bottype` text COLLATE utf8mb4_unicode_ci,
  `score` int DEFAULT '0',
  `limitchangeloc` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '0',
  `status_cron` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT '1',
  `expire` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user`
--

LOCK TABLES `user` WRITE;
/*!40000 ALTER TABLE `user` DISABLE KEYS */;
INSERT INTO `user` VALUES ('1000000001',1,1,'shop_owner','none','','','','home',NULL,'none',0,'Active',1,'0','0','f','0','0','none','100','1725000000','1','1',NULL,'0','0','0','0','0',0,'0','1',NULL,NULL),('5012345671',1,1,'reza_karimi','none','','','','home',NULL,'+989121112233',85000,'Active',1,'12','1731800000','f','1','0','none','100','1730000000','1','1',NULL,'0','0','0','0','0',0,'0','1',NULL,NULL),('5012345672',1,1,'sara_agent','none','','','','home',NULL,'989351234567',420000,'Active',1,'40','1731850000','n','0','0','none','100','1730100000','1','1',NULL,'0','0','active','0','0',25,'0','1',NULL,NULL),('5012345673',0,1,'none','none','','','','home',NULL,'none',-125000,'Active',1,'3','1731700000','n2','0','0','none','100','1730200000','1','0',NULL,'0','0','0','0','0',0,'0','1','1924992000',NULL),('5012345674',1,1,'blocked_user','none','','','','home','اسپم در پشتیبانی\nبار دوم: \"تهدید\" و \'توهین\' (100\\%)','none',0,'block',1,'90','1731300000','f','0','0','none','100','1730300000','1','1',NULL,'0','0','0','0','0',0,'0','1',NULL,NULL),('5012345675',0,1,'mina_ref','none','','','','home',NULL,'confrim number by admin',12000,'Active',1,'5','1731900000','f','0','5012345671','none','100','1731000000','1','1',NULL,'15','0','0','0','0',0,'0','1',NULL,NULL),('5012345676',1,1,'res_customer','none','','','','home',NULL,'none',30000,'Active',1,'2','1731600000','f','0','0','none','100','1731500000','1','1',NULL,'0','0','0','0','7012345678:AAFakeResellerTokenForFixturesOnly',0,'0','1',NULL,NULL),('5012345677',1,1,'res_only','none','','','','home',NULL,'none',0,'Active',1,'4','1731710000','f','0','0','none','100','1731690000','1','0',NULL,'0','0','active','0','7012345678:AAFakeResellerTokenForFixturesOnly',0,'0','1',NULL,NULL);
/*!40000 ALTER TABLE `user` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-04-18  3:30:01
