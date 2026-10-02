-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 03, 2026 at 12:40 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ojtrack`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `activity_log`
--

INSERT INTO `activity_log` (`id`, `user_id`, `action`, `details`, `ip_address`, `created_at`) VALUES
(1, 6, 'Login', 'Successful login', '192.168.1.45', '2026-09-28 08:23:20'),
(2, 2, 'Requirement Approved', 'Approved Medical Certificate for Maria Santos', '192.168.1.10', '2026-09-28 08:23:20'),
(3, 4, 'Evaluation Submitted', 'Mid-Term Evaluation for Maria Santos', '10.0.0.24', '2026-09-28 08:23:20'),
(4, 7, 'Login', 'Successful login', '192.168.1.88', '2026-09-28 08:23:20'),
(5, 1, 'User Created', 'New user: Carlo Mendoza (Student)', '192.168.1.1', '2026-09-28 08:23:20'),
(6, 1, 'Login', 'Successful login', '::1', '2026-09-28 08:25:51'),
(7, 1, 'Login', 'Successful login', '::1', '2026-09-28 09:18:41'),
(8, 1, 'Login', 'Successful login', '::1', '2026-09-28 10:14:14'),
(9, 6, 'Login', 'Successful login', '::1', '2026-09-28 10:18:24'),
(10, 2, 'Login', 'Successful login', '::1', '2026-09-28 10:18:24'),
(11, 4, 'Login', 'Successful login', '::1', '2026-09-28 10:18:25'),
(12, 1, 'Login', 'Successful login', '::1', '2026-09-28 10:18:25'),
(13, 4, 'Login', 'Successful login', '::1', '2026-09-28 10:19:52'),
(14, 2, 'Login', 'Successful login', '::1', '2026-09-28 10:22:35'),
(15, 6, 'Login', 'Successful login', '::1', '2026-09-28 10:24:59'),
(16, 1, 'Login', 'Successful login', '::1', '2026-09-28 20:38:54'),
(17, 1, 'Login', 'Successful login', '::1', '2026-09-28 20:44:54'),
(18, 1, 'Login', 'Successful login', '::1', '2026-09-28 20:48:23'),
(19, 1, 'Login', 'Successful login', '::1', '2026-09-28 21:19:52'),
(20, 1, 'Login', 'Successful login', '::1', '2026-09-28 21:32:25'),
(21, 1, 'Login', 'Successful login', '::1', '2026-09-28 21:34:43'),
(22, 1, 'Login', 'Successful login', '::1', '2026-09-28 21:38:03'),
(23, 1, 'Login', 'Successful login', '::1', '2026-09-28 21:40:16'),
(24, 1, 'Login', 'Successful login', '::1', '2026-09-28 21:47:43'),
(25, 1, 'Login', 'Successful login', '::1', '2026-09-28 21:50:07'),
(26, 1, 'Login', 'Successful login', '::1', '2026-09-28 21:57:47'),
(27, 1, 'Login', 'Successful login', '::1', '2026-09-28 22:00:43'),
(28, 1, 'Login', 'Successful login', '::1', '2026-09-28 22:02:37'),
(29, 8, 'Login', 'Successful login', '::1', '2026-09-28 22:02:49'),
(30, 8, 'Login', 'Successful login', '::1', '2026-09-28 22:07:05'),
(31, 8, 'Message Sent', 'Thread: 3', '::1', '2026-09-28 22:10:25'),
(32, 1, 'Login', 'Successful login', '::1', '2026-09-28 22:10:50'),
(33, 2, 'Login', 'Successful login', '::1', '2026-09-28 22:11:09'),
(34, 1, 'Login', 'Successful login', '::1', '2026-09-28 22:13:32'),
(35, 1, 'Login', 'Successful login', '::1', '2026-09-28 22:15:19'),
(36, 1, 'Login', 'Successful login', '::1', '2026-09-28 22:17:01'),
(37, 1, 'Login', 'Successful login', '::1', '2026-09-28 22:17:46'),
(38, 1, 'Login', 'Successful login', '::1', '2026-09-28 22:18:22'),
(39, 1, 'Login', 'Successful login', '::1', '2026-09-28 22:18:33'),
(40, 3, 'Login', 'Successful login', '::1', '2026-09-28 22:19:08'),
(41, 1, 'Login', 'Successful login', '::1', '2026-09-28 22:20:01'),
(42, 4, 'Login', 'Successful login', '::1', '2026-09-28 22:20:22'),
(43, 1, 'Login', 'Successful login', '::1', '2026-09-28 22:21:58'),
(44, 8, 'Login', 'Successful login', '::1', '2026-09-28 22:22:28'),
(45, 3, 'Login', 'Successful login', '::1', '2026-09-28 22:23:27'),
(46, 1, 'Login', 'Successful login', '::1', '2026-09-28 22:28:11'),
(47, 8, 'Login', 'Successful login', '::1', '2026-09-28 22:28:29'),
(48, 1, 'Login', 'Successful login', '::1', '2026-09-28 22:29:28'),
(49, 2, 'Login', 'Successful login', '::1', '2026-09-28 22:29:44'),
(50, 8, 'Login', 'Successful login', '::1', '2026-09-28 22:34:45'),
(51, 8, 'Attendance Deleted', 'Record ID: 11', '::1', '2026-09-28 22:46:42'),
(52, 8, 'Attendance Deleted', 'Record ID: 11', '::1', '2026-09-28 22:46:47'),
(53, 8, 'Attendance Deleted', 'Record ID: 12', '::1', '2026-09-28 22:49:38'),
(54, 8, 'Attendance Deleted', 'Record ID: 12', '::1', '2026-09-28 22:49:42'),
(55, 8, 'Attendance Deleted', 'Record ID: 13', '::1', '2026-09-28 22:49:49'),
(56, 8, 'Attendance Deleted', 'Record ID: 14', '::1', '2026-09-28 22:50:01'),
(57, 8, 'Attendance Deleted', 'Record ID: 15', '::1', '2026-09-28 22:51:31'),
(58, 8, 'Attendance Deleted', 'Record ID: 16', '::1', '2026-09-28 22:53:09'),
(59, 1, 'Login', 'Successful login', '::1', '2026-09-28 22:53:46'),
(60, 2, 'Login', 'Successful login', '::1', '2026-09-28 22:54:14'),
(61, 1, 'Login', 'Successful login', '::1', '2026-09-29 04:18:47'),
(62, 1, 'User Status Changed', 'User ID: 8 set to inactive', '::1', '2026-09-29 04:22:56'),
(63, 1, 'User Status Changed', 'User ID: 8 set to active', '::1', '2026-09-29 04:22:58'),
(64, 1, 'Login', 'Successful login', '::1', '2026-09-29 04:28:28'),
(65, 3, 'Login', 'Successful login', '::1', '2026-09-29 04:28:42'),
(66, 1, 'Login', 'Successful login', '::1', '2026-09-29 04:33:06'),
(67, 1, 'Student Assignment Updated', 'Updated assignments for Ana Reyes', '::1', '2026-09-29 04:37:06'),
(68, 3, 'Requirement Added', 'Medical Cert for Ana Reyes', '::1', '2026-09-29 04:53:57'),
(69, 1, 'Login', 'Successful login', '::1', '2026-09-29 04:54:58'),
(70, 1, 'Student Assignment Updated', 'Updated assignments for Ana Reyes', '::1', '2026-09-29 04:55:45'),
(71, 8, 'Login', 'Successful login', '::1', '2026-09-29 05:00:30'),
(72, 8, 'Requirement Submitted', 'Requirement ID 10', '::1', '2026-09-29 05:00:44'),
(73, 3, 'Requirement Approved', 'Req ID: 10 (Medical Cert) for Ana Reyes', '::1', '2026-09-29 05:22:09'),
(74, 1, 'Login', 'Successful login', '::1', '2026-09-29 06:21:01'),
(75, 8, 'Login', 'Successful login', '::1', '2026-09-29 06:21:19'),
(76, 1, 'Login', 'Successful login', '::1', '2026-09-29 06:24:34'),
(77, 8, 'Login', 'Successful login', '::1', '2026-09-29 06:25:43'),
(78, 1, 'Login', 'Successful login', '::1', '2026-09-29 06:25:52'),
(79, 1, 'Login', 'Successful login', '::1', '2026-09-29 06:37:33'),
(80, 2, 'Login', 'Successful login', '::1', '2026-09-29 06:38:12'),
(81, 1, 'Login', 'Successful login', '::1', '2026-09-29 06:40:35'),
(82, 1, 'Login', 'Successful login', '::1', '2026-09-29 07:44:17'),
(83, 1, 'Student Assignment Updated', 'Updated assignments for Ana Reyes', '::1', '2026-09-29 07:45:59'),
(84, 1, 'Login', 'Successful login', '::1', '2026-09-29 07:47:05'),
(85, 9, 'Login', 'Successful login', '::1', '2026-09-29 07:47:32'),
(86, 1, 'Login', 'Successful login', '::1', '2026-09-29 07:54:31'),
(87, 2, 'Login', 'Successful login', '::1', '2026-09-29 07:54:50'),
(88, 1, 'Login', 'Successful login', '::1', '2026-09-29 09:58:28'),
(89, 1, 'User Status Changed', 'User ID: 8 set to inactive', '::1', '2026-09-29 10:05:16'),
(90, 1, 'User Status Changed', 'User ID: 8 set to active', '::1', '2026-09-29 10:05:18'),
(91, 3, 'Login', 'Successful login', '::1', '2026-09-29 10:18:13'),
(92, 1, 'Login', 'Successful login', '::1', '2026-09-29 10:29:58'),
(93, 4, 'Login', 'Successful login', '::1', '2026-09-29 10:30:14'),
(94, 4, 'Login', 'Successful login', '::1', '2026-09-29 10:35:23'),
(95, 1, 'Login', 'Successful login', '::1', '2026-09-29 10:35:38'),
(96, 9, 'Login', 'Successful login', '::1', '2026-09-29 10:35:48'),
(97, 1, 'Login', 'Successful login', '::1', '2026-10-01 01:42:30'),
(98, 3, 'Login', 'Successful login', '::1', '2026-10-01 02:08:48'),
(99, 1, 'Login', 'Successful login', '::1', '2026-10-01 02:25:57'),
(100, 4, 'Login', 'Successful login', '::1', '2026-10-01 02:26:08'),
(101, 1, 'Login', 'Successful login', '::1', '2026-10-01 02:30:53'),
(102, 8, 'Login', 'Successful login', '::1', '2026-10-01 02:31:04'),
(103, 1, 'Login', 'Successful login', '::1', '2026-10-01 04:51:52'),
(104, 1, 'Login', 'Successful login', '::1', '2026-10-01 05:19:56'),
(105, 1, 'Login', 'Successful login', '::1', '2026-10-01 05:24:21'),
(106, 1, 'Login', 'Successful login', '::1', '2026-10-01 05:25:30'),
(107, 1, 'Login', 'Successful login', '::1', '2026-10-01 05:31:14'),
(108, 1, 'Login', 'Successful login', '::1', '2026-10-01 06:09:17'),
(109, 1, 'Login', 'Successful login', '::1', '2026-10-01 06:24:58'),
(110, 1, 'Login', 'Successful login', '::1', '2026-10-01 06:34:36'),
(111, 1, 'Login', 'Successful login', '::1', '2026-10-01 06:38:01'),
(112, 1, 'Login', 'Successful login', '::1', '2026-10-01 06:38:24'),
(113, 1, 'Login', 'Successful login', '::1', '2026-10-01 06:38:33'),
(114, 1, 'Login', 'Successful login', '::1', '2026-10-01 06:41:03'),
(115, 1, 'Login', 'Successful login', '::1', '2026-10-01 06:52:23'),
(116, 1, 'Login', 'Successful login', '::1', '2026-10-01 07:02:35'),
(117, 1, 'User Status Changed', 'User ID: 8 set to inactive', '::1', '2026-10-01 07:02:43'),
(118, 1, 'User Status Changed', 'User ID: 8 set to active', '::1', '2026-10-01 07:02:44'),
(119, 1, 'User Status Changed', 'User ID: 8 set to inactive', '::1', '2026-10-01 07:03:41'),
(120, 1, 'User Status Changed', 'User ID: 8 set to active', '::1', '2026-10-01 07:03:43'),
(121, 1, 'Login', 'Successful login', '::1', '2026-10-01 07:14:33'),
(122, 1, 'Login', 'Successful login', '::1', '2026-10-01 07:19:40'),
(123, 1, 'Login', 'Successful login', '::1', '2026-10-01 07:23:40'),
(124, 1, 'Login', 'Successful login', '::1', '2026-10-01 07:23:51'),
(125, 1, 'Login', 'Successful login', '::1', '2026-10-01 10:47:46'),
(126, 1, 'Login', 'Successful login', '::1', '2026-10-01 10:52:55'),
(127, 1, 'Login', 'Successful login', '::1', '2026-10-01 12:02:54'),
(128, 1, 'Login', 'Successful login', '::1', '2026-10-01 12:06:42'),
(129, 1, 'Student Archived', 'Archived student Ana Reyes (ID: 3)', '::1', '2026-10-01 12:35:33'),
(130, 1, 'Student Archived', 'Archived student Carlo Mendoza (ID: 4)', '::1', '2026-10-01 13:37:57'),
(131, 1, 'User Restored', 'User ID: 9 (Carlo Mendoza / student)', '::1', '2026-10-01 13:38:24'),
(132, 1, 'User Restored', 'User ID: 8 (Ana Reyes / student)', '::1', '2026-10-01 13:38:26'),
(133, 1, 'Login', 'Successful login', '::1', '2026-10-01 13:59:02'),
(134, 1, 'Login', 'Successful login', '::1', '2026-10-01 13:59:23'),
(135, 3, 'Login', 'Successful login', '::1', '2026-10-01 14:00:41'),
(136, 1, 'Login', 'Successful login', '::1', '2026-10-01 14:03:58'),
(137, 1, 'Login', 'Successful login', '::1', '2026-10-01 14:06:30'),
(138, 1, 'Student Assignment Updated', 'Updated assignments for Ana Reyes → Program: BSCS, Coord: Prof. Jocelyn Rivera', '::1', '2026-10-01 14:32:30'),
(139, 1, 'Login', 'Successful login', '::1', '2026-10-01 14:33:25'),
(140, 1, 'Login', 'Successful login', '::1', '2026-10-01 14:39:43'),
(141, 1, 'Login', 'Successful login', '::1', '2026-10-01 14:45:48'),
(142, 1, 'Login', 'Successful login', '::1', '2026-10-01 14:58:05'),
(143, 1, 'Announcement Posted', 'Hi Everyone', '::1', '2026-10-01 14:59:20'),
(144, 3, 'Login', 'Successful login', '::1', '2026-10-01 14:59:55'),
(145, 1, 'Login', 'Successful login', '::1', '2026-10-01 15:01:28'),
(146, 8, 'Login', 'Successful login', '::1', '2026-10-01 15:01:41'),
(147, 1, 'Login', 'Successful login', '::1', '2026-10-01 15:03:48'),
(148, 4, 'Login', 'Successful login', '::1', '2026-10-01 15:04:02'),
(149, 1, 'Login', 'Successful login', '::1', '2026-10-01 15:04:56'),
(150, 1, 'Login', 'Successful login', '::1', '2026-10-01 21:07:29'),
(151, 5, 'Login', 'Successful login', '::1', '2026-10-01 21:23:21'),
(152, 1, 'Login', 'Successful login', '::1', '2026-10-01 21:24:44'),
(153, 1, 'Login', 'Successful login', '::1', '2026-10-01 21:28:00'),
(154, 1, 'Login', 'Successful login', '::1', '2026-10-02 00:23:39'),
(155, 1, 'Login', 'Successful login', '::1', '2026-10-02 01:03:22'),
(156, 2, 'Login', 'Successful login', '::1', '2026-10-02 01:03:35'),
(157, 2, 'Login', 'Successful login', '::1', '2026-10-02 01:07:23'),
(158, 1, 'Login', 'Successful login', '::1', '2026-10-02 01:09:24'),
(159, 1, 'Login', 'Successful login', '::1', '2026-10-02 02:17:02'),
(160, 1, 'Student Assignment Updated', 'Updated assignments for Ana Reyes → Program: BSCS, Coord: Prof. Jocelyn Rivera', '::1', '2026-10-02 02:41:40'),
(161, 1, 'Student Assignment Updated', 'Updated assignments for Ana Reyes → Program: BSCS, Coord: Prof. Jocelyn Rivera', '::1', '2026-10-02 02:41:47'),
(162, 1, 'Login', 'Successful login', '::1', '2026-10-02 02:49:38'),
(163, 1, 'Login', 'Successful login', '::1', '2026-10-02 02:49:43'),
(164, 4, 'Login', 'Successful login', '::1', '2026-10-02 03:38:16'),
(165, 1, 'Login', 'Successful login', '::1', '2026-10-02 03:40:27'),
(166, 1, 'Login', 'Successful login', '::1', '2026-10-02 03:44:02'),
(167, 5, 'Login', 'Successful login', '::1', '2026-10-02 03:44:19'),
(168, 1, 'Login', 'Successful login', '::1', '2026-10-02 03:46:43'),
(169, 5, 'Login', 'Successful login', '::1', '2026-10-02 03:47:55'),
(170, 1, 'Login', 'Successful login', '::1', '2026-10-02 03:50:20'),
(171, 1, 'Login', 'Successful login', '::1', '2026-10-02 03:51:24'),
(172, 1, 'Login', 'Successful login', '::1', '2026-10-02 03:58:53'),
(173, 5, 'Login', 'Successful login', '::1', '2026-10-02 03:59:11'),
(174, 1, 'Login', 'Successful login', '::1', '2026-10-02 04:06:52'),
(175, 5, 'Login', 'Successful login', '::1', '2026-10-02 04:07:12'),
(176, 1, 'Login', 'Successful login', '::1', '2026-10-02 04:12:18'),
(177, 5, 'Login', 'Successful login', '::1', '2026-10-02 04:19:44'),
(178, 1, 'Login', 'Successful login', '::1', '2026-10-02 04:19:55'),
(179, 2, 'Login', 'Successful login', '::1', '2026-10-02 04:20:16'),
(180, 2, 'Requirement Rejected', 'Req ID: 4 (Parents\' Consent Form) for Maria Santos', '::1', '2026-10-02 04:38:35'),
(181, 2, 'Requirements Added', '1 template(s)', '::1', '2026-10-02 04:58:32'),
(182, 2, 'Requirements Added', '1 template(s)', '::1', '2026-10-02 04:59:06'),
(183, 2, 'Requirements Added', '1 template(s)', '::1', '2026-10-02 04:59:25'),
(184, 2, 'Requirements Sent', '3 assignment(s)', '::1', '2026-10-02 05:00:05'),
(185, 1, 'Login', 'Successful login', '::1', '2026-10-02 05:00:31'),
(186, 8, 'Login', 'Successful login', '::1', '2026-10-02 05:00:46'),
(187, 8, 'Requirement Submitted', 'Requirement ID 11', '::1', '2026-10-02 05:02:09'),
(188, 8, 'Requirement Submitted', 'Requirement ID 12', '::1', '2026-10-02 05:02:14'),
(189, 8, 'Requirement Submitted', 'Requirement ID 13', '::1', '2026-10-02 05:02:19'),
(190, 2, 'Login', 'Successful login', '::1', '2026-10-02 05:02:32'),
(191, 2, 'Requirement Approved', 'Req ID: 13 (Medical Certificate) for Ana Reyes', '::1', '2026-10-02 05:02:52'),
(192, 2, 'Requirement Approved', 'Req ID: 12 (Birth Certificate) for Ana Reyes', '::1', '2026-10-02 05:02:54'),
(193, 2, 'Requirement Approved', 'Req ID: 11 (Parent Consent) for Ana Reyes', '::1', '2026-10-02 05:02:56'),
(194, 8, 'Login', 'Successful login', '::1', '2026-10-02 05:03:32'),
(195, 1, 'Login', 'Successful login', '::1', '2026-10-02 05:06:35'),
(196, 1, 'Login', 'Successful login', '::1', '2026-10-02 05:07:14'),
(197, 3, 'Login', 'Successful login', '::1', '2026-10-02 05:07:34'),
(198, 3, 'Profile Updated', '', '::1', '2026-10-02 05:08:30'),
(199, 1, 'Login', 'Successful login', '::1', '2026-10-02 05:08:44'),
(200, 1, 'Login', 'Successful login', '::1', '2026-10-02 05:13:13'),
(201, 3, 'Login', 'Successful login', '::1', '2026-10-02 05:13:27'),
(202, 2, 'Login', 'Successful login', '::1', '2026-10-02 05:13:59'),
(203, 1, 'Login', 'Successful login', '::1', '2026-10-02 05:16:54'),
(204, 1, 'Login', 'Successful login', '::1', '2026-10-02 05:19:31'),
(205, 1, 'Login', 'Successful login', '::1', '2026-10-02 05:24:59'),
(206, 5, 'Login', 'Successful login', '::1', '2026-10-02 05:25:19'),
(207, 5, 'Certificate Template Updated', '', '::1', '2026-10-02 05:25:59'),
(208, 1, 'Login', 'Successful login', '::1', '2026-10-02 05:26:36'),
(209, 4, 'Login', 'Successful login', '::1', '2026-10-02 05:26:49'),
(210, 4, 'Certificate Template Updated', '', '::1', '2026-10-02 05:34:30'),
(211, 4, 'Certificate Template Updated', '', '::1', '2026-10-02 05:34:41'),
(212, 4, 'Certificate Template Updated', '', '::1', '2026-10-02 05:36:08'),
(213, 4, 'Certificate Template Updated', '', '::1', '2026-10-02 05:37:49'),
(214, 4, 'Evaluation Submitted', 'Student: Ana Reyes, Type: midterm, Score: 85', '::1', '2026-10-02 05:40:30'),
(215, 1, 'Login', 'Successful login', '::1', '2026-10-02 06:15:45'),
(216, 4, 'Login', 'Successful login', '::1', '2026-10-02 06:16:03'),
(217, 1, 'Login', 'Successful login', '::1', '2026-10-02 07:22:51'),
(218, 1, 'Login', 'Successful login', '::1', '2026-10-02 07:23:02'),
(219, 3, 'Login', 'Successful login', '::1', '2026-10-02 07:23:09'),
(220, 3, 'Evaluation Form Saved', 'Communication', '::1', '2026-10-02 07:24:24'),
(221, 3, 'Evaluation Form Saved', 'Communication', '::1', '2026-10-02 07:24:32'),
(222, 1, 'Login', 'Successful login', '::1', '2026-10-02 07:25:03'),
(223, 2, 'Login', 'Successful login', '::1', '2026-10-02 07:25:14'),
(224, 2, 'Evaluation Form Saved', 'Evaluate', '::1', '2026-10-02 07:32:00'),
(225, 1, 'Login', 'Successful login', '::1', '2026-10-02 07:35:04'),
(226, 5, 'Login', 'Successful login', '::1', '2026-10-02 07:35:18'),
(227, 5, 'Certificate Template Updated', '', '::1', '2026-10-02 07:35:51'),
(228, 1, 'Login', 'Successful login', '::1', '2026-10-02 07:36:10'),
(229, 4, 'Login', 'Successful login', '::1', '2026-10-02 07:36:25'),
(230, 1, 'Login', 'Successful login', '::1', '2026-10-02 07:39:10'),
(231, 2, 'Login', 'Successful login', '::1', '2026-10-02 07:39:37'),
(232, 2, 'Evaluation Form Sent', '4 assignment(s)', '::1', '2026-10-02 09:02:19'),
(233, 1, 'Login', 'Successful login', '::1', '2026-10-02 09:02:44'),
(234, 4, 'Login', 'Successful login', '::1', '2026-10-02 09:03:10'),
(235, 4, 'Evaluation Form Submitted', 'Submission #1, Score: 100', '::1', '2026-10-02 09:03:56'),
(236, 1, 'Login', 'Successful login', '::1', '2026-10-02 09:05:12'),
(237, 1, 'Login', 'Successful login', '::1', '2026-10-02 09:05:31'),
(238, 8, 'Login', 'Successful login', '::1', '2026-10-02 09:05:41'),
(239, 1, 'Login', 'Successful login', '::1', '2026-10-02 09:06:10'),
(240, 4, 'Login', 'Successful login', '::1', '2026-10-02 09:06:21'),
(241, 4, 'Evaluation Form Submitted', 'Submission #3, Score: 100', '::1', '2026-10-02 09:07:19'),
(242, 8, 'Login', 'Successful login', '::1', '2026-10-02 09:07:29'),
(243, 4, 'Login', 'Successful login', '::1', '2026-10-02 09:08:47'),
(244, 4, 'Bulk Attendance', '1 trainee(s) · morning_in · 2026-10-02 17:19:17', '::1', '2026-10-02 09:19:17'),
(245, 4, 'Bulk Attendance', '1 trainee(s) recorded, 0 skipped · 2026-10-02 17:25:17', '::1', '2026-10-02 09:25:17'),
(246, 4, 'Bulk Attendance', '1 trainee(s) recorded, 0 skipped · 2026-10-02 17:25:42', '::1', '2026-10-02 09:25:42'),
(247, 4, 'Bulk Attendance', '1 trainee(s) recorded, 0 skipped · 2026-10-02 17:25:51', '::1', '2026-10-02 09:25:51'),
(248, 4, 'Bulk Attendance', '1 trainee(s) recorded, 0 skipped · 2026-10-02 17:26:04', '::1', '2026-10-02 09:26:04'),
(249, 4, 'Bulk Attendance', '1 trainee(s) recorded, 0 skipped · 2026-10-02 17:28:21', '::1', '2026-10-02 09:28:21'),
(250, 4, 'Bulk Attendance', '1 trainee(s) recorded, 0 skipped · 2026-10-02 17:28:48', '::1', '2026-10-02 09:28:48'),
(251, 8, 'Login', 'Successful login', '::1', '2026-10-02 09:29:30'),
(252, 4, 'Login', 'Successful login', '::1', '2026-10-02 09:30:44'),
(253, 4, 'Evaluation Form Submitted', 'Submission #4, Score: 72', '::1', '2026-10-02 09:47:01'),
(254, 4, 'Certificate Template Updated', '', '::1', '2026-10-02 10:11:24'),
(255, 1, 'Login', 'Successful login', '::1', '2026-10-02 10:13:04'),
(256, 3, 'Login', 'Successful login', '::1', '2026-10-02 10:13:17'),
(257, 8, 'Login', 'Successful login', '::1', '2026-10-02 10:13:31'),
(258, 1, 'Login', 'Successful login', '::1', '2026-10-02 10:13:36'),
(259, 2, 'Login', 'Successful login', '::1', '2026-10-02 10:13:52'),
(260, 1, 'Login', 'Successful login', '::1', '2026-10-02 10:22:09'),
(261, 1, 'Login', 'Successful login', '::1', '2026-10-02 10:24:48'),
(262, 2, 'Login', 'Successful login', '::1', '2026-10-02 10:25:00'),
(263, 8, 'Login', 'Successful login', '::1', '2026-10-02 10:43:33'),
(264, 1, 'Login', 'Successful login', '::1', '2026-10-02 11:16:00'),
(265, 8, 'Login', 'Successful login', '::1', '2026-10-02 11:16:59'),
(266, 1, 'Login', 'Successful login', '::1', '2026-10-02 11:23:20'),
(267, 9, 'Login', 'Successful login', '::1', '2026-10-02 11:23:39'),
(268, 8, 'Login', 'Successful login', '::1', '2026-10-02 11:27:13'),
(269, 9, 'Login', 'Successful login', '::1', '2026-10-02 11:27:41'),
(270, 1, 'Login', 'Successful login', '::1', '2026-10-02 11:31:07'),
(271, 6, 'Login', 'Successful login', '::1', '2026-10-02 11:31:30'),
(272, 6, 'Profile Updated', '', '::1', '2026-10-02 11:49:06'),
(273, 1, 'Login', 'Successful login', '::1', '2026-10-02 15:05:51'),
(274, 1, 'User Created', 'aligator (student) ID: S20260001', '::1', '2026-10-02 15:06:23'),
(275, 12, 'Login', 'Successful login', '::1', '2026-10-02 15:06:33'),
(276, 1, 'Login', 'Successful login', '::1', '2026-10-02 15:10:42'),
(277, 2, 'Login', 'Successful login', '::1', '2026-10-02 15:11:31'),
(278, 1, 'Login', 'Successful login', '::1', '2026-10-02 15:11:52'),
(279, 1, 'Student Assignment Updated', 'Updated assignments for aligator → Program: BSIT, Coord: Prof. Jocelyn Rivera', '::1', '2026-10-02 15:13:16'),
(280, 12, 'Login', 'Successful login', '::1', '2026-10-02 15:13:52'),
(281, 2, 'Login', 'Successful login', '::1', '2026-10-02 15:14:05'),
(282, 2, 'Requirements Sent', '3 assignment(s)', '::1', '2026-10-02 15:14:39'),
(283, 12, 'Requirement Submitted', 'Requirement ID 14 from onboarding', '::1', '2026-10-02 15:15:17'),
(284, 12, 'Requirement Submitted', 'Requirement ID 15 from onboarding', '::1', '2026-10-02 15:16:06'),
(285, 12, 'Requirement Submitted', 'Requirement ID 16 from onboarding', '::1', '2026-10-02 15:16:12'),
(286, 2, 'Requirements Sent', '3 assignment(s)', '::1', '2026-10-02 15:16:50'),
(287, 2, 'Requirement Approved', 'Req ID: 16 (Medical Certificate) for aligator', '::1', '2026-10-02 15:17:08'),
(288, 2, 'Requirement Approved', 'Req ID: 15 (Birth Certificate) for aligator', '::1', '2026-10-02 15:17:11'),
(289, 2, 'Requirement Approved', 'Req ID: 14 (Parent Consent) for aligator', '::1', '2026-10-02 15:17:14'),
(290, 12, 'Login', 'Successful login', '::1', '2026-10-02 15:17:37'),
(291, 2, 'Requirement Approved', 'Req ID: 17 (Parent Consent) for aligator', '::1', '2026-10-02 15:18:03'),
(292, 2, 'Requirement Approved', 'Req ID: 18 (Birth Certificate) for aligator', '::1', '2026-10-02 15:18:07'),
(293, 2, 'Requirement Approved', 'Req ID: 19 (Medical Certificate) for aligator', '::1', '2026-10-02 15:18:10'),
(294, 1, 'Login', 'Successful login', '::1', '2026-10-02 20:38:37'),
(295, 1, 'User Status Changed', 'User ID: 12 set to inactive', '::1', '2026-10-02 20:38:59'),
(296, 1, 'User Created', 'banana (student) ID: S20260002', '::1', '2026-10-02 20:39:36'),
(297, 1, 'Student Assignment Updated', 'Updated assignments for aligator → Program: BSIT, Coord: Prof. Jocelyn Rivera', '::1', '2026-10-02 20:40:37'),
(298, 13, 'Login', 'Successful login', '::1', '2026-10-02 20:40:57'),
(299, 1, 'Login', 'Successful login', '::1', '2026-10-02 20:47:34'),
(300, 1, 'User Status Changed', 'User ID: 13 set to inactive', '::1', '2026-10-02 20:47:51'),
(301, 1, 'Login', 'Successful login', '::1', '2026-10-02 20:48:05'),
(302, 1, 'User Status Changed', 'User ID: 13 set to active', '::1', '2026-10-02 20:48:11'),
(303, 1, 'User Updated', 'User ID: 13 (banana)', '::1', '2026-10-02 20:48:17'),
(304, 13, 'Login', 'Successful login', '::1', '2026-10-02 20:49:27'),
(305, 1, 'Login', 'Successful login', '::1', '2026-10-02 21:07:52'),
(306, 1, 'Student Assignment Updated', 'Updated assignments for banana → Program: BSIT, Coord: Prof. Jocelyn Rivera', '::1', '2026-10-02 21:08:14'),
(307, 2, 'Login', 'Successful login', '::1', '2026-10-02 21:08:30'),
(308, 2, 'Requirements Sent', '3 assignment(s)', '::1', '2026-10-02 21:14:43'),
(309, 13, 'Login', 'Successful login', '::1', '2026-10-02 21:14:50'),
(310, 13, 'Requirement Submitted', 'Requirement ID 22 from onboarding', '::1', '2026-10-02 21:15:27'),
(311, 1, 'Login', 'Successful login', '::1', '2026-10-02 21:16:40'),
(312, 1, 'Login', 'Successful login', '::1', '2026-10-02 21:16:48'),
(313, 2, 'Login', 'Successful login', '::1', '2026-10-02 21:17:05'),
(314, 13, 'Requirement Submitted', 'Requirement ID 22 from onboarding', '::1', '2026-10-02 21:33:27'),
(315, 13, 'Requirement Submitted', 'Requirement ID 22 from onboarding', '::1', '2026-10-02 21:43:19'),
(316, 13, 'Requirement Submitted', 'Requirement ID 22 from onboarding', '::1', '2026-10-02 21:44:29'),
(317, 13, 'Requirement Submitted', 'Requirement ID 22 from onboarding', '::1', '2026-10-02 21:46:58'),
(318, 13, 'Requirement Submitted', 'Requirement ID 22 from onboarding', '::1', '2026-10-02 21:50:43'),
(319, 13, 'Requirement Submitted', 'Requirement ID 20 from onboarding', '::1', '2026-10-02 21:51:19'),
(320, 13, 'Requirement Submitted', 'Requirement ID 20 from onboarding', '::1', '2026-10-02 21:53:08'),
(321, 13, 'Requirement Submitted', 'Requirement ID 21 from onboarding', '::1', '2026-10-02 21:53:50'),
(322, 13, 'Requirement Submitted', 'Requirement ID 22 from onboarding', '::1', '2026-10-02 21:53:50'),
(323, 2, 'Requirement Approved', 'Req ID: 21 (Birth Certificate) for banana', '::1', '2026-10-02 21:54:26'),
(324, 2, 'Requirement Approved', 'Req ID: 22 (Medical Certificate) for banana', '::1', '2026-10-02 21:54:29'),
(325, 2, 'Requirement Approved', 'Req ID: 20 (Parent Consent) for banana', '::1', '2026-10-02 21:54:31'),
(326, 2, 'Requirement Rejected', 'Req ID: 21 (Birth Certificate) for banana', '::1', '2026-10-02 21:54:39'),
(327, 13, 'Requirement Submitted', 'Requirement ID 21 from onboarding', '::1', '2026-10-02 21:54:49'),
(328, 13, 'Requirement Submitted', 'Requirement ID 21 from onboarding', '::1', '2026-10-02 21:58:39'),
(329, 13, 'Requirement Submitted', 'Requirement ID 21 from onboarding', '::1', '2026-10-02 21:58:46'),
(330, 2, 'Requirement Rejected', 'Req ID: 21 (Birth Certificate) for banana', '::1', '2026-10-02 21:59:04'),
(331, 13, 'Requirement Submitted', 'Requirement ID 21 from onboarding', '::1', '2026-10-02 21:59:15'),
(332, 13, 'Requirement Submitted', 'Requirement ID 21 from onboarding', '::1', '2026-10-02 21:59:47'),
(333, 2, 'Requirement Approved', 'Req ID: 21 (Birth Certificate) for banana', '::1', '2026-10-02 21:59:54'),
(334, 2, 'Requirement Approved', 'Req ID: 21 (Birth Certificate) for banana', '::1', '2026-10-02 22:00:03'),
(335, 1, 'Login', 'Successful login', '::1', '2026-10-02 22:11:24'),
(336, 2, 'Login', 'Successful login', '::1', '2026-10-02 22:11:48'),
(337, 2, 'Requirement Rejected', 'Req ID: 21 (Birth Certificate) for banana', '::1', '2026-10-02 22:11:59'),
(338, 13, 'Login', 'Successful login', '::1', '2026-10-02 22:12:15'),
(339, 2, 'Login', 'Successful login', '::1', '2026-10-02 22:12:50'),
(340, 2, 'Requirement Approved', 'Req ID: 21 (Birth Certificate) for banana', '::1', '2026-10-02 22:13:05'),
(341, 13, 'Login', 'Successful login', '::1', '2026-10-02 22:13:11'),
(342, 2, 'Login', 'Successful login', '::1', '2026-10-02 22:13:32'),
(343, 2, 'Student Updated', 'Updated details for aligator (ID: 7) → ongoing', '::1', '2026-10-02 22:14:07'),
(344, 2, 'Student Updated', 'Updated details for banana (ID: 8) → ongoing', '::1', '2026-10-02 22:14:28'),
(345, 13, 'Login', 'Successful login', '::1', '2026-10-02 22:14:37'),
(346, 2, 'Login', 'Successful login', '::1', '2026-10-02 22:16:09'),
(347, 2, 'Student Updated', 'Updated details for banana (ID: 8) → ongoing', '::1', '2026-10-02 22:17:25'),
(348, 1, 'Login', 'Successful login', '::1', '2026-10-02 22:25:21'),
(349, 2, 'Login', 'Successful login', '::1', '2026-10-02 22:31:12');

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `body` text NOT NULL,
  `tag` varchar(50) DEFAULT 'General',
  `target_role` enum('all','student','coordinator','company','admin') DEFAULT 'all',
  `created_by` int(11) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_pinned` tinyint(1) DEFAULT 0,
  `attachment_file` varchar(255) DEFAULT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  `expires_at` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `announcements`
