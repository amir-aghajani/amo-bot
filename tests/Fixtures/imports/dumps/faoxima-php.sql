-- Database: `faoxima_bot`
-- Generated at: 2026-11-10T13:30:00+03:30

SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `DiscountSell`;
CREATE TABLE `DiscountSell` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codeDiscount` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `limitDiscount` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `usedDiscount` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `DiscountSell` (`id`, `codeDiscount`, `price`, `limitDiscount`, `usedDiscount`) VALUES ('1', 'NOWRUZ', '20', '100', '7');
INSERT INTO `DiscountSell` (`id`, `codeDiscount`, `price`, `limitDiscount`, `usedDiscount`) VALUES ('2', 'off\'50', '50', '0', '0');

DROP TABLE IF EXISTS `Payment_report`;
CREATE TABLE `Payment_report` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_user` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_Status` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `Payment_report` (`id`, `id_user`, `price`, `payment_Status`) VALUES ('1', '1000001', '50000', 'paid');
INSERT INTO `Payment_report` (`id`, `id_user`, `price`, `payment_Status`) VALUES ('2', '1000002', '120000', 'Unpaid');
INSERT INTO `Payment_report` (`id`, `id_user`, `price`, `payment_Status`) VALUES ('3', '1000001', '75000', NULL);

DROP TABLE IF EXISTS `setting`;
CREATE TABLE `setting` (
  `Bot_Status` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wheelـluck` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wheelـluck_price` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `keyboardmain` text COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `setting` (`Bot_Status`, `wheelـluck`, `wheelـluck_price`, `keyboardmain`) VALUES ('botstatuson', '1', '0', '{\"keyboard\":[[{\"text\":\"text_sell\"},{\"text\":\"text_extend\"}]]}');

DROP TABLE IF EXISTS `user`;
CREATE TABLE `user` (
  `id` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `number` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `Balance` int(11) NOT NULL DEFAULT 0,
  `User_Status` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `agent` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'f',
  `register` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `description_blocking` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `user` (`id`, `username`, `number`, `Balance`, `User_Status`, `agent`, `register`, `description_blocking`) VALUES ('1000001', 'reza', '989121112233', '0', 'Active', 'f', '1700000000', NULL);
INSERT INTO `user` (`id`, `username`, `number`, `Balance`, `User_Status`, `agent`, `register`, `description_blocking`) VALUES ('1000002', 'sara', 'none', '-5000', 'block', 'n', 'none', 'line one\nline two\r\nwith \\ a backslash, a \'quote\' and \"double\", a nul \0 and a sub \Z');
INSERT INTO `user` (`id`, `username`, `number`, `Balance`, `User_Status`, `agent`, `register`, `description_blocking`) VALUES ('1000003', 'مریم', 'confrim number by admin', '1200000', 'Active', 'n2', '1700003600', '');

SET FOREIGN_KEY_CHECKS=1;
