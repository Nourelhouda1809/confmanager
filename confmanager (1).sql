-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1
-- Généré le : sam. 23 mai 2026 à 03:50
-- Version du serveur : 10.4.32-MariaDB
-- Version de PHP : 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `confmanager`
--

-- --------------------------------------------------------

--
-- Structure de la table `articles`
--

CREATE TABLE `articles` (
  `id` int(11) NOT NULL,
  `conference_id` int(11) NOT NULL,
  `topic_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `author` varchar(255) NOT NULL,
  `author_institution` varchar(255) DEFAULT NULL,
  `author_email` varchar(255) DEFAULT NULL,
  `submitted_by` int(11) DEFAULT NULL,
  `submission_date` date NOT NULL,
  `status` enum('new','assigned','accepted','rejected','review','revision') DEFAULT 'new',
  `final_decision` enum('accepted','revision','rejected') DEFAULT NULL,
  `final_comment` text DEFAULT NULL,
  `decision_date` timestamp NULL DEFAULT NULL,
  `decided_by` int(11) DEFAULT NULL,
  `abstract` text DEFAULT NULL,
  `keywords` varchar(255) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `reject_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `articles`
--

INSERT INTO `articles` (`id`, `conference_id`, `topic_id`, `title`, `author`, `author_institution`, `author_email`, `submitted_by`, `submission_date`, `status`, `final_decision`, `final_comment`, `decision_date`, `decided_by`, `abstract`, `keywords`, `file_path`, `assigned_to`, `reject_reason`, `created_at`) VALUES
(5, 14, 10, 'Microservices Architecture for Scalable Applications', 'ines.mostafaoui', 'hassiba ben bouali', 'C@gmail.com', NULL, '2026-05-23', 'assigned', NULL, NULL, NULL, NULL, 'This paper presents a scalable microservices architecture designed for modern distributed applications using Docker and Kubernetes.', '\"Microservices\",       \"Docker\",       \"Kubernetes\",       \"Scalability\"', 'articles/art_6a1103eb1c33b9.90548020.pdf', NULL, NULL, '2026-05-23 01:33:31'),
(6, 14, 10, 'Agile Development in Distributed Teams', 'ines.mostafaoui', '', 'C@gmail.com', NULL, '2026-05-23', 'new', NULL, NULL, NULL, NULL, 'The article studies Agile methodologies in remote software teams and analyses collaboration challenges and productivity factors.', '\"Agile\",       \"Scrum\",       \"Distributed Teams\",       \"Project Management\"', 'articles/art_6a11056e9a0c67.39738629.pdf', NULL, NULL, '2026-05-23 01:39:58');

-- --------------------------------------------------------

--
-- Structure de la table `article_reviewers`
--

CREATE TABLE `article_reviewers` (
  `id` int(11) NOT NULL,
  `article_id` int(11) NOT NULL,
  `evaluator_id` int(11) NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `article_reviewers`
--

INSERT INTO `article_reviewers` (`id`, `article_id`, `evaluator_id`, `assigned_at`, `completed_at`) VALUES
(2, 5, 12, '2026-05-23 01:36:21', NULL);

-- --------------------------------------------------------

--
-- Structure de la table `article_revisions`
--

CREATE TABLE `article_revisions` (
  `id` int(11) NOT NULL,
  `article_id` int(11) NOT NULL,
  `submitted_by` int(11) NOT NULL,
  `revision_message` text DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `conferences`
--

CREATE TABLE `conferences` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name_fr` varchar(255) NOT NULL,
  `name_en` varchar(255) NOT NULL,
  `type` enum('Conférence','Séminaire','Colloque') DEFAULT 'Conférence',
  `disciplines` varchar(255) DEFAULT NULL,
  `organizer` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `submission_start_date` date NOT NULL,
  `submission_deadline` date NOT NULL,
  `review_start_date` date NOT NULL,
  `review_end_date` date NOT NULL,
  `requirements` text DEFAULT NULL,
  `max_articles` int(11) DEFAULT 40,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `conferences`
--

INSERT INTO `conferences` (`id`, `user_id`, `name_fr`, `name_en`, `type`, `disciplines`, `organizer`, `location`, `start_date`, `end_date`, `submission_start_date`, `submission_deadline`, `review_start_date`, `review_end_date`, `requirements`, `max_articles`, `created_at`) VALUES
(13, 14, 'Conférence Internationale sur l’Intelligence Artificielle', 'International Conference on Artificial Intelligence', 'Conférence', 'Artificial Intelligence, Machine Learning, Data Science', 'University of Oran 1', 'Oran, Algeria', '2026-06-20', '2026-06-22', '2026-02-01', '2026-04-15', '2026-04-20', '2026-05-20', 'Articles must be original and written in English. Papers will be indexed in IEEE Xplore.', 40, '2026-05-23 01:19:26'),
(14, 14, 'Conférence Internationale sur le Génie Logiciel', 'International Conference on Software Engineering', 'Conférence', 'Software Engineering, Web Development, Cloud Computing', 'University of Constantine 2', 'Constantine, Algeria', '2026-09-15', '2026-09-17', '2026-05-01', '2026-07-10', '2026-07-15', '2026-08-15', 'Accepted papers will be published in Springer proceedings.', 40, '2026-05-23 01:23:13'),
(15, 14, 'Conférence Internationale sur le Cloud Computing', 'International Conference on Cloud Computing', 'Conférence', 'Cloud Computing, DevOps, Cyber Security, Distributed Systems', 'USTO Mohamed Boudiaf', 'Oran, Algeria', '2026-11-10', '2026-11-12', '2026-06-01', '2026-08-20', '2026-08-25', '2026-09-25', 'Accepted papers will be published in indexed proceedings. Articles must be written in English and formatted using IEEE template.', 40, '2026-05-23 01:44:19');

-- --------------------------------------------------------

--
-- Structure de la table `conference_topics`
--

CREATE TABLE `conference_topics` (
  `conference_id` int(11) NOT NULL,
  `topic_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `conference_topics`
--

INSERT INTO `conference_topics` (`conference_id`, `topic_id`) VALUES
(13, 9),
(13, 14),
(13, 22),
(13, 23),
(14, 10),
(14, 24),
(14, 25),
(14, 26),
(15, 18),
(15, 19),
(15, 20),
(15, 21);

-- --------------------------------------------------------

--
-- Structure de la table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('accepted','rejected','revision','info') DEFAULT 'info',
  `message` text NOT NULL,
  `article_id` int(11) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `message`, `article_id`, `is_read`, `created_at`) VALUES
(2, 12, 'info', 'Un nouvel article vous a été assigné : Microservices Architecture for Scalable Applications', 5, 0, '2026-05-23 01:36:21');

-- --------------------------------------------------------

--
-- Structure de la table `reviews`
--

CREATE TABLE `reviews` (
  `id` int(11) NOT NULL,
  `article_id` int(11) NOT NULL,
  `evaluator_id` int(11) NOT NULL,
  `comment` text NOT NULL,
  `recommendation` enum('accept','minor_revision','major_revision','reject') NOT NULL,
  `originality` tinyint(1) DEFAULT NULL COMMENT 'Score 1-5',
  `relevance` tinyint(1) DEFAULT NULL COMMENT 'Score 1-5',
  `clarity` tinyint(1) DEFAULT NULL COMMENT 'Score 1-5',
  `score_global` decimal(3,2) DEFAULT NULL COMMENT 'Moyenne calculée',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `topics`
--

CREATE TABLE `topics` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `topics`
--

INSERT INTO `topics` (`id`, `name`, `created_at`) VALUES
(1, 'Informatique', '2026-05-09 01:53:10'),
(2, 'Intelligence Artificielle', '2026-05-09 01:53:10'),
(3, 'Robotique', '2026-05-09 01:53:10'),
(4, 'Linguistique', '2026-05-09 01:53:10'),
(5, 'Langue arabe', '2026-05-09 01:53:10'),
(6, 'Éducation', '2026-05-09 01:53:10'),
(7, 'IoT', '2026-05-09 01:53:10'),
(8, 'Sécurité informatique', '2026-05-09 01:53:10'),
(9, 'Big Data', '2026-05-09 01:53:10'),
(10, 'Cloud Computing', '2026-05-09 01:53:10'),
(11, 'Ingénierie', '2026-05-09 01:53:10'),
(12, 'Mathématiques', '2026-05-09 01:53:10'),
(13, 'Machine Learning', '2026-05-09 04:35:30'),
(14, 'Deep Learning', '2026-05-09 04:35:55'),
(15, 'NLP', '2026-05-09 04:36:52'),
(16, 'Cryptography', '2026-05-09 04:40:04'),
(17, 'Ethical Hacking', '2026-05-09 04:40:26'),
(18, 'Cloud Infrastructure', '2026-05-23 01:45:05'),
(19, 'Docker & Kubernetes', '2026-05-23 01:45:32'),
(20, 'DevOps Automation', '2026-05-23 01:45:49'),
(21, 'Distributed Systems', '2026-05-23 01:46:06'),
(22, 'Natural Language Processing', '2026-05-23 01:46:52'),
(23, 'Computer Vision', '2026-05-23 01:47:16'),
(24, 'DevOps', '2026-05-23 01:48:09'),
(25, 'Microservices', '2026-05-23 01:48:30'),
(26, 'Agile Development', '2026-05-23 01:48:49');

-- --------------------------------------------------------

--
-- Structure de la table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `cover` varchar(255) DEFAULT NULL,
  `twitter` varchar(100) DEFAULT NULL,
  `linkedin` varchar(100) DEFAULT NULL,
  `facebook` varchar(100) DEFAULT NULL,
  `researchgate` varchar(100) DEFAULT NULL,
  `specialties` text DEFAULT NULL,
  `two_factor_enabled` tinyint(1) DEFAULT 0,
  `password` varchar(255) NOT NULL,
  `role` enum('chercheur','gestionnaire','reviewer') NOT NULL DEFAULT 'chercheur',
  `status` enum('active','pending','blocked') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `labo` varchar(255) DEFAULT NULL,
  `grade` varchar(100) DEFAULT NULL,
  `keywords` text DEFAULT NULL,
  `institution` varchar(255) DEFAULT NULL,
  `department` varchar(255) DEFAULT NULL,
  `reviewer_code` varchar(100) DEFAULT NULL,
  `service` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `users`
--

INSERT INTO `users` (`id`, `first_name`, `last_name`, `username`, `email`, `phone`, `location`, `bio`, `avatar`, `cover`, `twitter`, `linkedin`, `facebook`, `researchgate`, `specialties`, `two_factor_enabled`, `password`, `role`, `status`, `created_at`, `labo`, `grade`, `keywords`, `institution`, `department`, `reviewer_code`, `service`) VALUES
(12, 'aziz', 'sadeg', 'aziz.sadeg', 'R@gmail.com', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, '$2y$10$NwJrWCTSF9on2O2D3ZNrE./Ba6rB7PMhdeeBgpubHQSQ8qddAq9a2', 'reviewer', 'active', '2026-05-23 00:56:01', NULL, NULL, NULL, NULL, NULL, 'REVIEWER123', NULL),
(13, 'Ines', 'mostafaoui', 'ines.mostafaoui', 'C@gmail.com', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, '$2y$10$d9XeqHA2IsFv/3mayf0j4OZxLOHojPyNvvrJsgDaWqZkKr9qASBG6', 'chercheur', 'active', '2026-05-23 00:59:01', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(14, 'Nourelhouda', 'Bendoukha', 'Nourelhouda.Bendoukha', 'G@gmail.com', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, '$2y$10$KhXvz2avxh6bvpZrQHBxHOwhfownLXtIUWv24Ti7oqbNllpOWTgji', 'gestionnaire', 'active', '2026-05-23 01:00:18', NULL, NULL, NULL, NULL, NULL, NULL, NULL);

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `articles`
--
ALTER TABLE `articles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `conference_id` (`conference_id`),
  ADD KEY `topic_id` (`topic_id`),
  ADD KEY `decided_by` (`decided_by`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `submitted_by` (`submitted_by`);

--
-- Index pour la table `article_reviewers`
--
ALTER TABLE `article_reviewers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_article_evaluator` (`article_id`,`evaluator_id`),
  ADD KEY `article_id` (`article_id`),
  ADD KEY `evaluator_id` (`evaluator_id`);

--
-- Index pour la table `article_revisions`
--
ALTER TABLE `article_revisions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `article_id` (`article_id`),
  ADD KEY `submitted_by` (`submitted_by`);

--
-- Index pour la table `conferences`
--
ALTER TABLE `conferences`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Index pour la table `conference_topics`
--
ALTER TABLE `conference_topics`
  ADD PRIMARY KEY (`conference_id`,`topic_id`),
  ADD KEY `topic_id` (`topic_id`);

--
-- Index pour la table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `article_id` (`article_id`);

--
-- Index pour la table `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`id`),
  ADD KEY `article_id` (`article_id`),
  ADD KEY `evaluator_id` (`evaluator_id`);

--
-- Index pour la table `topics`
--
ALTER TABLE `topics`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Index pour la table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `articles`
--
ALTER TABLE `articles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT pour la table `article_reviewers`
--
ALTER TABLE `article_reviewers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT pour la table `article_revisions`
--
ALTER TABLE `article_revisions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `conferences`
--
ALTER TABLE `conferences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT pour la table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT pour la table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `topics`
--
ALTER TABLE `topics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT pour la table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `articles`
--
ALTER TABLE `articles`
  ADD CONSTRAINT `articles_ibfk_1` FOREIGN KEY (`conference_id`) REFERENCES `conferences` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `articles_ibfk_2` FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `articles_ibfk_3` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `articles_ibfk_4` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `articles_ibfk_5` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `article_reviewers`
--
ALTER TABLE `article_reviewers`
  ADD CONSTRAINT `article_reviewers_ibfk_1` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `article_reviewers_ibfk_2` FOREIGN KEY (`evaluator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `article_revisions`
--
ALTER TABLE `article_revisions`
  ADD CONSTRAINT `article_revisions_ibfk_1` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `article_revisions_ibfk_2` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `conferences`
--
ALTER TABLE `conferences`
  ADD CONSTRAINT `conferences_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `conference_topics`
--
ALTER TABLE `conference_topics`
  ADD CONSTRAINT `conference_topics_ibfk_1` FOREIGN KEY (`conference_id`) REFERENCES `conferences` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `conference_topics_ibfk_2` FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `reviews`
--
ALTER TABLE `reviews`
  ADD CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reviews_ibfk_2` FOREIGN KEY (`evaluator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
