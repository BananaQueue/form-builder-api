-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 16, 2026 at 03:25 AM
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
(17, 7, 60, 'Permanent'),
(18, 7, 61, ' Project Development Officer I'),
(20, 9, 92, 'Test 2,Test 3'),
(21, 9, 93, '1'),
(22, 9, 94, ''),
(23, 10, 57, 'DENR'),
(24, 10, 58, ''),
(25, 11, 57, 'EMB'),
(26, 11, 58, 'ORD'),
(27, 12, 305, 'fdsafsadf@com');

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
  `form_code` varchar(20) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `category_id` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `forms`
--

INSERT INTO `forms` (`id`, `form_code`, `title`, `description`, `created_at`, `category_id`) VALUES
(18, 'qH7KGoqD', 'Conditional Test', 'Testing Conditional Question', '2026-03-11 02:30:56', 1),
(21, 'H4COaKe3', 'EMPLOYMENT', 'Nkl;dsf;kog', '2026-03-12 02:00:57', 3),
(22, 'qZf5mbva', 'Rating Test', 'Testing rating type', '2026-03-12 05:31:39', 2),
(23, 'OTX63pE5', 'Test', 'Test Test', '2026-03-25 08:34:42', 1),
(43, 'mQBjJUtF', 'End of Learning Evaluation', '', '2026-03-31 00:03:45', 3),
(44, 'IyOYC1Bu', 'Learning Service Provider Evaluation Form', '', '2026-03-31 00:07:38', 3),
(52, 'wG0whlQS', 'Employee Details Form', 'Form to record basic employee data', '2026-03-31 03:54:23', 1),
(53, '7jr8k2E5', 'Email Test', 'Testing emial', '2026-04-07 08:23:03', 1);

-- --------------------------------------------------------

--
-- Table structure for table `questions`
--

