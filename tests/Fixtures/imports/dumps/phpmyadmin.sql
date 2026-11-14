-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Nov 10, 2026 at 01:30 PM
-- Server version: 10.6.19-MariaDB-cll-lve
-- PHP Version: 8.1.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `mybot_db`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`mybot`@`localhost` PROCEDURE `reset_balances` ()   BEGIN
  INSERT INTO `user` (`id`, `username`) VALUES ('7777777', 'procedure');
  UPDATE `user` SET `Balance` = 0;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `channels`
--

CREATE TABLE `channels` (
  `link` varchar(200) NOT NULL,
  `remark` varchar(200) DEFAULT NULL,
  `linkjoin` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `channels`
--

INSERT INTO `channels` (`link`, `remark`, `linkjoin`) VALUES
('@my_channel', 'کانال اصلی', 'https://t.me/my_channel'),
('-1001234567890', 'It\'s private', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `marzban_panel`
--

CREATE TABLE `marzban_panel` (
  `id` int(6) UNSIGNED NOT NULL,
  `name_panel` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url_panel` varchar(2000) DEFAULT NULL,
  `username_panel` varchar(200) DEFAULT NULL,
  `password_panel` varchar(200) DEFAULT NULL,
  `api_key` varchar(500) DEFAULT NULL,
  `type` varchar(100) DEFAULT NULL,
  `secret_code` blob DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `marzban_panel`
--

INSERT INTO `marzban_panel` (`id`, `name_panel`, `url_panel`, `username_panel`, `password_panel`, `api_key`, `type`, `secret_code`) VALUES
(1, 'Germany', 'https://de.example.com:8000', 'admin', 'secret#1', NULL, 'marzban', 0x6b6579),
(2, 'Finland', 'https://fi.example.com/panel', NULL, NULL, 'api-key-xyz', 'pasarguard', '');

-- --------------------------------------------------------

--
-- Table structure for table `user`
--

CREATE TABLE `user` (
  `id` varchar(500) NOT NULL,
  `username` varchar(500) NOT NULL DEFAULT '',
  `number` varchar(300) NOT NULL DEFAULT 'none',
  `Balance` int(11) NOT NULL DEFAULT 0,
  `User_Status` varchar(500) NOT NULL DEFAULT 'Active',
  `agent` varchar(100) NOT NULL DEFAULT 'f',
  `register` varchar(100) NOT NULL DEFAULT 'none',
  `description_blocking` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user`
--

INSERT INTO `user` (`id`, `username`, `number`, `Balance`, `User_Status`, `agent`, `register`, `description_blocking`) VALUES
('1000001', 'ali_vpn', '+989121234567', 150000, 'Active', 'f', '1700000000', NULL),
('1000002', '', 'none', -25000, 'block', 'n2', 'none', 'It\'s blocked'),
('1000003', 'نیما', 'none', 0, 'Active', 'n', '1700000500', NULL);

--
-- Triggers `user`
--
DELIMITER $$
CREATE TRIGGER `after_user` AFTER INSERT ON `user` FOR EACH ROW BEGIN
  INSERT INTO `channels` (`link`) VALUES (NEW.id);
END
$$
DELIMITER ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `marzban_panel`
--
ALTER TABLE `marzban_panel`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `user`
--
ALTER TABLE `user`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `marzban_panel`
--
ALTER TABLE `marzban_panel`
  MODIFY `id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
