-- A miniature of the legacy dump: the same phpMyAdmin shape, the same latin1
-- declarations on UTF-8 bytes, and one instance of every parsing hazard the
-- real file contains. Nothing here is copied from Legacy/.
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";
/*!40101 SET NAMES utf8mb4 */;

CREATE TABLE `conferences` (
  `id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `submission_deadline` date NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

INSERT INTO `conferences` (`id`, `name`, `submission_deadline`) VALUES
(5, 'Example Pediatric Symposium', '2023-12-02'),
(6, 'Example Pediatric Symposium', '2024-11-25');

CREATE TABLE `users` (
  `id` int NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','manager','reviewer','multi-role') NOT NULL,
  `conference_id` int DEFAULT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_token_expiry` datetime DEFAULT NULL,
  `full_name` varchar(255) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- A mixed-case address, a NULL reset_token, an unnormalised full_name, and a
-- mailbox rather than a person - all four are in the real data.
INSERT INTO `users` (`id`, `email`, `password`, `role`, `conference_id`, `reset_token`, `reset_token_expiry`, `full_name`) VALUES
(1, 'owner@example.org', '$2y$10$abcdefghijklmnopqrstuv', 'admin', NULL, NULL, NULL, 'admin'),
(14, 'Manager.One@example.org', '$2y$10$abcdefghijklmnopqrstuv', 'manager', NULL, NULL, NULL, 'dr. manager one'),
(16, 'reviewer.one@example.org', '$2y$10$abcdefghijklmnopqrstuv', 'reviewer', NULL, NULL, NULL, 'Dr Reviewer One'),
(17, 'Reviewer.Two@example.org', '$2y$10$abcdefghijklmnopqrstuv', 'reviewer', NULL, NULL, NULL, 'Dr Reviewer Two'),
(20, 'education@example.org', '$2y$10$abcdefghijklmnopqrstuv', 'reviewer', NULL, NULL, NULL, 'education');

CREATE TABLE `conference_managers` (
  `conference_id` int NOT NULL,
  `manager_id` int NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

INSERT INTO `conference_managers` (`conference_id`, `manager_id`) VALUES
(5, 14),
(6, 14);

CREATE TABLE `conference_reviewers` (
  `id` int NOT NULL,
  `conference_id` int DEFAULT NULL,
  `reviewer_id` int DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- Row 13 is the junk shape legacy-review.md:196 describes: both ids NULL.
INSERT INTO `conference_reviewers` (`id`, `conference_id`, `reviewer_id`) VALUES
(10, 5, 16),
(11, 5, 17),
(13, NULL, NULL),
(14, 6, 20);

CREATE TABLE `evaluation_forms` (
  `id` int NOT NULL,
  `conference_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

INSERT INTO `evaluation_forms` (`id`, `conference_id`, `created_at`) VALUES
(6, 5, '2023-11-28 17:44:09'),
(7, 6, '2024-11-26 17:58:01');

CREATE TABLE `evaluation_questions` (
  `id` int NOT NULL,
  `evaluation_form_id` int NOT NULL,
  `question` text NOT NULL,
  `question_type` enum('likert','textarea') NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `likert_scale` int DEFAULT NULL,
  `order_column` int DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- Form 6's order_column is 0 on every row (the legacy ordering bug,
-- legacy-review.md:176); form 7's is populated. Both shapes are here.
INSERT INTO `evaluation_questions` (`id`, `evaluation_form_id`, `question`, `question_type`, `created_at`, `likert_scale`, `order_column`) VALUES
(50, 6, 'Is the research question clearly stated?', 'likert', '2023-11-28 17:44:09', 5, 0),
(51, 6, 'Is the methodology sound?', 'likert', '2023-11-28 17:44:09', 5, 0),
(59, 7, 'Is the research question clearly stated?', 'likert', '2023-11-28 17:44:09', 5, 0),
(60, 7, 'Any free-text comments for the authors?', 'textarea', '2024-11-26 17:58:01', NULL, 1);

CREATE TABLE `reviewer_invitations` (
  `id` int NOT NULL,
  `manager_id` int DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `token` varchar(255) DEFAULT NULL,
  `status` enum('pending','accepted','expired') DEFAULT 'pending',
  `role` enum('reviewer') DEFAULT 'reviewer',
  `sent_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime DEFAULT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `conference_id` int DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- 'Pending' with a capital P against a lowercase enum (legacy-review.md:269),
-- and a live plaintext token past its expiry.
INSERT INTO `reviewer_invitations` (`id`, `manager_id`, `email`, `full_name`, `token`, `status`, `role`, `sent_at`, `expires_at`, `accepted_at`, `conference_id`) VALUES
(17, 14, 'reviewer.one@example.org', 'Dr Reviewer One', '4dfca4e3f1cedae5bd705c3302c50877', 'accepted', 'reviewer', '2023-11-20 09:00:00', '2023-12-04 09:00:00', '2023-11-21 10:00:00', 5),
(24, 14, 'never.accepted@example.org', 'Dr Never Accepted', '6fcf89e9c05b44a51d7d5239170e454c', 'Pending', 'reviewer', '2024-10-01 09:00:00', '2024-10-15 09:00:00', NULL, 6);

CREATE TABLE `submissions` (
  `id` int NOT NULL,
  `conference_id` int DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `affiliation` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `presenter_names` text NOT NULL,
  `authors` text NOT NULL,
  `abstract` text NOT NULL,
  `attachment` varchar(255) NOT NULL,
  `contact_email` varchar(255) NOT NULL,
  `contact_phone` varchar(15) NOT NULL,
  `submission_date` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- Row 5: affiliation holds a NAME (the 2023 swap). Row 6: an escaped
-- apostrophe, a \r\n inside a text column, and a bullet-separated author list.
-- Row 20: a clean 2024 row with a real institution.
INSERT INTO `submissions` (`id`, `conference_id`, `title`, `affiliation`, `presenter_names`, `authors`, `abstract`, `attachment`, `contact_email`, `contact_phone`, `submission_date`) VALUES
(5, 5, 'Bedside ultrasound in the paediatric ward', 'Dr Alia Example', 'Dr Alia Example', 'Dr Alia Example, Dr Badr Example', 'A prospective cohort of forty children.', 'uploads/1699719268_4752.pdf', 'Alia.Example@example.org', '00966500000001', '2023-11-11 16:14:27'),
(6, 5, 'A child\'s response to early mobilisation', 'How does mobilisation affect recovery', 'Dr Alia Example', 'Dr Alia Example • Dr Badr Example • Dr Carim Example', 'Line one.\r\nLine two, with a semicolon; inside it.', 'uploads/1699719578_3346.pdf', 'alia.example@example.org', '0500000002', '2023-11-12 09:00:00'),
(20, 6, 'Sepsis recognition at triage', 'Example Central Hospital', 'Dr Dalia Example', 'Dr Dalia Example & Dr Emad Example', 'Retrospective review of two hundred charts.', 'uploads/1729330644_2837.pdf', 'dalia.example@example.org', '966500000003', '2024-10-19 09:37:23');

CREATE TABLE `reviews` (
  `id` int NOT NULL,
  `submission_id` int NOT NULL,
  `reviewer_id` int NOT NULL,
  `question_id` int NOT NULL,
  `answer` text
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- Two complete reviews of submission 5 and one INCOMPLETE review of
-- submission 20 (one answer of the two questions on form 7), which is the
-- shape of the two real incomplete 2024 reviews.
INSERT INTO `reviews` (`id`, `submission_id`, `reviewer_id`, `question_id`, `answer`) VALUES
(1, 5, 16, 50, '4'),
(2, 5, 16, 51, '5'),
(3, 5, 17, 50, '3'),
(4, 5, 17, 51, '2'),
(5, 20, 20, 59, '4');

CREATE TABLE `newsletters` (
  `id` int UNSIGNED NOT NULL,
  `email` varchar(255) NOT NULL,
  `date` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- No INSERT: the real table has zero rows and an auto-increment of 4, so the
-- reader has to answer "no rows" rather than "no table".

ALTER TABLE `conferences` ADD PRIMARY KEY (`id`);
ALTER TABLE `users` ADD PRIMARY KEY (`id`), ADD UNIQUE KEY `email` (`email`);
COMMIT;
