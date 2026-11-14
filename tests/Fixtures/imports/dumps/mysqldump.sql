-- MySQL dump 10.13  Distrib 8.0.39, for Linux (x86_64)
--
-- Host: localhost    Database: vpnbot
-- ------------------------------------------------------
-- Server version	8.0.39-0ubuntu0.22.04.1

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
-- Table structure for table `marzban_panel`
--

DROP TABLE IF EXISTS `marzban_panel`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marzban_panel` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code_panel` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name_panel` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url_panel` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `username_panel` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_panel` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `api_key` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hosts` json DEFAULT NULL,
  `secret_code` blob,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='panels; (the bot''s)';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `marzban_panel`
--

LOCK TABLES `marzban_panel` WRITE;
/*!40000 ALTER TABLE `marzban_panel` DISABLE KEYS */;
INSERT INTO `marzban_panel` VALUES (1,'a1b2','آلمان ۱','https://de.example.com:2053','admin','p@ss;word\'s',NULL,'x-ui_single','{\"de\": [\"a\", \"b\"]}',_binary 'k\0\Z'),(2,'c3d4','Netherlands','https://nl.example.com','root','',
'tok-123','marzban',NULL,NULL);
/*!40000 ALTER TABLE `marzban_panel` ENABLE KEYS */;
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
INSERT INTO `textbot` VALUES ('text_start','سلام! به ربات خوش آمدید;\n-- not a comment\n/* not a comment either */'),('text_help','INSERT INTO `user` VALUES (\'0\');');
/*!40000 ALTER TABLE `textbot` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user`
--

DROP TABLE IF EXISTS `user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user` (
  `id` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `limit_usertest` int NOT NULL DEFAULT '1',
  `username` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `number` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `Balance` int NOT NULL DEFAULT '0',
  `User_Status` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `agent` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'f',
  `register` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `description_blocking` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `idx_agent` (`agent`(10))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user`
--

LOCK TABLES `user` WRITE;
/*!40000 ALTER TABLE `user` DISABLE KEYS */;
INSERT INTO `user` VALUES ('1000001',1,'ali_vpn','+989121234567',150000,'Active','f','1700000000',NULL),('1000002',0,'','none',-25000,'block','n2','none','بدهکار؛ \"مسدود\" شد\r\nخط دوم\ttab \\ backslash 100\% (و پرانتز), یک \'نقل\''),('1000003',1,'نیما_۱','989351112233',0,'Active','n','1700000500','');
INSERT INTO `user` VALUES ('1000004',1,'zahra','confirm number by admin',2500,'Active','f','none',NULL);
/*!40000 ALTER TABLE `user` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'vpnbot'
--
/*!50003 DROP PROCEDURE IF EXISTS `reset_test` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES' */ ;
DELIMITER ;;
CREATE DEFINER=`vpnbot`@`localhost` PROCEDURE `reset_test`()
BEGIN
  INSERT INTO `user` VALUES ('9999999',1,'procedure','none',0,'Active','f','none',NULL);
  UPDATE `user` SET `limit_usertest` = 1;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-10 13:30:00