--

INSERT INTO `announcements` (`id`, `title`, `body`, `tag`, `target_role`, `created_by`, `is_active`, `created_at`, `is_pinned`, `attachment_file`, `attachment_name`, `expires_at`) VALUES
(1, 'Mid-Term Report Submission Deadline', 'All OJT students are reminded to submit their Mid-Term Narrative Report on or before September 30, 2025. Reports must be submitted through the OJTrack portal. Late submissions will require coordinator approval.', 'Important', 'all', 2, 1, '2026-09-28 08:23:20', 0, NULL, NULL, NULL),
(2, 'OJT Coordinator Office Hours', 'The OJT Coordinator will hold consultation hours every Tuesday and Thursday, 1:00 PM – 4:00 PM at the Dean\'s Office.', 'General', 'all', 2, 1, '2026-09-28 08:23:20', 0, NULL, NULL, NULL),
(3, 'Attendance Sheet Submission Reminder', 'Please ensure your daily time record (DTR) is signed by your company supervisor every Friday.', 'Reminder', 'student', 2, 1, '2026-09-28 08:23:20', 0, NULL, NULL, NULL),
(4, 'Welcome to OJT Season 2025–2026', 'Congratulations to all students who have been cleared to start their On-the-Job Training. Use the OJTRACK system to track your progress.', 'Welcome', 'student', 2, 1, '2026-09-28 08:23:20', 0, NULL, NULL, NULL),
(5, 'Hi Everyone', 'this is it', 'Welcome', 'all', 1, 1, '2026-10-01 14:59:20', 0, 'announcements/ann_1790866760_2a7fc426.png', '{4EFC6D80-3B61-4BA6-BEB8-0C8F8A345621}.png', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `morning_in` time DEFAULT NULL,
  `morning_out` time DEFAULT NULL,
  `afternoon_in` time DEFAULT NULL,
  `afternoon_out` time DEFAULT NULL,
  `hours_rendered` decimal(4,2) DEFAULT 0.00,
  `remarks` varchar(255) DEFAULT NULL,
  `status` enum('present','absent','excused') DEFAULT 'present'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `student_id`, `date`, `time_in`, `time_out`, `morning_in`, `morning_out`, `afternoon_in`, `afternoon_out`, `hours_rendered`, `remarks`, `status`) VALUES
