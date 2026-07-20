-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 29, 2026 at 07:06 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.1.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ems_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `id` int(11) NOT NULL,
  `office` varchar(100) NOT NULL,
  `title` varchar(200) NOT NULL,
  `status` enum('Implemented','Partially','Not Implemented') NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `approval_status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `file_link` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `documents`
--

INSERT INTO `documents` (`id`, `office`, `title`, `status`, `file_name`, `approval_status`, `file_link`) VALUES
(6, 'Registrar', 'collegefair', 'Implemented', 'capstone7-updated-chapter1-2.docx', 'Rejected', NULL),
(7, 'Registrar', 'capstone', 'Partially', 'compliance-matrix.docx', 'Rejected', NULL),
(8, 'Finance', 'DOCX', 'Partially', 'Balmes-ouput-prototype.NEW (1).docx', 'Rejected', NULL),
(9, 'Finance', 'card', 'Implemented', 'vans.jpg', 'Approved', NULL),
(10, 'Registrar', 'card', 'Implemented', 'vans.jpg', 'Pending', NULL),
(11, 'Registrar', 'grades', 'Implemented', 'vans.jpg', 'Pending', NULL),
(12, 'Registrar', 'gggggg', 'Partially', 'QA-dashboard.png', 'Pending', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `audit_recommendations`
--

CREATE TABLE `audit_recommendations` (
  `id` int(11) NOT NULL,
  `audit_type` enum('External','Internal') NOT NULL,
  `office` varchar(100) NOT NULL,
  `recommendation` text NOT NULL,
  `year` varchar(4) NOT NULL,
  `status` enum('Pending','Submitted','Not Submitted') NOT NULL DEFAULT 'Pending',
  `remarks` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `feedback`
--

CREATE TABLE `feedback` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `office` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `date_sent` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tasks`
--

CREATE TABLE `tasks` (
  `id` int(11) NOT NULL,
  `task_name` varchar(200) NOT NULL,
  `office` varchar(100) NOT NULL,
  `due_date` date NOT NULL,
  `status` enum('Implemented','Partially','Not Implemented') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(30) DEFAULT '',
  `role` varchar(20) NOT NULL,
  `office` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `email`, `phone`, `role`, `office`) VALUES
(1, 'admin', 'admin@2026', 'adrianbalmes211@gmail.com', '', 'admin', 'Admin'),
(2, 'user1', '12345', 'sannoelpodadera1@gmail.com', '', 'user', 'Faculty'),
(3, 'reg1', '123456', '', '', 'user', 'Registrar'),
(5, 'finance', '123456', '', '', 'user', 'Finance'),
(6, 'cssao', 'cssao2026', '', '', 'user', 'CSSAO');


-- --------------------------------------------------------

--
-- Table structure for table `sms_outbox`
--

CREATE TABLE `sms_outbox` (
  `id` int(11) NOT NULL,
  `phone` varchar(30) NOT NULL,
  `message` text NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `recommendation_documents`
--

CREATE TABLE `recommendation_documents` (
  `id` int(11) NOT NULL,
  `recommendation_id` int(11) NOT NULL,
  `office` varchar(100) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `uploaded_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `site_content`
--

CREATE TABLE `site_content` (
  `content_key` varchar(150) NOT NULL,
  `content_value` text NOT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_recommendations`
--
ALTER TABLE `audit_recommendations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `feedback`
--
ALTER TABLE `feedback`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sms_outbox`
--
ALTER TABLE `sms_outbox`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `site_content`
--
ALTER TABLE `site_content`
  ADD PRIMARY KEY (`content_key`);

--
-- Indexes for table `recommendation_documents`
--
ALTER TABLE `recommendation_documents`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_recommendations`
--
ALTER TABLE `audit_recommendations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tasks`
--
ALTER TABLE `tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `sms_outbox`
--
ALTER TABLE `sms_outbox`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `recommendation_documents`
--
ALTER TABLE `recommendation_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
