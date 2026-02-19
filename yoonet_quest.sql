-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 18, 2026 at 12:53 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

CREATE DATABASE yoonet_quest;

-- --------------------------------------------------------

--
-- Table structure for table `achievements`
--

CREATE TABLE `achievements` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `icon` varchar(100) DEFAULT NULL,
  `badge_color` varchar(7) DEFAULT '#FFD700',
  `xp_reward` int(11) DEFAULT 0,
  `criteria_type` enum('quest_count','xp_total','skill_mastery','submission_streak','custom') NOT NULL,
  `criteria_value` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `employee_id` varchar(50) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `calls`
--

CREATE TABLE `calls` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `qa_score` float NOT NULL,
  `call_time` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `call_logs`
--

CREATE TABLE `call_logs` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `log_date` date NOT NULL,
  `log_text` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `comprehensive_skills`
--

CREATE TABLE `comprehensive_skills` (
  `id` int(11) NOT NULL,
  `skill_name` varchar(100) NOT NULL,
  `category_id` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `tier_1_points` int(11) DEFAULT 25,
  `tier_2_points` int(11) DEFAULT 40,
  `tier_3_points` int(11) DEFAULT 60,
  `tier_4_points` int(11) DEFAULT 85,
  `tier_5_points` int(11) DEFAULT 115,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `comprehensive_skills`
--

INSERT INTO `comprehensive_skills` (`id`, `skill_name`, `category_id`, `description`, `tier_1_points`, `tier_2_points`, `tier_3_points`, `tier_4_points`, `tier_5_points`, `created_at`) VALUES
(201, 'Python Programming', 1, 'Proficiency in Python programming language', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(202, 'JavaScript', 1, 'Proficiency in JavaScript programming', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(203, 'Java Development', 1, 'Proficiency in Java programming', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(204, 'C# .NET', 1, 'Proficiency in C# and .NET framework', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(205, 'PHP Development', 1, 'Proficiency in PHP web development', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(206, 'React.js', 1, 'Frontend development with React', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(207, 'Angular', 1, 'Frontend development with Angular', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(208, 'Vue.js', 1, 'Frontend development with Vue', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(209, 'Node.js', 1, 'Backend development with Node.js', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(210, 'SQL & Database', 1, 'Database design and SQL queries', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(211, 'MongoDB', 1, 'NoSQL database with MongoDB', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(212, 'Git Version Control', 1, 'Version control with Git', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(213, 'Docker', 1, 'Containerization with Docker', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(214, 'Kubernetes', 1, 'Container orchestration', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(215, 'AWS Cloud', 1, 'Amazon Web Services', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(216, 'Azure Cloud', 1, 'Microsoft Azure platform', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(217, 'CI/CD Pipelines', 1, 'Continuous Integration/Deployment', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(218, 'REST API Design', 1, 'RESTful API development', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(219, 'GraphQL', 1, 'GraphQL API development', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(220, 'Microservices', 1, 'Microservices architecture', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(221, 'Public Speaking', 2, 'Effective public speaking skills', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(222, 'Written Communication', 2, 'Clear and professional writing', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(223, 'Active Listening', 2, 'Attentive and empathetic listening', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(224, 'Presentation Skills', 2, 'Creating and delivering presentations', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(225, 'Email Etiquette', 2, 'Professional email communication', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(226, 'Meeting Facilitation', 2, 'Leading effective meetings', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(227, 'Negotiation', 2, 'Negotiation and persuasion skills', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(228, 'Conflict Resolution', 2, 'Resolving workplace conflicts', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(229, 'Cross-Cultural Communication', 2, 'Communicating across cultures', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(230, 'Technical Writing', 2, 'Writing technical documentation', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(231, 'Stakeholder Management', 2, 'Managing stakeholder relationships', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(232, 'Client Relations', 2, 'Building client relationships', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(233, 'Team Communication', 2, 'Effective team communication', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(234, 'Feedback Delivery', 2, 'Constructive feedback skills', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(235, 'Storytelling', 2, 'Narrative and storytelling abilities', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(236, 'Leadership', 3, 'Leading and inspiring teams', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(237, 'Teamwork', 3, 'Collaborative team participation', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(238, 'Problem Solving', 3, 'Analytical problem-solving skills', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(239, 'Critical Thinking', 3, 'Logical and analytical thinking', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(240, 'Time Management', 3, 'Effective time management', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(241, 'Adaptability', 3, 'Adapting to change', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(242, 'Emotional Intelligence', 3, 'Understanding and managing emotions', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(243, 'Decision Making', 3, 'Making informed decisions', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(244, 'Creativity', 3, 'Creative thinking and innovation', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(245, 'Attention to Detail', 3, 'Precision and accuracy', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(246, 'Work Ethic', 3, 'Professional work ethic', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(247, 'Self-Motivation', 3, 'Internal drive and initiative', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(248, 'Stress Management', 3, 'Managing workplace stress', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(249, 'Mentoring', 3, 'Guiding and developing others', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(250, 'Empathy', 3, 'Understanding others perspectives', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(251, 'Project Management', 4, 'Planning and managing projects', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(252, 'Agile Methodologies', 4, 'Agile and Scrum practices', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(253, 'Strategic Planning', 4, 'Long-term strategic thinking', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(254, 'Budget Management', 4, 'Financial planning and budgeting', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(255, 'Business Analysis', 4, 'Analyzing business processes', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(256, 'Risk Management', 4, 'Identifying and mitigating risks', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(257, 'Sales & Marketing', 4, 'Sales and marketing strategies', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(258, 'Customer Service', 4, 'Excellent customer service', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(259, 'Vendor Management', 4, 'Managing vendor relationships', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(260, 'Quality Assurance', 4, 'Quality control and testing', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(261, 'Change Management', 4, 'Managing organizational change', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(262, 'Process Improvement', 4, 'Optimizing business processes', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(263, 'Financial Analysis', 4, 'Financial data analysis', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(264, 'Compliance & Governance', 4, 'Regulatory compliance', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(265, 'Performance Metrics', 4, 'KPI tracking and reporting', 25, 40, 60, 85, 115, '2025-10-09 04:35:30'),
(266, 'Anything', 3, 'Custom skill: Anything', 5, 10, 15, 20, 25, '2025-10-09 08:14:16'),
(267, 'Money Making', 4, 'Custom skill: Money Making', 5, 10, 15, 20, 25, '2025-10-09 18:07:19'),
(268, 'Eyut', 3, 'Custom skill: Eyut', 5, 10, 15, 20, 25, '2025-10-14 19:51:46'),
(269, 'Presentation Custom Skill', 4, 'Custom skill: Presentation Custom Skill', 5, 10, 15, 20, 25, '2025-10-16 00:13:49'),
(270, 'Custom Skills', 4, 'Custom skill: Custom Skills', 5, 10, 15, 20, 25, '2025-10-16 00:19:41'),
(271, 'Python Programming', 1, 'Proficiency in Python programming language', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(272, 'JavaScript', 1, 'Proficiency in JavaScript programming', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(273, 'Java Development', 1, 'Proficiency in Java programming', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(274, 'C# .NET', 1, 'Proficiency in C# and .NET framework', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(275, 'PHP Development', 1, 'Proficiency in PHP web development', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(276, 'React.js', 1, 'Frontend development with React', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(277, 'Angular', 1, 'Frontend development with Angular', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(278, 'Vue.js', 1, 'Frontend development with Vue', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(279, 'Node.js', 1, 'Backend development with Node.js', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(280, 'SQL & Database', 1, 'Database design and SQL queries', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(281, 'MongoDB', 1, 'NoSQL database with MongoDB', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(282, 'Git Version Control', 1, 'Version control with Git', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(283, 'Docker', 1, 'Containerization with Docker', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(284, 'Kubernetes', 1, 'Container orchestration', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(285, 'AWS Cloud', 1, 'Amazon Web Services', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(286, 'Azure Cloud', 1, 'Microsoft Azure platform', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(287, 'CI/CD Pipelines', 1, 'Continuous Integration/Deployment', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(288, 'REST API Design', 1, 'RESTful API development', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(289, 'GraphQL', 1, 'GraphQL API development', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(290, 'Microservices', 1, 'Microservices architecture', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(291, 'Public Speaking', 2, 'Effective public speaking skills', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(292, 'Written Communication', 2, 'Clear and professional writing', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(293, 'Active Listening', 2, 'Attentive and empathetic listening', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(294, 'Presentation Skills', 2, 'Creating and delivering presentations', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(295, 'Email Etiquette', 2, 'Professional email communication', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(296, 'Meeting Facilitation', 2, 'Leading effective meetings', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(297, 'Negotiation', 2, 'Negotiation and persuasion skills', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(298, 'Conflict Resolution', 2, 'Resolving workplace conflicts', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(299, 'Cross-Cultural Communication', 2, 'Communicating across cultures', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(300, 'Technical Writing', 2, 'Writing technical documentation', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(301, 'Stakeholder Management', 2, 'Managing stakeholder relationships', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(302, 'Client Relations', 2, 'Building client relationships', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(303, 'Team Communication', 2, 'Effective team communication', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(304, 'Feedback Delivery', 2, 'Constructive feedback skills', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(305, 'Storytelling', 2, 'Narrative and storytelling abilities', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(306, 'Leadership', 3, 'Leading and inspiring teams', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(307, 'Teamwork', 3, 'Collaborative team participation', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(308, 'Problem Solving', 3, 'Analytical problem-solving skills', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(309, 'Critical Thinking', 3, 'Logical and analytical thinking', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(310, 'Time Management', 3, 'Effective time management', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(311, 'Adaptability', 3, 'Adapting to change', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(312, 'Emotional Intelligence', 3, 'Understanding and managing emotions', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(313, 'Decision Making', 3, 'Making informed decisions', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(314, 'Creativity', 3, 'Creative thinking and innovation', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(315, 'Attention to Detail', 3, 'Precision and accuracy', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(316, 'Work Ethic', 3, 'Professional work ethic', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(317, 'Self-Motivation', 3, 'Internal drive and initiative', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(318, 'Stress Management', 3, 'Managing workplace stress', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(319, 'Mentoring', 3, 'Guiding and developing others', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(320, 'Empathy', 3, 'Understanding others perspectives', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(321, 'Project Management', 4, 'Planning and managing projects', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(322, 'Agile Methodologies', 4, 'Agile and Scrum practices', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(323, 'Strategic Planning', 4, 'Long-term strategic thinking', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(324, 'Budget Management', 4, 'Financial planning and budgeting', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(325, 'Business Analysis', 4, 'Analyzing business processes', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(326, 'Risk Management', 4, 'Identifying and mitigating risks', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(327, 'Sales & Marketing', 4, 'Sales and marketing strategies', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(328, 'Customer Service', 4, 'Excellent customer service', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(329, 'Vendor Management', 4, 'Managing vendor relationships', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(330, 'Quality Assurance', 4, 'Quality control and testing', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(331, 'Change Management', 4, 'Managing organizational change', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(332, 'Process Improvement', 4, 'Optimizing business processes', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(333, 'Financial Analysis', 4, 'Financial data analysis', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(334, 'Compliance & Governance', 4, 'Regulatory compliance', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(335, 'Performance Metrics', 4, 'KPI tracking and reporting', 25, 40, 60, 85, 115, '2025-10-25 05:12:28'),
(336, 'Wews', 4, 'Custom skill: Wews', 5, 10, 15, 20, 25, '2025-10-25 06:18:58'),
(337, 'Python Programming', 1, 'Proficiency in Python programming language', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(338, 'JavaScript', 1, 'Proficiency in JavaScript programming', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(339, 'Java Development', 1, 'Proficiency in Java programming', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(340, 'C# .NET', 1, 'Proficiency in C# and .NET framework', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(341, 'PHP Development', 1, 'Proficiency in PHP web development', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(342, 'React.js', 1, 'Frontend development with React', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(343, 'Angular', 1, 'Frontend development with Angular', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(344, 'Vue.js', 1, 'Frontend development with Vue', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(345, 'Node.js', 1, 'Backend development with Node.js', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(346, 'SQL & Database', 1, 'Database design and SQL queries', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(347, 'MongoDB', 1, 'NoSQL database with MongoDB', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(348, 'Git Version Control', 1, 'Version control with Git', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(349, 'Docker', 1, 'Containerization with Docker', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(350, 'Kubernetes', 1, 'Container orchestration', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(351, 'AWS Cloud', 1, 'Amazon Web Services', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(352, 'Azure Cloud', 1, 'Microsoft Azure platform', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(353, 'CI/CD Pipelines', 1, 'Continuous Integration/Deployment', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(354, 'REST API Design', 1, 'RESTful API development', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(355, 'GraphQL', 1, 'GraphQL API development', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(356, 'Microservices', 1, 'Microservices architecture', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(357, 'Public Speaking', 2, 'Effective public speaking skills', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(358, 'Written Communication', 2, 'Clear and professional writing', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(359, 'Active Listening', 2, 'Attentive and empathetic listening', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(360, 'Presentation Skills', 2, 'Creating and delivering presentations', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(361, 'Email Etiquette', 2, 'Professional email communication', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(362, 'Meeting Facilitation', 2, 'Leading effective meetings', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(363, 'Negotiation', 2, 'Negotiation and persuasion skills', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(364, 'Conflict Resolution', 2, 'Resolving workplace conflicts', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(365, 'Cross-Cultural Communication', 2, 'Communicating across cultures', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(366, 'Technical Writing', 2, 'Writing technical documentation', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(367, 'Stakeholder Management', 2, 'Managing stakeholder relationships', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(368, 'Client Relations', 2, 'Building client relationships', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(369, 'Team Communication', 2, 'Effective team communication', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(370, 'Feedback Delivery', 2, 'Constructive feedback skills', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(371, 'Storytelling', 2, 'Narrative and storytelling abilities', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(372, 'Leadership', 3, 'Leading and inspiring teams', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(373, 'Teamwork', 3, 'Collaborative team participation', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(374, 'Problem Solving', 3, 'Analytical problem-solving skills', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(375, 'Critical Thinking', 3, 'Logical and analytical thinking', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(376, 'Time Management', 3, 'Effective time management', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(377, 'Adaptability', 3, 'Adapting to change', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(378, 'Emotional Intelligence', 3, 'Understanding and managing emotions', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(379, 'Decision Making', 3, 'Making informed decisions', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(380, 'Creativity', 3, 'Creative thinking and innovation', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(381, 'Attention to Detail', 3, 'Precision and accuracy', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(382, 'Work Ethic', 3, 'Professional work ethic', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(383, 'Self-Motivation', 3, 'Internal drive and initiative', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(384, 'Stress Management', 3, 'Managing workplace stress', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(385, 'Mentoring', 3, 'Guiding and developing others', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(386, 'Empathy', 3, 'Understanding others perspectives', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(387, 'Project Management', 4, 'Planning and managing projects', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(388, 'Agile Methodologies', 4, 'Agile and Scrum practices', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(389, 'Strategic Planning', 4, 'Long-term strategic thinking', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(390, 'Budget Management', 4, 'Financial planning and budgeting', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(391, 'Business Analysis', 4, 'Analyzing business processes', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(392, 'Risk Management', 4, 'Identifying and mitigating risks', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(393, 'Sales & Marketing', 4, 'Sales and marketing strategies', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(394, 'Customer Service', 4, 'Excellent customer service', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(395, 'Vendor Management', 4, 'Managing vendor relationships', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(396, 'Quality Assurance', 4, 'Quality control and testing', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(397, 'Change Management', 4, 'Managing organizational change', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(398, 'Process Improvement', 4, 'Optimizing business processes', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(399, 'Financial Analysis', 4, 'Financial data analysis', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(400, 'Compliance & Governance', 4, 'Regulatory compliance', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(401, 'Performance Metrics', 4, 'KPI tracking and reporting', 25, 40, 60, 85, 115, '2025-11-02 14:34:18'),
(402, 'Python Programming', 1, 'Proficiency in Python programming language', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(403, 'JavaScript', 1, 'Proficiency in JavaScript programming', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(404, 'Java Development', 1, 'Proficiency in Java programming', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(405, 'C# .NET', 1, 'Proficiency in C# and .NET framework', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(406, 'PHP Development', 1, 'Proficiency in PHP web development', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(407, 'React.js', 1, 'Frontend development with React', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(408, 'Angular', 1, 'Frontend development with Angular', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(409, 'Vue.js', 1, 'Frontend development with Vue', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(410, 'Node.js', 1, 'Backend development with Node.js', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(411, 'SQL & Database', 1, 'Database design and SQL queries', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(412, 'MongoDB', 1, 'NoSQL database with MongoDB', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(413, 'Git Version Control', 1, 'Version control with Git', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(414, 'Docker', 1, 'Containerization with Docker', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(415, 'Kubernetes', 1, 'Container orchestration', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(416, 'AWS Cloud', 1, 'Amazon Web Services', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(417, 'Azure Cloud', 1, 'Microsoft Azure platform', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(418, 'CI/CD Pipelines', 1, 'Continuous Integration/Deployment', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(419, 'REST API Design', 1, 'RESTful API development', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(420, 'GraphQL', 1, 'GraphQL API development', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(421, 'Microservices', 1, 'Microservices architecture', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(422, 'Public Speaking', 2, 'Effective public speaking skills', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(423, 'Written Communication', 2, 'Clear and professional writing', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(424, 'Active Listening', 2, 'Attentive and empathetic listening', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(425, 'Presentation Skills', 2, 'Creating and delivering presentations', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(426, 'Email Etiquette', 2, 'Professional email communication', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(427, 'Meeting Facilitation', 2, 'Leading effective meetings', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(428, 'Negotiation', 2, 'Negotiation and persuasion skills', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(429, 'Conflict Resolution', 2, 'Resolving workplace conflicts', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(430, 'Cross-Cultural Communication', 2, 'Communicating across cultures', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(431, 'Technical Writing', 2, 'Writing technical documentation', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(432, 'Stakeholder Management', 2, 'Managing stakeholder relationships', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(433, 'Client Relations', 2, 'Building client relationships', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(434, 'Team Communication', 2, 'Effective team communication', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(435, 'Feedback Delivery', 2, 'Constructive feedback skills', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(436, 'Storytelling', 2, 'Narrative and storytelling abilities', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(437, 'Leadership', 3, 'Leading and inspiring teams', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(438, 'Teamwork', 3, 'Collaborative team participation', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(439, 'Problem Solving', 3, 'Analytical problem-solving skills', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(440, 'Critical Thinking', 3, 'Logical and analytical thinking', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(441, 'Time Management', 3, 'Effective time management', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(442, 'Adaptability', 3, 'Adapting to change', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(443, 'Emotional Intelligence', 3, 'Understanding and managing emotions', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(444, 'Decision Making', 3, 'Making informed decisions', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(445, 'Creativity', 3, 'Creative thinking and innovation', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(446, 'Attention to Detail', 3, 'Precision and accuracy', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(447, 'Work Ethic', 3, 'Professional work ethic', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(448, 'Self-Motivation', 3, 'Internal drive and initiative', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(449, 'Stress Management', 3, 'Managing workplace stress', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(450, 'Mentoring', 3, 'Guiding and developing others', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(451, 'Empathy', 3, 'Understanding others perspectives', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(452, 'Project Management', 4, 'Planning and managing projects', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(453, 'Agile Methodologies', 4, 'Agile and Scrum practices', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(454, 'Strategic Planning', 4, 'Long-term strategic thinking', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(455, 'Budget Management', 4, 'Financial planning and budgeting', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(456, 'Business Analysis', 4, 'Analyzing business processes', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(457, 'Risk Management', 4, 'Identifying and mitigating risks', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(458, 'Sales & Marketing', 4, 'Sales and marketing strategies', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(459, 'Customer Service', 4, 'Excellent customer service', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(460, 'Vendor Management', 4, 'Managing vendor relationships', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(461, 'Quality Assurance', 4, 'Quality control and testing', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(462, 'Change Management', 4, 'Managing organizational change', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(463, 'Process Improvement', 4, 'Optimizing business processes', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(464, 'Financial Analysis', 4, 'Financial data analysis', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(465, 'Compliance & Governance', 4, 'Regulatory compliance', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(466, 'Performance Metrics', 4, 'KPI tracking and reporting', 25, 40, 60, 85, 115, '2025-11-02 14:43:30'),
(476, 'Client Communication', 8, 'Auto skill for Client & Support Operations: Client Communication', 5, 10, 15, 20, 25, '2025-11-07 10:29:15'),
(477, 'Ticket Management', 8, 'Auto skill for Client & Support Operations: Ticket Management', 5, 10, 15, 20, 25, '2025-11-07 10:29:15'),
(478, 'Incident Diagnosis', 8, 'Auto skill for Client & Support Operations: Incident Diagnosis', 5, 10, 15, 20, 25, '2025-11-07 10:29:15'),
(479, 'Call Handling', 9, 'Auto skill for Client Call: Call Handling', 5, 10, 15, 20, 25, '2026-02-16 13:31:24'),
(480, 'Customer Empathy', 9, 'Auto skill for Client Call: Customer Empathy', 5, 10, 15, 20, 25, '2026-02-16 13:31:24'),
(481, 'Basic Troubleshooting', 9, 'Auto skill for Client Call: Basic Troubleshooting', 5, 10, 15, 20, 25, '2026-02-16 13:31:24'),
(482, 'Communication', 9, 'Auto skill for Client Call: Communication', 5, 10, 15, 20, 25, '2026-02-18 11:27:23'),
(483, 'Attention to Detail', 9, 'Auto skill for Client Call: Attention to Detail', 5, 10, 15, 20, 25, '2026-02-18 11:27:23'),
(484, 'Tech Proficiency', 9, 'Auto skill for Client Call: Tech Proficiency', 5, 10, 15, 20, 25, '2026-02-18 11:27:23'),
(485, 'Empathy', 9, 'Auto skill for Client Call: Empathy', 5, 10, 15, 20, 25, '2026-02-18 11:27:23'),
(486, 'Teamwork', 9, 'Auto skill for Client Call: Teamwork', 5, 10, 15, 20, 25, '2026-02-18 11:27:23');

-- --------------------------------------------------------

--
-- Table structure for table `employee_groups`
--

CREATE TABLE `employee_groups` (
  `id` int(11) NOT NULL,
  `group_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_by` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `event_attendance`
--

CREATE TABLE `event_attendance` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `event_type` varchar(50) NOT NULL,
  `attended_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `group_members`
--

CREATE TABLE `group_members` (
  `id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patient_records`
--

CREATE TABLE `patient_records` (
  `id` int(11) NOT NULL,
  `updated_by` varchar(50) NOT NULL,
  `approved_by` varchar(50) DEFAULT NULL,
  `update_time` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quests`
--

CREATE TABLE `quests` (
  `id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `status` enum('active','inactive','completed','archived','draft','deleted') NOT NULL DEFAULT 'active',
  `due_date` datetime DEFAULT NULL,
  `created_by` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `quest_assignment_type` enum('mandatory','optional') DEFAULT 'optional',
  `quest_type` enum('single','recurring') DEFAULT 'single',
  `visibility` enum('public','private') DEFAULT 'public',
  `recurrence_pattern` varchar(50) DEFAULT NULL,
  `recurrence_end_date` datetime DEFAULT NULL,
  `publish_at` datetime DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `max_attempts` int(11) DEFAULT 1,
  `support_client_name` varchar(255) DEFAULT NULL,
  `support_ticket_id` varchar(100) DEFAULT NULL,
  `support_priority` enum('low','medium','high','critical') DEFAULT NULL,
  `support_sla_hours` int(11) DEFAULT NULL,
  `support_channel` varchar(100) DEFAULT NULL,
  `support_contact` varchar(255) DEFAULT NULL,
  `display_type` varchar(50) DEFAULT 'custom',
  `client_name` varchar(255) DEFAULT NULL,
  `client_reference` varchar(255) DEFAULT NULL,
  `sla_priority` enum('low','medium','high') DEFAULT 'medium',
  `expected_response` varchar(100) DEFAULT NULL,
  `client_contact_email` varchar(255) DEFAULT NULL,
  `client_contact_phone` varchar(50) DEFAULT NULL,
  `sla_due_hours` int(11) DEFAULT NULL,
  `external_ticket_link` varchar(500) DEFAULT NULL,
  `service_level_description` text DEFAULT NULL,
  `vendor_name` varchar(255) DEFAULT NULL,
  `estimated_hours` decimal(6,2) DEFAULT NULL,
  `aggregation_date` date DEFAULT NULL,
  `aggregation_shift` varchar(20) DEFAULT NULL,
  `call_log_path` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quests`
--

INSERT INTO `quests` (`id`, `title`, `description`, `status`, `due_date`, `created_by`, `created_at`, `updated_at`, `quest_assignment_type`, `quest_type`, `visibility`, `recurrence_pattern`, `recurrence_end_date`, `publish_at`, `category_id`, `max_attempts`, `support_client_name`, `support_ticket_id`, `support_priority`, `support_sla_hours`, `support_channel`, `support_contact`, `display_type`, `client_name`, `client_reference`, `sla_priority`, `expected_response`, `client_contact_email`, `client_contact_phone`, `sla_due_hours`, `external_ticket_link`, `service_level_description`, `vendor_name`, `estimated_hours`, `aggregation_date`, `aggregation_shift`, `call_log_path`) VALUES
(137, 'Client Call Handling (February 18, 2026 #1)', 'Client Call Handling (February 18, 2026 #1)', 'active', '2026-02-28 18:45:00', 'QL001', '2026-02-18 18:43:45', NULL, 'mandatory', '', 'private', NULL, NULL, NULL, NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL, 'client_call', NULL, NULL, 'medium', NULL, 'name@client.com', '0999 999 9999', 24, 'https://open.spotify.com/album/3KmSMUwyrakryureTNI4U8', 'None', 'Supplier Name', 3.50, '2026-02-28', 'full_day', NULL),
(138, 'Client Call Handling (February 18, 2026 #1)', 'Client Call Handling (February 18, 2026 #1)', 'active', '2026-02-28 18:45:00', 'QL001', '2026-02-18 18:52:07', NULL, 'mandatory', '', 'private', NULL, NULL, NULL, NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL, 'client_call', NULL, NULL, 'medium', NULL, 'name@client.com', '0999 999 9999', 24, 'https://open.spotify.com/album/3KmSMUwyrakryureTNI4U8', 'None', 'Supplier Name', 3.50, '2026-02-28', 'full_day', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `quest_assessment_details`
--

CREATE TABLE `quest_assessment_details` (
  `id` int(11) NOT NULL,
  `quest_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `submission_id` int(11) NOT NULL,
  `skill_name` varchar(255) NOT NULL,
  `base_points` int(11) NOT NULL DEFAULT 0,
  `performance_multiplier` decimal(4,2) NOT NULL DEFAULT 1.00,
  `performance_label` varchar(50) DEFAULT NULL,
  `adjusted_points` int(11) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `reviewed_by` varchar(50) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quest_assessment_details`
--

INSERT INTO `quest_assessment_details` (`id`, `quest_id`, `user_id`, `submission_id`, `skill_name`, `base_points`, `performance_multiplier`, `performance_label`, `adjusted_points`, `notes`, `reviewed_by`, `reviewed_at`, `created_at`) VALUES
(43, 119, 3, 65, 'Client Communication', 12, 1.68, 'Exceptional', 20, '', 'QL003', '2025-11-07 23:25:24', '2025-11-07 23:25:24'),
(44, 119, 3, 65, 'Ticket Management', 5, 1.68, 'Exceptional', 8, '', 'QL003', '2025-11-07 23:25:24', '2025-11-07 23:25:24'),
(45, 119, 3, 65, 'Incident Diagnosis', 12, 1.68, 'Exceptional', 20, '', 'QL003', '2025-11-07 23:25:24', '2025-11-07 23:25:24'),
(46, 117, 2, 64, 'Client Communication', 12, 1.20, 'Meets Expectations', 14, 'Galing hanep', 'QL003', '2025-11-07 23:30:35', '2025-11-07 23:30:35'),
(47, 117, 2, 64, 'Ticket Management', 5, 1.20, 'Meets Expectations', 6, 'Galing hanep', 'QL003', '2025-11-07 23:30:35', '2025-11-07 23:30:35'),
(48, 117, 2, 64, 'Incident Diagnosis', 12, 1.20, 'Meets Expectations', 14, 'Galing hanep', 'QL003', '2025-11-07 23:30:35', '2025-11-07 23:30:35'),
(49, 115, 2, 62, 'Client Communication', 12, 1.68, 'Exceptional', 20, 'Wow!', 'QL003', '2025-11-07 23:34:39', '2025-11-07 23:34:39'),
(50, 115, 2, 62, 'Ticket Management', 5, 1.68, 'Exceptional', 8, 'Wow!', 'QL003', '2025-11-07 23:34:39', '2025-11-07 23:34:39'),
(51, 115, 2, 62, 'Incident Diagnosis', 12, 1.68, 'Exceptional', 20, 'Wow!', 'QL003', '2025-11-07 23:34:39', '2025-11-07 23:34:39'),
(52, 122, 5, 66, 'Client Communication', 12, 1.44, 'Exceeds Expectations', 17, 'Wowwww', 'QL001', '2025-11-07 23:55:30', '2025-11-07 23:55:30'),
(53, 122, 5, 66, 'Ticket Management', 5, 1.44, 'Exceeds Expectations', 7, 'Wowwww', 'QL001', '2025-11-07 23:55:30', '2025-11-07 23:55:30'),
(54, 122, 5, 66, 'Incident Diagnosis', 12, 1.44, 'Exceeds Expectations', 17, 'Wowwww', 'QL001', '2025-11-07 23:55:30', '2025-11-07 23:55:30'),
(55, 123, 5, 67, 'Client Communication', 12, 1.68, 'Exceptional', 20, '', 'QL001', '2025-11-07 23:58:44', '2025-11-07 23:58:44'),
(56, 123, 5, 67, 'Ticket Management', 5, 1.68, 'Exceptional', 8, '', 'QL001', '2025-11-07 23:58:44', '2025-11-07 23:58:44'),
(57, 123, 5, 67, 'Incident Diagnosis', 12, 1.68, 'Exceptional', 20, '', 'QL001', '2025-11-07 23:58:44', '2025-11-07 23:58:44'),
(58, 124, 12, 68, 'AWS Cloud', 50, 1.40, 'Exceptional', 70, '', 'QL002', '2025-11-08 00:30:02', '2025-11-08 00:30:02'),
(59, 124, 12, 68, 'Email Etiquette', 50, 1.40, 'Exceptional', 70, '', 'QL002', '2025-11-08 00:30:02', '2025-11-08 00:30:02');

-- --------------------------------------------------------

--
-- Table structure for table `quest_attachments`
--

CREATE TABLE `quest_attachments` (
  `id` int(11) NOT NULL,
  `quest_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_size` int(11) NOT NULL,
  `file_type` varchar(100) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `uploaded_by` varchar(255) DEFAULT NULL,
  `is_sample_output` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quest_attachments`
--

INSERT INTO `quest_attachments` (`id`, `quest_id`, `file_name`, `file_path`, `file_size`, `file_type`, `uploaded_at`, `uploaded_by`, `is_sample_output`) VALUES
(3, 137, 'Data_Engineer_Internship_Resume.pdf', 'uploads/quest_attachments/sample_output_699597e109113.pdf', 52281, 'application/pdf', '2026-02-18 10:43:45', 'QL001', 1),
(4, 138, 'Data_Engineer_Internship_Resume.pdf', 'uploads/quest_attachments/sample_output_699599d7b3c68.pdf', 52281, 'application/pdf', '2026-02-18 10:52:07', 'QL001', 1);

-- --------------------------------------------------------

--
-- Table structure for table `quest_completions`
--

CREATE TABLE `quest_completions` (
  `id` int(11) NOT NULL,
  `quest_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `completed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `total_points_awarded` int(11) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quest_completions`
--

INSERT INTO `quest_completions` (`id`, `quest_id`, `user_id`, `completed_at`, `total_points_awarded`, `notes`) VALUES
(22, 119, 3, '2025-11-07 23:25:24', 48, ''),
(23, 117, 2, '2025-11-07 23:30:35', 34, ''),
(24, 115, 2, '2025-11-07 23:34:39', 48, ''),
(25, 122, 5, '2025-11-07 23:55:30', 41, ''),
(26, 123, 5, '2025-11-07 23:58:44', 48, ''),
(27, 124, 12, '2025-11-08 00:30:02', 140, '');

-- --------------------------------------------------------

--
-- Table structure for table `quest_skills`
--

CREATE TABLE `quest_skills` (
  `id` int(11) NOT NULL,
  `quest_id` int(11) NOT NULL,
  `skill_id` int(11) NOT NULL,
  `tier_level` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `required_level` enum('beginner','intermediate','advanced','expert') DEFAULT 'beginner'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quest_skills`
--

INSERT INTO `quest_skills` (`id`, `quest_id`, `skill_id`, `tier_level`, `created_at`, `required_level`) VALUES
(341, 137, 482, 1, '2026-02-18 11:27:23', 'beginner'),
(342, 137, 483, 1, '2026-02-18 11:27:23', 'beginner'),
(343, 137, 484, 1, '2026-02-18 11:27:23', 'beginner'),
(344, 137, 485, 1, '2026-02-18 11:27:23', 'beginner'),
(345, 137, 486, 1, '2026-02-18 11:27:23', 'beginner'),
(346, 138, 482, 1, '2026-02-18 11:27:23', 'beginner'),
(347, 138, 483, 1, '2026-02-18 11:27:23', 'beginner'),
(348, 138, 484, 1, '2026-02-18 11:27:23', 'beginner'),
(349, 138, 485, 1, '2026-02-18 11:27:23', 'beginner'),
(350, 138, 486, 1, '2026-02-18 11:27:23', 'beginner');

-- --------------------------------------------------------

--
-- Table structure for table `quest_submissions`
--

CREATE TABLE `quest_submissions` (
  `id` int(11) NOT NULL,
  `quest_id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `submission_text` text DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','approved','rejected','needs_revision') NOT NULL DEFAULT 'pending',
  `feedback` text DEFAULT NULL,
  `reviewed_by` varchar(50) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `drive_link` text DEFAULT NULL,
  `additional_xp` int(11) DEFAULT 0,
  `grade` enum('A','B','C','D','F') DEFAULT NULL,
  `text_content` text DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `submission_type` enum('file','link','text') DEFAULT NULL,
  `file_name` varchar(255) DEFAULT '',
  `ticket_reference` varchar(255) DEFAULT NULL,
  `time_spent_hours` decimal(8,2) DEFAULT NULL,
  `evidence_json` text DEFAULT NULL,
  `resolution_status` varchar(100) DEFAULT NULL,
  `follow_up_required` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quest_subtasks`
--

CREATE TABLE `quest_subtasks` (
  `id` int(11) NOT NULL,
  `quest_id` int(11) NOT NULL,
  `description` text NOT NULL,
  `is_completed` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quest_types`
--

CREATE TABLE `quest_types` (
  `id` int(11) NOT NULL,
  `type_key` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quest_types`
--

INSERT INTO `quest_types` (`id`, `type_key`, `name`, `description`, `icon`, `created_at`) VALUES
(1, 'custom', 'Custom', 'User-defined/custom quests', 'fa-star', '2025-11-06 04:27:22'),
(2, 'client_support', 'Client & Support Operations', 'Client support related quests (auto-attached skills)', 'fa-headset', '2025-11-06 04:27:22');

-- --------------------------------------------------------

--
-- Table structure for table `skill_assessments`
--

CREATE TABLE `skill_assessments` (
  `id` int(11) NOT NULL,
  `submission_id` int(11) NOT NULL,
  `skill_id` int(11) NOT NULL,
  `base_tier_level` int(11) NOT NULL,
  `performance_modifier` enum('below_expectations','meets_expectations','exceeds_expectations','exceptional') DEFAULT 'meets_expectations',
  `points_awarded` int(11) NOT NULL DEFAULT 0,
  `assessor_notes` text DEFAULT NULL,
  `assessed_by` varchar(50) NOT NULL,
  `assessed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `skill_categories`
--

CREATE TABLE `skill_categories` (
  `id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(50) DEFAULT NULL,
  `color` varchar(7) DEFAULT '#6366f1',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `display_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `skill_categories`
--

INSERT INTO `skill_categories` (`id`, `category_name`, `description`, `icon`, `color`, `created_at`, `display_order`) VALUES
(1, 'Technical Skills', 'Programming, software development, and technical expertise', 'fas fa-code', '#3B82F6', '2025-10-08 20:54:49', 0),
(2, 'Communication Skills', 'Written, verbal, and interpersonal communication abilities', 'fas fa-comments', '#F59E0B', '2025-10-08 20:54:49', 0),
(3, 'Soft Skills', 'Personal attributes and social abilities', 'fas fa-heart', '#EF4444', '2025-10-08 20:54:49', 0),
(4, 'Business Skills', 'Management, strategy, and business acumen', 'fas fa-briefcase', '#10B981', '2025-10-08 20:54:49', 0),
(8, 'Client & Support Operations', NULL, NULL, '#6366f1', '2025-11-07 10:29:15', 0),
(9, 'Client Call', NULL, NULL, '#6366f1', '2026-02-16 13:31:24', 0);

-- --------------------------------------------------------

--
-- Table structure for table `skill_level_thresholds`
--

CREATE TABLE `skill_level_thresholds` (
  `id` int(11) NOT NULL,
  `level` int(11) NOT NULL,
  `cumulative_xp` int(11) NOT NULL,
  `stage` enum('Learning','Applying','Mastering','Innovating') NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `skill_level_thresholds`
--

INSERT INTO `skill_level_thresholds` (`id`, `level`, `cumulative_xp`, `stage`, `active`, `created_at`) VALUES
(1, 1, 0, 'Learning', 1, '2025-10-14 19:21:21'),
(2, 2, 100, 'Learning', 1, '2025-10-14 19:21:21'),
(3, 3, 260, 'Learning', 1, '2025-10-14 19:21:21'),
(4, 4, 510, 'Applying', 1, '2025-10-14 19:21:21'),
(5, 5, 900, 'Applying', 1, '2025-10-14 19:21:21'),
(6, 6, 1500, 'Applying', 1, '2025-10-14 19:21:21'),
(7, 7, 2420, 'Mastering', 1, '2025-10-14 19:21:21'),
(8, 8, 4600, 'Mastering', 1, '2025-10-14 19:21:21'),
(9, 9, 7700, 'Mastering', 1, '2025-10-14 19:21:21'),
(10, 10, 12700, 'Innovating', 1, '2025-10-14 19:21:21'),
(11, 11, 19300, 'Innovating', 1, '2025-10-14 19:21:21'),
(12, 12, 29150, 'Innovating', 1, '2025-10-14 19:21:21');

-- --------------------------------------------------------

--
-- Table structure for table `skill_points_history`
--

CREATE TABLE `skill_points_history` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `skill_id` int(11) NOT NULL,
  `points_change` int(11) NOT NULL,
  `source_type` enum('quest_completion','assessment_adjustment','manual_adjustment','decay') NOT NULL,
  `source_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` enum('string','integer','boolean','json') NOT NULL DEFAULT 'string',
  `description` text DEFAULT NULL,
  `is_user_configurable` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `is_user_configurable`, `updated_at`) VALUES
(1, 'system_name', 'YooNet Quest System', 'string', 'The name of the application', 1, '2025-10-25 05:12:28'),
(2, 'max_file_upload_size', '5242880', 'integer', 'Maximum file upload size in bytes (5MB)', 1, '2025-10-25 05:12:28'),
(3, 'default_quest_xp', '10', 'integer', 'Default XP value for new quests', 1, '2025-10-25 05:12:28'),
(4, 'enable_achievements', 'true', 'boolean', 'Enable achievement system', 1, '2025-10-25 05:12:28'),
(5, 'default_theme', 'default', 'string', 'Default theme for new users', 1, '2025-10-25 05:12:28'),
(6, 'email_notifications', 'true', 'boolean', 'Enable email notifications', 1, '2025-10-25 05:12:28'),
(7, 'session_timeout', '3600', 'integer', 'Session timeout in seconds', 0, '2025-10-25 05:12:28'),
(8, 'password_min_length', '8', 'integer', 'Minimum password length', 0, '2025-10-25 05:12:28'),
(9, 'max_login_attempts', '5', 'integer', 'Maximum login attempts before lockout', 0, '2025-10-25 05:12:28'),
(10, 'backup_retention_days', '30', 'integer', 'Number of days to retain backups', 0, '2025-10-25 05:12:28');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('skill_associate','quest_lead','admin','quest_taker','hybrid','quest_giver','participant','contributor') NOT NULL DEFAULT 'skill_associate',
  `profile_photo` varchar(255) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `job_position` varchar(50) DEFAULT NULL,
  `quest_interests` text DEFAULT NULL,
  `profile_completed` tinyint(1) DEFAULT 0,
  `hire_date` date DEFAULT NULL,
  `availability_status` enum('full_time','part_time','casual','project_based') DEFAULT 'full_time',
  `availability_message` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `preferred_role` enum('quest_taker','quest_giver','hybrid') DEFAULT NULL,
  `availability` enum('full_time','part_time','casual','project_based') DEFAULT NULL,
  `availability_hours` varchar(20) DEFAULT NULL,
  `last_name` varchar(150) DEFAULT NULL,
  `first_name` varchar(150) DEFAULT NULL,
  `middle_name` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `employee_id`, `email`, `password`, `full_name`, `role`, `profile_photo`, `bio`, `location`, `department`, `job_position`, `quest_interests`, `profile_completed`, `hire_date`, `availability_status`, `availability_message`, `created_at`, `updated_at`, `preferred_role`, `availability`, `availability_hours`, `last_name`, `first_name`, `middle_name`) VALUES
(1, 'ADMIN001', 'admin@yoonet.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin', NULL, NULL, NULL, NULL, 'junior_customer_service_associate', NULL, 0, NULL, '', NULL, '2025-10-08 20:54:49', '2026-02-03 10:36:04', NULL, NULL, NULL, NULL, NULL, NULL),
(2, 'QL001', 'questlead@yoonet.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Bacani, Christiane, R.', 'quest_lead', NULL, 'None', NULL, NULL, 'junior_customer_service_associate', 'Development Projects,Research Tasks', 1, NULL, 'casual', NULL, '2025-10-08 20:54:49', '2026-02-03 10:36:04', NULL, 'casual', NULL, NULL, NULL, NULL),
(3, 'SA001', 'skillassoc@yoonet.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Christiane Rhely Joselle A. Bacani', 'skill_associate', NULL, '', NULL, NULL, 'junior_customer_service_associate', 'Team Collaboration,Innovation Projects', 1, NULL, 'full_time', NULL, '2025-10-08 20:54:49', '2026-02-03 10:36:04', NULL, 'full_time', NULL, NULL, NULL, NULL),
(5, 'QL002', 'gavjvorzki@gmail.com', '$2y$10$HmvegFPQYhBcviVtBbdKxOQ1V/osBYltV8EY63/touknBe31gijO.', 'Chrae Flores-Bacani', 'quest_lead', NULL, 'I am a UI/UX Designer', NULL, NULL, 'junior_customer_service_associate', 'Design Challenges,Research Tasks', 1, NULL, 'casual', NULL, '2025-10-15 00:19:04', '2026-02-03 10:36:04', NULL, 'casual', NULL, NULL, NULL, NULL),
(7, 'SA002', 'ronnelferrer@gmail.com', '$2y$10$HqnXmgCwA2Lr1oJToc1Icu6mxqwy/ZBt4tbBiNcunuhan.WJ9k/PW', 'Christiane Rhely Joselle A. Bacani', 'skill_associate', NULL, 'I am the best programmer of all time', NULL, NULL, 'junior_customer_service_associate', 'Research Tasks', 1, NULL, 'project_based', NULL, '2025-10-30 23:38:49', '2026-02-03 10:36:04', NULL, 'project_based', NULL, NULL, NULL, NULL),
(12, 'QL003', 'christianerhellyjosellebacani@rocketmail.com', '$2y$10$p8CmRL6WvAAlfesceTZUDeqjw095/aeQMRP2wYA3GCafZmZciOou2', 'Bacani, Christiane Rhely Joselle, A.', 'quest_lead', NULL, '', NULL, NULL, 'junior_customer_service_associate', 'Development Projects,Design Challenges,Research Tasks,Learning Goals,Team Collaboration,Innovation Projects', 1, NULL, 'full_time', NULL, '2025-11-03 22:56:13', '2026-02-03 10:46:12', NULL, 'project_based', NULL, 'Bacani', 'Christiane Rhely Joselle', 'Aguibitin'),
(13, 'SA004', 'christianbacani581@gmail.com', '$2y$10$LGcJzR8uY.KQo85UlFHCUehzN.Nd14YX0xSVHm88WU72CY2kuFYBC', 'Smith, Joe, F.', 'skill_associate', NULL, 'The best Joe Smith', NULL, NULL, 'junior_customer_service_associate', 'Development Projects,Design Challenges', 1, NULL, 'full_time', NULL, '2026-01-21 08:41:19', '2026-02-03 10:36:04', NULL, 'part_time', NULL, 'Smith', 'Joe', 'Fruger'),
(14, 'SA003', 'crjabacani@bpsu.edu.ph', '$2y$10$BMAMtzH4wzUvr1dIFtSIne.4JnotbGNjATPbNqxde8hRiwBdYARX.', 'Bacani, Christiane Rhely Joselle, À.', 'quest_lead', NULL, '', NULL, NULL, 'junior_customer_service_associate', 'Research Tasks,Learning Goals,Innovation Projects', 1, NULL, 'full_time', NULL, '2026-01-31 02:34:43', '2026-02-03 10:36:04', NULL, 'project_based', NULL, 'Bacani', 'Christiane Rhely Joselle', 'Àguibitin'),
(17, 'QL10', 'aldrindilig@bpsu.edu.ph', '$2y$10$do2Je6.NXdwtDI.YqaiVYuOWwTUmgjFWXBf8uetV0BzdCqCfDE2le', 'Bacani, Christiane Rhely Joselle, À.', 'quest_lead', NULL, '', NULL, NULL, 'junior_customer_service_associate', 'Development Projects,Innovation Projects', 1, NULL, 'full_time', NULL, '2026-02-03 04:55:02', '2026-02-03 11:56:03', NULL, 'full_time', NULL, 'Bacani', 'Christiane Rhely Joselle', 'Àguibitin'),
(18, 'QL005', 'johnronnelferrer2003@gmail.com', '$2y$10$v1/RNm.ehSYozCmhFUuh1evjX0kJsckhYI2LoGz/YGzJBMtq.V5EK', 'Ferrer, John Ronnel, B.', 'quest_lead', NULL, '', NULL, NULL, 'junior_customer_service_associate', 'Development Projects,Research Tasks,Team Collaboration', 1, NULL, 'full_time', NULL, '2026-02-09 06:15:29', '2026-02-10 14:14:25', NULL, 'full_time', NULL, 'Ferrer', 'John Ronnel', 'Buan'),
(19, 'QL007', 'johnbuanferrer@gmail.com', '$2y$10$RQuUh9IpWFUm.Rb1tn1p0uYgzBLe/N5xDqdfCdX8HauK5PFWvocta', 'Ferrer, John Ronnel, B.', 'quest_lead', NULL, NULL, NULL, NULL, 'junior_customer_service_associate', NULL, 0, NULL, 'full_time', NULL, '2026-02-09 06:23:47', NULL, NULL, 'full_time', NULL, 'Ferrer', 'John Ronnel', 'Buan'),
(20, 'QL008', 'ashpascual2@gmail.com', '$2y$10$ScmSWJ3RywHWYG5m4lDZFOoYakliFmPFPNisAXSjLIEvEwnyUVs0y', 'Pascual, Ash Edward, D.', 'quest_lead', NULL, NULL, NULL, NULL, 'junior_customer_service_associate', NULL, 0, NULL, 'full_time', NULL, '2026-02-10 07:15:20', NULL, NULL, 'full_time', NULL, 'Pascual', 'Ash Edward', 'Dimaculangan'),
(21, 'QL009', 'jronnelferrer23@gmail.com', '$2y$10$L52h7Co2NkpNHIP65UJiy.yq3AzR6UuCkVKp2dqQ00Z9ZLZx2yDNS', 'Pascual, Ash Edward, D.', 'quest_lead', NULL, NULL, NULL, NULL, 'junior_customer_service_associate', NULL, 0, NULL, 'full_time', NULL, '2026-02-10 07:19:46', NULL, NULL, 'full_time', NULL, 'Pascual', 'Ash Edward', 'Dimaculangan'),
(22, 'QL44', 'raeflores012@gmai.com', '$2y$10$W7aIcPktjwo5nQgKpsovveBO/46crt3iV5GHV2bhJJMUEaLyYRH3G', 'Flores-Bacani, Rica Mae, G.', 'quest_lead', NULL, NULL, NULL, NULL, 'junior_customer_service_associate', NULL, 0, NULL, 'full_time', NULL, '2026-02-10 07:25:32', NULL, NULL, 'full_time', NULL, 'Flores-Bacani', 'Rica Mae', 'Gueco');

-- --------------------------------------------------------

--
-- Table structure for table `user_achievements`
--

CREATE TABLE `user_achievements` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `achievement_type` varchar(50) NOT NULL,
  `achievement_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(50) DEFAULT NULL,
  `skill_id` int(11) DEFAULT NULL,
  `earned_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_earned_skills`
--

CREATE TABLE `user_earned_skills` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `skill_name` varchar(255) NOT NULL,
  `total_points` int(11) NOT NULL DEFAULT 0,
  `current_level` int(11) NOT NULL DEFAULT 1,
  `current_stage` enum('Learning','Applying','Mastering','Innovating') NOT NULL DEFAULT 'Learning',
  `last_used` timestamp NULL DEFAULT NULL,
  `recent_points` int(11) NOT NULL DEFAULT 0,
  `status` enum('ACTIVE','STALE','RUSTY') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `employee_id` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_earned_skills`
--

INSERT INTO `user_earned_skills` (`id`, `user_id`, `skill_name`, `total_points`, `current_level`, `current_stage`, `last_used`, `recent_points`, `status`, `created_at`, `updated_at`, `employee_id`) VALUES
(46, 3, 'Client Communication', 20, 1, 'Learning', '2025-11-07 23:25:24', 20, 'ACTIVE', '2025-11-07 23:25:24', '2025-11-07 23:25:24', ''),
(47, 3, 'Ticket Management', 8, 1, 'Learning', '2025-11-07 23:25:24', 8, 'ACTIVE', '2025-11-07 23:25:24', '2025-11-07 23:25:24', ''),
(48, 3, 'Incident Diagnosis', 20, 1, 'Learning', '2025-11-07 23:25:24', 20, 'ACTIVE', '2025-11-07 23:25:24', '2025-11-07 23:25:24', ''),
(49, 2, 'Client Communication', 34, 1, 'Learning', '2025-11-07 23:34:39', 20, 'ACTIVE', '2025-11-07 23:30:35', '2025-11-07 23:34:39', ''),
(50, 2, 'Ticket Management', 14, 1, 'Learning', '2025-11-07 23:34:39', 8, 'ACTIVE', '2025-11-07 23:30:35', '2025-11-07 23:34:39', ''),
(51, 2, 'Incident Diagnosis', 34, 1, 'Learning', '2025-11-07 23:34:39', 20, 'ACTIVE', '2025-11-07 23:30:35', '2025-11-07 23:34:39', ''),
(52, 5, 'Client Communication', 37, 1, 'Learning', '2025-11-07 23:58:44', 20, 'ACTIVE', '2025-11-07 23:55:30', '2025-11-07 23:58:44', ''),
(53, 5, 'Ticket Management', 15, 1, 'Learning', '2025-11-07 23:58:44', 8, 'ACTIVE', '2025-11-07 23:55:30', '2025-11-07 23:58:44', ''),
(54, 5, 'Incident Diagnosis', 37, 1, 'Learning', '2025-11-07 23:58:44', 20, 'ACTIVE', '2025-11-07 23:55:30', '2025-11-07 23:58:44', ''),
(55, 12, 'AWS Cloud', 70, 1, 'Learning', '2025-11-08 00:30:02', 70, 'ACTIVE', '2025-11-08 00:30:02', '2025-11-08 00:30:02', ''),
(56, 12, 'Email Etiquette', 70, 1, 'Learning', '2025-11-08 00:30:02', 70, 'ACTIVE', '2025-11-08 00:30:02', '2025-11-08 00:30:02', '');

-- --------------------------------------------------------

--
-- Table structure for table `user_preferences`
--

CREATE TABLE `user_preferences` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `preference_key` varchar(100) NOT NULL,
  `preference_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_profile_views`
--

CREATE TABLE `user_profile_views` (
  `id` int(11) NOT NULL,
  `profile_employee_id` varchar(50) NOT NULL,
  `viewer_employee_id` varchar(50) NOT NULL,
  `viewed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_quests`
--

CREATE TABLE `user_quests` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `quest_id` int(11) NOT NULL,
  `status` enum('assigned','in_progress','submitted','completed','declined') NOT NULL DEFAULT 'assigned',
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `started_at` timestamp NULL DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `progress_notes` text DEFAULT NULL,
  `progress_percentage` decimal(5,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `attempts` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_quests`
--

INSERT INTO `user_quests` (`id`, `employee_id`, `quest_id`, `status`, `assigned_at`, `started_at`, `submitted_at`, `completed_at`, `progress_notes`, `progress_percentage`, `notes`, `attempts`) VALUES
(255, 'QL003', 137, 'in_progress', '2026-02-18 10:43:45', NULL, NULL, NULL, NULL, 0.00, NULL, 1),
(256, 'QL003', 138, 'in_progress', '2026-02-18 10:52:07', NULL, NULL, NULL, NULL, 0.00, NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `user_settings`
--

CREATE TABLE `user_settings` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `theme` varchar(50) NOT NULL DEFAULT 'default',
  `dark_mode` tinyint(1) NOT NULL DEFAULT 0,
  `font_size` varchar(20) NOT NULL DEFAULT 'medium',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_settings`
--

INSERT INTO `user_settings` (`id`, `user_id`, `theme`, `dark_mode`, `font_size`, `created_at`, `updated_at`) VALUES
(1, 12, 'default', 0, 'medium', '2025-11-07 20:25:19', '2025-11-07 20:25:19'),
(2, 2, 'default', 0, 'medium', '2026-02-03 10:13:12', '2026-02-03 10:13:12'),
(3, 17, 'default', 0, 'medium', '2026-02-03 11:56:04', '2026-02-03 11:56:04');

-- --------------------------------------------------------

--
-- Table structure for table `user_skills`
--

CREATE TABLE `user_skills` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `skill_id` int(11) NOT NULL,
  `proficiency_level` enum('beginner','intermediate','advanced','expert') NOT NULL DEFAULT 'beginner',
  `years_experience` decimal(3,1) DEFAULT 0.0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_skill_progress`
--

CREATE TABLE `user_skill_progress` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `skill_id` int(11) NOT NULL,
  `total_points` int(11) DEFAULT 0,
  `skill_level` int(11) DEFAULT 1,
  `current_stage` enum('LEARNING','APPLYING','MASTERING','INNOVATING') DEFAULT 'LEARNING',
  `last_activity` timestamp NULL DEFAULT NULL,
  `activity_status` enum('ACTIVE','STALE','RUSTY') DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `xp_history`
--

CREATE TABLE `xp_history` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `xp_change` int(11) NOT NULL,
  `source_type` enum('quest_complete','quest_submit','quest_review','bonus','penalty','manual') NOT NULL,
  `source_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `xp_history`
--

INSERT INTO `xp_history` (`id`, `employee_id`, `xp_change`, `source_type`, `source_id`, `description`, `created_at`) VALUES
(38, '2', 0, '', 119, 'Optional quest assigned: Quest Title for Client and Support Operations # 5 (Editted)', '2025-11-07 22:45:16'),
(39, '5', 0, '', 119, 'Optional quest assigned: Quest Title for Client and Support Operations # 5 (Editted)', '2025-11-07 22:45:16'),
(40, '3', 0, '', 119, 'Optional quest assigned: Quest Title for Client and Support Operations # 5 (Editted)', '2025-11-07 22:45:16'),
(41, '7', 0, '', 119, 'Optional quest assigned: Quest Title for Client and Support Operations # 5 (Editted)', '2025-11-07 22:45:16'),
(42, '5', 0, '', 120, 'Optional quest assigned: (TEST) Quest Title for Client and Support Operations #1 (Edited)', '2025-11-07 23:45:50'),
(43, '12', 0, '', 124, 'Optional quest assigned: (TEST) Quest Title for Custom Quest Type #1 (Edited)', '2025-11-08 00:18:16');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `achievements`
--
ALTER TABLE `achievements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `idx_criteria_type` (`criteria_type`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_employee_id` (`employee_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_entity_type` (`entity_type`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_activity_logs_user_created` (`user_id`,`created_at`);

--
-- Indexes for table `calls`
--
ALTER TABLE `calls`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `call_logs`
--
ALTER TABLE `call_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `comprehensive_skills`
--
ALTER TABLE `comprehensive_skills`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `employee_groups`
--
ALTER TABLE `employee_groups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `event_attendance`
--
ALTER TABLE `event_attendance`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `group_members`
--
ALTER TABLE `group_members`
  ADD PRIMARY KEY (`id`),
  ADD KEY `group_id` (`group_id`),
  ADD KEY `employee_id` (`employee_id`);

--
-- Indexes for table `patient_records`
--
ALTER TABLE `patient_records`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `quests`
--
ALTER TABLE `quests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_quests_status_created` (`status`,`created_at`);

--
-- Indexes for table `quest_assessment_details`
--
ALTER TABLE `quest_assessment_details`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_submission_skill` (`submission_id`,`skill_name`);

--
-- Indexes for table `quest_attachments`
--
ALTER TABLE `quest_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `quest_id` (`quest_id`);

--
-- Indexes for table `quest_completions`
--
ALTER TABLE `quest_completions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_quest` (`quest_id`,`user_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_quest` (`quest_id`);

--
-- Indexes for table `quest_skills`
--
ALTER TABLE `quest_skills`
  ADD PRIMARY KEY (`id`),
  ADD KEY `quest_id` (`quest_id`),
  ADD KEY `skill_id` (`skill_id`);

--
-- Indexes for table `quest_submissions`
--
ALTER TABLE `quest_submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `quest_id` (`quest_id`),
  ADD KEY `employee_id` (`employee_id`),
  ADD KEY `reviewed_by` (`reviewed_by`),
  ADD KEY `idx_submissions_status_submitted` (`status`,`submitted_at`);

--
-- Indexes for table `quest_subtasks`
--
ALTER TABLE `quest_subtasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `quest_id` (`quest_id`);

--
-- Indexes for table `quest_types`
--
ALTER TABLE `quest_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `type_key` (`type_key`);

--
-- Indexes for table `skill_assessments`
--
ALTER TABLE `skill_assessments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `submission_id` (`submission_id`),
  ADD KEY `skill_id` (`skill_id`),
  ADD KEY `assessed_by` (`assessed_by`);

--
-- Indexes for table `skill_categories`
--
ALTER TABLE `skill_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `skill_level_thresholds`
--
ALTER TABLE `skill_level_thresholds`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_level` (`level`);

--
-- Indexes for table `skill_points_history`
--
ALTER TABLE `skill_points_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`),
  ADD KEY `skill_id` (`skill_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employee_id` (`employee_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_role_profile` (`role`,`profile_completed`);

--
-- Indexes for table `user_achievements`
--
ALTER TABLE `user_achievements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`),
  ADD KEY `skill_id` (`skill_id`);

--
-- Indexes for table `user_earned_skills`
--
ALTER TABLE `user_earned_skills`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_skill_name` (`user_id`,`skill_name`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_skill_name` (`skill_name`);

--
-- Indexes for table `user_preferences`
--
ALTER TABLE `user_preferences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employee_preference` (`employee_id`,`preference_key`),
  ADD KEY `employee_id` (`employee_id`);

--
-- Indexes for table `user_profile_views`
--
ALTER TABLE `user_profile_views`
  ADD PRIMARY KEY (`id`),
  ADD KEY `profile_employee_id` (`profile_employee_id`),
  ADD KEY `viewer_employee_id` (`viewer_employee_id`);

--
-- Indexes for table `user_quests`
--
ALTER TABLE `user_quests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employee_quest` (`employee_id`,`quest_id`),
  ADD KEY `quest_id` (`quest_id`),
  ADD KEY `employee_id` (`employee_id`),
  ADD KEY `idx_user_quests_status_assigned` (`status`,`assigned_at`);

--
-- Indexes for table `user_settings`
--
ALTER TABLE `user_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `user_skills`
--
ALTER TABLE `user_skills`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_skill_unique` (`user_id`,`skill_id`),
  ADD KEY `skill_id` (`skill_id`),
  ADD KEY `idx_proficiency` (`proficiency_level`);

--
-- Indexes for table `user_skill_progress`
--
ALTER TABLE `user_skill_progress`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_skill` (`employee_id`,`skill_id`),
  ADD KEY `skill_id` (`skill_id`),
  ADD KEY `employee_id` (`employee_id`);

--
-- Indexes for table `xp_history`
--
ALTER TABLE `xp_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_employee_id` (`employee_id`),
  ADD KEY `idx_source_type` (`source_type`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_xp_history_employee_created` (`employee_id`,`created_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `achievements`
--
ALTER TABLE `achievements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `calls`
--
ALTER TABLE `calls`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `call_logs`
--
ALTER TABLE `call_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `comprehensive_skills`
--
ALTER TABLE `comprehensive_skills`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=487;

--
-- AUTO_INCREMENT for table `employee_groups`
--
ALTER TABLE `employee_groups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `event_attendance`
--
ALTER TABLE `event_attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `group_members`
--
ALTER TABLE `group_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `patient_records`
--
ALTER TABLE `patient_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quests`
--
ALTER TABLE `quests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=139;

--
-- AUTO_INCREMENT for table `quest_assessment_details`
--
ALTER TABLE `quest_assessment_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=60;

--
-- AUTO_INCREMENT for table `quest_attachments`
--
ALTER TABLE `quest_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `quest_completions`
--
ALTER TABLE `quest_completions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `quest_skills`
--
ALTER TABLE `quest_skills`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=351;

--
-- AUTO_INCREMENT for table `quest_submissions`
--
ALTER TABLE `quest_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=69;

--
-- AUTO_INCREMENT for table `quest_subtasks`
--
ALTER TABLE `quest_subtasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quest_types`
--
ALTER TABLE `quest_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=240;

--
-- AUTO_INCREMENT for table `skill_assessments`
--
ALTER TABLE `skill_assessments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `skill_categories`
--
ALTER TABLE `skill_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `skill_level_thresholds`
--
ALTER TABLE `skill_level_thresholds`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT for table `skill_points_history`
--
ALTER TABLE `skill_points_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `user_achievements`
--
ALTER TABLE `user_achievements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_earned_skills`
--
ALTER TABLE `user_earned_skills`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=57;

--
-- AUTO_INCREMENT for table `user_preferences`
--
ALTER TABLE `user_preferences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_profile_views`
--
ALTER TABLE `user_profile_views`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_quests`
--
ALTER TABLE `user_quests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=257;

--
-- AUTO_INCREMENT for table `user_settings`
--
ALTER TABLE `user_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `user_skills`
--
ALTER TABLE `user_skills`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_skill_progress`
--
ALTER TABLE `user_skill_progress`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `xp_history`
--
ALTER TABLE `xp_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `comprehensive_skills`
--
ALTER TABLE `comprehensive_skills`
  ADD CONSTRAINT `comprehensive_skills_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `skill_categories` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `employee_groups`
--
ALTER TABLE `employee_groups`
  ADD CONSTRAINT `employee_groups_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE;

--
-- Constraints for table `group_members`
--
ALTER TABLE `group_members`
  ADD CONSTRAINT `group_members_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `employee_groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `group_members_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE;

--
-- Constraints for table `quests`
--
ALTER TABLE `quests`
  ADD CONSTRAINT `quests_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE;

--
-- Constraints for table `quest_attachments`
--
ALTER TABLE `quest_attachments`
  ADD CONSTRAINT `quest_attachments_ibfk_1` FOREIGN KEY (`quest_id`) REFERENCES `quests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quest_skills`
--
ALTER TABLE `quest_skills`
  ADD CONSTRAINT `quest_skills_ibfk_1` FOREIGN KEY (`quest_id`) REFERENCES `quests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `quest_skills_ibfk_2` FOREIGN KEY (`skill_id`) REFERENCES `comprehensive_skills` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quest_submissions`
--
ALTER TABLE `quest_submissions`
  ADD CONSTRAINT `quest_submissions_ibfk_1` FOREIGN KEY (`quest_id`) REFERENCES `quests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `quest_submissions_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `quest_submissions_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`employee_id`) ON DELETE SET NULL;

--
-- Constraints for table `quest_subtasks`
--
ALTER TABLE `quest_subtasks`
  ADD CONSTRAINT `quest_subtasks_ibfk_1` FOREIGN KEY (`quest_id`) REFERENCES `quests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `skill_assessments`
--
ALTER TABLE `skill_assessments`
  ADD CONSTRAINT `skill_assessments_ibfk_1` FOREIGN KEY (`submission_id`) REFERENCES `quest_submissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `skill_assessments_ibfk_2` FOREIGN KEY (`skill_id`) REFERENCES `comprehensive_skills` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `skill_assessments_ibfk_3` FOREIGN KEY (`assessed_by`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE;

--
-- Constraints for table `skill_points_history`
--
ALTER TABLE `skill_points_history`
  ADD CONSTRAINT `skill_points_history_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `skill_points_history_ibfk_2` FOREIGN KEY (`skill_id`) REFERENCES `comprehensive_skills` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_achievements`
--
ALTER TABLE `user_achievements`
  ADD CONSTRAINT `user_achievements_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_achievements_ibfk_2` FOREIGN KEY (`skill_id`) REFERENCES `comprehensive_skills` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `user_preferences`
--
ALTER TABLE `user_preferences`
  ADD CONSTRAINT `user_preferences_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE;

--
-- Constraints for table `user_profile_views`
--
ALTER TABLE `user_profile_views`
  ADD CONSTRAINT `user_profile_views_ibfk_1` FOREIGN KEY (`profile_employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_profile_views_ibfk_2` FOREIGN KEY (`viewer_employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE;

--
-- Constraints for table `user_quests`
--
ALTER TABLE `user_quests`
  ADD CONSTRAINT `user_quests_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_quests_ibfk_2` FOREIGN KEY (`quest_id`) REFERENCES `quests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_settings`
--
ALTER TABLE `user_settings`
  ADD CONSTRAINT `user_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_skills`
--
ALTER TABLE `user_skills`
  ADD CONSTRAINT `user_skills_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_skills_ibfk_2` FOREIGN KEY (`skill_id`) REFERENCES `comprehensive_skills` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_skill_progress`
--
ALTER TABLE `user_skill_progress`
  ADD CONSTRAINT `user_skill_progress_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_skill_progress_ibfk_2` FOREIGN KEY (`skill_id`) REFERENCES `comprehensive_skills` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