(1, 1, '2025-09-08', '08:00:00', '17:00:00', NULL, NULL, NULL, NULL, 9.00, NULL, 'present'),
(2, 1, '2025-09-09', '08:00:00', '17:00:00', NULL, NULL, NULL, NULL, 9.00, NULL, 'present'),
(3, 1, '2025-09-10', '08:15:00', '17:00:00', NULL, NULL, NULL, NULL, 8.75, NULL, 'present'),
(4, 1, '2025-09-11', '08:00:00', '17:00:00', NULL, NULL, NULL, NULL, 9.00, NULL, 'present'),
(5, 1, '2025-09-12', '08:00:00', '17:00:00', NULL, NULL, NULL, NULL, 9.00, NULL, 'present'),
(6, 1, '2025-09-15', '08:00:00', '17:00:00', NULL, NULL, NULL, NULL, 9.00, NULL, 'present'),
(7, 1, '2025-09-16', '08:00:00', '17:00:00', NULL, NULL, NULL, NULL, 9.00, NULL, 'present'),
(8, 1, '2025-09-17', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL, 'absent'),
(9, 1, '2025-09-18', '08:00:00', '17:00:00', NULL, NULL, NULL, NULL, 9.00, NULL, 'present'),
(10, 1, '2025-09-19', '08:00:00', '17:00:00', NULL, NULL, NULL, NULL, 9.00, NULL, 'present'),
(17, 3, '2026-09-29', NULL, NULL, '07:38:00', NULL, NULL, NULL, 0.00, NULL, 'present'),
(18, 4, '2026-09-29', NULL, NULL, '09:47:00', '12:36:00', NULL, NULL, 2.82, NULL, 'present'),
(19, 3, '2026-10-01', NULL, NULL, '04:32:00', NULL, NULL, '17:02:00', 0.00, NULL, 'present'),
(20, 3, '2026-10-02', '17:19:17', '17:25:51', '17:19:17', NULL, '17:25:42', '17:25:51', 0.00, NULL, 'present'),
(21, 1, '2026-10-02', '17:25:17', '17:26:04', NULL, NULL, '17:25:17', '17:26:04', 0.01, NULL, 'present'),
(22, 2, '2026-10-02', '17:28:21', '17:28:48', NULL, NULL, '17:28:21', '17:28:48', 0.01, NULL, 'present');

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `company_name` varchar(150) NOT NULL,
  `supervisor_name` varchar(100) DEFAULT NULL,
  `location` varchar(200) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `cert_template` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `companies`
