-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 25, 2026 at 03:19 AM
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
-- Database: `form_builder`
--

-- --------------------------------------------------------

--
-- Table structure for table `answers`
--

CREATE TABLE `answers` (
  `id` int(11) NOT NULL,
  `response_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `answer_text` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `answers`
--

INSERT INTO `answers` (`id`, `response_id`, `question_id`, `answer_text`) VALUES
(61, 20, 468, 'IIS UPDATES'),
(62, 20, 469, '2026-05-11'),
(63, 20, 470, 'Darwin Karl Bactuitis Pua'),
(64, 20, 471, 'Project Development Assistant'),
(65, 20, 472, '27'),
(66, 20, 473, '0.583'),
(67, 20, 474, 'darwinpua.engr@gmail.com'),
(68, 20, 475, ''),
(69, 20, 476, 'Agree'),
(70, 20, 477, 'Agree'),
(71, 20, 478, 'Agree'),
(72, 20, 479, 'Agree'),
(73, 20, 480, ''),
(74, 20, 481, 'Agree'),
(75, 20, 482, 'Agree'),
(76, 20, 483, 'Agree'),
(77, 20, 484, 'Agree'),
(78, 20, 485, 'Agree'),
(79, 20, 486, 'Agree'),
(80, 20, 487, 'Yes'),
(81, 20, 488, 'No'),
(82, 20, 489, 'None'),
(83, 20, 490, 'Very Good');

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`, `created_at`) VALUES
(1, 'General', '2026-02-24 09:16:11'),
(2, 'External', '2026-02-24 09:16:11'),
(3, 'Internal', '2026-02-24 09:16:11');

-- --------------------------------------------------------

--
-- Table structure for table `forms`
--