CREATE TABLE `questions` (
  `id` int(11) NOT NULL,
  `form_id` int(11) NOT NULL,
  `question_text` text NOT NULL,
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

INSERT INTO `questions` (`id`, `form_id`, `question_text`, `question_type`, `rating_scale`, `number_min`, `number_max`, `number_step`, `datetime_type`, `position`, `is_required`, `condition_question_id`, `condition_type`, `condition_value`) VALUES
(57, 18, 'Bureau', 'checkbox', NULL, NULL, NULL, NULL, NULL, 0, 1, NULL, 'equals', NULL),
(58, 18, 'Division', 'text', NULL, NULL, NULL, NULL, NULL, 1, 1, 57, 'equals', 'EMB'),
(60, 21, 'What is your current employment status? ', 'checkbox', NULL, NULL, NULL, NULL, NULL, 0, 1, NULL, 'equals', NULL),
(61, 21, 'What is your Job Title?', 'multiple_choice', NULL, NULL, NULL, NULL, NULL, 1, 1, 60, 'equals', 'Permanent'),
(78, 22, 'How many percent', 'text', NULL, NULL, NULL, NULL, NULL, 0, 1, NULL, 'equals', NULL),
(79, 22, 'Do you agree?', 'rating', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, 'equals', NULL),
(80, 22, 'Are you lying?', 'text', NULL, NULL, NULL, NULL, NULL, 2, 1, 78, 'is_answered', NULL),
(92, 23, 'Test', 'multiple_choice', NULL, NULL, NULL, NULL, NULL, 0, 1, NULL, 'equals', NULL),
(93, 23, '2 Test', 'checkbox', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, 'equals', NULL),
(94, 23, 'Seret', 'text', NULL, NULL, NULL, NULL, NULL, 2, 1, 92, 'equals', 'Test 1'),
(116, 43, 'Learning and Development Intervention Title', 'text', NULL, NULL, NULL, NULL, NULL, 0, 1, NULL, 'equals', NULL),
(117, 43, 'Date/s (mm/dd/yyyy)', 'datetime', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, 'equals', NULL),
(118, 43, 'Learner\'s Name (First Name, Middle Name, Surname)', 'text', NULL, NULL, NULL, NULL, NULL, 2, 1, NULL, 'equals', NULL),
(119, 43, 'Learner\'s Position', 'text', NULL, NULL, NULL, NULL, NULL, 3, 1, NULL, 'equals', NULL),
(120, 43, 'Learner\'s Age', 'number', NULL, NULL, NULL, NULL, NULL, 4, 1, NULL, 'equals', NULL),
(121, 43, 'Email Address', 'text', NULL, NULL, NULL, NULL, NULL, 5, 1, NULL, 'equals', NULL),
(122, 43, 'Objective of the Event: The objectives were clearly communicated', 'rating', 'satisfaction_5', NULL, NULL, NULL, NULL, 6, 1, NULL, 'equals', NULL),
(123, 43, 'Objective of the Event: The objectives of the L&D intervention were attained', 'rating', 'satisfaction_5', NULL, NULL, NULL, NULL, 7, 1, NULL, 'equals', NULL),
(124, 43, 'Topics: The Sequence of topics is logical and faciliated easier understanding', 'rating', 'satisfaction_5', NULL, NULL, NULL, NULL, 8, 1, NULL, 'equals', NULL),
(125, 43, 'Topics: The intervention was comprehensive and provided my needed knwoledge', 'rating', 'satisfaction_5', NULL, NULL, NULL, NULL, 9, 1, NULL, 'equals', NULL),
(126, 43, 'Topics: The intervention is relevant to my job', 'rating', 'agree_5', NULL, NULL, NULL, NULL, 10, 1, NULL, 'equals', NULL),
(127, 43, 'Time Schedule: The time alloted for each session/section was sufficient', 'rating', 'agree_5', NULL, NULL, NULL, NULL, 11, 1, NULL, 'equals', NULL),
(128, 43, 'Time Schedule: The time alloted for the training was sufficient ', 'rating', 'agree_5', NULL, NULL, NULL, NULL, 12, 1, NULL, 'equals', NULL),
(129, 43, 'Methodology: The methodologies used were appropriate ', 'rating', 'agree_5', NULL, NULL, NULL, NULL, 13, 1, NULL, 'equals', NULL),
(130, 43, 'Learning Event Team: The learning event team was attentive to the basic needs of learners', 'rating', 'agree_5', NULL, NULL, NULL, NULL, 14, 1, NULL, 'equals', NULL),
(131, 43, 'Learning Event Team: The learning event team was organized and well-prepared', 'rating', 'agree_5', NULL, NULL, NULL, NULL, 15, 1, NULL, 'equals', NULL),
(132, 43, 'How was your overall experience with the training?', 'rating', 'quality_5', NULL, NULL, NULL, NULL, 16, 1, NULL, 'equals', NULL),
(133, 43, 'Overall, what did you gain from this learning?', 'text', NULL, NULL, NULL, NULL, NULL, 17, 1, NULL, 'equals', NULL),
(134, 43, 'What part of the Learning Event do you think was least helpful? Why?', 'text', NULL, NULL, NULL, NULL, NULL, 18, 1, NULL, 'equals', NULL),
(135, 43, 'Your comments or suggestions about this Learning Event.', 'text', NULL, NULL, NULL, NULL, NULL, 19, 1, NULL, 'equals', NULL),
(244, 44, 'Learning and Developement Intervention Title', 'text', NULL, NULL, NULL, '1', 'date', 0, 1, NULL, 'equals', NULL),
(245, 44, 'Date/s (mm/dd/yyyy)', 'datetime', NULL, NULL, NULL, '1', 'date', 1, 1, NULL, 'equals', NULL),
(246, 44, 'Learner\'s Name (First Name, Middle Name, Surname)', 'text', NULL, NULL, NULL, '1', 'date', 2, 1, NULL, 'equals', NULL),
(247, 44, 'Learner\'s Position', 'text', NULL, NULL, NULL, '1', 'date', 3, 1, NULL, 'equals', NULL),
(248, 44, 'Learner\'s Age', 'number', NULL, 0.00, NULL, '1', NULL, 4, 1, NULL, 'equals', NULL),
(249, 44, 'Years in the Department regarless of employement status/nature', 'number', NULL, NULL, NULL, '1', 'date', 5, 1, NULL, 'equals', NULL),
(250, 44, 'Email Address', 'text', NULL, NULL, NULL, '1', 'date', 6, 1, NULL, 'equals', NULL),
(251, 44, 'Content: The objectives of the event were clearly defined and communicated', 'rating', 'agree_5', NULL, NULL, '1', 'date', 7, 1, NULL, 'equals', NULL),
(252, 44, 'Content: The objectives of the event were attained', 'rating', 'agree_5', NULL, NULL, '1', 'date', 8, 1, NULL, 'equals', NULL),
(253, 44, 'Content: The time allotted for the training was sufficient', 'rating', 'agree_5', NULL, NULL, '1', 'date', 9, 1, NULL, 'equals', NULL),
(254, 44, 'Content: The training has a good mix of theories and applications', 'rating', 'agree_5', NULL, NULL, '1', 'date', 10, 1, NULL, 'equals', NULL),
(255, 44, 'Participation and interaction was encouraged', 'rating', 'agree_5', NULL, NULL, '1', 'date', 11, 1, NULL, 'equals', NULL),
(256, 44, 'The electronic media used in the discussion assisted my learning and understanding of the topic', 'rating', 'agree_5', NULL, NULL, '1', 'date', 12, 1, NULL, 'equals', NULL),
(257, 44, 'Relevant examples were provided for in-depth discussion', 'rating', 'agree_5', NULL, NULL, '1', 'date', 13, 1, NULL, 'equals', NULL),
(258, 44, 'The training team was attentice to the needs of the learners', 'rating', 'agree_5', NULL, NULL, '1', 'date', 14, 1, NULL, 'equals', NULL),
(259, 44, 'The trainers/lecturers were effective in presenting the topics', 'rating', 'agree_5', NULL, NULL, '1', 'date', 15, 1, NULL, 'equals', NULL),
(260, 44, 'Overall, this is a helpful course that should be taken by other EMB-1 personnel', 'rating', 'agree_5', NULL, NULL, '1', 'date', 16, 1, NULL, 'equals', NULL),
(261, 44, 'Do you recommend the training provider to conduct the same or other related trainings to EMB-1? Kindly expound your answer.', 'text', NULL, NULL, NULL, '1', 'date', 17, 1, NULL, 'equals', NULL),
(262, 44, 'Apart from this Learning Service Provider, do you know other organizations/institutions that conduct similar or related learning interventions?', 'checkbox', NULL, NULL, NULL, '1', 'date', 18, 1, NULL, 'equals', NULL),
(263, 44, 'Do you have any recommendations/comments to improve the performance of the training provider?', 'text', NULL, NULL, NULL, '1', 'date', 19, 1, NULL, 'equals', NULL),
(264, 44, 'Overall, how was the performance of the Learning Service Provider?', 'rating', 'quality_5', NULL, NULL, '1', 'date', 20, 1, NULL, 'equals', NULL),
(293, 52, 'Full Name (First name, Middle name, Surname)', 'text', NULL, NULL, NULL, '1', 'date', 0, 1, NULL, 'equals', NULL),
(294, 52, 'Department or Bureau', 'checkbox', NULL, NULL, NULL, '1', 'date', 1, 1, NULL, 'equals', NULL),
(295, 52, 'Division', 'checkbox', NULL, NULL, NULL, '1', 'date', 2, 1, 294, 'equals', 'Environmental Management Bureau'),
(296, 52, 'Unit/Section', 'checkbox', NULL, NULL, NULL, '1', 'date', 3, 1, 295, 'equals', 'EMED'),
(297, 52, 'Unit/Section', 'checkbox', NULL, NULL, NULL, '1', 'date', 4, 1, 295, 'equals', 'CPD'),
(298, 52, 'Unit/Section', 'checkbox', NULL, NULL, NULL, '1', 'date', 5, 1, 295, 'equals', 'FAD'),
(299, 52, 'Unit/Section', 'checkbox', NULL, NULL, NULL, '1', 'date', 6, 1, 295, 'equals', 'ORD'),
(300, 52, 'Position', 'text', NULL, NULL, NULL, '1', 'date', 7, 1, NULL, 'equals', NULL),
(301, 52, 'Address', 'text', NULL, NULL, NULL, '1', 'date', 8, 1, NULL, 'equals', NULL),
(302, 52, 'Contact No', 'text', NULL, NULL, NULL, '1', 'date', 9, 1, NULL, 'equals', NULL),
(303, 52, 'Birthdate (mm/dd/yyyy)', 'datetime', NULL, NULL, NULL, '1', 'date', 10, 1, NULL, 'equals', NULL),
(304, 52, 'Email', 'text', NULL, NULL, NULL, '1', 'date', 11, 1, NULL, 'equals', NULL),
(305, 53, 'Email address', 'email', NULL, NULL, NULL, NULL, NULL, 0, 1, NULL, 'equals', NULL);

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
(130, 57, 'DENR', 0),
(131, 57, 'EMB', 1),
(135, 60, 'Permanent', 0),
(136, 60, 'Job Order', 1),
(137, 60, 'Contract of Service', 2),
(138, 61, 'Data Controller I', 0),
(139, 61, ' Data Controller II', 1),
(140, 61, ' Project Development Officer I', 2),
(141, 61, 'Planning Officer I', 3),
(142, 61, 'Planning Officer II', 4),
(193, 79, 'Strongly Disagree', 0),
(194, 79, 'Disagree', 1),
(195, 79, 'Neutral', 2),
(196, 79, 'Agree', 3),
(197, 79, 'Strongly Agree', 4),
(210, 92, 'Test 1', 0),
(211, 92, 'Test 2', 1),
(212, 92, 'Test 3', 2),
(213, 93, '1', 0),
(214, 93, '2', 1),
(215, 93, '3', 2),
(271, 122, 'Very Dissatisfied', 0),
(272, 122, 'Dissatisfied', 1),
(273, 122, 'Neutral', 2),
(274, 122, 'Satisfied', 3),
(275, 122, 'Very Satisfied', 4),
(276, 123, 'Very Dissatisfied', 0),
(277, 123, 'Dissatisfied', 1),
(278, 123, 'Neutral', 2),
(279, 123, 'Satisfied', 3),
(280, 123, 'Very Satisfied', 4),
(281, 124, 'Very Dissatisfied', 0),
(282, 124, 'Dissatisfied', 1),
(283, 124, 'Neutral', 2),
(284, 124, 'Satisfied', 3),
(285, 124, 'Very Satisfied', 4),
(286, 125, 'Very Dissatisfied', 0),
(287, 125, 'Dissatisfied', 1),
(288, 125, 'Neutral', 2),
(289, 125, 'Satisfied', 3),
(290, 125, 'Very Satisfied', 4),
(291, 126, 'Strongly Disagree', 0),
(292, 126, 'Disagree', 1),
(293, 126, 'Neutral', 2),
(294, 126, 'Agree', 3),
(295, 126, 'Strongly Agree', 4),
(296, 127, 'Strongly Disagree', 0),
(297, 127, 'Disagree', 1),
(298, 127, 'Neutral', 2),
(299, 127, 'Agree', 3),
(300, 127, 'Strongly Agree', 4),
(301, 128, 'Strongly Disagree', 0),
(302, 128, 'Disagree', 1),
(303, 128, 'Neutral', 2),
(304, 128, 'Agree', 3),
(305, 128, 'Strongly Agree', 4),
(306, 129, 'Strongly Disagree', 0),
(307, 129, 'Disagree', 1),
(308, 129, 'Neutral', 2),
(309, 129, 'Agree', 3),
(310, 129, 'Strongly Agree', 4),
(311, 130, 'Strongly Disagree', 0),
(312, 130, 'Disagree', 1),
(313, 130, 'Neutral', 2),
(314, 130, 'Agree', 3),
(315, 130, 'Strongly Agree', 4),
(316, 131, 'Strongly Disagree', 0),
(317, 131, 'Disagree', 1),
(318, 131, 'Neutral', 2),
(319, 131, 'Agree', 3),
(320, 131, 'Strongly Agree', 4),
(321, 132, 'Poor', 0),
(322, 132, 'Fair', 1),
(323, 132, 'Good', 2),
(324, 132, 'Very Good', 3),
(325, 132, 'Excellent', 4),
(611, 251, 'Strongly Disagree', 0),
(612, 251, 'Disagree', 1),
(613, 251, 'Neutral', 2),
(614, 251, 'Agree', 3),
(615, 251, 'Strongly Agree', 4),
(616, 252, 'Strongly Disagree', 0),
(617, 252, 'Disagree', 1),
(618, 252, 'Neutral', 2),
(619, 252, 'Agree', 3),
(620, 252, 'Strongly Agree', 4),
(621, 253, 'Strongly Disagree', 0),
(622, 253, 'Disagree', 1),
(623, 253, 'Neutral', 2),
(624, 253, 'Agree', 3),
(625, 253, 'Strongly Agree', 4),
(626, 254, 'Strongly Disagree', 0),
(627, 254, 'Disagree', 1),
(628, 254, 'Neutral', 2),
(629, 254, 'Agree', 3),
(630, 254, 'Strongly Agree', 4),
(631, 255, 'Strongly Disagree', 0),
(632, 255, 'Disagree', 1),
(633, 255, 'Neutral', 2),
(634, 255, 'Agree', 3),
(635, 255, 'Strongly Agree', 4),
(636, 256, 'Strongly Disagree', 0),
(637, 256, 'Disagree', 1),
(638, 256, 'Neutral', 2),
(639, 256, 'Agree', 3),
(640, 256, 'Strongly Agree', 4),
(641, 257, 'Strongly Disagree', 0),
(642, 257, 'Disagree', 1),
(643, 257, 'Neutral', 2),
(644, 257, 'Agree', 3),
(645, 257, 'Strongly Agree', 4),
(646, 258, 'Strongly Disagree', 0),
(647, 258, 'Disagree', 1),
(648, 258, 'Neutral', 2),
(649, 258, 'Agree', 3),
(650, 258, 'Strongly Agree', 4),
(651, 259, 'Strongly Disagree', 0),
(652, 259, 'Disagree', 1),
(653, 259, 'Neutral', 2),
(654, 259, 'Agree', 3),
(655, 259, 'Strongly Agree', 4),
(656, 260, 'Strongly Disagree', 0),
(657, 260, 'Disagree', 1),
(658, 260, 'Neutral', 2),
(659, 260, 'Agree', 3),
(660, 260, 'Strongly Agree', 4),
(661, 262, 'Yes', 0),
(662, 262, 'No', 1),
(663, 264, 'Poor', 0),
(664, 264, 'Fair', 1),
(665, 264, 'Good', 2),
(666, 264, 'Very Good', 3),
(667, 264, 'Excellent', 4),
(692, 294, 'Department of Natural Resources', 0),
(693, 294, 'Environmental Management Bureau', 1),
(694, 295, 'EMED', 0),
(695, 295, 'CPD', 1),
(696, 295, 'FAD', 2),
(697, 295, 'ORD', 3),
(698, 296, 'AWMS', 0),
(699, 296, 'CHWMS', 1),
(700, 296, 'AMTSS', 2),
(701, 296, 'ESWMS', 3),
(702, 297, 'EIAMS', 0),
(703, 297, 'AWPS', 1),
(704, 297, 'CHWPS', 2),
(705, 298, 'Accounting Unit', 0),
(706, 298, 'Budget Unit', 1),
(707, 298, 'Property/GSS', 2),
(708, 298, 'Cashier Unit', 3),
(709, 298, 'Records Unit', 4),
(710, 298, 'HRMD Unit', 5),
(711, 299, 'PISMU/MIS', 0),
(712, 299, 'REL', 1),
(713, 299, 'EEIU', 2),
(714, 299, 'Legal Unit', 3),
(715, 299, 'Climate Change Unit', 4);

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
(7, 21, '2026-03-12 02:10:01'),
(8, 22, '2026-03-12 05:32:18'),
(9, 23, '2026-03-30 00:00:07'),
(10, 18, '2026-03-30 00:00:36'),
(11, 18, '2026-03-30 00:00:48'),
(12, 53, '2026-04-07 08:23:21');

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
  ADD KEY `idx_form_code` (`form_code`);

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
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `answers`
--
ALTER TABLE `answers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `forms`
--
ALTER TABLE `forms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- AUTO_INCREMENT for table `questions`
--
ALTER TABLE `questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=306;

--
-- AUTO_INCREMENT for table `question_options`
--
ALTER TABLE `question_options`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=716;

--
-- AUTO_INCREMENT for table `responses`
--
ALTER TABLE `responses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

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