--

INSERT INTO `companies` (`id`, `user_id`, `company_name`, `supervisor_name`, `location`, `contact_number`, `status`, `cert_template`) VALUES
(1, 4, 'Mindanao ICT Hub, Inc.', 'Engr. Ramon Diaz', 'Cagayan de Oro City', '09XX-XXX-0001', 'active', '{\"logo\":\"cert_logos\\/certlogo_1_1790919368.png\",\"org_name\":\"Mindanao ICT Hub, Inc.\",\"org_address\":\"Cagayan de Oro City\",\"cert_title\":\"Certificate of Recognition\",\"body_text\":\"Has successfully completed 600 hours of ON THE JOB TRAINING assigned in Accounting Department. Given this 31st day of January 2024 at Unit 6, G\\/F Saunterfield Bldg., Ortigas Avenue Extension, Cainta Rizal 1900 Philippines\",\"signatory_name\":\"Engr. Ramon Diaz\",\"signatory_title\":\"Training Supervisor\",\"footer_text\":\"In recognition of dedication, commitment, and performance during the On-the-Job Training program.\"}'),
(2, 5, 'Davao SoftTech Solutions', 'Ms. Grace Tan', 'Davao City', '09XX-XXX-0002', 'active', '{\"logo\":\"\",\"org_name\":\"Davao SoftTech Solutions\",\"org_address\":\"Davao City\",\"cert_title\":\"Certificate of Recognition\",\"body_text\":\"This is to certify that {student_name} of {program} has successfully completed the required OJT training hours at {company_name}, with a total of {rendered_hours} rendered hours.\",\"signatory_name\":\"Ms. Grace Tan\",\"signatory_title\":\"Training Supervisor\",\"footer_text\":\"In recognition of dedication, commitment, and performance during the On-the-Job Training program.\"}');

-- --------------------------------------------------------

--
-- Table structure for table `coordinators`
--

CREATE TABLE `coordinators` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `coordinator_id_no` varchar(50) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `contact_number` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `coordinators`
--

INSERT INTO `coordinators` (`id`, `user_id`, `coordinator_id_no`, `department`, `contact_number`) VALUES
(1, 2, 'COORD-2021-001', 'Information Technology', '0917-555-0101'),
(2, 3, 'COORD-2021-002', 'Computer Science', '0918-555-0202');

-- --------------------------------------------------------

--
-- Table structure for table `evaluations`
--

CREATE TABLE `evaluations` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `evaluator_id` int(11) NOT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `evaluation_type` enum('midterm','final') NOT NULL,
  `technical_skills` int(11) DEFAULT NULL,
  `work_ethic` int(11) DEFAULT NULL,
  `communication` int(11) DEFAULT NULL,
  `teamwork` int(11) DEFAULT NULL,
  `initiative` int(11) DEFAULT NULL,
  `adaptability` int(11) DEFAULT NULL,
  `overall_score` decimal(5,2) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `status` enum('pending','completed') DEFAULT 'pending',
  `evaluated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `evaluations`
--

INSERT INTO `evaluations` (`id`, `student_id`, `company_id`, `evaluator_id`, `requested_by`, `evaluation_type`, `technical_skills`, `work_ethic`, `communication`, `teamwork`, `initiative`, `adaptability`, `overall_score`, `comments`, `status`, `evaluated_at`) VALUES
(1, 1, 1, 4, NULL, 'midterm', 88, 92, 85, 90, 87, 91, 88.83, 'Maria has been an excellent OJT student. She demonstrates strong technical aptitude and is always willing to learn.', 'completed', '2025-10-10 02:00:00'),
(2, 3, 1, 4, NULL, 'midterm', 85, 85, 85, 85, 85, 85, 85.00, '', 'completed', '2026-10-02 05:40:30');

-- --------------------------------------------------------

--
-- Table structure for table `evaluation_assignments`
--

CREATE TABLE `evaluation_assignments` (
  `id` int(11) NOT NULL,
  `form_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `status` enum('pending','completed') DEFAULT 'pending',
  `answers` text DEFAULT NULL,
  `overall_score` decimal(5,2) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `evaluation_forms`