CREATE TABLE `forms` (
  `id` int(11) NOT NULL,
  `created_by` int(11) DEFAULT NULL COMMENT 'FK to users.id — which user created this form',
  `form_code` varchar(20) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `privacy_notice` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 = no privacy notice modal, 1 = show standard privacy notice on submit',
  `step_mode` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 = continuous form, 1 = multi-step form driven by section blocks',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `category_id` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `forms`
--

INSERT INTO `forms` (`id`, `created_by`, `form_code`, `title`, `description`, `privacy_notice`, `step_mode`, `created_at`, `category_id`) VALUES
(43, 1, 'mQBjJUtF', 'End of Learning Evaluation', '', 1, 1, '2026-03-31 00:03:45', 3),
(44, 1, 'IyOYC1Bu', 'Learning Service Provider Evaluation Form', '', 1, 0, '2026-03-31 00:07:38', 3),
(52, 1, 'wG0whlQS', 'Employee Details Form', 'Form to record basic employee data', 1, 0, '2026-03-31 03:54:23', 2),
(62, 1, 'form-ownership-Du4e8', 'Form Ownership', 'Test of form ownership', 1, 0, '2026-05-04 07:35:53', 1),
(63, 2, 'finance-and-administ', 'Finance and Administrative Division Form', 'Form for the Finance and Administrative Division', 1, 0, '2026-05-05 00:54:32', 1);

-- --------------------------------------------------------

--
-- Table structure for table `questions`
--

CREATE TABLE `questions` (
  `id` int(11) NOT NULL,
  `form_id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `description` text DEFAULT NULL,
  `question_type` varchar(50) NOT NULL,
  `rating_scale` varchar(50) DEFAULT NULL,
  `number_min` decimal(10,2) DEFAULT NULL,
  `number_max` decimal(10,2) DEFAULT NULL,
  `number_step` varchar(10) DEFAULT NULL,
  `datetime_type` varchar(20) DEFAULT NULL,
  `position` int(11) DEFAULT 0,
  `is_required` tinyint(1) DEFAULT 1,
  `condition_question_id` int(11) DEFAULT NULL,
  `condition_type` varchar(50) DEFAULT 'equals',
  `condition_value` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `questions`
--

INSERT INTO `questions` (`id`, `form_id`, `question_text`, `description`, `question_type`, `rating_scale`, `number_min`, `number_max`, `number_step`, `datetime_type`, `position`, `is_required`, `condition_question_id`, `condition_type`, `condition_value`) VALUES
(417, 43, 'Learning and Development Intervention Title', NULL, 'text', NULL, NULL, NULL, '1', 'date', 0, 1, NULL, 'equals', NULL),
(418, 43, 'Date/s (mm/dd/yyyy)', NULL, 'datetime', NULL, NULL, NULL, '1', 'date', 1, 1, NULL, 'equals', NULL),
(419, 43, 'Learner\'s Name (First Name, Middle Name, Surname)', NULL, 'text', NULL, NULL, NULL, '1', 'date', 2, 1, NULL, 'equals', NULL),
(420, 43, 'Learner\'s Position', NULL, 'text', NULL, NULL, NULL, '1', 'date', 3, 1, NULL, 'equals', NULL),
(421, 43, 'Learner\'s Age', NULL, 'number', NULL, NULL, NULL, '1', 'date', 4, 1, NULL, 'equals', NULL),
(422, 43, 'Email Address', NULL, 'email', NULL, NULL, NULL, '1', 'date', 5, 1, NULL, 'equals', NULL),
(423, 43, 'Objective of the Event:', NULL, 'section', NULL, NULL, NULL, '1', 'date', 6, 0, NULL, 'equals', NULL),
(424, 43, 'Objective of the Event: The objectives were clearly communicated', NULL, 'rating', 'satisfaction_5', NULL, NULL, '1', 'date', 7, 1, NULL, 'equals', NULL),
(425, 43, 'Objective of the Event: The objectives of the L&D intervention were attained', NULL, 'rating', 'satisfaction_5', NULL, NULL, '1', 'date', 8, 1, NULL, 'equals', NULL),
(426, 43, 'Topic:', NULL, 'section', NULL, NULL, NULL, '1', 'date', 9, 0, NULL, 'equals', NULL),
(427, 43, 'Topics: The Sequence of topics is logical and faciliated easier understanding', NULL, 'rating', 'satisfaction_5', NULL, NULL, '1', 'date', 10, 1, NULL, 'equals', NULL),
(428, 43, 'Topics: The intervention was comprehensive and provided my needed knwoledge', NULL, 'rating', 'satisfaction_5', NULL, NULL, '1', 'date', 11, 1, NULL, 'equals', NULL),
(429, 43, 'Topics: The intervention is relevant to my job', NULL, 'rating', 'agree_5', NULL, NULL, '1', 'date', 12, 1, NULL, 'equals', NULL),
(430, 43, 'Time Schedule:', NULL, 'section', NULL, NULL, NULL, '1', 'date', 13, 0, NULL, 'equals', NULL),
(431, 43, 'Time Schedule: The time alloted for each session/section was sufficient', NULL, 'rating', 'agree_5', NULL, NULL, '1', 'date', 14, 1, NULL, 'equals', NULL),
(432, 43, 'Time Schedule: The time alloted for the training was sufficient ', NULL, 'rating', 'agree_5', NULL, NULL, '1', 'date', 15, 1, NULL, 'equals', NULL),
(433, 43, 'Methodology:', NULL, 'section', NULL, NULL, NULL, '1', 'date', 16, 0, NULL, 'equals', NULL),
(434, 43, 'Methodology: The methodologies used were appropriate ', NULL, 'rating', 'agree_5', NULL, NULL, '1', 'date', 17, 1, NULL, 'equals', NULL),
(435, 43, 'Learning Event Team:', NULL, 'section', NULL, NULL, NULL, '1', 'date', 18, 0, NULL, 'equals', NULL),
(436, 43, 'Learning Event Team: The learning event team was attentive to the basic needs of learners', NULL, 'rating', 'agree_5', NULL, NULL, '1', 'date', 19, 1, NULL, 'equals', NULL),
(437, 43, 'Learning Event Team: The learning event team was organized and well-prepared', NULL, 'rating', 'agree_5', NULL, NULL, '1', 'date', 20, 1, NULL, 'equals', NULL),
(438, 43, 'Overall', NULL, 'section', NULL, NULL, NULL, '1', 'date', 21, 0, NULL, 'equals', NULL),
(439, 43, 'How was your overall experience with the training?', NULL, 'rating', 'quality_5', NULL, NULL, '1', 'date', 22, 1, NULL, 'equals', NULL),
(440, 43, 'Overall, what did you gain from this learning?', NULL, 'text', NULL, NULL, NULL, '1', 'date', 23, 1, NULL, 'equals', NULL),
(441, 43, 'What part of the Learning Event do you think was least helpful? Why?', NULL, 'text', NULL, NULL, NULL, '1', 'date', 24, 1, NULL, 'equals', NULL),
(442, 43, 'Your comments or suggestions about this Learning Event.', NULL, 'text', NULL, NULL, NULL, '1', 'date', 25, 1, NULL, 'equals', NULL),
(459, 63, 'Are you part of the FAD?', NULL, 'text', NULL, NULL, NULL, NULL, NULL, 0, 1, NULL, 'equals', NULL),
(468, 44, 'Learning and Developement Intervention Title', NULL, 'text', NULL, NULL, NULL, '1', 'date', 0, 1, NULL, 'equals', NULL),
(469, 44, 'Date/s (mm/dd/yyyy)', NULL, 'datetime', NULL, NULL, NULL, '1', 'date', 1, 1, NULL, 'equals', NULL),
(470, 44, 'Learner\'s Name (First Name, Middle Name, Surname)', NULL, 'text', NULL, NULL, NULL, '1', 'date', 2, 1, NULL, 'equals', NULL),
(471, 44, 'Learner\'s Position', NULL, 'text', NULL, NULL, NULL, '1', 'date', 3, 1, NULL, 'equals', NULL),
(472, 44, 'Learner\'s Age', NULL, 'number', NULL, 0.00, NULL, '1', 'date', 4, 1, NULL, 'equals', NULL),
(473, 44, 'Years in the Department regarless of employement status/nature', NULL, 'number', NULL, NULL, NULL, '1', 'date', 5, 1, NULL, 'equals', NULL),
(474, 44, 'Email Address', NULL, 'text', NULL, NULL, NULL, '1', 'date', 6, 1, NULL, 'equals', NULL),
(475, 44, 'Content:', NULL, 'section', NULL, NULL, NULL, '1', 'date', 7, 0, NULL, 'equals', NULL),
(476, 44, 'Content: The objectives of the event were clearly defined and communicated', NULL, 'rating', 'agree_5', NULL, NULL, '1', 'date', 8, 1, NULL, 'equals', NULL),
(477, 44, 'Content: The objectives of the event were attained', NULL, 'rating', 'agree_5', NULL, NULL, '1', 'date', 9, 1, NULL, 'equals', NULL),
(478, 44, 'Content: The time allotted for the training was sufficient', NULL, 'rating', 'agree_5', NULL, NULL, '1', 'date', 10, 1, NULL, 'equals', NULL),
(479, 44, 'Content: The training has a good mix of theories and applications', NULL, 'rating', 'agree_5', NULL, NULL, '1', 'date', 11, 1, NULL, 'equals', NULL),
(480, 44, 'Overall:', NULL, 'section', NULL, NULL, NULL, '1', 'date', 12, 0, NULL, 'equals', NULL),
(481, 44, 'Participation and interaction was encouraged', NULL, 'rating', 'agree_5', NULL, NULL, '1', 'date', 13, 1, NULL, 'equals', NULL),
(482, 44, 'The electronic media used in the discussion assisted my learning and understanding of the topic', NULL, 'rating', 'agree_5', NULL, NULL, '1', 'date', 14, 1, NULL, 'equals', NULL),
(483, 44, 'Relevant examples were provided for in-depth discussion', NULL, 'rating', 'agree_5', NULL, NULL, '1', 'date', 15, 1, NULL, 'equals', NULL),
(484, 44, 'The training team was attentice to the needs of the learners', NULL, 'rating', 'agree_5', NULL, NULL, '1', 'date', 16, 1, NULL, 'equals', NULL),
(485, 44, 'The trainers/lecturers were effective in presenting the topics', NULL, 'rating', 'agree_5', NULL, NULL, '1', 'date', 17, 1, NULL, 'equals', NULL),
(486, 44, 'Overall, this is a helpful course that should be taken by other EMB-1 personnel', NULL, 'rating', 'agree_5', NULL, NULL, '1', 'date', 18, 1, NULL, 'equals', NULL),
(487, 44, 'Do you recommend the training provider to conduct the same or other related trainings to EMB-1? Kindly expound your answer.', NULL, 'text', NULL, NULL, NULL, '1', 'date', 19, 1, NULL, 'equals', NULL),
(488, 44, 'Apart from this Learning Service Provider, do you know other organizations/institutions that conduct similar or related learning interventions?', NULL, 'checkbox', NULL, NULL, NULL, '1', 'date', 20, 1, NULL, 'equals', NULL),
(489, 44, 'Do you have any recommendations/comments to improve the performance of the training provider?', NULL, 'text', NULL, NULL, NULL, '1', 'date', 21, 1, NULL, 'equals', NULL),
(490, 44, 'Overall, how was the performance of the Learning Service Provider?', NULL, 'rating', 'quality_5', NULL, NULL, '1', 'date', 22, 1, NULL, 'equals', NULL),
(609, 52, 'General', NULL, 'section', NULL, NULL, NULL, NULL, 'date', 0, 0, NULL, 'equals', NULL),
(610, 52, 'Full Name (First name, Middle name, Surname)', NULL, 'text', NULL, NULL, NULL, NULL, 'date', 1, 1, NULL, 'equals', NULL),
(611, 52, 'Department or Bureau', NULL, 'checkbox', NULL, NULL, NULL, NULL, 'date', 2, 1, NULL, 'equals', NULL),
(612, 52, 'Division', NULL, 'checkbox', NULL, NULL, NULL, NULL, 'date', 3, 1, 611, 'equals', 'Environmental Management Bureau'),
(613, 52, 'Unit/Section', NULL, 'checkbox', NULL, NULL, NULL, NULL, 'date', 4, 1, 612, 'equals', 'EMED'),
(614, 52, 'Unit/Section', NULL, 'checkbox', NULL, NULL, NULL, NULL, 'date', 5, 1, 612, 'equals', 'CPD'),
(615, 52, 'Unit/Section', NULL, 'checkbox', NULL, NULL, NULL, NULL, 'date', 6, 1, 612, 'equals', 'FAD'),
(616, 52, 'Unit/Section', NULL, 'checkbox', NULL, NULL, NULL, NULL, 'date', 7, 1, 612, 'equals', 'ORD'),
(617, 52, 'Position', NULL, 'text', NULL, NULL, NULL, NULL, 'date', 8, 1, NULL, 'equals', NULL),
(618, 52, 'Address', NULL, 'text', NULL, NULL, NULL, NULL, 'date', 9, 1, NULL, 'equals', NULL),
(619, 52, 'Contact No', NULL, 'text', NULL, NULL, NULL, NULL, 'date', 10, 1, NULL, 'equals', NULL),
(620, 52, 'Birthdate (mm/dd/yyyy)', NULL, 'datetime', NULL, NULL, NULL, NULL, 'date', 11, 1, NULL, 'equals', NULL),
(621, 52, 'Email', NULL, 'email', NULL, NULL, NULL, NULL, NULL, 12, 1, NULL, 'equals', NULL),
(622, 62, 'Who owns his form?', NULL, 'text', NULL, NULL, NULL, NULL, 'date', 0, 1, NULL, 'equals', NULL),
(623, 62, 'Do you own this form?', NULL, 'multiple_choice', NULL, NULL, NULL, NULL, 'date', 1, 1, NULL, 'equals', NULL),
(624, 62, 'Input a number', NULL, 'number', NULL, 0.00, NULL, NULL, 'date', 2, 1, NULL, 'equals', NULL),
(625, 62, 'Input a number', NULL, 'number', NULL, 0.00, NULL, NULL, 'date', 3, 1, NULL, 'equals', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `question_options`
--

CREATE TABLE `question_options` (
  `id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `option_text` varchar(255) NOT NULL,
  `position` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `question_options`
--

INSERT INTO `question_options` (`id`, `question_id`, `option_text`, `position`) VALUES
(922, 424, 'Very Dissatisfied', 0),
(923, 424, 'Dissatisfied', 1),
(924, 424, 'Neutral', 2),
(925, 424, 'Satisfied', 3),
(926, 424, 'Very Satisfied', 4),
(927, 425, 'Very Dissatisfied', 0),
(928, 425, 'Dissatisfied', 1),
(929, 425, 'Neutral', 2),
(930, 425, 'Satisfied', 3),
(931, 425, 'Very Satisfied', 4),
(932, 427, 'Very Dissatisfied', 0),
(933, 427, 'Dissatisfied', 1),
(934, 427, 'Neutral', 2),
(935, 427, 'Satisfied', 3),
(936, 427, 'Very Satisfied', 4),
(937, 428, 'Very Dissatisfied', 0),
(938, 428, 'Dissatisfied', 1),
(939, 428, 'Neutral', 2),
(940, 428, 'Satisfied', 3),
(941, 428, 'Very Satisfied', 4),
(942, 429, 'Strongly Disagree', 0),
(943, 429, 'Disagree', 1),
(944, 429, 'Neutral', 2),
(945, 429, 'Agree', 3),
(946, 429, 'Strongly Agree', 4),
(947, 431, 'Strongly Disagree', 0),
(948, 431, 'Disagree', 1),
(949, 431, 'Neutral', 2),
(950, 431, 'Agree', 3),
(951, 431, 'Strongly Agree', 4),
(952, 432, 'Strongly Disagree', 0),
(953, 432, 'Disagree', 1),
(954, 432, 'Neutral', 2),
(955, 432, 'Agree', 3),
(956, 432, 'Strongly Agree', 4),
(957, 434, 'Strongly Disagree', 0),
(958, 434, 'Disagree', 1),
(959, 434, 'Neutral', 2),
(960, 434, 'Agree', 3),
(961, 434, 'Strongly Agree', 4),
(962, 436, 'Strongly Disagree', 0),
(963, 436, 'Disagree', 1),
(964, 436, 'Neutral', 2),
(965, 436, 'Agree', 3),
(966, 436, 'Strongly Agree', 4),
(967, 437, 'Strongly Disagree', 0),
(968, 437, 'Disagree', 1),
(969, 437, 'Neutral', 2),
(970, 437, 'Agree', 3),
(971, 437, 'Strongly Agree', 4),
(972, 439, 'Poor', 0),
(973, 439, 'Fair', 1),
(974, 439, 'Good', 2),
(975, 439, 'Very Good', 3),
(976, 439, 'Excellent', 4),
(1012, 476, 'Strongly Disagree', 0),
(1013, 476, 'Disagree', 1),
(1014, 476, 'Neutral', 2),
(1015, 476, 'Agree', 3),
(1016, 476, 'Strongly Agree', 4),
(1017, 477, 'Strongly Disagree', 0),
(1018, 477, 'Disagree', 1),
(1019, 477, 'Neutral', 2),
(1020, 477, 'Agree', 3),
(1021, 477, 'Strongly Agree', 4),
(1022, 478, 'Strongly Disagree', 0),
(1023, 478, 'Disagree', 1),
(1024, 478, 'Neutral', 2),
(1025, 478, 'Agree', 3),
(1026, 478, 'Strongly Agree', 4),
(1027, 479, 'Strongly Disagree', 0),
(1028, 479, 'Disagree', 1),
(1029, 479, 'Neutral', 2),
(1030, 479, 'Agree', 3),
(1031, 479, 'Strongly Agree', 4),
(1032, 481, 'Strongly Disagree', 0),
(1033, 481, 'Disagree', 1),
(1034, 481, 'Neutral', 2),
(1035, 481, 'Agree', 3),
(1036, 481, 'Strongly Agree', 4),
(1037, 482, 'Strongly Disagree', 0),
(1038, 482, 'Disagree', 1),
(1039, 482, 'Neutral', 2),
(1040, 482, 'Agree', 3),
(1041, 482, 'Strongly Agree', 4),
(1042, 483, 'Strongly Disagree', 0),
(1043, 483, 'Disagree', 1),
(1044, 483, 'Neutral', 2),
(1045, 483, 'Agree', 3),
(1046, 483, 'Strongly Agree', 4),
(1047, 484, 'Strongly Disagree', 0),
(1048, 484, 'Disagree', 1),
(1049, 484, 'Neutral', 2),
(1050, 484, 'Agree', 3),
(1051, 484, 'Strongly Agree', 4),
(1052, 485, 'Strongly Disagree', 0),
(1053, 485, 'Disagree', 1),
(1054, 485, 'Neutral', 2),
(1055, 485, 'Agree', 3),
(1056, 485, 'Strongly Agree', 4),
(1057, 486, 'Strongly Disagree', 0),
(1058, 486, 'Disagree', 1),
(1059, 486, 'Neutral', 2),
(1060, 486, 'Agree', 3),
(1061, 486, 'Strongly Agree', 4),
(1062, 488, 'Yes', 0),
(1063, 488, 'No', 1),
(1064, 490, 'Poor', 0),
(1065, 490, 'Fair', 1),
(1066, 490, 'Good', 2),
(1067, 490, 'Very Good', 3),
(1068, 490, 'Excellent', 4),
(1200, 611, 'Department of Natural Resources', 0),
(1201, 611, 'Environmental Management Bureau', 1),
(1202, 612, 'EMED', 0),
(1203, 612, 'CPD', 1),
(1204, 612, 'FAD', 2),
(1205, 612, 'ORD', 3),
(1206, 613, 'AWMS', 0),
(1207, 613, 'CHWMS', 1),
(1208, 613, 'AMTSS', 2),
(1209, 613, 'ESWMS', 3),
(1210, 614, 'EIAMS', 0),
(1211, 614, 'AWPS', 1),
(1212, 614, 'CHWPS', 2),
(1213, 615, 'Accounting Unit', 0),
(1214, 615, 'Budget Unit', 1),
(1215, 615, 'Property/GSS', 2),
(1216, 615, 'Cashier Unit', 3),
(1217, 615, 'Records Unit', 4),
(1218, 615, 'HRMD Unit', 5),
(1219, 616, 'PISMU/MIS', 0),
(1220, 616, 'REL', 1),
(1221, 616, 'EEIU', 2),
(1222, 616, 'Legal Unit', 3),
(1223, 616, 'Climate Change Unit', 4),
(1224, 623, 'Yes', 0),
(1225, 623, 'No', 1),
(1226, 623, 'Unsure', 2);

-- --------------------------------------------------------

--
-- Table structure for table `responses`
--

CREATE TABLE `responses` (
  `id` int(11) NOT NULL,
  `form_id` int(11) NOT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `responses`
--

INSERT INTO `responses` (`id`, `form_id`, `submitted_at`) VALUES
(18, 52, '2026-05-07 07:59:06'),
(20, 44, '2026-05-11 07:06:43'),
(21, 62, '2026-05-11 07:11:53'),
(22, 52, '2026-05-11 07:12:48'),
(23, 62, '2026-05-11 08:17:55'),
(24, 62, '2026-05-12 03:23:55'),
(26, 62, '2026-05-12 08:44:08'),
(33, 62, '2026-05-14 00:34:11'),
(34, 62, '2026-05-14 00:39:44');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `role` enum('user','super_admin') NOT NULL DEFAULT 'user',
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `role`, `password_hash`, `created_at`) VALUES
(1, 'admin_ORD', 'user', '$2y$10$IvPoUIRuu1yeUkszn8Cu4eNDv2D7/anuWsIkAFoa3m0gsUGZEeZuu', '2026-04-29 01:17:52'),
(2, 'admin_FAD', 'user', '$2y$10$Llz2RKEem6B01bpRXtfAD.xF.UQUaE.S9VfyVcvfXsJWgbOP8FFju', '2026-05-04 07:49:03'),
(4, 'admin', 'super_admin', '$2y$10$ZXxdygcLZzm2H9dYOtYFsOAnKAy1Vgixk6jvacWEWqgIvS2D.TmiC', '2026-05-14 05:58:29');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `answers`
--
ALTER TABLE `answers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `response_id` (`response_id`),
  ADD KEY `question_id` (`question_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `forms`
--
ALTER TABLE `forms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `form_code` (`form_code`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `idx_form_code` (`form_code`),
  ADD KEY `fk_forms_created_by` (`created_by`);

--
-- Indexes for table `questions`
--
ALTER TABLE `questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `form_id` (`form_id`),
  ADD KEY `fk_condition_question` (`condition_question_id`);

--
-- Indexes for table `question_options`
--
ALTER TABLE `question_options`
  ADD PRIMARY KEY (`id`),
  ADD KEY `question_id` (`question_id`);

--
-- Indexes for table `responses`
--
ALTER TABLE `responses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `form_id` (`form_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `answers`
--
ALTER TABLE `answers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=132;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `forms`
--
ALTER TABLE `forms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=76;

--
-- AUTO_INCREMENT for table `questions`
--
ALTER TABLE `questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=628;

--
-- AUTO_INCREMENT for table `question_options`
--
ALTER TABLE `question_options`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1227;

--
-- AUTO_INCREMENT for table `responses`
--
ALTER TABLE `responses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `answers`
--
ALTER TABLE `answers`
  ADD CONSTRAINT `answers_ibfk_1` FOREIGN KEY (`response_id`) REFERENCES `responses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `answers_ibfk_2` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `forms`
--
ALTER TABLE `forms`
  ADD CONSTRAINT `fk_forms_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `forms_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`);

--
-- Constraints for table `questions`
--
ALTER TABLE `questions`
  ADD CONSTRAINT `fk_condition_question` FOREIGN KEY (`condition_question_id`) REFERENCES `questions` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `questions_ibfk_1` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `question_options`
--
ALTER TABLE `question_options`
  ADD CONSTRAINT `question_options_ibfk_1` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `responses`
--
ALTER TABLE `responses`
  ADD CONSTRAINT `responses_ibfk_1` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
