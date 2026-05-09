-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1
-- Généré le : sam. 09 mai 2026 à 06:59
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
  `submission_date` date NOT NULL,
  `status` enum('new','assigned','accepted','rejected','review') DEFAULT 'new',
  `abstract` text DEFAULT NULL,
  `keywords` varchar(255) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `assigned_to` varchar(255) DEFAULT NULL,
  `reject_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
(11, 1, 'Conférence Internationale sur l’Intelligence Artificielle', 'International Conference on Artificial Intelligence', 'Conférence', 'Artificial Intelligence, Computer Science, Data Science', 'University of Oran', 'Oran, Algeria', '2026-09-15', '2026-09-17', '2026-05-20', '2026-07-10', '2026-07-15', '2026-08-20', 'Articles must be original and written in English. Maximum 8 pages.', 40, '2026-05-09 03:57:32'),
(12, 1, 'Séminaire National sur la Cybersécurité', 'National Seminar on Cybersecurity', 'Conférence', 'Cybersecurity, Networks, Information Systems', 'Hassiba Benbouali University', 'Chlef, Algeria', '2026-11-05', '2026-11-08', '2026-07-01', '2026-09-01', '2026-09-05', '2026-10-01', 'PDF format only, plagiarism rate below 15%.', 40, '2026-05-09 04:39:14');

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
(11, 2),
(11, 13),
(11, 15),
(12, 8),
(12, 16),
(12, 17);

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
(17, 'Ethical Hacking', '2026-05-09 04:40:26');

-- --------------------------------------------------------

--
-- Structure de la table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
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

INSERT INTO `users` (`id`, `first_name`, `last_name`, `email`, `password`, `role`, `status`, `created_at`, `labo`, `grade`, `keywords`, `institution`, `department`, `reviewer_code`, `service`) VALUES
(1, 'Nourelhouda', 'Bendoukha', 'benhouda1809@gmail.com', '$2y$10$uKqN4.dEPeG9vIgzuTuEh.ZelXO9ppIoY92Y1mWoAo1cMN.1kJy7.', 'gestionnaire', 'active', '2026-05-08 22:28:04', 'LISIA', 'Doctorant', 'cybersécurité ', NULL, NULL, NULL, NULL),
(4, 'Ines', 'mostafaoui', 'ines.mostafaoui@gmail.com', '$2y$10$qLOz0aVJeTGaZ1FFc.5ieu8MOojLR7pKxBI9MQEbktV2RXucedxS6', 'chercheur', 'active', '2026-05-09 04:19:33', 'LISIA', 'Doctorant', 'cybersécurité ', NULL, NULL, NULL, NULL),
(6, 'aziz', 'sadeg', 'aziz.Sadeg@gmail.com', '$2y$10$7gO.OszjagOcSdUhfGvbpuR1KMimzGg6AtcAAOTmLSwCpkxv2ui6e', 'reviewer', 'active', '2026-05-09 04:51:44', NULL, NULL, NULL, NULL, NULL, 'REVIEWER123', 'Informatique');

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `articles`
--
ALTER TABLE `articles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `conference_id` (`conference_id`),
  ADD KEY `topic_id` (`topic_id`);

--
-- Index pour la table `article_reviewers`
--
ALTER TABLE `article_reviewers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_article_evaluator` (`article_id`,`evaluator_id`),
  ADD KEY `article_id` (`article_id`),
  ADD KEY `evaluator_id` (`evaluator_id`);

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
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `articles`
--
ALTER TABLE `articles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `article_reviewers`
--
ALTER TABLE `article_reviewers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `conferences`
--
ALTER TABLE `conferences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT pour la table `topics`
--
ALTER TABLE `topics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT pour la table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `articles`
--
ALTER TABLE `articles`
  ADD CONSTRAINT `articles_ibfk_1` FOREIGN KEY (`conference_id`) REFERENCES `conferences` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `articles_ibfk_2` FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `article_reviewers`
--
ALTER TABLE `article_reviewers`
  ADD CONSTRAINT `article_reviewers_ibfk_1` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `article_reviewers_ibfk_2` FOREIGN KEY (`evaluator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

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
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