--

CREATE TABLE `evaluation_forms` (
  `id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `criteria` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(20) DEFAULT 'draft',
  `version` int(11) DEFAULT 1,
  `parent_id` int(11) DEFAULT 0,
  `score_mode` varchar(20) DEFAULT 'percentage',
  `rating_max` int(11) DEFAULT 100,
  `published_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `evaluation_forms`
--

INSERT INTO `evaluation_forms` (`id`, `created_by`, `title`, `description`, `criteria`, `created_at`, `status`, `version`, `parent_id`, `score_mode`, `rating_max`, `published_at`) VALUES
(1, 3, 'Communication', 'Is he/she a team player?', '[\"Communication Skills\"]', '2026-10-02 07:24:24', 'draft', 1, 0, 'percentage', 100, NULL),
(2, 3, 'Communication', 'Is he/she a team player?', '[\"Communication Skills\"]', '2026-10-02 07:24:32', 'draft', 1, 0, 'percentage', 100, NULL),
(3, 2, 'Evaluate', 'Is he/she a team player?', '[\"Technical Skills\"]', '2026-10-02 07:32:00', 'active', 1, 0, 'percentage', 100, '2026-10-02 09:00:07');

-- --------------------------------------------------------

--
-- Table structure for table `eval_answers`
--

CREATE TABLE `eval_answers` (
  `id` int(11) NOT NULL,
  `submission_id` int(11) NOT NULL,
  `section_title` varchar(200) DEFAULT NULL,
  `criterion_label` varchar(200) DEFAULT NULL,
  `score` decimal(5,1) DEFAULT NULL,
  `equivalent` decimal(4,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `eval_answers`
--

INSERT INTO `eval_answers` (`id`, `submission_id`, `section_title`, `criterion_label`, `score`, `equivalent`) VALUES
(1, 1, 'one', 'ok', 100.0, 1.25),
(2, 1, 'one', 'no', 100.0, 1.25),
(3, 1, 'Two', 'twoPointOne', 100.0, 1.25),
(4, 3, 'one', 'ok', 100.0, 1.25),
(5, 3, 'one', 'no', 100.0, 1.25),
(6, 3, 'Two', 'twoPointOne', 100.0, 1.25),
(7, 4, 'one', 'ok', 100.0, 1.25),
(8, 4, 'one', 'no', 53.0, 53.00),
(9, 4, 'Two', 'twoPointOne', 63.0, 63.00);

-- --------------------------------------------------------

--
-- Table structure for table `eval_criteria`
--

CREATE TABLE `eval_criteria` (
  `id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `label` varchar(200) NOT NULL,
  `sort_order` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `eval_criteria`
--

INSERT INTO `eval_criteria` (`id`, `section_id`, `label`, `sort_order`) VALUES
(1, 1, 'ok', 1),
(2, 1, 'no', 2),
(3, 2, 'twoPointOne', 1);

-- --------------------------------------------------------

--
-- Table structure for table `eval_rating_rules`
--

CREATE TABLE `eval_rating_rules` (
  `id` int(11) NOT NULL,
  `form_id` int(11) NOT NULL,
  `score_min` int(11) NOT NULL,
  `score_max` int(11) NOT NULL,
  `equivalent` decimal(4,2) NOT NULL,
  `description` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `eval_rating_rules`
--

INSERT INTO `eval_rating_rules` (`id`, `form_id`, `score_min`, `score_max`, `equivalent`, `description`) VALUES
(1, 3, 96, 100, 1.25, '');

-- --------------------------------------------------------

--
-- Table structure for table `eval_sections`
--

CREATE TABLE `eval_sections` (
  `id` int(11) NOT NULL,
  `form_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `sort_order` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `eval_sections`
--

INSERT INTO `eval_sections` (`id`, `form_id`, `title`, `sort_order`) VALUES
(1, 3, 'one', 1),
(2, 3, 'Two', 2);

-- --------------------------------------------------------

--
-- Table structure for table `eval_submissions`
--

CREATE TABLE `eval_submissions` (
  `id` int(11) NOT NULL,
  `form_id` int(11) NOT NULL,
  `form_version` int(11) DEFAULT 1,
  `student_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `status` enum('pending','completed') DEFAULT 'pending',
  `overall_score` decimal(5,2) DEFAULT NULL,
  `overall_equivalent` decimal(4,2) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `eval_submissions`
--

INSERT INTO `eval_submissions` (`id`, `form_id`, `form_version`, `student_id`, `company_id`, `requested_by`, `status`, `overall_score`, `overall_equivalent`, `comments`, `submitted_at`, `created_at`) VALUES
(1, 3, 1, 1, 1, 2, 'completed', 100.00, 1.25, 'Good work', '2026-10-02 09:03:56', '2026-10-02 09:02:19'),
(2, 3, 1, 2, 1, 2, 'pending', NULL, NULL, NULL, NULL, '2026-10-02 09:02:19'),
(3, 3, 1, 3, 1, 2, 'completed', 100.00, 1.25, '10 out of 10 kay ako ning kabit HAHAHAH', '2026-10-02 09:07:19', '2026-10-02 09:02:19'),
(4, 3, 1, 5, 1, 2, 'completed', 72.00, NULL, 'BUGO ni sya', '2026-10-02 09:47:01', '2026-10-02 09:02:19');

-- --------------------------------------------------------

--
-- Table structure for table `journal_entries`
--

CREATE TABLE `journal_entries` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `entry_date` date NOT NULL,
  `week_number` int(11) DEFAULT NULL,
  `activities` text NOT NULL,
  `learnings` text NOT NULL,
  `challenges` text NOT NULL,
  `hours_rendered` decimal(4,2) DEFAULT 0.00,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `coordinator_remarks` text DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `proof_image` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `journal_entries`
--

INSERT INTO `journal_entries` (`id`, `student_id`, `entry_date`, `week_number`, `activities`, `learnings`, `challenges`, `hours_rendered`, `status`, `coordinator_remarks`, `submitted_at`, `reviewed_at`, `proof_image`) VALUES
(1, 1, '2025-09-23', 3, 'Attended the daily standup meeting. Worked on the inventory module — implemented the search and filter functionality. Had a code review session with Engr. Diaz in the afternoon.', 'Learned how to write more efficient SQL queries using indexed columns.', 'Had difficulty optimizing the search query for large datasets. Resolved by adding a database index.', 9.00, 'approved', NULL, '2026-09-28 08:23:20', NULL, NULL),
(2, 1, '2025-09-22', 3, 'Attended orientation for the new sprint. Was assigned to work on the inventory management module. Set up the development environment for the new task.', 'Got familiar with the codebase structure and the team\'s development workflow.', 'Setting up the local development environment took longer than expected due to missing dependencies.', 9.00, 'approved', NULL, '2026-09-28 08:23:20', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `thread_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `messages`
--

INSERT INTO `messages` (`id`, `thread_id`, `sender_id`, `message`, `sent_at`) VALUES
(1, 1, 2, 'Good morning, everyone! Please remember that your OJT requirements should be complete before deployment.', '2026-09-28 08:23:20'),
(2, 1, 6, 'Good morning, Ma\'am! Noted. Thank you for the reminder.', '2026-09-28 08:23:20'),
(3, 1, 2, 'Also, please submit your mid-term narrative report on or before September 30.', '2026-09-28 08:23:20'),
(4, 2, 2, 'Good afternoon, company supervisors. Please provide updates regarding your assigned OJT students.', '2026-09-28 08:23:20'),
(5, 2, 4, 'Good afternoon, Prof. Rivera. Maria Santos is progressing well with her assigned tasks.', '2026-09-28 08:23:20'),
(6, 3, 8, 'hi', '2026-09-28 22:08:12'),
(7, 3, 8, 'Hello maam good morning', '2026-09-28 22:10:25');

-- --------------------------------------------------------

--
-- Table structure for table `message_reads`
--

CREATE TABLE `message_reads` (
  `id` int(11) NOT NULL,
  `message_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `message_reads`
--

INSERT INTO `message_reads` (`id`, `message_id`, `user_id`, `read_at`) VALUES
(1, 1, 6, '2026-09-28 10:18:24'),
(2, 3, 6, '2026-09-28 10:18:24'),
(3, 2, 2, '2026-09-28 10:18:25'),
(4, 4, 4, '2026-09-28 10:18:25'),
(9, 1, 8, '2026-09-28 22:02:53'),
(10, 2, 8, '2026-09-28 22:02:53'),
(11, 3, 8, '2026-09-28 22:02:53'),
(51, 6, 8, '2026-09-28 22:08:12'),
(64, 7, 8, '2026-09-28 22:10:25'),
(71, 6, 2, '2026-09-28 22:11:25'),
(72, 7, 2, '2026-09-28 22:11:25'),
(74, 5, 2, '2026-09-28 22:11:30'),
(104, 1, 9, '2026-09-29 07:52:06'),
(105, 2, 9, '2026-09-29 07:52:06'),
(106, 3, 9, '2026-09-29 07:52:06'),
(124, 4, 5, '2026-10-01 21:23:33'),
(125, 5, 5, '2026-10-01 21:23:33');

-- --------------------------------------------------------

--
-- Table structure for table `message_threads`
--

CREATE TABLE `message_threads` (
  `id` int(11) NOT NULL,
  `name` varchar(150) DEFAULT NULL,
  `thread_type` enum('group','direct') DEFAULT 'group',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `message_threads`
--

INSERT INTO `message_threads` (`id`, `name`, `thread_type`, `created_by`, `created_at`, `description`) VALUES
(1, 'BSIT 4A OJT Group', 'group', 2, '2026-09-28 08:23:20', NULL),
(2, 'Industry Partners Group', 'group', 2, '2026-09-28 08:23:20', NULL),
(3, 'Prof. Jocelyn Rivera & Ana Reyes', 'direct', 8, '2026-09-28 22:08:12', 'Direct Conversation');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `notif_type` enum('info','warning','error','success') DEFAULT 'info',
  `link` varchar(500) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `message`, `notif_type`, `link`, `is_read`, `created_at`) VALUES
(1, 6, 'OJT Acceptance Letter was rejected. Please resubmit with correct signature.', 'error', NULL, 1, '2026-09-28 08:23:20'),
(2, 6, 'Mid-term report deadline is September 30, 2025.', 'warning', NULL, 1, '2026-09-28 08:23:20'),
(3, 6, 'Attendance for September 23 has been recorded.', 'info', NULL, 1, '2026-09-28 08:23:20'),
(4, 2, 'New message from Ana Reyes', 'info', '/ojtrack/student/messages.php', 1, '2026-09-28 22:08:12'),
(5, 8, 'Your coordinator/company assignment was updated by the administrator.', 'info', '/ojtrack/student/dashboard.php', 1, '2026-09-29 04:37:06'),
(6, 8, 'New requirement added: Medical Cert. Please submit the document.', 'info', '/ojtrack/student/requirements.php', 1, '2026-09-29 04:53:57'),
(7, 8, 'Your coordinator/company assignment was updated by the administrator.', 'info', '/ojtrack/student/dashboard.php', 1, '2026-09-29 04:55:45'),
(8, 3, 'Ana Reyes submitted an OJT requirement for review.', 'info', '/ojtrack/coordinator/requirements.php', 1, '2026-09-29 05:00:44'),
(9, 8, 'Your requirement document \'Medical Cert\' has been approved.', '', '/ojtrack/student/requirements.php', 1, '2026-09-29 05:22:09'),
(10, 8, 'Your coordinator/company assignment was updated by the administrator.', 'info', '/ojtrack/student/dashboard.php', 1, '2026-09-29 07:45:59'),
(11, 8, 'Your coordinator/company assignment was updated by the administrator.', 'info', '/ojtrack/student/dashboard.php', 1, '2026-10-01 14:32:30'),
(12, 2, 'New announcement: Hi Everyone', 'info', '/ojtrack/coordinator/announcements.php', 1, '2026-10-01 14:59:20'),
(13, 3, 'New announcement: Hi Everyone', 'info', '/ojtrack/coordinator/announcements.php', 1, '2026-10-01 14:59:20'),
(14, 4, 'New announcement: Hi Everyone', 'info', '/ojtrack/company/announcements.php', 1, '2026-10-01 14:59:20'),
(15, 5, 'New announcement: Hi Everyone', 'info', '/ojtrack/company/announcements.php', 1, '2026-10-01 14:59:20'),
(16, 6, 'New announcement: Hi Everyone', 'info', '/ojtrack/student/announcements.php', 1, '2026-10-01 14:59:20'),
(17, 7, 'New announcement: Hi Everyone', 'info', '/ojtrack/student/announcements.php', 0, '2026-10-01 14:59:20'),
(18, 8, 'New announcement: Hi Everyone', 'info', '/ojtrack/student/announcements.php', 1, '2026-10-01 14:59:20'),
(19, 9, 'New announcement: Hi Everyone', 'info', '/ojtrack/student/announcements.php', 1, '2026-10-01 14:59:20'),
(20, 10, 'New announcement: Hi Everyone', 'info', '/ojtrack/student/announcements.php', 0, '2026-10-01 14:59:20'),
(21, 8, 'Your coordinator/company assignment was updated by the administrator.', 'info', '/ojtrack/student/dashboard.php', 1, '2026-10-02 02:41:40'),
(22, 8, 'Your coordinator/company assignment was updated by the administrator.', 'info', '/ojtrack/student/dashboard.php', 1, '2026-10-02 02:41:47'),
(23, 6, 'Your requirement document \'Parents\' Consent Form\' was returned with remarks: Under review', '', '/ojtrack/student/requirements.php', 1, '2026-10-02 04:38:35'),
(24, 8, 'New requirement: Parent Consent. Please submit the document.', 'info', '/ojtrack/student/requirements.php', 1, '2026-10-02 05:00:05'),
(25, 8, 'New requirement: Birth Certificate. Please submit the document.', 'info', '/ojtrack/student/requirements.php', 1, '2026-10-02 05:00:05'),
(26, 8, 'New requirement: Medical Certificate. Please submit the document.', 'info', '/ojtrack/student/requirements.php', 1, '2026-10-02 05:00:05'),
(27, 2, 'Ana Reyes submitted an OJT requirement for review.', 'info', '/ojtrack/coordinator/requirements.php', 1, '2026-10-02 05:02:09'),
(28, 2, 'Ana Reyes submitted an OJT requirement for review.', 'info', '/ojtrack/coordinator/requirements.php', 1, '2026-10-02 05:02:14'),
(29, 2, 'Ana Reyes submitted an OJT requirement for review.', 'info', '/ojtrack/coordinator/requirements.php', 1, '2026-10-02 05:02:19'),
(30, 8, 'Your requirement document \'Medical Certificate\' has been approved.', '', '/ojtrack/student/requirements.php', 1, '2026-10-02 05:02:52'),
(31, 8, 'Your requirement document \'Birth Certificate\' has been approved.', '', '/ojtrack/student/requirements.php', 1, '2026-10-02 05:02:54'),
(32, 8, 'Your requirement document \'Parent Consent\' has been approved.', '', '/ojtrack/student/requirements.php', 1, '2026-10-02 05:02:56'),
(33, 8, 'Your Midterm Performance Evaluation was submitted by Mindanao ICT Hub, Inc..', '', '/ojtrack/student/evaluation.php', 1, '2026-10-02 05:40:30'),
(34, 2, 'Midterm Evaluation submitted for Ana Reyes by Mindanao ICT Hub, Inc..', '', '/ojtrack/coordinator/reports.php', 1, '2026-10-02 05:40:30'),
(35, 4, 'OJT Coordinator sent the evaluation form \"Evaluate\" for Maria Santos.', '', '/ojtrack/company/evaluation.php', 1, '2026-10-02 09:02:19'),
(36, 4, 'OJT Coordinator sent the evaluation form \"Evaluate\" for Juan dela Cruz.', '', '/ojtrack/company/evaluation.php', 1, '2026-10-02 09:02:19'),
(37, 4, 'OJT Coordinator sent the evaluation form \"Evaluate\" for Ana Reyes.', '', '/ojtrack/company/evaluation.php', 1, '2026-10-02 09:02:19'),
(38, 4, 'OJT Coordinator sent the evaluation form \"Evaluate\" for Ryan Castro.', '', '/ojtrack/company/evaluation.php', 1, '2026-10-02 09:02:19'),
(39, 6, 'Your evaluation form was submitted by Mindanao ICT Hub, Inc..', '', '/ojtrack/student/evaluation.php', 1, '2026-10-02 09:03:56'),
(40, 2, 'Evaluation \"Evaluate\" submitted by Mindanao ICT Hub, Inc. for Maria Santos (score 100/100).', '', '/ojtrack/coordinator/evaluation.php', 1, '2026-10-02 09:03:56'),
(41, 8, 'Your evaluation form was submitted by Mindanao ICT Hub, Inc..', '', '/ojtrack/student/evaluation.php', 1, '2026-10-02 09:07:19'),
(42, 2, 'Evaluation \"Evaluate\" submitted by Mindanao ICT Hub, Inc. for Ana Reyes (score 100/100).', '', '/ojtrack/coordinator/evaluation.php', 1, '2026-10-02 09:07:19'),
(43, 10, 'Your evaluation form was submitted by Mindanao ICT Hub, Inc..', '', '/ojtrack/student/evaluation.php', 0, '2026-10-02 09:47:01'),
(44, 2, 'Evaluation \"Evaluate\" submitted by Mindanao ICT Hub, Inc. for Ryan Castro (score 72/100).', '', '/ojtrack/coordinator/evaluation.php', 1, '2026-10-02 09:47:01'),
(45, 12, 'Your coordinator/company assignment was updated by the administrator.', 'info', '/ojtrack/student/dashboard.php', 1, '2026-10-02 15:13:16'),
(46, 12, 'New requirement: Parent Consent. Please submit the document.', 'info', '/ojtrack/student/requirements.php', 1, '2026-10-02 15:14:39'),
(47, 12, 'New requirement: Birth Certificate. Please submit the document.', 'info', '/ojtrack/student/requirements.php', 1, '2026-10-02 15:14:39'),
(48, 12, 'New requirement: Medical Certificate. Please submit the document.', 'info', '/ojtrack/student/requirements.php', 1, '2026-10-02 15:14:39'),
(49, 2, 'aligator submitted an OJT requirement for review.', 'info', '/ojtrack/coordinator/requirements.php', 1, '2026-10-02 15:15:17'),
(50, 2, 'aligator submitted an OJT requirement for review.', 'info', '/ojtrack/coordinator/requirements.php', 1, '2026-10-02 15:16:06'),
(51, 2, 'aligator submitted an OJT requirement for review.', 'info', '/ojtrack/coordinator/requirements.php', 1, '2026-10-02 15:16:12'),
(52, 12, 'New requirement: Parent Consent. Please submit the document.', 'info', '/ojtrack/student/requirements.php', 1, '2026-10-02 15:16:50'),
(53, 12, 'New requirement: Birth Certificate. Please submit the document.', 'info', '/ojtrack/student/requirements.php', 1, '2026-10-02 15:16:50'),
(54, 12, 'New requirement: Medical Certificate. Please submit the document.', 'info', '/ojtrack/student/requirements.php', 1, '2026-10-02 15:16:50'),
(55, 12, 'Your requirement document \'Medical Certificate\' has been approved.', '', '/ojtrack/student/requirements.php', 1, '2026-10-02 15:17:08'),
(56, 12, 'Your requirement document \'Birth Certificate\' has been approved.', '', '/ojtrack/student/requirements.php', 1, '2026-10-02 15:17:11'),
(57, 12, 'Your requirement document \'Parent Consent\' has been approved.', '', '/ojtrack/student/requirements.php', 1, '2026-10-02 15:17:14'),
(58, 12, 'Your requirement document \'Parent Consent\' has been approved.', '', '/ojtrack/student/requirements.php', 1, '2026-10-02 15:18:03'),
(59, 12, 'Your requirement document \'Birth Certificate\' has been approved.', '', '/ojtrack/student/requirements.php', 1, '2026-10-02 15:18:07'),
(60, 12, 'Your requirement document \'Medical Certificate\' has been approved.', '', '/ojtrack/student/requirements.php', 1, '2026-10-02 15:18:10'),
(61, 12, 'Your coordinator/company assignment was updated by the administrator.', 'info', '/ojtrack/student/dashboard.php', 0, '2026-10-02 20:40:37'),
(62, 13, 'Your coordinator/company assignment was updated by the administrator.', 'info', '/ojtrack/student/dashboard.php', 1, '2026-10-02 21:08:14'),
(63, 13, 'New requirement: Parent Consent. Please submit the document.', 'info', '/ojtrack/student/requirements.php', 1, '2026-10-02 21:14:43'),
(64, 13, 'New requirement: Birth Certificate. Please submit the document.', 'info', '/ojtrack/student/requirements.php', 1, '2026-10-02 21:14:43'),
(65, 13, 'New requirement: Medical Certificate. Please submit the document.', 'info', '/ojtrack/student/requirements.php', 1, '2026-10-02 21:14:43'),
(66, 2, 'banana submitted an OJT requirement for review.', 'info', '/ojtrack/coordinator/requirements.php', 1, '2026-10-02 21:15:27'),
(67, 2, 'banana submitted an OJT requirement for review.', 'info', '/ojtrack/coordinator/requirements.php', 1, '2026-10-02 21:33:27'),
(68, 2, 'banana submitted an OJT requirement for review.', 'info', '/ojtrack/coordinator/requirements.php', 1, '2026-10-02 21:43:19'),
(69, 2, 'banana submitted an OJT requirement for review.', 'info', '/ojtrack/coordinator/requirements.php', 1, '2026-10-02 21:44:29'),
(70, 2, 'banana submitted an OJT requirement for review.', 'info', '/ojtrack/coordinator/requirements.php', 1, '2026-10-02 21:46:58'),
(71, 2, 'banana submitted an OJT requirement for review.', 'info', '/ojtrack/coordinator/requirements.php', 1, '2026-10-02 21:50:43'),
(72, 2, 'banana submitted 1 OJT requirement for review.', 'info', '/ojtrack/coordinator/requirements.php', 1, '2026-10-02 21:51:19'),
(73, 2, 'banana submitted 1 OJT requirement for review.', 'info', '/ojtrack/coordinator/requirements.php', 1, '2026-10-02 21:53:08'),
(74, 2, 'banana submitted 2 OJT requirements for review.', 'info', '/ojtrack/coordinator/requirements.php', 1, '2026-10-02 21:53:50'),
(75, 13, 'Your requirement document \'Birth Certificate\' has been approved.', '', '/ojtrack/student/requirements.php', 1, '2026-10-02 21:54:26'),
(76, 13, 'Your requirement document \'Medical Certificate\' has been approved.', '', '/ojtrack/student/requirements.php', 1, '2026-10-02 21:54:29'),
(77, 13, 'Your requirement document \'Parent Consent\' has been approved.', '', '/ojtrack/student/requirements.php', 1, '2026-10-02 21:54:31'),
(78, 13, 'Your requirement document \'Birth Certificate\' was returned with remarks: Submitted — awaiting coordinator review', '', '/ojtrack/student/requirements.php', 1, '2026-10-02 21:54:39'),
(79, 2, 'banana submitted 1 OJT requirement for review.', 'info', '/ojtrack/coordinator/requirements.php', 1, '2026-10-02 21:54:49'),
(80, 2, 'banana submitted 1 OJT requirement for review.', 'info', '/ojtrack/coordinator/requirements.php', 1, '2026-10-02 21:58:39'),
(81, 2, 'banana submitted 1 OJT requirement for review.', 'info', '/ojtrack/coordinator/requirements.php', 1, '2026-10-02 21:58:46'),
(82, 13, 'Your requirement document \'Birth Certificate\' was returned with remarks: Submitted — awaiting coordinator review', '', '/ojtrack/student/requirements.php', 1, '2026-10-02 21:59:04'),
(83, 2, 'banana submitted 1 OJT requirement for review.', 'info', '/ojtrack/coordinator/requirements.php', 1, '2026-10-02 21:59:15'),
(84, 2, 'banana submitted 1 OJT requirement for review.', 'info', '/ojtrack/coordinator/requirements.php', 1, '2026-10-02 21:59:47'),
(85, 13, 'Your requirement document \'Birth Certificate\' has been approved.', '', '/ojtrack/student/requirements.php', 1, '2026-10-02 21:59:54'),
(86, 13, 'Your requirement document \'Birth Certificate\' has been approved.', '', '/ojtrack/student/requirements.php', 1, '2026-10-02 22:00:03'),
(87, 13, 'Your requirement document \'Birth Certificate\' was returned with remarks: Submitted — awaiting coordinator review', '', '/ojtrack/student/requirements.php', 1, '2026-10-02 22:11:59'),
(88, 13, 'Your requirement document \'Birth Certificate\' has been approved.', '', '/ojtrack/student/requirements.php', 1, '2026-10-02 22:13:05'),
(89, 12, 'Your OJT status was updated to ongoing by your coordinator.', 'info', '/ojtrack/student/dashboard.php', 0, '2026-10-02 22:14:07'),
(90, 13, 'Your OJT status was updated to ongoing by your coordinator.', 'info', '/ojtrack/student/dashboard.php', 1, '2026-10-02 22:14:28'),
(91, 13, 'Your OJT status was updated to ongoing by your coordinator.', 'info', '/ojtrack/student/dashboard.php', 0, '2026-10-02 22:17:25');

-- --------------------------------------------------------

--
-- Table structure for table `ojt_requirements`
--

CREATE TABLE `ojt_requirements` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `document_name` varchar(150) NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `deadline` date DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ojt_requirements`
--

INSERT INTO `ojt_requirements` (`id`, `student_id`, `document_name`, `file_path`, `deadline`, `submitted_at`, `status`, `remarks`, `reviewed_by`, `reviewed_at`) VALUES
(1, 1, 'MOA / Endorsement Letter', NULL, '2025-09-05', '2025-09-03 00:30:00', 'approved', 'Good to go.', NULL, NULL),
(2, 1, 'Medical Certificate', NULL, '2025-09-05', '2025-09-05 01:00:00', 'approved', '', NULL, NULL),
(3, 1, 'OJT Insurance', NULL, '2025-09-05', '2025-09-05 02:00:00', 'approved', '', NULL, NULL),
(4, 1, 'Parents\' Consent Form', NULL, '2025-09-10', '2025-09-07 00:00:00', 'rejected', 'Under review', NULL, '2026-10-02 04:38:35'),
(5, 1, 'OJT Acceptance Letter', NULL, '2025-09-08', '2025-09-08 01:00:00', 'rejected', 'Signature missing. Please resubmit.', NULL, NULL),
(6, 1, 'OJT Weekly Plan', NULL, '2025-09-12', NULL, 'pending', 'Not yet submitted', NULL, NULL),
(7, 1, 'Barangay Clearance', NULL, '2025-09-12', NULL, 'pending', 'Not yet submitted', NULL, NULL),
(8, 1, 'NBI Clearance', NULL, '2025-09-15', NULL, 'pending', 'Not yet submitted', NULL, NULL),
(9, 1, 'Waiver Form', NULL, '2025-09-15', NULL, 'pending', 'Not yet submitted', NULL, NULL),
(10, 3, 'Medical Cert', 'requirements/req_3_10_1790658044.pdf', '2026-09-30', '2026-09-29 05:00:44', 'approved', 'nice one', NULL, '2026-09-29 05:22:09'),
(11, 3, 'Parent Consent', 'requirements/req_3_11_1790917329.jpg', '2026-10-03', '2026-10-02 05:02:09', 'approved', 'Submitted — awaiting coordinator review', NULL, '2026-10-02 05:02:56'),
(12, 3, 'Birth Certificate', 'requirements/req_3_12_1790917334.jpg', '2026-10-03', '2026-10-02 05:02:14', 'approved', 'Submitted — awaiting coordinator review', NULL, '2026-10-02 05:02:54'),
(13, 3, 'Medical Certificate', 'requirements/req_3_13_1790917339.jpg', '2026-10-03', '2026-10-02 05:02:19', 'approved', 'Submitted — awaiting coordinator review', NULL, '2026-10-02 05:02:52'),
(14, 7, 'Parent Consent', 'requirements/req_7_14_c8bea2695f98d224.jpg', '2026-10-03', '2026-10-02 15:15:17', 'approved', 'Submitted — awaiting coordinator review', NULL, '2026-10-02 15:17:14'),
(15, 7, 'Birth Certificate', 'requirements/req_7_15_efb6cd6af52f5d6a.jpg', '2026-10-03', '2026-10-02 15:16:06', 'approved', 'Submitted — awaiting coordinator review', NULL, '2026-10-02 15:17:11'),
(16, 7, 'Medical Certificate', 'requirements/req_7_16_80eed2553acc7018.png', '2026-10-03', '2026-10-02 15:16:12', 'approved', 'Submitted — awaiting coordinator review', NULL, '2026-10-02 15:17:08'),
(17, 7, 'Parent Consent', NULL, '2026-10-03', NULL, 'approved', 'Added by coordinator', NULL, '2026-10-02 15:18:03'),
(18, 7, 'Birth Certificate', NULL, '2026-10-03', NULL, 'approved', 'Added by coordinator', NULL, '2026-10-02 15:18:07'),
(19, 7, 'Medical Certificate', NULL, '2026-10-03', NULL, 'approved', 'For Medical Recods', NULL, '2026-10-02 15:18:10'),
(20, 8, 'Parent Consent', 'requirements/req_8_20_673048824ab55659.jpg', '2026-10-04', '2026-10-02 21:53:08', 'approved', 'Submitted — awaiting coordinator review', NULL, '2026-10-02 21:54:31'),
(21, 8, 'Birth Certificate', 'requirements/req_8_21_7578ff069c956c4d.jpg', '2026-10-04', '2026-10-02 21:59:47', 'approved', 'Submitted — awaiting coordinator review', NULL, '2026-10-02 22:13:05'),
(22, 8, 'Medical Certificate', 'requirements/req_8_22_74bd69e06094d485.png', '2026-10-04', '2026-10-02 21:53:50', 'approved', 'Submitted — awaiting coordinator review', NULL, '2026-10-02 21:54:29');

-- --------------------------------------------------------

--
-- Table structure for table `programs`
--

CREATE TABLE `programs` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(150) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `programs`
--

INSERT INTO `programs` (`id`, `code`, `name`, `status`, `created_at`, `updated_at`) VALUES
(1, 'BSIT', 'Bachelor of Science in Information Technology', 'active', '2026-10-01 11:58:04', '2026-10-01 11:58:04'),
(2, 'BSCS', 'Bachelor of Science in Computer Science', 'active', '2026-10-01 11:58:04', '2026-10-01 11:58:04'),
(3, 'BSEd', 'Bachelor of Secondary Education', 'active', '2026-10-01 11:58:04', '2026-10-01 11:58:04'),
(4, 'BSMET', 'Bachelor of Science in Mechanical Engineering Technology', 'active', '2026-10-01 11:58:04', '2026-10-01 11:58:04'),
(5, 'BSET', 'Bachelor of Science in Electrical Technology', 'active', '2026-10-01 11:58:04', '2026-10-01 11:58:04'),
(6, 'BSTCM', 'Bachelor of Science in Technology Communication Management', 'active', '2026-10-01 11:58:04', '2026-10-01 11:58:04');

-- --------------------------------------------------------

--
-- Table structure for table `reports`
--

CREATE TABLE `reports` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `report_name` varchar(150) NOT NULL,
  `report_type` enum('initial','midterm','final','monthly') NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `deadline` date DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','approved','rejected','for_review') DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `reports`
--

INSERT INTO `reports` (`id`, `student_id`, `report_name`, `report_type`, `file_path`, `deadline`, `submitted_at`, `status`, `remarks`, `reviewed_by`, `reviewed_at`) VALUES
(1, 1, 'Acceptance Report', 'initial', NULL, '2025-09-12', '2025-09-11 02:00:00', 'approved', NULL, NULL, NULL),
(2, 1, 'Mid-Term Narrative Report', 'midterm', NULL, '2025-09-30', NULL, 'pending', NULL, NULL, NULL),
(3, 1, 'Final Narrative Report', 'final', NULL, '2025-11-10', NULL, 'pending', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `requirement_templates`
--

CREATE TABLE `requirement_templates` (
  `id` int(11) NOT NULL,
  `coordinator_id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `requirement_templates`
--

INSERT INTO `requirement_templates` (`id`, `coordinator_id`, `name`, `description`, `created_at`) VALUES
(1, 1, 'Medical Certificate', 'For Medical Recods', '2026-10-02 04:58:32'),
(2, 1, 'Birth Certificate', '', '2026-10-02 04:59:06'),
(3, 1, 'Parent Consent', '', '2026-10-02 04:59:25');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `student_id_no` varchar(20) NOT NULL,
  `program_id` int(11) DEFAULT NULL,
  `program` varchar(100) DEFAULT 'BS Information Technology',
  `department` varchar(100) DEFAULT NULL,
  `year_level` varchar(20) DEFAULT '4th Year',
  `contact_number` varchar(20) DEFAULT NULL,
  `coordinator_id` int(11) DEFAULT NULL,
  `company_id` int(11) DEFAULT NULL,
  `ojt_status` enum('pending','not_started','ongoing','completed','on_hold','withdrawn') DEFAULT 'pending',
  `onboarding_completed_at` timestamp NULL DEFAULT NULL,
  `status_notes` text DEFAULT NULL,
  `is_archived` tinyint(1) DEFAULT 0,
  `required_hours` int(11) DEFAULT 486,
  `rendered_hours` decimal(8,2) DEFAULT 0.00,
  `ojt_start_date` date DEFAULT NULL,
  `ojt_end_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `user_id`, `student_id_no`, `program_id`, `program`, `department`, `year_level`, `contact_number`, `coordinator_id`, `company_id`, `ojt_status`, `onboarding_completed_at`, `status_notes`, `is_archived`, `required_hours`, `rendered_hours`, `ojt_start_date`, `ojt_end_date`) VALUES
(1, 6, '2022-00123', 1, 'BSIT', 'Information Technology', '4th Year', '', 1, 1, 'completed', '2026-10-02 00:00:00', NULL, 0, 486, 486.00, '2025-09-08', NULL),
(2, 7, '2022-00145', 1, 'BSIT', 'Information Technology', '4th Year', NULL, 1, 1, 'ongoing', '2026-10-02 00:00:00', NULL, 0, 486, 0.00, '2025-09-08', NULL),
(3, 8, '2022-00201', 2, 'BSCS', 'Information Technology', '4th Year', NULL, 1, 1, 'completed', '2026-10-02 00:00:00', NULL, NULL, 486, 486.00, '2025-09-08', '0000-00-00'),
(4, 9, '2022-00098', 1, 'BSIT', 'Information Technology', '4th Year', NULL, 1, NULL, 'completed', '2026-10-02 00:00:00', NULL, 0, 486, 486.00, NULL, NULL),
(5, 10, '2021-00089', 1, 'BSIT', 'Information Technology', '4th Year', NULL, 1, 1, 'completed', '2026-10-02 00:00:00', NULL, 0, 486, 486.00, '2025-09-08', NULL),
(7, 12, 'S20260001', 1, 'BSIT', 'BSIT', '4th Year', NULL, 1, 1, 'ongoing', '2026-10-02 15:18:23', NULL, 0, 486, 0.00, NULL, NULL),
(8, 13, 'S20260002', 1, 'BSIT', 'BSIT', '4th Year', NULL, 1, 2, 'ongoing', '2026-10-02 21:59:58', NULL, 0, 486, 0.00, '2026-10-03', '2026-10-31');

-- --------------------------------------------------------

--
-- Table structure for table `thread_members`
--

CREATE TABLE `thread_members` (
  `id` int(11) NOT NULL,
  `thread_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `thread_members`
--

INSERT INTO `thread_members` (`id`, `thread_id`, `user_id`, `joined_at`) VALUES
(1, 1, 2, '2026-09-28 08:23:20'),
(2, 1, 6, '2026-09-28 08:23:20'),
(3, 1, 7, '2026-09-28 08:23:20'),
(4, 1, 8, '2026-09-28 08:23:20'),
(5, 1, 9, '2026-09-28 08:23:20'),
(6, 1, 10, '2026-09-28 08:23:20'),
(7, 2, 2, '2026-09-28 08:23:20'),
(8, 2, 4, '2026-09-28 08:23:20'),
(9, 2, 5, '2026-09-28 08:23:20'),
(10, 3, 8, '2026-09-28 22:08:12'),
(11, 3, 2, '2026-09-28 22:08:12');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('student','coordinator','company','admin') NOT NULL,
  `status` enum('active','inactive','archived') DEFAULT 'active',
  `avatar` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `status`, `avatar`, `created_at`) VALUES
(1, 'System Admin', 'admin@ustp.edu.ph', '$2y$10$r6urthy2QLpsO7ybJoha6ek7MlIEPjCcQ1QK9Sw4MuKFQuWoiYQti', 'admin', 'active', NULL, '2026-09-28 08:23:19'),
(2, 'Prof. Jocelyn Rivera', 'jocelyn.rivera@ustp.edu.ph', '$2y$10$UjMCzN5bF4BGcF/V35nYpOsOgJbnjtC.2elLom9w0yond/YV10f9e', 'coordinator', 'active', NULL, '2026-09-28 08:23:19'),
(3, 'Prof. Mark Santos', 'mark.santos@ustp.edu.ph', '$2y$10$UjMCzN5bF4BGcF/V35nYpOsOgJbnjtC.2elLom9w0yond/YV10f9e', 'coordinator', 'active', NULL, '2026-09-28 08:23:19'),
(4, 'Engr. Ramon Diaz', 'r.diaz@icthub.com', '$2y$10$JCSOJyubjV/NrK7zOIE2PempfoQFkgNi8g64whMO5wtCmohkUUEGm', 'company', 'active', NULL, '2026-09-28 08:23:19'),
(5, 'Ms. Grace Tan', 'g.tan@davaosoft.com', '$2y$10$JCSOJyubjV/NrK7zOIE2PempfoQFkgNi8g64whMO5wtCmohkUUEGm', 'company', 'active', NULL, '2026-09-28 08:23:19'),
(6, 'Maria Santos', 'maria.santos@ustp.edu.ph', '$2y$10$UKBXkww2/NrJDjFlk5vAsusocFLdtQYps.6Ol7M7zCRYARIU5jcHe', 'student', 'active', NULL, '2026-09-28 08:23:20'),
(7, 'Juan dela Cruz', 'juan.delacruz@ustp.edu.ph', '$2y$10$UKBXkww2/NrJDjFlk5vAsusocFLdtQYps.6Ol7M7zCRYARIU5jcHe', 'student', 'active', NULL, '2026-09-28 08:23:20'),
(8, 'Ana Reyes', 'ana.reyes@ustp.edu.ph', '$2y$10$UKBXkww2/NrJDjFlk5vAsusocFLdtQYps.6Ol7M7zCRYARIU5jcHe', 'student', 'active', NULL, '2026-09-28 08:23:20'),
(9, 'Carlo Mendoza', 'carlo.mendoza@ustp.edu.ph', '$2y$10$UKBXkww2/NrJDjFlk5vAsusocFLdtQYps.6Ol7M7zCRYARIU5jcHe', 'student', 'active', NULL, '2026-09-28 08:23:20'),
(10, 'Ryan Castro', 'ryan.castro@ustp.edu.ph', '$2y$10$UKBXkww2/NrJDjFlk5vAsusocFLdtQYps.6Ol7M7zCRYARIU5jcHe', 'student', 'active', NULL, '2026-09-28 08:23:20'),
(12, 'aligator', 'ali@ustp.edu.ph', '$2y$10$bxWs3YnSR89PXmgh2hDY1OAUx3Ez6BJd165jf2L5225ePh2wQzxy2', 'student', 'inactive', NULL, '2026-10-02 15:06:23'),
(13, 'banana', 'ban@ustp.edu.ph', '$2y$10$VXf2Sxcyckj41mNTC1AjtewNVNSm/1XBXQUGjo5Rl9dhFEJX/mDjO', 'student', 'active', NULL, '2026-10-02 20:39:36');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`student_id`,`date`);

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `coordinators`
--
ALTER TABLE `coordinators`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `evaluations`
--
ALTER TABLE `evaluations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `evaluator_id` (`evaluator_id`);

--
-- Indexes for table `evaluation_assignments`
--
ALTER TABLE `evaluation_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `form_id` (`form_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `requested_by` (`requested_by`);

--
-- Indexes for table `evaluation_forms`
--
ALTER TABLE `evaluation_forms`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `eval_answers`
--
ALTER TABLE `eval_answers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `submission_id` (`submission_id`);

--
-- Indexes for table `eval_criteria`
--
ALTER TABLE `eval_criteria`
  ADD PRIMARY KEY (`id`),
  ADD KEY `section_id` (`section_id`);

--
-- Indexes for table `eval_rating_rules`
--
ALTER TABLE `eval_rating_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `form_id` (`form_id`);

--
-- Indexes for table `eval_sections`
--
ALTER TABLE `eval_sections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `form_id` (`form_id`);

--
-- Indexes for table `eval_submissions`
--
ALTER TABLE `eval_submissions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `thread_id` (`thread_id`),
  ADD KEY `sender_id` (`sender_id`);

--
-- Indexes for table `message_reads`
--
ALTER TABLE `message_reads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_read` (`message_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `message_threads`
--
ALTER TABLE `message_threads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `ojt_requirements`
--
ALTER TABLE `ojt_requirements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `reviewed_by` (`reviewed_by`);

--
-- Indexes for table `programs`
--
ALTER TABLE `programs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `reviewed_by` (`reviewed_by`);

--
-- Indexes for table `requirement_templates`
--
ALTER TABLE `requirement_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `coordinator_id` (`coordinator_id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_id_no` (`student_id_no`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `coordinator_id` (`coordinator_id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `program_id` (`program_id`);

--
-- Indexes for table `thread_members`
--
ALTER TABLE `thread_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_member` (`thread_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=350;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `coordinators`
--
ALTER TABLE `coordinators`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `evaluations`
--
ALTER TABLE `evaluations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `evaluation_assignments`
--
ALTER TABLE `evaluation_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `evaluation_forms`
--
ALTER TABLE `evaluation_forms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `eval_answers`
--
ALTER TABLE `eval_answers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `eval_criteria`
--
ALTER TABLE `eval_criteria`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `eval_rating_rules`
--
ALTER TABLE `eval_rating_rules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `eval_sections`
--
ALTER TABLE `eval_sections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `eval_submissions`
--
ALTER TABLE `eval_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `journal_entries`
--
ALTER TABLE `journal_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `message_reads`
--
ALTER TABLE `message_reads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=222;

--
-- AUTO_INCREMENT for table `message_threads`
--
ALTER TABLE `message_threads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=92;

--
-- AUTO_INCREMENT for table `ojt_requirements`
--
ALTER TABLE `ojt_requirements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `programs`
--
ALTER TABLE `programs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `reports`
--
ALTER TABLE `reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `requirement_templates`
--
ALTER TABLE `requirement_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `thread_members`
--
ALTER TABLE `thread_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `announcements`
--
ALTER TABLE `announcements`
  ADD CONSTRAINT `announcements_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `companies`
--
ALTER TABLE `companies`
  ADD CONSTRAINT `companies_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `coordinators`
--
ALTER TABLE `coordinators`
  ADD CONSTRAINT `coordinators_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `evaluations`
--
ALTER TABLE `evaluations`
  ADD CONSTRAINT `evaluations_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `evaluations_ibfk_2` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  ADD CONSTRAINT `evaluations_ibfk_3` FOREIGN KEY (`evaluator_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `evaluation_assignments`
--
ALTER TABLE `evaluation_assignments`
  ADD CONSTRAINT `evaluation_assignments_ibfk_1` FOREIGN KEY (`form_id`) REFERENCES `evaluation_forms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `evaluation_assignments_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `evaluation_assignments_ibfk_3` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `evaluation_assignments_ibfk_4` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `evaluation_forms`
--
ALTER TABLE `evaluation_forms`
  ADD CONSTRAINT `evaluation_forms_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `eval_answers`
--
ALTER TABLE `eval_answers`
  ADD CONSTRAINT `eval_answers_ibfk_1` FOREIGN KEY (`submission_id`) REFERENCES `eval_submissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `eval_criteria`
--
ALTER TABLE `eval_criteria`
  ADD CONSTRAINT `eval_criteria_ibfk_1` FOREIGN KEY (`section_id`) REFERENCES `eval_sections` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `eval_rating_rules`
--
ALTER TABLE `eval_rating_rules`
  ADD CONSTRAINT `eval_rating_rules_ibfk_1` FOREIGN KEY (`form_id`) REFERENCES `evaluation_forms` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `eval_sections`
--
ALTER TABLE `eval_sections`
  ADD CONSTRAINT `eval_sections_ibfk_1` FOREIGN KEY (`form_id`) REFERENCES `evaluation_forms` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD CONSTRAINT `journal_entries_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`thread_id`) REFERENCES `message_threads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `message_reads`
--
ALTER TABLE `message_reads`
  ADD CONSTRAINT `message_reads_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `message_reads_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `message_threads`
--
ALTER TABLE `message_threads`
  ADD CONSTRAINT `message_threads_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ojt_requirements`
--
ALTER TABLE `ojt_requirements`
  ADD CONSTRAINT `ojt_requirements_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ojt_requirements_ibfk_2` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `reports`
--
ALTER TABLE `reports`
  ADD CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reports_ibfk_2` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `requirement_templates`
--
ALTER TABLE `requirement_templates`
  ADD CONSTRAINT `requirement_templates_ibfk_1` FOREIGN KEY (`coordinator_id`) REFERENCES `coordinators` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `students_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `students_ibfk_2` FOREIGN KEY (`coordinator_id`) REFERENCES `coordinators` (`id`),
  ADD CONSTRAINT `students_ibfk_3` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`);

--
-- Constraints for table `thread_members`
--
ALTER TABLE `thread_members`
  ADD CONSTRAINT `thread_members_ibfk_1` FOREIGN KEY (`thread_id`) REFERENCES `message_threads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `thread_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
