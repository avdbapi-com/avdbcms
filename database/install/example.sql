/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19-11.4.5-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: avdbcms
-- ------------------------------------------------------
-- Server version	11.4.5-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;

--
-- Table structure for table `activities`
--

DROP TABLE IF EXISTS `activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `subject_type` varchar(255) NOT NULL,
  `subject_id` bigint(20) unsigned NOT NULL,
  `perform_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `users_activity_user_id_foreign` (`user_id`),
  KEY `users_activity_subject_type_subject_id_index` (`subject_type`,`subject_id`),
  CONSTRAINT `users_activity_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activities`
--

LOCK TABLES `activities` WRITE;
/*!40000 ALTER TABLE `activities` DISABLE KEYS */;
/*!40000 ALTER TABLE `activities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_audit_logs`
--

DROP TABLE IF EXISTS `admin_audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(150) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `admin_audit_logs_user_id_foreign` (`user_id`),
  KEY `admin_audit_logs_created_at_index` (`created_at`),
  CONSTRAINT `admin_audit_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_audit_logs`
--

LOCK TABLES `admin_audit_logs` WRITE;
/*!40000 ALTER TABLE `admin_audit_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `admin_audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `announcements`
--

DROP TABLE IF EXISTS `announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `announcements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'info',
  `position` varchar(20) NOT NULL DEFAULT 'top',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `starts_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `announcements_is_active_index` (`is_active`),
  KEY `announcements_starts_at_index` (`starts_at`),
  KEY `announcements_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `announcements`
--

LOCK TABLES `announcements` WRITE;
/*!40000 ALTER TABLE `announcements` DISABLE KEYS */;
/*!40000 ALTER TABLE `announcements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `avdb_import_runs`
--

DROP TABLE IF EXISTS `avdb_import_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `avdb_import_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `status` varchar(20) NOT NULL DEFAULT 'queued',
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings`)),
  `imported` int(10) unsigned NOT NULL DEFAULT 0,
  `skipped` int(10) unsigned NOT NULL DEFAULT 0,
  `updated` int(10) unsigned NOT NULL DEFAULT 0,
  `failed` int(10) unsigned NOT NULL DEFAULT 0,
  `lang_skipped` int(10) unsigned NOT NULL DEFAULT 0,
  `tags_created` int(10) unsigned NOT NULL DEFAULT 0,
  `thumbs_downloaded` int(10) unsigned NOT NULL DEFAULT 0,
  `total_pages` int(10) unsigned NOT NULL DEFAULT 0,
  `completed_pages` int(10) unsigned NOT NULL DEFAULT 0,
  `progress` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `current_category` tinyint(3) unsigned DEFAULT NULL,
  `current_page` int(10) unsigned DEFAULT NULL,
  `message` varchar(500) DEFAULT NULL,
  `log` longtext DEFAULT NULL,
  `cancel_requested` tinyint(1) NOT NULL DEFAULT 0,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `avdb_import_runs_status_index` (`status`),
  KEY `avdb_import_runs_cancel_requested_index` (`cancel_requested`)
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `avdb_import_runs`
--

LOCK TABLES `avdb_import_runs` WRITE;
/*!40000 ALTER TABLE `avdb_import_runs` DISABLE KEYS */;
/*!40000 ALTER TABLE `avdb_import_runs` ENABLE KEYS */;
UNLOCK TABLES;

DROP TABLE IF EXISTS `xvid_import_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `xvid_import_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `status` varchar(20) NOT NULL DEFAULT 'queued',
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings`)),
  `imported` int(10) unsigned NOT NULL DEFAULT 0,
  `skipped` int(10) unsigned NOT NULL DEFAULT 0,
  `updated` int(10) unsigned NOT NULL DEFAULT 0,
  `failed` int(10) unsigned NOT NULL DEFAULT 0,
  `tags_created` int(10) unsigned NOT NULL DEFAULT 0,
  `thumbs_downloaded` int(10) unsigned NOT NULL DEFAULT 0,
  `total_pages` int(10) unsigned NOT NULL DEFAULT 0,
  `completed_pages` int(10) unsigned NOT NULL DEFAULT 0,
  `progress` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `current_category` varchar(100) DEFAULT NULL,
  `current_page` int(10) unsigned DEFAULT NULL,
  `message` varchar(500) DEFAULT NULL,
  `log` longtext DEFAULT NULL,
  `cancel_requested` tinyint(1) NOT NULL DEFAULT 0,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `xvid_import_runs_status_index` (`status`),
  KEY `xvid_import_runs_cancel_requested_index` (`cancel_requested`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `xvid_import_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `xvid_import_schedules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `expression` varchar(100) NOT NULL DEFAULT '0 3 * * *',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `categories` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`categories`)),
  `pages` int(10) unsigned NOT NULL DEFAULT 1,
  `start_page` int(10) unsigned NOT NULL DEFAULT 1,
  `pagesize` int(10) unsigned NOT NULL DEFAULT 50,
  `delay` int(10) unsigned NOT NULL DEFAULT 0,
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings`)),
  `last_run_at` timestamp NULL DEFAULT NULL,
  `next_run_at` timestamp NULL DEFAULT NULL,
  `last_run_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `xvid_import_schedules_is_active_index` (`is_active`),
  KEY `xvid_import_schedules_last_run_id_foreign` (`last_run_id`),
  CONSTRAINT `xvid_import_schedules_last_run_id_foreign` FOREIGN KEY (`last_run_id`) REFERENCES `xvid_import_runs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `avdb_import_schedules`
--

DROP TABLE IF EXISTS `avdb_import_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `avdb_import_schedules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `frequency` varchar(24) NOT NULL DEFAULT 'daily',
  `run_time` time DEFAULT NULL,
  `weekday` tinyint(3) unsigned DEFAULT NULL,
  `month_day` tinyint(3) unsigned DEFAULT NULL,
  `interval_minutes` int(10) unsigned DEFAULT NULL,
  `skip_if_active` tinyint(1) NOT NULL DEFAULT 1,
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`settings`)),
  `last_run_at` timestamp NULL DEFAULT NULL,
  `last_run_id` bigint(20) unsigned DEFAULT NULL,
  `last_status` varchar(32) DEFAULT NULL,
  `last_message` text DEFAULT NULL,
  `next_run_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `avdb_import_schedules_last_run_id_foreign` (`last_run_id`),
  KEY `avdb_import_schedules_enabled_next_run_at_index` (`enabled`,`next_run_at`),
  KEY `avdb_import_schedules_next_run_at_index` (`next_run_at`),
  CONSTRAINT `avdb_import_schedules_last_run_id_foreign` FOREIGN KEY (`last_run_id`) REFERENCES `avdb_import_runs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `avdb_import_schedules`
--

LOCK TABLES `avdb_import_schedules` WRITE;
/*!40000 ALTER TABLE `avdb_import_schedules` DISABLE KEYS */;
/*!40000 ALTER TABLE `avdb_import_schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) DEFAULT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `background` varchar(255) DEFAULT NULL,
  `in_menu` tinyint(1) NOT NULL DEFAULT 0,
  `position` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES
(1,'Censored','censored',NULL,NULL,1,1,'2026-05-01 07:35:48','2026-05-01 07:35:48'),
(2,'Uncensored','uncensored',NULL,NULL,1,2,'2026-05-01 07:35:48','2026-05-01 07:35:48'),
(3,'Uncensored Leaked','uncensored-leaked',NULL,NULL,1,3,'2026-05-01 07:35:48','2026-05-01 07:35:48'),
(4,'Amateur','amateur',NULL,NULL,1,4,'2026-05-01 07:35:48','2026-05-01 07:35:48'),
(5,'Chinese AV','chinese-av',NULL,NULL,1,5,'2026-05-01 07:35:48','2026-05-01 07:35:48'),
(6,'Hentai','hentai',NULL,NULL,1,6,'2026-05-01 07:35:48','2026-05-01 07:35:48'),
(7,'English Subtitle','english-subtitle',NULL,NULL,1,7,'2026-05-01 07:35:48','2026-05-01 07:35:48');
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `comments`
--

DROP TABLE IF EXISTS `comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `comments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `video_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `content` longtext NOT NULL,
  `ip` varchar(45) NOT NULL,
  `banned_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `comments_video_id_foreign` (`video_id`),
  KEY `comments_user_id_foreign` (`user_id`),
  KEY `comments_parent_id_foreign` (`parent_id`),
  KEY `comments_video_parent_created_idx` (`video_id`,`parent_id`,`created_at`,`id`),
  KEY `comments_user_created_idx` (`user_id`,`created_at`,`id`),
  CONSTRAINT `comments_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `comments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `comments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `comments_video_id_foreign` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `comments`
--

LOCK TABLES `comments` WRITE;
/*!40000 ALTER TABLE `comments` DISABLE KEYS */;
/*!40000 ALTER TABLE `comments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contact_messages`
--

DROP TABLE IF EXISTS `contact_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `message` text NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_messages_is_read_index` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contact_messages`
--

LOCK TABLES `contact_messages` WRITE;
/*!40000 ALTER TABLE `contact_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `contact_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `custom_pages`
--

DROP TABLE IF EXISTS `custom_pages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `custom_pages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `slug` varchar(200) NOT NULL,
  `content` longtext DEFAULT NULL,
  `meta_title` varchar(200) DEFAULT NULL,
  `meta_description` varchar(500) DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 0,
  `show_in_footer` tinyint(1) NOT NULL DEFAULT 0,
  `show_in_header` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `custom_pages_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `custom_pages`
--

LOCK TABLES `custom_pages` WRITE;
/*!40000 ALTER TABLE `custom_pages` DISABLE KEYS */;
/*!40000 ALTER TABLE `custom_pages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exports`
--

DROP TABLE IF EXISTS `exports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `exports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `file` varchar(255) DEFAULT NULL,
  `size` int(10) unsigned DEFAULT NULL COMMENT 'size in bytes',
  `status` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exports`
--

LOCK TABLES `exports` WRITE;
/*!40000 ALTER TABLE `exports` DISABLE KEYS */;
/*!40000 ALTER TABLE `exports` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `favorites_playlist`
--

DROP TABLE IF EXISTS `favorites_playlist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `favorites_playlist` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `playlist_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `favorites_playlist_playlist_id_foreign` (`playlist_id`),
  KEY `favorites_playlist_user_id_foreign` (`user_id`),
  CONSTRAINT `favorites_playlist_playlist_id_foreign` FOREIGN KEY (`playlist_id`) REFERENCES `playlists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `favorites_playlist_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `favorites_playlist`
--

LOCK TABLES `favorites_playlist` WRITE;
/*!40000 ALTER TABLE `favorites_playlist` DISABLE KEYS */;
/*!40000 ALTER TABLE `favorites_playlist` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `featured_content`
--

DROP TABLE IF EXISTS `featured_content`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `featured_content` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('video','user','category','banner') NOT NULL DEFAULT 'video',
  `video_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(200) DEFAULT NULL,
  `description` varchar(500) DEFAULT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `link_url` varchar(500) DEFAULT NULL,
  `position` varchar(50) NOT NULL DEFAULT 'homepage_hero',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `starts_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `featured_content_position_is_active_sort_order_index` (`position`,`is_active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `featured_content`
--

LOCK TABLES `featured_content` WRITE;
/*!40000 ALTER TABLE `featured_content` DISABLE KEYS */;
/*!40000 ALTER TABLE `featured_content` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `interactions`
--

DROP TABLE IF EXISTS `interactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `interactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `likeable_type` varchar(255) NOT NULL,
  `likeable_id` bigint(20) unsigned NOT NULL,
  `status` tinyint(1) NOT NULL,
  `perform_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `interactions_user_id_foreign` (`user_id`),
  KEY `interactions_likeable_type_likeable_id_index` (`likeable_type`,`likeable_id`),
  KEY `interactions_likeable_status_idx` (`likeable_type`,`likeable_id`,`status`,`id`),
  KEY `interactions_user_likeable_idx` (`user_id`,`likeable_type`,`likeable_id`),
  KEY `interactions_likeable_perform_idx` (`likeable_type`,`likeable_id`,`perform_at`),
  CONSTRAINT `interactions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `interactions`
--

LOCK TABLES `interactions` WRITE;
/*!40000 ALTER TABLE `interactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `interactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ip_bans`
--

DROP TABLE IF EXISTS `ip_bans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ip_bans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `reason` text DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_bans_ip_address_unique` (`ip_address`),
  KEY `ip_bans_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ip_bans`
--

LOCK TABLES `ip_bans` WRITE;
/*!40000 ALTER TABLE `ip_bans` DISABLE KEYS */;
/*!40000 ALTER TABLE `ip_bans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB AUTO_INCREMENT=758 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `languages`
--

DROP TABLE IF EXISTS `languages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `languages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL,
  `label` varchar(100) NOT NULL,
  `emoji` varchar(20) NOT NULL DEFAULT '?',
  `direction` varchar(3) NOT NULL DEFAULT 'ltr',
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `languages_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `languages`
--

LOCK TABLES `languages` WRITE;
/*!40000 ALTER TABLE `languages` DISABLE KEYS */;
INSERT INTO `languages` VALUES
(1,'en','English','🇬🇧','ltr',1,1,0,'2026-04-25 07:17:05','2026-04-25 07:17:05'),
(2,'fr','Français','🇫🇷','ltr',0,1,1,'2026-04-25 07:17:05','2026-04-25 07:17:05'),
(3,'th','ไทย','🇹🇭','ltr',0,1,2,'2026-04-25 07:17:05','2026-04-25 07:17:05'),
(4,'id','Indonesia','🇮🇩','ltr',0,1,3,'2026-04-25 07:17:05','2026-04-25 07:17:05'),
(5,'ko','한국어','🇰🇷','ltr',0,1,4,'2026-04-25 07:17:05','2026-04-25 07:17:05'),
(6,'vi','Tiếng Việt','🇻🇳','ltr',0,1,5,'2026-04-25 07:17:05','2026-04-25 07:17:05'),
(7,'zh','中文','🇨🇳','ltr',0,1,6,'2026-04-25 07:17:05','2026-04-25 07:17:05'),
(8,'ja','日本語','🇯🇵','ltr',0,1,7,'2026-04-25 07:17:05','2026-04-25 07:17:05'),
(9,'de','Deutsch','🇩🇪','ltr',0,1,8,'2026-04-25 07:17:05','2026-04-25 07:17:05'),
(10,'es','Español','🇪🇸','ltr',0,1,9,'2026-04-25 07:17:05','2026-04-25 07:17:05'),
(11,'pt','Português','🇵🇹','ltr',0,1,10,'2026-04-25 07:17:05','2026-04-25 07:17:05'),
(12,'ru','Русский','🇷🇺','ltr',0,1,11,'2026-04-25 07:17:05','2026-04-25 07:17:05'),
(13,'ar','العربية','🇸🇦','ltr',0,1,12,'2026-04-25 07:17:05','2026-04-25 07:17:05'),
(14,'tr','Türkçe','🇹🇷','ltr',0,1,13,'2026-04-25 07:17:05','2026-04-25 07:17:05'),
(15,'hi','हिन्दी','🇮🇳','ltr',0,1,14,'2026-04-25 07:17:05','2026-04-25 07:17:05'),
(16,'ms','Melayu','🇲🇾','ltr',0,1,15,'2026-04-25 07:17:05','2026-04-25 07:17:05'),
(17,'tl','Filipino','🇵🇭','ltr',0,1,16,'2026-04-25 07:17:05','2026-04-25 07:17:05');
/*!40000 ALTER TABLE `languages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=160 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES
(1,'2014_10_12_000000_create_users_table',1),
(2,'2014_10_12_100000_create_password_resets_table',1),
(3,'2019_12_14_000001_create_personal_access_tokens_table',1),
(4,'2022_01_05_160308_create_categories_table',1),
(5,'2022_01_14_212030_create_reports_table',1),
(6,'2022_10_30_172213_create_videos_table',1),
(7,'2022_11_07_112856_create_subscriptions_table',1),
(8,'2022_11_08_141005_create_comments_table',1),
(9,'2022_11_09_120529_create_interactions_table',1),
(10,'2022_12_20_184208_create_views_table',1),
(11,'2022_12_24_001259_create_activity_log_table',1),
(12,'2023_02_21_142853_create_playlists_table',1),
(13,'2023_02_21_144015_create_notifications_table',1),
(14,'2023_04_18_231421_create_exports_table',1),
(15,'2023_04_19_113720_create_jobs_table',1),
(16,'2023_04_19_115214_create_failed_jobs_table',1),
(17,'2023_07_20_123937_add_stripe_to_users',1),
(18,'2023_07_20_124122_create_plans_table',1),
(19,'2023_07_20_124422_create_premium_subscriptions_table',1),
(20,'2023_07_20_125241_create_transactions_table',1),
(21,'2023_08_08_172612_add_size_to_videos',1),
(22,'2023_08_21_134835_add_social_id_to_users',1),
(23,'2023_11_20_152715_update_videos_table',1),
(24,'2023_12_19_173610_add_youtube_id_to_videos',2),
(25,'2024_01_03_131739_add_card_information_to_premium_subscriptions',3),
(26,'2018_08_08_100000_create_telescope_entries_table',4),
(27,'2024_01_17_144111_add_slug_to_videos',5),
(30,'2024_01_20_202136_add_index_to_uuid_videos',6),
(31,'2024_01_21_161714_create_users_activity_table',7),
(32,'2024_01_23_132636_create_searches_table',8),
(34,'2024_02_12_131517_create_subtitles_table',9),
(35,'2024_02_20_114802_create_notifications_table',10),
(38,'2024_04_05_170244_add_is_short_to_videos',13),
(40,'2024_04_26_145839_add_slug_to_users',14),
(41,'2024_05_10_132727_create_thumbnails_table',15),
(46,'2024_05_28_224724_add_size_to_exports_table',16),
(47,'2024_07_25_161943_add_sort_to_playlists_table',17),
(55,'2025_02_04_150827_rename_premium_subscriptions_table',18),
(56,'2025_05_19_215255_add_phone_to_users',19),
(58,'2025_06_28_171459_create_password_reset_tokens_table',20),
(59,'2025_06_29_201902_create_job_batches_table',21),
(60,'2025_07_20_200646_add_views_to_videos',22),
(61,'2025_07_24_223719_add_user_agent_for_views',23),
(62,'2025_08_08_115508_add_is_deletable_to_playlists_table',24),
(63,'2025_09_02_184427_rename_dates_videos_table',25),
(64,'2025_09_19_223932_add_soft_delete',26),
(65,'2025_11_28_180234_add_soft_delete_views_table',27),
(66,'2025_12_30_133811_add_sort_to_playlists_table',27),
(67,'2026_01_07_155649_add_show_ad_field_to_videos',27),
(68,'2026_01_18_175313_add_ip_field_to_search',27),
(69,'2026_01_19_175239_update_reason_field_type',27),
(70,'2026_01_20_215019_rename_table_users_activity',27),
(71,'2026_01_23_155514_add_language_to_users_table',27),
(72,'2026_04_20_195519_create_settings_table',27),
(73,'2026_04_21_053347_create_featured_content_table',27),
(74,'2026_04_21_054438_create_custom_pages_table',27),
(75,'2026_04_22_073651_create_languages_table',27),
(76,'2026_04_23_114643_create_jobs_table',27),
(77,'2026_04_23_170000_add_upload18_fields_to_videos',27),
(78,'2026_04_25_192900_create_admin_utility_tables',28),
(79,'2026_04_26_150000_add_paypal_gateway_fields',29),
(80,'2026_04_26_160000_normalize_legacy_plan_durations',30),
(81,'2026_04_26_170000_add_large_scale_lookup_indexes',31),
(82,'2026_04_26_171000_add_large_scale_view_indexes',32),
(83,'2026_04_26_172000_add_large_scale_feed_and_view_indexes',33),
(84,'2026_04_26_173000_add_large_scale_search_indexes',34),
(85,'2026_04_26_174000_add_large_scale_counter_and_playlist_indexes',35),
(86,'2014_10_11_000000_create_missing_legacy_base_tables',36),
(87,'2026_04_27_010000_add_hot_cursor_and_cleanup_indexes',36),
(88,'2026_04_27_020000_seed_smart_discovery_settings',36),
(89,'2026_04_27_030000_seed_search_history_retention_settings',36),
(90,'2026_04_27_031000_add_notification_and_search_retention_indexes',36),
(91,'2026_04_27_032000_seed_broadcast_notification_settings',36),
(92,'2026_04_27_033000_seed_creator_spotlight_settings',36),
(93,'2026_04_27_034000_add_notification_broadcast_target_indexes',36),
(94,'2026_04_27_035000_seed_trending_collections_settings',36),
(95,'2026_04_27_036000_seed_watch_momentum_digest_settings',36),
(96,'2026_04_27_037000_add_watch_momentum_subscription_index',36),
(97,'2026_04_27_038000_seed_creator_quality_pulse_settings',36),
(98,'2026_04_27_039000_seed_studio_health_alerts_settings',36),
(99,'2026_04_27_040000_seed_creator_recovery_checklist_settings',36),
(100,'2026_04_27_041000_seed_viewer_continue_queue_settings',36),
(101,'2026_04_27_042000_create_playback_progress_signals_and_settings',36),
(102,'2026_04_27_043000_add_admin_dashboard_explain_indexes',36),
(103,'2026_04_27_044000_seed_appearance_experience_controls',37),
(104,'2026_04_27_231500_seed_home_widget_motion_settings',37),
(105,'2026_04_27_232500_seed_home_widget_autorun_settings',38),
(106,'2026_04_28_010000_remove_autoplay_appearance_settings',39),
(107,'2026_04_28_020000_remove_watch_momentum_and_continue_queue_settings',40),
(109,'2026_04_28_040000_seed_homepage_feed_settings',41),
(110,'2026_04_28_050000_seed_home_widget_video_feed_settings',42),
(111,'2026_04_28_060000_replace_home_feed_rows_with_load_mode',43),
(112,'2026_04_28_070000_remove_home_upload_cta_setting',44),
(113,'2026_04_28_080000_seed_text_accent_appearance_settings',45),
(114,'2026_04_28_090000_remove_player_memory_appearance_setting',46),
(115,'2026_04_28_100000_seed_watch_page_polish_settings',47),
(116,'2026_04_28_110000_seed_watch_page_experience_settings',48),
(117,'2026_04_28_120000_remove_sidebar_upload_button_appearance_setting',49),
(118,'2026_04_28_130000_seed_secondary_page_appearance_settings',50),
(119,'2026_04_28_140000_seed_premium_page_settings',51),
(120,'2026_04_28_150000_add_channel_customization_to_users_and_seed_settings',52),
(121,'2026_04_28_151000_seed_channel_featured_layout_settings',53),
(122,'2026_04_28_152000_seed_channel_home_section_settings',54),
(123,'2026_05_01_174611_create_video_translations_table',55),
(124,'2026_05_02_010000_create_avdb_import_runs_table',56),
(125,'2026_05_02_011000_ensure_avdb_import_columns',56),
(126,'2026_05_02_012000_create_tag_translations_table',57),
(127,'2026_05_02_020000_add_avdb_metadata_to_videos_table',58),
(128,'2026_05_02_030000_add_sitemap_scale_indexes',59),
(129,'2026_05_04_130000_add_avdb_update_tracking_columns',60),
(130,'2026_05_04_131000_add_avdb_content_hash_to_videos_table',61),
(131,'2026_05_05_010000_seed_responsive_interface_appearance_settings',62),
(132,'2026_05_05_020000_hide_premium_downloads_comparison_row',63),
(133,'2026_05_05_030000_add_email_settings_and_verification_toggle',64),
(134,'2026_05_05_000000_rebrand_settings_to_avdbclip',65),
(135,'2026_05_05_040000_align_upload_and_user_settings',65),
(136,'2026_05_05_050000_clean_legacy_settings_and_seed_discovery_copy',66),
(137,'2026_05_05_060000_seed_localization_and_thumbnail_settings',67),
(138,'2026_05_05_070000_remove_legacy_upload_max_size_setting',68),
(139,'2026_05_05_080000_merge_user_password_min_into_validation_setting',69),
(140,'2026_05_05_090000_seed_responsive_test_ads',70),
(141,'2026_05_05_100000_add_feed_specific_ad_settings',71),
(142,'2026_05_05_110000_add_feed_specific_ad_layout_settings',72),
(143,'2026_05_05_120000_add_grid_ad_max_per_feed_setting',73),
(144,'2026_05_05_130000_expand_feed_specific_ad_slots',74),
(145,'2026_05_05_140000_add_high_cpm_ad_formats',75),
(146,'2026_05_05_150000_add_ad_preview_test_feedback',76),
(147,'2026_05_05_160000_remove_overlay_ad_formats',77),
(148,'2026_05_05_170000_remove_push_direct_and_ad_preview_settings',78),
(149,'2026_05_05_180000_add_video_ad_media_and_midroll_settings',79),
(150,'2026_05_05_190000_remove_midroll_and_enable_random_preroll_urls',80),
(151,'2026_05_05_200000_add_captcha_security_settings',81),
(152,'2026_05_05_210000_add_scalable_view_tracking_settings',82),
(153,'2026_05_08_000000_add_avdb_creator_fields_to_users_table',83),
(154,'2026_05_08_010000_normalize_avdb_creator_channel_profiles',84),
(155,'2026_05_09_000000_create_avdb_import_schedules_table',85),
(156,'2026_05_10_000000_add_profile_uid_to_users_table',86),
(157,'2026_05_14_000000_seed_domain_seo_template_settings',87),
(159,'2026_05_15_000000_add_avdb_duration_sync_tracking_to_videos',88);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` char(36) NOT NULL,
  `type` varchar(255) NOT NULL,
  `notifiable_type` varchar(255) NOT NULL,
  `notifiable_id` bigint(20) unsigned NOT NULL,
  `data` text NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`),
  KEY `notifications_notifiable_created_id_idx` (`notifiable_type`,`notifiable_id`,`created_at`,`id`),
  KEY `notifications_unread_count_idx` (`notifiable_type`,`notifiable_id`,`read_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `type` varchar(255) DEFAULT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_id_idx` (`expires_at`,`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plans`
--

DROP TABLE IF EXISTS `plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `plans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `price` int(10) unsigned NOT NULL COMMENT 'in centimes',
  `duration` smallint(6) NOT NULL COMMENT 'in month',
  `stripe_id` varchar(255) DEFAULT NULL,
  `paypal_plan_id` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plans_stripe_id_unique` (`stripe_id`),
  KEY `plans_paypal_plan_id_index` (`paypal_plan_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plans`
--

LOCK TABLES `plans` WRITE;
/*!40000 ALTER TABLE `plans` DISABLE KEYS */;
INSERT INTO `plans` VALUES
(1,'monthly',500,1,NULL,NULL,'2026-04-25 07:17:05','2026-04-25 07:17:05'),
(2,'annually',5000,12,NULL,NULL,'2026-04-25 07:17:05','2026-04-25 07:17:05');
/*!40000 ALTER TABLE `plans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `playback_progress_signals`
--

DROP TABLE IF EXISTS `playback_progress_signals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `playback_progress_signals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `video_id` bigint(20) unsigned NOT NULL,
  `position_seconds` int(10) unsigned NOT NULL,
  `duration_seconds` int(10) unsigned NOT NULL,
  `progress_basis_points` smallint(5) unsigned NOT NULL DEFAULT 0,
  `last_played_at` timestamp NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `playback_progress_user_video_unique` (`user_id`,`video_id`),
  KEY `playback_progress_user_expiry_played_idx` (`user_id`,`expires_at`,`last_played_at`),
  KEY `playback_progress_video_played_idx` (`video_id`,`last_played_at`),
  KEY `playback_progress_expiry_id_idx` (`expires_at`,`id`),
  CONSTRAINT `playback_progress_signals_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `playback_progress_signals_video_id_foreign` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `playback_progress_signals`
--

LOCK TABLES `playback_progress_signals` WRITE;
/*!40000 ALTER TABLE `playback_progress_signals` DISABLE KEYS */;
/*!40000 ALTER TABLE `playback_progress_signals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `playlist_has_videos`
--

DROP TABLE IF EXISTS `playlist_has_videos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `playlist_has_videos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `playlist_id` bigint(20) unsigned NOT NULL,
  `video_id` bigint(20) unsigned NOT NULL,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `playlist_has_videos_playlist_id_foreign` (`playlist_id`),
  KEY `playlist_has_videos_video_id_foreign` (`video_id`),
  KEY `playlist_has_videos_playlist_position_idx` (`playlist_id`,`position`,`id`),
  KEY `playlist_has_videos_playlist_added_idx` (`playlist_id`,`added_at`,`id`),
  KEY `playlist_has_videos_playlist_video_idx` (`playlist_id`,`video_id`),
  CONSTRAINT `playlist_has_videos_playlist_id_foreign` FOREIGN KEY (`playlist_id`) REFERENCES `playlists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `playlist_has_videos_video_id_foreign` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `playlist_has_videos`
--

LOCK TABLES `playlist_has_videos` WRITE;
/*!40000 ALTER TABLE `playlist_has_videos` DISABLE KEYS */;
/*!40000 ALTER TABLE `playlist_has_videos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `playlists`
--

DROP TABLE IF EXISTS `playlists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `playlists` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `status` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `sort` int(11) NOT NULL DEFAULT 5,
  `is_deletable` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `playlists_user_id_foreign` (`user_id`),
  KEY `playlists_uuid_idx` (`uuid`),
  KEY `playlists_user_status_updated_id_idx` (`user_id`,`status`,`updated_at`,`id`),
  CONSTRAINT `playlists_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `playlists`
--

LOCK TABLES `playlists` WRITE;
/*!40000 ALTER TABLE `playlists` DISABLE KEYS */;
/*!40000 ALTER TABLE `playlists` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `premiums`
--

DROP TABLE IF EXISTS `premiums`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `premiums` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_provider` varchar(32) NOT NULL DEFAULT 'stripe',
  `stripe_status` varchar(255) NOT NULL,
  `next_payment` timestamp NULL DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `plan_id` bigint(20) unsigned DEFAULT NULL,
  `stripe_id` varchar(255) DEFAULT NULL,
  `trial_ends_at` timestamp NULL DEFAULT NULL,
  `card_last4` varchar(255) NOT NULL,
  `card_expired_at` date NOT NULL,
  `ends_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `premium_subscriptions_stripe_id_unique` (`stripe_id`),
  KEY `premium_subscriptions_user_id_foreign` (`user_id`),
  KEY `premium_subscriptions_plan_id_foreign` (`plan_id`),
  KEY `premiums_payment_provider_index` (`payment_provider`),
  KEY `premiums_status_ends_user_idx` (`stripe_status`,`ends_at`,`user_id`),
  CONSTRAINT `premium_subscriptions_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `premium_subscriptions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `premiums`
--

LOCK TABLES `premiums` WRITE;
/*!40000 ALTER TABLE `premiums` DISABLE KEYS */;
/*!40000 ALTER TABLE `premiums` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `redirects`
--

DROP TABLE IF EXISTS `redirects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `redirects` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source` varchar(500) NOT NULL,
  `destination` varchar(500) NOT NULL,
  `status_code` smallint(5) unsigned NOT NULL DEFAULT 301,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `hits` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `redirects_source_unique` (`source`),
  KEY `redirects_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `redirects`
--

LOCK TABLES `redirects` WRITE;
/*!40000 ALTER TABLE `redirects` DISABLE KEYS */;
/*!40000 ALTER TABLE `redirects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reports`
--

DROP TABLE IF EXISTS `reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `reportable_type` varchar(255) NOT NULL,
  `reportable_id` bigint(20) unsigned NOT NULL,
  `reason` int(11) NOT NULL,
  `comment` text DEFAULT NULL,
  `status` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `reports_user_id_foreign` (`user_id`),
  KEY `reports_reportable_type_reportable_id_index` (`reportable_type`,`reportable_id`),
  KEY `admin_reports_created_id_idx` (`created_at`,`id`),
  CONSTRAINT `reports_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reports`
--

LOCK TABLES `reports` WRITE;
/*!40000 ALTER TABLE `reports` DISABLE KEYS */;
/*!40000 ALTER TABLE `reports` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `searches`
--

DROP TABLE IF EXISTS `searches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `searches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `query` varchar(255) NOT NULL,
  `ip` varchar(255) DEFAULT NULL,
  `lang` varchar(255) DEFAULT NULL,
  `results` smallint(5) unsigned NOT NULL DEFAULT 0,
  `perform_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `searches_user_id_foreign` (`user_id`),
  KEY `searches_query_perform_at_idx` (`query`,`perform_at`),
  KEY `searches_perform_id_idx` (`perform_at`,`id`),
  CONSTRAINT `searches_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `searches`
--

LOCK TABLES `searches` WRITE;
/*!40000 ALTER TABLE `searches` DISABLE KEYS */;
/*!40000 ALTER TABLE `searches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`),
  CONSTRAINT `sessions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `value` text DEFAULT NULL,
  `group` varchar(255) NOT NULL DEFAULT 'general',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_key_unique` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=584 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES
(1,'site_name','AVDBCMS','general','2026-04-25 07:17:05','2026-05-05 03:55:31'),
(2,'site_description','Share and Watch Amazing Videos','general','2026-04-25 07:17:05','2026-04-25 07:17:05'),
(3,'site_keywords','video,streaming,upload','general','2026-04-25 07:17:05','2026-04-25 07:17:05'),
(4,'registration_enabled','1','general','2026-04-25 07:17:05','2026-04-25 07:17:05'),
(6,'upload_max_duration','86400','uploads','2026-04-25 07:17:05','2026-05-08 18:34:37'),
(7,'upload_allowed_formats','mp4,webm,avi,mov,mkv','uploads','2026-04-25 07:17:05','2026-04-25 07:17:05'),
(8,'comments_enabled','1','comments','2026-04-25 07:17:05','2026-04-25 07:17:05'),
(10,'default_video_status','0','uploads','2026-04-25 07:17:05','2026-05-05 03:55:31'),
(12,'contact_email','admin@example.com','general','2026-04-25 07:17:05','2026-05-05 03:55:31'),
(13,'per_page_videos','24','general','2026-04-25 07:17:05','2026-04-25 07:17:05'),
(14,'per_page_comments','20','general','2026-04-25 07:17:05','2026-04-25 07:17:05'),
(15,'allow_social_login','1','general','2026-04-25 07:17:05','2026-04-25 07:17:05'),
(16,'premium_enabled','1','monetization','2026-04-25 07:17:05','2026-04-25 07:17:05'),
(17,'ads_enabled','1','monetization','2026-04-25 07:17:05','2026-05-05 06:36:21'),
(18,'ad_code_header','','monetization','2026-04-25 07:17:05','2026-05-05 10:28:26'),
(19,'ad_code_sidebar','','monetization','2026-04-25 07:17:05','2026-05-05 06:36:21'),
(20,'ad_code_video','','monetization','2026-04-25 07:17:05','2026-05-05 06:36:21'),
(21,'custom_css','','appearance','2026-04-25 07:17:05','2026-04-25 07:17:05'),
(22,'custom_js','','appearance','2026-04-25 07:17:05','2026-04-25 07:17:05'),
(23,'footer_text','(c) 2026 AVDBCMS. All rights reserved.','appearance','2026-04-25 07:17:05','2026-05-05 03:55:31'),
(24,'smtp_host','','email','2026-04-25 07:17:05','2026-05-09 03:17:15'),
(25,'smtp_port','2525','email','2026-04-25 07:17:05','2026-05-09 03:17:15'),
(26,'smtp_username','','email','2026-04-25 07:17:05','2026-05-09 03:17:15'),
(27,'smtp_password','','email','2026-04-25 07:17:05','2026-05-09 03:17:15'),
(28,'smtp_encryption','','email','2026-04-25 07:17:05','2026-04-25 07:17:05'),
(29,'mail_from_address','noreply@example.com','email','2026-04-25 07:17:05','2026-05-05 03:55:31'),
(30,'mail_from_name','AVDBCMS','email','2026-04-25 07:17:05','2026-05-05 03:55:31'),
(31,'upload18_api_key','','uploads','2026-04-25 10:06:23','2026-04-25 10:06:23'),
(32,'upload18_api_url','https://upload18.org/api','uploads','2026-04-25 10:06:23','2026-04-25 10:06:23'),
(33,'upload18_default_cid','15','uploads','2026-04-25 10:06:23','2026-04-25 10:06:23'),
(34,'upload18_default_fid','18','uploads','2026-04-25 10:06:23','2026-04-25 10:06:23'),
(35,'upload18_default_mycid','0','uploads','2026-04-25 10:06:23','2026-04-25 10:06:23'),
(36,'default_video_language','','uploads','2026-04-25 10:06:23','2026-05-05 06:18:06'),
(37,'max_thumbnail_size','2048','uploads','2026-04-25 10:06:23','2026-04-25 10:06:23'),
(38,'allow_custom_thumbnails','1','uploads','2026-04-25 10:06:23','2026-04-25 10:06:23'),
(39,'theme','dark','appearance','2026-04-25 12:35:03','2026-04-25 12:35:03'),
(42,'grid_columns','4','appearance','2026-04-25 12:35:03','2026-10-20 00:00:00'),
(43,'surface_style','glass','appearance','2026-04-25 12:35:03','2026-04-27 10:42:31'),
(44,'radius_scale','balanced','appearance','2026-04-25 12:35:03','2026-10-20 00:00:00'),
(45,'motion_level','full','appearance','2026-04-25 12:35:03','2026-04-25 12:35:03'),
(46,'content_density','comfortable','appearance','2026-04-25 12:35:03','2026-04-25 19:59:36'),
(47,'logo_url','','appearance','2026-04-25 12:35:03','2026-05-07 09:09:09'),
(48,'favicon_url','','appearance','2026-04-25 12:35:03','2026-05-07 09:09:09'),
(49,'announcement_banner','','appearance','2026-04-25 12:35:03','2026-04-25 12:35:03'),
(50,'custom_head_html','','appearance','2026-04-25 12:35:03','2026-04-25 12:35:03'),
(57,'free_max_uploads','1000000','planlimits','2026-04-25 20:05:28','2026-05-02 11:18:31'),
(58,'free_max_file_size','10485760000','planlimits','2026-04-25 20:05:28','2026-05-02 11:18:31'),
(59,'free_max_storage','10995116277760','planlimits','2026-04-25 20:05:28','2026-05-02 11:18:31'),
(60,'premium_max_uploads','','planlimits','2026-04-25 20:05:28','2026-04-25 20:05:28'),
(61,'premium_max_file_size','104857600000','planlimits','2026-04-25 20:05:28','2026-05-02 11:18:31'),
(62,'premium_max_storage','','planlimits','2026-04-25 20:05:28','2026-04-25 20:05:28'),
(63,'trial_period_days','30','planlimits','2026-04-25 20:05:28','2026-04-25 20:05:28'),
(64,'trial_email_reminder','7','planlimits','2026-04-25 20:05:28','2026-04-25 20:05:28'),
(65,'upload18_chunk_size_mb','90','uploads','2026-04-25 20:20:23','2026-04-25 20:20:23'),
(66,'upload18_thumbnail_count','3','uploads','2026-04-25 20:20:23','2026-04-25 20:20:23'),
(67,'stripe_enabled','1','monetization','2026-04-26 03:44:16','2026-04-26 03:44:16'),
(68,'paypal_enabled','1','monetization','2026-04-26 03:44:16','2026-04-26 03:44:16'),
(69,'per_page_users','15','general','2026-04-26 09:59:22','2026-04-26 09:59:22'),
(70,'per_page_search','20','general','2026-04-26 09:59:22','2026-04-26 09:59:22'),
(71,'per_page_activity','12','general','2026-04-26 09:59:22','2026-04-26 09:59:22'),
(72,'profile_videos_limit','8','general','2026-04-26 09:59:22','2026-04-26 09:59:22'),
(73,'profile_playlists_limit','6','general','2026-04-26 09:59:22','2026-04-26 09:59:22'),
(74,'dashboard_widget_limit','5','general','2026-04-26 09:59:22','2026-04-26 09:59:22'),
(75,'discover_users_limit','100','general','2026-04-26 09:59:22','2026-04-26 09:59:22'),
(76,'search_suggestions_limit','10','general','2026-04-26 09:59:22','2026-04-26 09:59:22'),
(77,'suggested_videos_count','9','general','2026-04-26 09:59:22','2026-04-26 09:59:22'),
(78,'allow_uploads','1','general','2026-04-26 09:59:22','2026-04-26 09:59:22'),
(79,'allow_embedding','1','general','2026-04-26 09:59:22','2026-04-26 09:59:22'),
(80,'show_trending','1','general','2026-04-26 09:59:22','2026-04-26 09:59:22'),
(81,'show_history','1','general','2026-04-26 09:59:22','2026-04-26 09:59:22'),
(82,'per_page_replies','10','comments','2026-04-26 09:59:27','2026-04-26 09:59:27'),
(83,'comment_preview_length','500','comments','2026-04-26 09:59:27','2026-04-26 09:59:27'),
(84,'view_cooldown_minutes','5','comments','2026-04-26 09:59:27','2026-04-26 09:59:27'),
(86,'max_register_attempts','1','users','2026-04-26 09:59:30','2026-04-26 09:59:30'),
(87,'max_avatar_size','2048','users','2026-04-26 09:59:30','2026-04-26 09:59:30'),
(88,'max_banner_size','5120','users','2026-04-26 09:59:30','2026-04-26 09:59:30'),
(89,'allow_account_deletion','1','users','2026-04-26 09:59:30','2026-04-26 09:59:30'),
(90,'show_premium_badge','1','monetization','2026-04-26 09:59:32','2026-04-26 09:59:32'),
(91,'ads_hide_for_premium','1','monetization','2026-04-26 09:59:32','2026-05-05 06:36:21'),
(92,'ad_preroll_enabled','1','monetization','2026-04-26 09:59:32','2026-05-05 06:36:21'),
(93,'ad_preroll_duration','5','monetization','2026-04-26 09:59:32','2026-05-05 06:36:21'),
(94,'ad_grid_position','8','monetization','2026-04-26 09:59:32','2026-05-05 08:07:51'),
(96,'ad_code_footer','','monetization','2026-04-26 09:59:32','2026-05-05 06:36:21'),
(97,'ad_code_mobile','','monetization','2026-04-26 09:59:32','2026-05-05 06:36:21'),
(98,'meta_title_template','{title} | {site_name}','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(99,'default_meta_description','','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(100,'og_image','','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(101,'twitter_card_type','summary_large_image','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(102,'twitter_handle','','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(103,'facebook_app_id','','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(104,'google_verification','','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(105,'video_url_prefix','video','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(106,'video_url_pattern','{slug}-{uuid}','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(107,'robots_max_snippet','-1','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(108,'robots_max_video_preview','-1','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(109,'robots_max_image_preview','large','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(110,'schema_organization_type','Organization','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(111,'sitemap_video_limit','1000','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(112,'robots_txt','','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(113,'indexnow_key','','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(114,'indexnow_endpoint','https://api.indexnow.org/indexnow','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(115,'robots_noindex','0','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(116,'robots_nofollow','0','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(117,'robots_noarchive','0','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(118,'robots_notranslate','0','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(119,'robots_noimageindex','0','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(120,'canonical_enabled','1','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(121,'hreflang_enabled','1','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(122,'website_schema_enabled','1','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(123,'search_action_schema_enabled','1','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(124,'video_schema_enabled','1','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(125,'video_seek_action_enabled','1','seo','2026-04-26 09:59:37','2026-05-13 12:39:44'),
(126,'sitemap_enabled','1','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(127,'video_sitemap_enabled','1','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(128,'indexnow_enabled','0','seo','2026-04-26 09:59:37','2026-04-26 09:59:37'),
(129,'terms_content','','pages','2026-04-26 09:59:39','2026-04-26 09:59:39'),
(130,'force_https','0','security','2026-04-26 09:59:42','2026-04-26 09:59:42'),
(131,'hsts_enabled','0','security','2026-04-26 09:59:42','2026-04-26 09:59:42'),
(132,'csp_enabled','0','security','2026-04-26 09:59:42','2026-04-26 09:59:42'),
(133,'xss_protection','1','security','2026-04-26 09:59:42','2026-04-26 09:59:42'),
(134,'nosniff_header','1','security','2026-04-26 09:59:42','2026-04-26 09:59:42'),
(135,'frame_options_header','1','security','2026-04-26 09:59:42','2026-04-26 09:59:42'),
(136,'hsts_max_age','31536000','security','2026-04-26 09:59:42','2026-04-26 09:59:42'),
(137,'csp_directives','','security','2026-04-26 09:59:42','2026-04-26 09:59:42'),
(138,'admin_ip_whitelist','','security','2026-04-26 09:59:42','2026-04-26 09:59:42'),
(139,'global_ip_blacklist','','security','2026-04-26 09:59:42','2026-04-26 09:59:42'),
(140,'timezone','Europe/Paris','localization','2026-04-26 09:59:44','2026-04-26 09:59:44'),
(141,'date_format','M d, Y','localization','2026-04-26 09:59:44','2026-04-26 09:59:44'),
(142,'rtl_support','0','localization','2026-04-26 09:59:44','2026-04-26 09:59:44'),
(143,'user_username_min','3','validation','2026-04-26 09:59:47','2026-04-26 09:59:47'),
(144,'user_username_max','40','validation','2026-04-26 09:59:47','2026-04-26 09:59:47'),
(145,'user_slug_min','3','validation','2026-04-26 09:59:47','2026-04-26 09:59:47'),
(146,'user_slug_max','30','validation','2026-04-26 09:59:47','2026-04-26 09:59:47'),
(147,'user_password_min','8','validation','2026-04-26 09:59:47','2026-05-05 06:23:38'),
(148,'user_description_max','5000','validation','2026-04-26 09:59:47','2026-04-26 09:59:47'),
(149,'user_website_max','255','validation','2026-04-26 09:59:47','2026-04-26 09:59:47'),
(150,'video_title_max','100','validation','2026-04-26 09:59:47','2026-04-26 09:59:47'),
(151,'video_description_max','5000','validation','2026-04-26 09:59:47','2026-04-26 09:59:47'),
(152,'comment_content_max','5000','validation','2026-04-26 09:59:47','2026-04-26 09:59:47'),
(153,'playlist_title_max','150','validation','2026-04-26 09:59:47','2026-04-26 09:59:47'),
(154,'playlist_description_max','5000','validation','2026-04-26 09:59:47','2026-04-26 09:59:47'),
(155,'report_comment_max','5000','validation','2026-04-26 09:59:47','2026-04-26 09:59:47'),
(156,'contact_name_min','3','validation','2026-04-26 09:59:47','2026-04-26 09:59:47'),
(157,'contact_name_max','40','validation','2026-04-26 09:59:47','2026-04-26 09:59:47'),
(158,'contact_message_min','10','validation','2026-04-26 09:59:47','2026-04-26 09:59:47'),
(159,'contact_message_max','5000','validation','2026-04-26 09:59:47','2026-04-26 09:59:47'),
(160,'api_rate_limit','60','api','2026-04-26 09:59:51','2026-04-26 09:59:51'),
(161,'login_rate_limit','5','api','2026-04-26 09:59:51','2026-04-26 09:59:51'),
(162,'upload_rate_limit','10','api','2026-04-26 09:59:51','2026-04-26 09:59:51'),
(163,'cors_allowed_origins','','api','2026-04-26 09:59:51','2026-04-27 09:34:14'),
(164,'cors_allowed_methods','GET,POST,PUT,DELETE,OPTIONS','api','2026-04-26 09:59:51','2026-04-27 09:34:14'),
(165,'cors_max_age','600','api','2026-04-26 09:59:51','2026-04-27 09:34:14'),
(166,'cors_supports_credentials','1','api','2026-04-26 09:59:51','2026-04-26 09:59:51'),
(167,'social_facebook','','sociallinks','2026-04-26 09:59:54','2026-04-26 09:59:54'),
(168,'social_twitter','','sociallinks','2026-04-26 09:59:54','2026-04-26 09:59:54'),
(169,'social_instagram','','sociallinks','2026-04-26 09:59:54','2026-04-26 09:59:54'),
(170,'social_youtube','','sociallinks','2026-04-26 09:59:54','2026-04-26 09:59:54'),
(171,'social_tiktok','','sociallinks','2026-04-26 09:59:54','2026-04-26 09:59:54'),
(172,'social_discord','','sociallinks','2026-04-26 09:59:54','2026-04-26 09:59:54'),
(173,'maintenance_mode_enabled','0','maintenance','2026-04-26 09:59:56','2026-04-26 09:59:56'),
(174,'maintenance_allow_admins','1','maintenance','2026-04-26 09:59:56','2026-04-26 09:59:56'),
(175,'maintenance_secret','','maintenance','2026-04-26 09:59:56','2026-04-26 09:59:56'),
(176,'maintenance_message','We are currently performing maintenance. Please check back soon.','maintenance','2026-04-26 09:59:56','2026-04-26 09:59:56'),
(177,'maintenance_retry_after','3600','maintenance','2026-04-26 09:59:56','2026-04-26 09:59:56'),
(178,'maintenance_allowed_ips','','maintenance','2026-04-26 09:59:56','2026-04-26 09:59:56'),
(179,'subscribe_rate_limit','5','api','2026-04-27 09:34:14','2026-04-27 09:34:14'),
(180,'comment_rate_limit','15','api','2026-04-27 09:34:14','2026-04-27 09:34:14'),
(181,'report_rate_limit','5','api','2026-04-27 09:34:14','2026-04-27 09:34:14'),
(182,'search_rate_limit','30','api','2026-04-27 09:34:14','2026-04-27 09:34:14'),
(183,'video_view_rate_limit','120','api','2026-04-27 09:34:14','2026-04-27 09:34:14'),
(184,'embed_allowed_domains','','api','2026-04-27 09:34:14','2026-04-27 09:34:14'),
(185,'smart_discovery_rail_enabled','1','discovery','2026-04-27 10:04:54','2026-04-27 10:16:46'),
(186,'smart_discovery_rail_limit','8','discovery','2026-04-27 10:04:54','2026-04-27 10:04:54'),
(187,'smart_discovery_rail_lookback_days','14','discovery','2026-04-27 10:04:54','2026-04-27 10:04:54'),
(188,'search_history_retention_days','180','general','2026-04-27 10:04:54','2026-04-27 10:04:54'),
(189,'search_history_max_rows','50000','general','2026-04-27 10:04:54','2026-04-27 10:04:54'),
(190,'search_history_cleanup_interval_minutes','60','general','2026-04-27 10:04:54','2026-04-27 10:04:54'),
(191,'broadcast_notification_chunk_size','500','notifications','2026-04-27 10:04:54','2026-04-27 10:04:54'),
(192,'creator_spotlight_enabled','1','discovery','2026-04-27 10:04:54','2026-04-27 10:16:46'),
(193,'creator_spotlight_limit','6','discovery','2026-04-27 10:04:54','2026-04-27 10:04:54'),
(194,'creator_spotlight_lookback_days','21','discovery','2026-04-27 10:04:54','2026-04-27 10:04:54'),
(195,'trending_collections_enabled','1','discovery','2026-04-27 10:04:54','2026-04-27 10:16:46'),
(196,'trending_collections_limit','6','discovery','2026-04-27 10:04:54','2026-04-27 10:04:54'),
(197,'trending_collections_lookback_days','14','discovery','2026-04-27 10:04:54','2026-04-27 10:04:54'),
(201,'creator_quality_pulse_enabled','1','users','2026-04-27 10:04:54','2026-04-27 10:04:54'),
(202,'creator_quality_pulse_limit','5','users','2026-04-27 10:04:54','2026-04-27 10:04:54'),
(203,'creator_quality_pulse_lookback_days','30','users','2026-04-27 10:04:54','2026-04-27 10:04:54'),
(204,'studio_health_alerts_enabled','1','users','2026-04-27 10:04:54','2026-04-27 10:04:54'),
(205,'studio_health_alerts_limit','5','users','2026-04-27 10:04:54','2026-04-27 10:04:54'),
(206,'studio_health_alerts_lookback_days','14','users','2026-04-27 10:04:54','2026-04-27 10:04:54'),
(207,'creator_recovery_checklist_enabled','1','users','2026-04-27 10:04:54','2026-04-27 10:04:54'),
(208,'creator_recovery_checklist_limit','5','users','2026-04-27 10:04:54','2026-04-27 10:04:54'),
(209,'creator_recovery_checklist_inactivity_days','21','users','2026-04-27 10:04:54','2026-04-27 10:04:54'),
(213,'playback_progress_signal_enabled','1','discovery','2026-04-27 10:04:54','2026-04-27 10:14:08'),
(214,'playback_progress_signal_min_duration_seconds','30','discovery','2026-04-27 10:04:54','2026-04-27 10:04:54'),
(215,'playback_progress_signal_retention_days','90','discovery','2026-04-27 10:04:54','2026-04-27 10:04:54'),
(216,'home_intro_kicker','Fresh picks','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(217,'home_intro_title','Watch what is moving now','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(218,'home_intro_subtitle','A fast, clean feed for new videos, creators, playlists, and trending moments.','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(219,'header_search_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(220,'header_upload_button_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(221,'header_notifications_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(222,'front_sidebar_pinned_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(224,'sidebar_playlists_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(225,'sidebar_subscriptions_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(226,'sidebar_explore_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(227,'sidebar_premium_link_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(228,'sidebar_locale_switcher_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(229,'sidebar_theme_switcher_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(231,'home_intro_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 19:42:59'),
(232,'home_category_tabs_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 19:42:59'),
(234,'home_rail_links_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(235,'home_fallback_rails_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(236,'home_main_feed_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(237,'watch_theater_button_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(238,'watch_keyboard_shortcuts_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(239,'watch_next_video_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(241,'watch_playlist_panel_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(242,'watch_related_videos_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(243,'watch_sidebar_ads_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(244,'watch_reactions_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(245,'watch_share_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(246,'watch_save_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(247,'watch_report_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(248,'watch_channel_panel_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(249,'watch_description_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(250,'watch_metadata_chips_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(251,'watch_comments_preview_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(252,'watch_sidebar_sticky_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(253,'video_card_hover_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(254,'video_card_progress_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(255,'announcement_banner_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(256,'custom_code_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(257,'back_to_top_enabled','1','appearance','2026-04-27 10:20:31','2026-04-27 10:20:31'),
(258,'effects_enabled','1','appearance','2026-04-27 10:42:01','2026-04-27 10:42:01'),
(259,'css_animations_enabled','1','appearance','2026-04-27 10:42:01','2026-04-27 10:42:01'),
(260,'css_transitions_enabled','1','appearance','2026-04-27 10:42:01','2026-04-27 10:42:01'),
(261,'css_hover_effects_enabled','1','appearance','2026-04-27 10:42:01','2026-04-27 10:42:01'),
(262,'css_gradient_effects_enabled','1','appearance','2026-04-27 10:42:01','2026-04-27 10:42:01'),
(263,'css_blur_effects_enabled','1','appearance','2026-04-27 10:42:01','2026-04-27 10:42:01'),
(264,'css_shadow_effects_enabled','1','appearance','2026-04-27 10:42:01','2026-04-27 10:42:01'),
(265,'css_sticky_header_enabled','1','appearance','2026-04-27 10:42:01','2026-04-27 10:42:01'),
(266,'css_shimmer_effect_enabled','1','appearance','2026-04-27 10:42:01','2026-04-27 10:42:01'),
(267,'js_anime_effects_enabled','1','appearance','2026-04-27 10:42:01','2026-04-27 10:42:01'),
(268,'js_theme_memory_enabled','1','appearance','2026-04-27 10:42:01','2026-04-27 10:42:01'),
(269,'js_theater_memory_enabled','1','appearance','2026-04-27 10:42:01','2026-04-27 10:42:01'),
(270,'js_smooth_scroll_enabled','1','appearance','2026-04-27 10:42:01','2026-04-27 10:42:01'),
(271,'js_copy_toast_enabled','1','appearance','2026-04-27 10:42:01','2026-04-27 10:42:01'),
(272,'js_lazy_comments_enabled','1','appearance','2026-04-27 10:42:01','2026-04-27 10:42:01'),
(274,'home_widget_motion_enabled','1','appearance','2026-04-27 11:20:00','2026-04-27 11:20:00'),
(275,'home_widget_motion_card_cascade_enabled','1','appearance','2026-04-27 11:20:00','2026-04-27 11:20:00'),
(276,'home_widget_motion_style','slide','appearance','2026-04-27 11:20:00','2026-04-27 11:20:00'),
(277,'home_widget_motion_duration_ms','720','appearance','2026-04-27 11:20:00','2026-04-27 11:20:00'),
(278,'home_widget_motion_distance_px','34','appearance','2026-04-27 11:20:00','2026-04-27 11:20:00'),
(279,'home_widget_motion_stagger_ms','70','appearance','2026-04-27 11:20:00','2026-04-27 11:20:00'),
(280,'home_widget_fresh_picks_motion_direction','right','appearance','2026-04-27 11:20:00','2026-04-27 11:20:00'),
(281,'home_widget_smart_discovery_motion_direction','left','appearance','2026-04-27 11:20:00','2026-04-27 11:20:00'),
(282,'home_widget_creator_spotlight_motion_direction','right','appearance','2026-04-27 11:20:00','2026-04-27 11:20:00'),
(283,'home_widget_trending_collections_motion_direction','left','appearance','2026-04-27 11:20:00','2026-04-27 11:20:00'),
(286,'home_widget_motion_autorun_enabled','1','appearance','2026-04-27 11:37:09','2026-04-27 11:37:09'),
(287,'home_widget_motion_pause_on_hover_enabled','1','appearance','2026-04-27 11:37:09','2026-04-27 11:37:09'),
(288,'home_widget_motion_speed_px_per_second','48','appearance','2026-04-27 11:37:09','2026-04-27 11:37:09'),
(310,'home_feed_source','newest','appearance','2026-04-27 13:21:20','2026-04-27 13:23:34'),
(311,'home_feed_category_id','0','appearance','2026-04-27 13:21:20','2026-04-27 13:21:20'),
(312,'home_feed_columns','4','appearance','2026-04-27 13:21:20','2026-10-20 00:00:00'),
(314,'smart_discovery_rail_videos_enabled','1','discovery','2026-04-27 13:40:35','2026-04-27 13:42:52'),
(315,'smart_discovery_rail_video_source','most_viewed','discovery','2026-04-27 13:40:35','2026-04-27 19:34:38'),
(316,'smart_discovery_rail_video_category_id','0','discovery','2026-04-27 13:40:35','2026-04-27 13:40:35'),
(317,'smart_discovery_rail_video_columns','5','discovery','2026-04-27 13:40:35','2026-04-27 13:40:35'),
(318,'smart_discovery_rail_video_rows','2','discovery','2026-04-27 13:40:35','2026-04-27 13:40:35'),
(319,'creator_spotlight_videos_enabled','1','discovery','2026-04-27 13:40:35','2026-04-27 13:42:52'),
(320,'creator_spotlight_video_source','most_commented','discovery','2026-04-27 13:40:35','2026-04-27 19:34:38'),
(321,'creator_spotlight_video_category_id','0','discovery','2026-04-27 13:40:35','2026-04-27 13:40:35'),
(322,'creator_spotlight_video_columns','5','discovery','2026-04-27 13:40:35','2026-04-27 13:40:35'),
(323,'creator_spotlight_video_rows','2','discovery','2026-04-27 13:40:35','2026-04-27 13:40:35'),
(324,'trending_collections_videos_enabled','0','discovery','2026-04-27 13:40:35','2026-04-27 19:34:38'),
(325,'trending_collections_video_source','most_commented','discovery','2026-04-27 13:40:35','2026-04-27 13:42:52'),
(326,'trending_collections_video_category_id','0','discovery','2026-04-27 13:40:35','2026-04-27 13:40:35'),
(327,'trending_collections_video_columns','5','discovery','2026-04-27 13:40:35','2026-04-27 13:40:35'),
(328,'trending_collections_video_rows','2','discovery','2026-04-27 13:40:35','2026-04-27 13:40:35'),
(329,'home_feed_load_mode','infinite','appearance','2026-04-27 13:51:39','2026-05-02 12:31:24'),
(331,'headline_accent_enabled','1','appearance','2026-04-27 20:11:54','2026-04-27 20:11:54'),
(332,'headline_accent_animation_enabled','1','appearance','2026-04-27 20:11:54','2026-04-27 20:11:54'),
(333,'watch_player_ambient_glow_enabled','1','appearance','2026-04-27 20:44:28','2026-04-27 20:44:28'),
(334,'watch_action_panel_enabled','1','appearance','2026-04-27 20:44:28','2026-04-27 20:44:28'),
(335,'watch_player_frame_style','cinematic','appearance','2026-04-27 20:44:28','2026-04-27 20:44:28'),
(336,'watch_up_next_card_enabled','1','appearance','2026-04-27 21:03:08','2026-04-27 21:03:08'),
(337,'watch_description_default_open','1','appearance','2026-04-27 21:03:08','2026-04-27 21:06:13'),
(338,'watch_sidebar_density','comfortable','appearance','2026-04-27 21:03:08','2026-04-27 21:03:08'),
(339,'secondary_page_design_enabled','1','appearance','2026-04-27 21:35:27','2026-04-27 21:35:27'),
(340,'secondary_page_hero_enabled','1','appearance','2026-04-27 21:35:27','2026-04-27 21:35:27'),
(341,'secondary_page_surfaces_enabled','1','appearance','2026-04-27 21:35:27','2026-04-27 21:35:27'),
(342,'secondary_page_form_polish_enabled','1','appearance','2026-04-27 21:35:27','2026-04-27 21:35:27'),
(343,'secondary_page_table_polish_enabled','1','appearance','2026-04-27 21:35:27','2026-04-27 21:35:27'),
(344,'secondary_page_density','comfortable','appearance','2026-04-27 21:35:27','2026-04-27 21:35:27'),
(345,'secondary_page_max_width','wide','appearance','2026-04-27 21:35:27','2026-04-27 21:35:27'),
(346,'contact_illustration_enabled','1','appearance','2026-04-27 21:35:27','2026-04-27 21:35:27'),
(347,'premium_comparison_enabled','1','appearance','2026-04-27 21:35:27','2026-04-27 21:35:27'),
(348,'premium_plan_switcher_enabled','1','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(349,'premium_timeline_enabled','1','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(350,'premium_feature_uploads_enabled','1','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(351,'premium_feature_downloads_enabled','1','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(352,'premium_feature_ad_free_enabled','1','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(353,'premium_comparison_uploads_row_enabled','1','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(354,'premium_comparison_file_size_row_enabled','1','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(355,'premium_comparison_storage_row_enabled','1','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(356,'premium_comparison_downloads_row_enabled','0','pages','2026-04-27 21:50:28','2026-05-05 03:27:53'),
(357,'premium_comparison_ads_row_enabled','1','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(358,'premium_comparison_downloads_free_enabled','0','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(359,'premium_comparison_downloads_premium_enabled','1','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(360,'premium_comparison_ads_free_enabled','1','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(361,'premium_comparison_ads_premium_enabled','0','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(362,'premium_page_kicker','Premium','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(363,'premium_page_title',':site Premium','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(364,'premium_page_subtitle','Unleash the full potential of your creative journey with our premium plan','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(365,'premium_disabled_message','Premium subscriptions are currently unavailable.','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(366,'premium_checkout_unavailable_message','Premium checkout is currently unavailable. Please check back later.','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(367,'premium_empty_plans_message','Premium plans are being prepared.','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(368,'premium_trial_badge_template',':days days trial','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(369,'premium_then_label','Then','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(370,'premium_cancel_anytime_label','Cancel anytime','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(371,'premium_card_or_paypal_label','Card or PayPal','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(372,'premium_paypal_label','PayPal','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(373,'premium_billing_heading','Billing','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(374,'premium_annual_savings_text','Save 2 months with the annual subscription','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(375,'premium_feature_uploads_title','Uploads','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(376,'premium_feature_uploads_description','Unlimited video uploads and storage space','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(377,'premium_feature_uploads_icon','fa-upload','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(378,'premium_feature_downloads_title','Downloads','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(379,'premium_feature_downloads_description','Save videos for when you really need them - like when you\'re on a plane or commuting.','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(380,'premium_feature_downloads_icon','fa-download','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(381,'premium_feature_ad_free_title','Videos without ads','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(382,'premium_feature_ad_free_description','Enjoy your favorite content even more, without ads','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(383,'premium_feature_ad_free_icon','fa-arrow-pointer','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(384,'premium_plan_unavailable_label','Plan unavailable','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(385,'premium_plan_unavailable_note','Checkout is not ready for this plan yet.','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(386,'premium_pay_card_label','Pay with card','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(387,'premium_pay_paypal_label','Pay with PayPal','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(388,'premium_start_card_label','Start with card','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(389,'premium_start_paypal_label','Start with PayPal','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(390,'premium_billing_trial_summary','Your billing starts on :date for the :period plan.','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(391,'premium_billing_without_trial_summary','Your billing starts today for the :period plan.','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(392,'premium_billing_charge_note','You will be charged automatically at the end of each period.','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(393,'premium_billing_refund_note','Payments won\'t be refunded for partial billing periods. Cancel anytime from your account.','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(394,'premium_timeline_trial_title',':days days trial','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(395,'premium_timeline_trial_description','Start your free trial of :site Premium and enjoy all premium features.','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(396,'premium_timeline_reminder_title',':date','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(397,'premium_timeline_reminder_description','We will email you :days days before your trial ends.','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(398,'premium_timeline_start_title',':date','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(399,'premium_timeline_start_description','Your subscription begins unless you canceled it during the trial period.','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(400,'premium_comparison_title','Plans comparison','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(401,'premium_comparison_free_label','Free plan','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(402,'premium_comparison_premium_label','Premium plan','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(403,'premium_comparison_uploads_label','Max Video Uploads','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(404,'premium_comparison_file_size_label','Max File Size Per Upload','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(405,'premium_comparison_storage_label','Video Storage Space','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(406,'premium_comparison_downloads_label','Downloads','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(407,'premium_comparison_ads_label','Ads on videos','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(408,'premium_unlimited_label','Unlimited','pages','2026-04-27 21:50:28','2026-04-27 21:50:28'),
(409,'channel_customization_enabled','1','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(410,'channel_customization_layout_enabled','1','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(411,'channel_customization_theme_enabled','1','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(412,'channel_customization_accent_enabled','1','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(413,'channel_customization_custom_accent_enabled','1','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(414,'channel_customization_surface_enabled','1','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(415,'channel_customization_banner_enabled','1','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(416,'channel_customization_avatar_enabled','1','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(417,'channel_customization_motion_enabled','1','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(418,'channel_customization_effects_enabled','1','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(419,'channel_customization_pattern_enabled','1','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(420,'channel_customization_shape_enabled','1','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(421,'channel_customization_video_layout_enabled','1','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(422,'channel_customization_featured_video_enabled','1','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(423,'channel_customization_sections_enabled','1','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(424,'channel_customization_cta_enabled','1','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(425,'channel_customization_stats_enabled','1','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(426,'channel_customization_socials_enabled','1','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(427,'channel_customization_upload_rhythm_enabled','1','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(428,'channel_default_layout','spotlight','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(429,'channel_default_theme','midnight','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(430,'channel_default_accent','coral','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(431,'channel_default_custom_accent','#18c8d8','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(432,'channel_default_surface','glass','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(433,'channel_default_banner_treatment','cinematic','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(434,'channel_default_avatar_frame','ring','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(435,'channel_default_motion','gentle','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(436,'channel_default_pattern','signal','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(437,'channel_default_header_shape','soft-arc','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(438,'channel_default_stats_style','pills','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(439,'channel_default_video_layout','grid','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(440,'channel_default_featured_mode','pinned','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(441,'channel_default_density','comfortable','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(442,'channel_tagline_max_length','120','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(443,'channel_cta_label_max_length','42','users','2026-04-27 23:01:40','2026-04-27 23:01:40'),
(444,'channel_customization_featured_layout_enabled','1','users','2026-04-28 00:01:19','2026-04-28 00:01:19'),
(445,'channel_customization_featured_sizing_enabled','1','users','2026-04-28 00:01:19','2026-04-28 00:01:19'),
(446,'channel_default_featured_layout','grid','users','2026-04-28 00:01:19','2026-04-28 00:01:19'),
(447,'channel_default_featured_columns','3','users','2026-04-28 00:01:19','2026-04-28 00:01:19'),
(448,'channel_default_featured_rows','1','users','2026-04-28 00:01:19','2026-04-28 00:01:19'),
(449,'channel_customization_latest_uploads_enabled','1','users','2026-04-28 00:38:32','2026-04-28 00:38:32'),
(450,'channel_customization_latest_layout_enabled','1','users','2026-04-28 00:38:32','2026-04-28 00:38:32'),
(451,'channel_customization_latest_sizing_enabled','1','users','2026-04-28 00:38:32','2026-04-28 00:38:32'),
(452,'channel_customization_playlists_enabled','1','users','2026-04-28 00:38:32','2026-04-28 00:38:32'),
(453,'channel_customization_playlists_layout_enabled','1','users','2026-04-28 00:38:32','2026-04-28 00:38:32'),
(454,'channel_customization_playlists_sizing_enabled','1','users','2026-04-28 00:38:32','2026-04-28 00:38:32'),
(455,'channel_default_latest_uploads_layout','grid','users','2026-04-28 00:38:32','2026-04-28 00:38:32'),
(456,'channel_default_latest_uploads_columns','3','users','2026-04-28 00:38:32','2026-04-28 00:38:32'),
(457,'channel_default_latest_uploads_rows','1','users','2026-04-28 00:38:32','2026-04-28 00:38:32'),
(458,'channel_default_playlists_layout','stacked','users','2026-04-28 00:38:32','2026-04-28 00:38:32'),
(459,'channel_default_playlists_columns','1','users','2026-04-28 00:38:32','2026-04-28 00:38:32'),
(460,'channel_default_playlists_rows','3','users','2026-04-28 00:38:32','2026-04-28 00:38:32'),
(461,'channel_default_playlist_video_columns','3','users','2026-04-28 00:38:32','2026-04-28 00:38:32'),
(462,'channel_default_playlist_video_rows','1','users','2026-04-28 00:38:32','2026-04-28 00:38:32'),
(463,'font_family','system','appearance','2026-05-02 12:30:26','2026-05-02 12:30:26'),
(464,'base_font_size','16','appearance','2026-05-02 12:30:26','2026-05-02 12:30:26'),
(465,'ui_font_scale','100','appearance','2026-05-02 12:30:26','2026-05-02 12:30:26'),
(466,'heading_font_scale','100','appearance','2026-05-02 12:30:26','2026-05-02 12:30:26'),
(467,'card_title_font_scale','100','appearance','2026-05-02 12:30:26','2026-05-02 12:30:26'),
(468,'text_contrast','normal','appearance','2026-05-02 12:30:26','2026-05-02 12:30:26'),
(469,'scrollbar_style','brand','appearance','2026-05-02 12:30:26','2026-10-20 00:00:00'),
(470,'upload_modal_style','glass','appearance','2026-05-02 12:30:26','2026-05-02 12:30:26'),
(471,'typography_controls_enabled','1','appearance','2026-05-02 12:30:26','2026-05-02 12:30:26'),
(472,'mobile_video_columns','2','appearance','2026-05-05 00:46:26','2026-05-05 00:46:26'),
(473,'mobile_video_card_density','compact','appearance','2026-05-05 00:46:26','2026-05-05 00:46:26'),
(474,'studio_video_preview_mode','button','appearance','2026-05-05 00:46:26','2026-05-05 00:46:26'),
(475,'enhanced_selects_enabled','1','appearance','2026-05-05 00:46:26','2026-05-05 00:46:26'),
(476,'horizontal_rails_hide_scrollbars_enabled','1','appearance','2026-05-05 00:46:26','2026-05-05 00:46:26'),
(477,'scroll_panel_hover_guard_enabled','1','appearance','2026-05-05 00:46:26','2026-05-05 00:46:26'),
(583,'theme_preset','tokyo_neon','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(584,'theme_flavor','signature','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(585,'home_layout_mode','youtube_spotlight','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(586,'hero_layout_mode','live_ticker_stream','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(587,'watch_layout_mode','classic_two_column','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(588,'video_card_layout_style','card_standard','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(589,'header_style','glass_blur','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(590,'sidebar_style','full','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(591,'category_card_style','gradient_mesh','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(592,'creator_avatar_ring_style','neon_pulse','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(593,'video_card_duration_style','pill','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(594,'modal_presentation_style','modal_slide_sheet','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(595,'motion_engine_level','smooth_fluid','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(596,'typography_pairing','modern_tech','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(597,'background_atmosphere_effect','clean_none','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(598,'interactive_3d_card_tilt_enabled','1','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(599,'cinema_ambient_light_diffusion_enabled','1','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(600,'watch_ambient_glow_intensity','intense','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(601,'hero_slider_autoplay_enabled','1','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(602,'video_card_title_lines','2','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(603,'theme_oled_black_enabled','0','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(604,'active_master_experience','youtube_spotlight_pro','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(605,'daily_design_scope','palette_only','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(606,'appearance_schema_version','2','appearance','2026-10-20 00:00:00','2026-10-20 00:00:00'),
(478,'email_verification_required','0','users','2026-05-05 03:39:54','2026-05-05 03:40:57'),
(479,'mail_mailer','smtp','email','2026-05-05 03:39:54','2026-05-08 19:23:08'),
(480,'support_email','support@example.com','email','2026-05-05 03:39:54','2026-05-08 19:23:08'),
(481,'server_email','noreply@example.com','email','2026-05-05 03:39:54','2026-05-08 19:23:08'),
(482,'social_telegram','','sociallinks','2026-05-05 03:55:31','2026-05-05 03:55:31'),
(483,'show_subscriber_count','1','users','2026-05-05 03:55:31','2026-05-05 03:55:31'),
(484,'adult_rating_enabled','1','seo','2026-05-05 03:55:31','2026-05-05 03:55:31'),
(485,'allow_replies','1','comments','2026-05-05 03:57:47','2026-05-05 03:57:47'),
(486,'smart_discovery_rail_kicker','Smart discovery','discovery','2026-05-05 03:57:47','2026-05-05 03:57:47'),
(487,'smart_discovery_rail_title','Tags with the most videos','discovery','2026-05-05 03:57:47','2026-05-05 03:57:47'),
(488,'smart_discovery_rail_fallback_kicker','Browse','discovery','2026-05-05 03:57:47','2026-05-05 03:57:47'),
(489,'smart_discovery_rail_fallback_title','Explore categories','discovery','2026-05-05 03:57:47','2026-05-05 03:57:47'),
(490,'smart_discovery_rail_link_label','View trending','discovery','2026-05-05 03:57:47','2026-05-05 03:57:47'),
(491,'creator_spotlight_kicker','Creator spotlight','discovery','2026-05-05 03:57:47','2026-05-05 03:57:47'),
(492,'creator_spotlight_title','Creators posting now','discovery','2026-05-05 03:57:47','2026-05-05 03:57:47'),
(493,'creator_spotlight_fallback_kicker','Creator spotlight','discovery','2026-05-05 03:57:47','2026-05-05 03:57:47'),
(494,'creator_spotlight_fallback_title','Find creators by category','discovery','2026-05-05 03:57:47','2026-05-05 03:57:47'),
(495,'creator_spotlight_link_label','Discover channels','discovery','2026-05-05 03:57:47','2026-05-05 03:57:47'),
(496,'trending_collections_kicker','Trending actors','discovery','2026-05-05 03:57:47','2026-05-05 03:57:47'),
(497,'trending_collections_title','Actors viewers are watching now','discovery','2026-05-05 03:57:47','2026-05-05 03:57:47'),
(498,'trending_collections_fallback_kicker','Browse collections','discovery','2026-05-05 03:57:47','2026-05-05 03:57:47'),
(499,'trending_collections_fallback_title','Collections to start with','discovery','2026-05-05 03:57:47','2026-05-05 03:57:47'),
(500,'trending_collections_link_label','View trending','discovery','2026-05-05 03:57:47','2026-05-05 03:57:47'),
(501,'default_locale','en','localization','2026-05-05 05:08:53','2026-05-05 05:08:53'),
(502,'allow_language_switch','1','localization','2026-05-05 05:08:53','2026-05-05 05:08:53'),
(503,'thumbnail_width','640','uploads','2026-05-05 05:08:53','2026-05-05 05:08:53'),
(504,'thumbnail_height','360','uploads','2026-05-05 05:08:53','2026-05-05 05:08:53'),
(505,'ad_grid_layout','grid_card','monetization','2026-05-05 07:55:17','2026-05-05 07:55:17'),
(506,'ad_code_grid_smart_discovery','','monetization','2026-05-05 07:55:17','2026-05-05 07:55:17'),
(507,'ad_code_grid_creator_spotlight','','monetization','2026-05-05 07:55:17','2026-05-05 07:55:17'),
(508,'ad_code_grid_trending_actors','','monetization','2026-05-05 07:55:17','2026-05-05 07:55:17'),
(509,'ad_code_grid_new_videos','','monetization','2026-05-05 07:55:17','2026-05-05 07:55:17'),
(510,'ad_grid_layout_smart_discovery','inherit','monetization','2026-05-05 08:00:32','2026-05-05 08:00:32'),
(511,'ad_grid_layout_creator_spotlight','wide_row','monetization','2026-05-05 08:00:32','2026-05-05 08:01:52'),
(512,'ad_grid_layout_trending_actors','inherit','monetization','2026-05-05 08:00:32','2026-05-05 08:00:32'),
(513,'ad_grid_layout_new_videos','inherit','monetization','2026-05-05 08:00:32','2026-05-05 08:00:32'),
(514,'ad_grid_max_per_feed','1','monetization','2026-05-05 08:06:17','2026-05-05 08:06:17'),
(515,'ad_grid_layout_trend_page','inherit','monetization','2026-05-05 08:21:23','2026-05-05 08:21:23'),
(516,'ad_grid_layout_category_page','inherit','monetization','2026-05-05 08:21:23','2026-05-05 08:21:23'),
(517,'ad_grid_layout_subscriptions','inherit','monetization','2026-05-05 08:21:23','2026-05-05 08:21:23'),
(518,'ad_grid_layout_channel_videos','inherit','monetization','2026-05-05 08:21:23','2026-05-05 08:21:23'),
(519,'ad_grid_layout_search_results','wide_row','monetization','2026-05-05 08:21:23','2026-05-05 08:21:23'),
(520,'ad_grid_layout_playlist_videos','wide_row','monetization','2026-05-05 08:21:23','2026-05-05 08:21:23'),
(521,'ad_grid_layout_watch_related','wide_row','monetization','2026-05-05 08:21:23','2026-05-05 08:21:23'),
(522,'ad_grid_layout_channel_home_featured','inherit','monetization','2026-05-05 08:21:23','2026-05-05 08:21:23'),
(523,'ad_grid_layout_channel_home_latest','inherit','monetization','2026-05-05 08:21:23','2026-05-05 08:21:23'),
(524,'ad_code_grid_trend_page','','monetization','2026-05-05 08:21:23','2026-05-05 08:21:23'),
(525,'ad_code_grid_category_page','','monetization','2026-05-05 08:21:23','2026-05-05 08:21:23'),
(526,'ad_code_grid_subscriptions','','monetization','2026-05-05 08:21:23','2026-05-05 08:21:23'),
(527,'ad_code_grid_channel_videos','','monetization','2026-05-05 08:21:23','2026-05-05 08:21:23'),
(528,'ad_code_grid_search_results','','monetization','2026-05-05 08:21:23','2026-05-05 08:21:23'),
(529,'ad_code_grid_playlist_videos','','monetization','2026-05-05 08:21:23','2026-05-05 08:21:23'),
(530,'ad_code_grid_watch_related','','monetization','2026-05-05 08:21:23','2026-05-05 08:21:23'),
(531,'ad_code_grid_channel_home_featured','','monetization','2026-05-05 08:21:23','2026-05-05 08:21:23'),
(532,'ad_code_grid_channel_home_latest','','monetization','2026-05-05 08:21:23','2026-05-05 08:21:23'),
(533,'ad_onclick_enabled','1','monetization','2026-05-05 09:54:14','2026-05-05 09:54:14'),
(539,'ad_code_onclick','','monetization','2026-05-05 09:54:14','2026-05-05 10:28:26'),
(545,'ad_preroll_media_url','https://test-videos.co.uk/vids/bigbuckbunny/mp4/h264/1080/Big_Buck_Bunny_1080_10s_30MB.mp4 | https://example.com/your-offer\nhttps://content.jwplatform.com/manifests/yp34SRmf.m3u8 | https://example.com/another-offer','monetization','2026-05-05 11:02:58','2026-05-05 11:02:58'),(582,'ad_preroll_click_url','','monetization','2026-05-05 11:02:58','2026-05-05 11:02:58'),
(547,'honeypot_enabled','1','security','2026-05-05 11:28:37','2026-05-05 11:28:37'),
(548,'captcha_provider','built_in','security','2026-05-05 11:28:37','2026-05-05 11:28:37'),
(549,'captcha_site_key','','security','2026-05-05 11:28:37','2026-05-05 11:28:37'),
(550,'captcha_secret_key','','security','2026-05-05 11:28:37','2026-05-05 11:28:37'),
(551,'captcha_on_login','1','security','2026-05-05 11:28:37','2026-05-05 11:28:37'),
(552,'captcha_on_register','1','security','2026-05-05 11:28:37','2026-05-05 11:28:37'),
(553,'captcha_on_contact','1','security','2026-05-05 11:28:37','2026-05-05 11:28:37'),
(554,'captcha_on_password_reset','1','security','2026-05-05 11:28:37','2026-05-05 11:28:37'),
(555,'view_log_mode','authenticated','comments','2026-05-05 11:52:47','2026-05-05 11:52:47'),
(556,'view_daily_stats_enabled','1','comments','2026-05-05 11:52:47','2026-05-05 11:52:47'),
(557,'view_history_retention_days','90','comments','2026-05-05 11:52:47','2026-05-05 11:52:47'),
(558,'profile_uid_urls_enabled','0','seo','2026-05-13 12:23:11','2026-05-13 12:39:18'),
(559,'video_pages_indexable','1','seo','2026-05-13 12:23:11','2026-05-13 12:23:11'),
(560,'category_pages_indexable','1','seo','2026-05-13 12:23:11','2026-05-13 12:23:11'),
(561,'profile_pages_indexable','1','seo','2026-05-13 12:23:11','2026-05-13 12:23:11'),
(562,'playlist_pages_indexable','1','seo','2026-05-13 12:23:11','2026-05-13 12:23:11'),
(563,'search_pages_indexable','1','seo','2026-05-13 12:23:11','2026-05-13 12:39:44'),
(564,'home_meta_title_template','Share and Watch Amazing Videos','seo','2026-05-13 12:23:11','2026-05-13 12:23:11'),
(565,'home_meta_description_template','Enjoy the videos and music you love, upload original content, and share it all with friends, family, and the world on {site_name}.','seo','2026-05-13 12:23:11','2026-05-13 12:23:11'),
(566,'trend_meta_title_template','Trending','seo','2026-05-13 12:23:11','2026-05-13 12:23:11'),
(567,'trend_meta_description_template','The pulse of what is trending on {site_name}. Check out the latest videos that people are watching right now.','seo','2026-05-13 12:23:11','2026-05-13 12:23:11'),
(568,'category_meta_title_template','{category} Videos','seo','2026-05-13 12:23:11','2026-05-13 12:23:11'),
(569,'category_meta_description_template','Watch {category} videos on {site_name}.','seo','2026-05-13 12:23:11','2026-05-13 12:23:11'),
(570,'search_meta_title_template','{search}','seo','2026-05-13 12:23:11','2026-05-13 12:23:11'),
(571,'search_meta_description_template','Search results for {search} on {site_name}.','seo','2026-05-13 12:23:11','2026-05-13 12:23:11'),
(572,'profile_meta_title_template','{username}','seo','2026-05-13 12:23:11','2026-05-13 12:23:11'),
(573,'profile_meta_description_template','{description}','seo','2026-05-13 12:23:11','2026-05-13 12:23:11'),
(574,'video_meta_title_template','{video_title}','seo','2026-05-13 12:23:11','2026-05-13 12:23:11'),
(575,'video_meta_description_template','{description}','seo','2026-05-13 12:23:11','2026-05-13 12:23:11'),
(576,'playlist_meta_title_template','{title}','seo','2026-05-13 12:23:11','2026-05-13 12:23:11'),
(577,'playlist_meta_description_template','{description}','seo','2026-05-13 12:23:11','2026-05-13 12:23:11'),
(578,'premium_meta_title_template','Premium','seo','2026-05-13 12:23:11','2026-05-13 12:23:11'),
(579,'premium_meta_description_template','Unleash the full potential of your creative journey with our premium plan.','seo','2026-05-13 12:23:11','2026-05-13 12:23:11'),
(580,'terms_meta_title_template','Terms of Service','seo','2026-05-13 12:23:11','2026-05-13 12:23:11'),
(581,'terms_meta_description_template','Read the terms of service, account rules, privacy notes, and data policies for using {site_name}.','seo','2026-05-13 12:23:11','2026-05-13 12:23:11');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subscriptions`
--

DROP TABLE IF EXISTS `subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `subscriber_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `subscribe_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `subscriptions_subscriber_id_foreign` (`subscriber_id`),
  KEY `subscriptions_user_id_foreign` (`user_id`),
  KEY `subscriptions_subscriber_subscribe_user_idx` (`subscriber_id`,`subscribe_at`,`user_id`),
  CONSTRAINT `subscriptions_subscriber_id_foreign` FOREIGN KEY (`subscriber_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subscriptions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=229 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscriptions`
--

LOCK TABLES `subscriptions` WRITE;
/*!40000 ALTER TABLE `subscriptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `subscriptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subtitles`
--

DROP TABLE IF EXISTS `subtitles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `subtitles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `video_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `language` varchar(3) NOT NULL,
  `file` varchar(255) NOT NULL,
  `status` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subtitles_video_id_foreign` (`video_id`),
  CONSTRAINT `subtitles_video_id_foreign` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subtitles`
--

LOCK TABLES `subtitles` WRITE;
/*!40000 ALTER TABLE `subtitles` DISABLE KEYS */;
/*!40000 ALTER TABLE `subtitles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tag_translations`
--

DROP TABLE IF EXISTS `tag_translations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tag_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tag_id` bigint(20) unsigned NOT NULL,
  `locale` varchar(10) NOT NULL,
  `name` varchar(150) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tag_translations_tag_id_locale_unique` (`tag_id`,`locale`),
  KEY `tag_translations_locale_name_index` (`locale`,`name`),
  CONSTRAINT `tag_translations_tag_id_foreign` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=59610 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tag_translations`
--

LOCK TABLES `tag_translations` WRITE;
/*!40000 ALTER TABLE `tag_translations` DISABLE KEYS */;
/*!40000 ALTER TABLE `tag_translations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tags`
--

DROP TABLE IF EXISTS `tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tags` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `usage_count` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tags_slug_unique` (`slug`),
  KEY `tags_usage_count_index` (`usage_count`)
) ENGINE=InnoDB AUTO_INCREMENT=25670 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tags`
--

LOCK TABLES `tags` WRITE;
/*!40000 ALTER TABLE `tags` DISABLE KEYS */;
/*!40000 ALTER TABLE `tags` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `thumbnails`
--

DROP TABLE IF EXISTS `thumbnails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `thumbnails` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `video_id` bigint(20) unsigned NOT NULL,
  `file` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `status` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `thumbnails_video_id_foreign` (`video_id`),
  KEY `thumbnails_video_active_status_idx` (`video_id`,`is_active`,`status`,`id`),
  CONSTRAINT `thumbnails_video_id_foreign` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4043 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `thumbnails`
--

LOCK TABLES `thumbnails` WRITE;
/*!40000 ALTER TABLE `thumbnails` DISABLE KEYS */;
/*!40000 ALTER TABLE `thumbnails` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transactions`
--

DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_provider` varchar(32) NOT NULL DEFAULT 'stripe',
  `stripe_id` varchar(255) NOT NULL,
  `amount` int(10) unsigned NOT NULL COMMENT 'amount in centimes',
  `tax` int(10) unsigned NOT NULL COMMENT 'tax in centimes',
  `fee` int(10) unsigned NOT NULL COMMENT 'stripe fee for transaction',
  `date` timestamp NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `subscription_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `address` varchar(255) NOT NULL,
  `city` varchar(255) NOT NULL,
  `postal_code` varchar(255) NOT NULL,
  `country` varchar(255) NOT NULL,
  `vat_id` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transactions_stripe_id_unique` (`stripe_id`),
  KEY `transactions_user_id_foreign` (`user_id`),
  KEY `transactions_subscription_id_foreign` (`subscription_id`),
  KEY `transactions_payment_provider_index` (`payment_provider`),
  CONSTRAINT `transactions_subscription_id_foreign` FOREIGN KEY (`subscription_id`) REFERENCES `premiums` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transactions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transactions`
--

LOCK TABLES `transactions` WRITE;
/*!40000 ALTER TABLE `transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `slug` varchar(255) DEFAULT NULL,
  `profile_uid` char(8) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `temporary_email` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `is_admin` tinyint(1) DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `banner` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `country` varchar(3) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `show_subscribers` tinyint(1) NOT NULL DEFAULT 1,
  `channel_customization` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`channel_customization`)),
  `banned_at` timestamp NULL DEFAULT NULL,
  `pinned_video_id` bigint(20) unsigned DEFAULT NULL,
  `facebook_id` varchar(255) DEFAULT NULL,
  `google_id` varchar(255) DEFAULT NULL,
  `stripe_id` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `phone_verified_at` timestamp NULL DEFAULT NULL,
  `phone_confirmation_code` varchar(255) DEFAULT NULL,
  `language` varchar(255) NOT NULL DEFAULT 'en',
  `avdb_creator_key` varchar(191) DEFAULT NULL,
  `avdb_creator_source` varchar(32) DEFAULT NULL,
  `avdb_creator_name` varchar(150) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_username_unique` (`username`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_stripe_id_unique` (`stripe_id`),
  UNIQUE KEY `users_facebook_id_unique` (`facebook_id`),
  UNIQUE KEY `users_google_id_unique` (`google_id`),
  UNIQUE KEY `users_slug_unique` (`slug`),
  UNIQUE KEY `users_phone_unique` (`phone`),
  UNIQUE KEY `users_avdb_creator_key_unique` (`avdb_creator_key`),
  UNIQUE KEY `users_profile_uid_unique` (`profile_uid`),
  KEY `users_email_verified_id_idx` (`email_verified_at`,`id`),
  KEY `admin_users_created_id_idx` (`created_at`,`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2769 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES
(1,'admin','admin','dyyxj7d4','admin@example.com',NULL,'$2y$10$Y5iUpc4BIr4IXdYzdugKaeQnKhvrOdAJfjrJrjXH1IiEfMUiN7ezi','2026-05-09 03:17:48','2026-05-15 09:13:18',NULL,1,NULL,'dTAbX7MyUMMjQZ3PiRB5brbsWuwWPdkoHtYYIJsw.jpg',NULL,'This did not like to go down the chimney!\' \'Oh! So Bill\'s got the other--Bill! fetch it here, lad!--Here, put \'em up at the moment, \'My dear! I wish you were INSIDE, you might do something better with the Queen,\' and she told her sister, who was gently brushing away some dead leaves that had slipped in like herself. \'Would it be of very little way out of sight. Alice remained looking thoughtfully at the time it vanished quite slowly, beginning with the lobsters, out to be listening, so she turned to the beginning of the cattle in the back. At last the Caterpillar took the hookah out of breath, and till the puppy\'s bark sounded quite faint in the chimney as she was shrinking rapidly; so she went on, looking anxiously round to see if there are, nobody attends to them--and you\'ve no idea what you\'re at!\" You know the meaning of it had struck her foot! She was a sound of many footsteps, and Alice looked at the house, and have next to her. The Cat seemed to her ear. \'You\'re thinking about something, my dear, I think?\' he said in a great crash, as if it likes.\' \'I\'d rather finish my tea,\' said the Pigeon; \'but if they do, why then they\'re a kind of rule, \'and vinegar that makes you forget to talk. I can\'t get out again. That\'s all.\' \'Thank you,\' said the King. \'When did you manage on the trumpet, and called out as loud as she swam lazily about in all my limbs very supple By the time they had a large canvas bag, which tied up at the end of your nose-- What made you so awfully clever?\' \'I have answered three questions, and that you never even spoke to Time!\' \'Perhaps not,\' Alice cautiously replied: \'but I know I have dropped them, I wonder?\' Alice guessed who it was, even before she got up this morning, but I shall have to ask his neighbour to tell its age, there was no \'One, two, three, and away,\' but they were gardeners, or soldiers, or courtiers, or three times over to herself, \'I wish the creatures argue. It\'s enough to try the first minute or two she stood watching them, and he wasn\'t going to say,\' said the Queen, the royal children; there were no arches left, and all sorts of things--I can\'t remember half of them--and it belongs to the jury, who instantly made a rush at Alice as he found it so yet,\' said the Duchess; \'I never was so small as this before, never! And I declare it\'s too bad, that it would be like, \'--for they haven\'t got much evidence YET,\' she said to Alice, and tried to fancy what the flame of a water-well,\' said the Duchess: you\'d better ask HER about it.\' \'She\'s in prison,\' the Queen was to eat the comfits: this caused some noise and confusion, as the March Hare, \'that \"I like what I was going on, as she came rather late, and the sounds will take care of the goldfish kept running in her brother\'s Latin Grammar, \'A mouse--of a mouse--to a mouse--a mouse--O mouse!\') The Mouse gave a look askance-- Said he thanked the whiting kindly, but he now hastily began again, using the ink, that was linked into hers began to repeat it, when a cry of \'The trial\'s beginning!\' was heard in the wind, and the constant heavy sobbing of the Queen till she fancied she heard something splashing about in all their simple joys, remembering her own child-life, and the bright eager eyes were getting so used to it as she spoke. (The unfortunate little Bill had left off staring at the end of the bread-and-butter. Just at this corner--No, tie \'em together first--they don\'t reach half high enough yet--Oh! they\'ll do next! If they had settled down again into its mouth and yawned once or twice, and shook itself. Then it got down off the mushroom, and crawled away in the shade: however, the moment he was going on, as she spoke. \'I must be the use of a book,\' thought Alice \'without pictures or conversations in it, and burning with curiosity, she ran across the field after it, never once considering how in the beautiful garden, among the branches, and every now and then; such as, \'Sure, I don\'t think,\' Alice went on, without attending to her, And mentioned me to sell you a present of everything I\'ve said as yet.\' \'A cheap sort of present!\' thought Alice. \'Now we shall get on better.\' \'I\'d rather finish my tea,\' said the Mock Turtle said: \'no wise fish would go round and swam slowly back to her: its face in some book, but I hadn\'t cried so much!\' Alas! it was done. They had not long to doubt, for the end of his great wig.\' The judge, by the carrier,\' she thought; \'and how funny it\'ll seem to put down yet, before the trial\'s begun.\' \'They\'re putting down their names,\' the Gryphon at the corners: next the ten courtiers; these were all talking at once, she found herself in a tone of great relief. \'Call the first position in which case it would make with the next moment she felt a little animal (she couldn\'t guess of what work it would be like, \'--for they haven\'t got much evidence YET,\' she said aloud. \'I must be Mabel after all, and I shall have somebody to talk to.\' \'How are you getting on now, my dear?\' it continued, turning.','LR','watsica.com',1,'{\"layout\":\"spotlight\",\"theme\":\"midnight\",\"accent\":\"coral\",\"custom_accent\":\"#18c8d8\",\"surface\":\"glass\",\"banner_treatment\":\"cinematic\",\"avatar_frame\":\"ring\",\"motion\":\"gentle\",\"pattern\":\"signal\",\"header_shape\":\"soft-arc\",\"stats_style\":\"pills\",\"video_layout\":\"grid\",\"featured_mode\":\"pinned\",\"featured_layout\":\"grid\",\"featured_columns\":\"5\",\"featured_rows\":\"1\",\"density\":\"comfortable\",\"tagline\":\"\",\"cta_label\":\"\",\"cta_url\":\"\",\"show_stats\":\"1\",\"show_featured_video\":\"1\",\"show_about_panel\":\"1\",\"show_social_links\":\"1\",\"show_upload_rhythm\":\"1\",\"show_subscribe_cta\":\"1\"}',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'en',NULL,NULL,NULL,'2016-07-21 00:23:39','2026-05-15 09:13:18',NULL),
(6,'pbergstrom','Pbergstrom','4xrhw0hz','user2@example.com',NULL,'$2y$10$NiQ0RSbUGTz3EKlAJy5IheSo1gaEbJuqX4kNvVFg5JZGa/Y5aKeH2','2026-04-25 07:18:36','2026-04-25 07:18:36',NULL,NULL,NULL,NULL,NULL,'I\'m a hatter.\' Here the Queen to play croquet.\' Then they all spoke at once, in a low, timid voice, \'If you didn\'t like cats.\' \'Not like cats!\' cried the Gryphon, sighing in his sleep, \'that \"I like what I like\"!\' \'You might just as well as the hall was very likely it can talk: at any rate a book written about me, that there was not an encouraging tone. Alice looked all round her once more, while the rest of my life.\' \'You are old, Father William,\' the young man said, \'And your hair has become very white; And yet I don\'t like it, yer honour, at all, as the Caterpillar seemed to have lessons to learn! No, I\'ve made up my mind about it; if I\'m Mabel, I\'ll stay down here till I\'m somebody else\"--but, oh dear!\' cried Alice, jumping up in her pocket, and was delighted to find quite a long way. So they sat down and began an account of the suppressed guinea-pigs, filled the air, I\'m afraid, but you might do something better with the grin, which remained some time after the others. \'We must burn the house before she made some tarts, All on a little way forwards each time and a Canary called out \'The race is over!\' and they repeated their arguments to her, though, as they would go, and broke to pieces against one of the Queen\'s absence, and were quite dry again, the Dodo solemnly, rising to its feet, ran round the court and got behind him, and said nothing. \'This here young lady,\' said the King. On this the whole pack of cards, after all. I needn\'t be so stingy about it, you know--\' She had already heard her sentence three of the house of the March Hare said in a natural way. \'I thought it had lost something; and she trembled till she fancied she heard a little shriek, and went to work nibbling at the thought that she looked down at her feet as the soldiers shouted in reply. \'Idiot!\' said the Mouse replied rather impatiently: \'any shrimp could have been a holiday?\' \'Of course they were\', said the Duchess, who seemed ready to agree to everything that Alice had not gone (We know it to her usual height. It was all ridges and furrows; the balls were live hedgehogs, the mallets live flamingoes, and the Gryphon hastily. \'Go on with the Queen jumped up and went on in the world am I? Ah, THAT\'S the great hall, with the tarts, you know--\' \'But, it goes on \"THEY ALL RETURNED FROM HIM TO YOU,\"\' said Alice. \'I don\'t even know what you mean,\' said Alice. \'I\'ve read that in about half no time! Take your choice!\' The Duchess took her choice, and was gone across to the door, and the cool fountains. CHAPTER VIII. The Queen\'s Croquet-Ground A large rose-tree stood near the door, and the beak-- Pray how did you manage to do that,\' said Alice. The King turned pale, and shut his eyes.--\'Tell her about the temper of your nose-- What made you so awfully clever?\' \'I have answered three questions, and that makes the matter with it. There could be beheaded, and that in about half no time! Take your choice!\' The Duchess took no notice of her ever getting out of the way I want to go after that savage Queen: so she went on, without attending to her; \'but those serpents! There\'s no pleasing them!\' Alice was more hopeless than ever: she sat on, with closed eyes, and half of them--and it belongs to the table, half hoping that they couldn\'t see it?\' So she sat down a large cat which was sitting on a branch of a tree. By the time she saw maps and pictures hung upon pegs. She took down a very little! Besides, SHE\'S she, and I\'m I, and--oh dear, how puzzling it all came different!\' the Mock Turtle said: \'I\'m too stiff. And the Gryphon went on without attending to her; \'but those serpents! There\'s no pleasing them!\' Alice was soon submitted to by the way YOU manage?\' Alice asked. \'We called him Tortoise because he taught us,\' said the Gryphon: and it said nothing. \'Perhaps it doesn\'t matter a bit,\' said the Hatter, with an air of great relief. \'Call the next moment a shower of saucepans, plates, and dishes. The Duchess took her choice, and was going off into a line along the course, here and there. There was a paper label, with the birds hurried off at once: one old Magpie began wrapping itself up and picking the daisies, when suddenly a White Rabbit blew three blasts on the spot.\' This did not at all a pity. I said \"What for?\"\' \'She boxed the Queen\'s absence, and were resting in the kitchen that did not like the right house, because the Duchess said to Alice. \'What IS the same thing,\' said the Mock Turtle, \'Drive on, old fellow! Don\'t be all day to day.\' This was such a very curious sensation, which puzzled her very much of it now in sight, and no more of it appeared. \'I don\'t see any wine,\' she remarked. \'There isn\'t any,\' said the Gryphon. \'They can\'t have anything to put everything upon Bill! I wouldn\'t be in Bill\'s place for a few minutes to see some meaning in it,\' said Alice. \'And ever since that,\' the Hatter replied. \'Of course twinkling begins with an anxious look at the number of bathing machines in the wood,\' continued the Hatter, it woke.','PS','rutherford.biz',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'en',NULL,NULL,NULL,'2022-06-06 15:23:08','2026-04-29 19:20:40','2026-04-29 19:20:40'),
(68,'blackbox_creator','BlackboxCreator','i5dj0ai3','user3@example.com',NULL,'$2y$10$bcyLbe2upN.bDpWQOyXrsulugOftPzXGcyfBdGi6jMsarWAlmoa3S','2026-05-15 09:41:36',NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'en',NULL,NULL,NULL,'2026-04-27 09:32:25','2026-04-29 13:46:35','2026-04-29 13:46:35');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `video_tags`
--

DROP TABLE IF EXISTS `video_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `video_tags` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `video_id` bigint(20) unsigned NOT NULL,
  `tag_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `video_tags_video_id_tag_id_unique` (`video_id`,`tag_id`),
  KEY `video_tags_tag_id_index` (`tag_id`),
  CONSTRAINT `video_tags_tag_id_foreign` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE,
  CONSTRAINT `video_tags_video_id_foreign` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=660319 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `video_tags`
--

LOCK TABLES `video_tags` WRITE;
/*!40000 ALTER TABLE `video_tags` DISABLE KEYS */;
/*!40000 ALTER TABLE `video_tags` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `video_translations`
--

DROP TABLE IF EXISTS `video_translations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `video_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `video_id` bigint(20) unsigned NOT NULL,
  `locale` varchar(5) NOT NULL,
  `title` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `type_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `video_translations_video_id_locale_unique` (`video_id`,`locale`),
  KEY `video_translations_locale_index` (`locale`),
  KEY `video_translations_locale_video_idx` (`locale`,`video_id`),
  CONSTRAINT `video_translations_video_id_foreign` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1749199 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `video_translations`
--

LOCK TABLES `video_translations` WRITE;
/*!40000 ALTER TABLE `video_translations` DISABLE KEYS */;
/*!40000 ALTER TABLE `video_translations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `video_view_daily_stats`
--

DROP TABLE IF EXISTS `video_view_daily_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `video_view_daily_stats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `video_id` bigint(20) unsigned NOT NULL,
  `view_date` date NOT NULL,
  `views_count` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `video_view_daily_video_date_unique` (`video_id`,`view_date`),
  KEY `video_view_daily_date_video_idx` (`view_date`,`video_id`),
  CONSTRAINT `video_view_daily_stats_video_id_foreign` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1271151 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `video_view_daily_stats`
--

LOCK TABLES `video_view_daily_stats` WRITE;
/*!40000 ALTER TABLE `video_view_daily_stats` DISABLE KEYS */;
/*!40000 ALTER TABLE `video_view_daily_stats` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `videos`
--

DROP TABLE IF EXISTS `videos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `videos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `file` varchar(255) DEFAULT NULL,
  `embed_code` varchar(255) DEFAULT NULL COMMENT 'Upload18 embed hash for iframe player',
  `poster_url` varchar(500) DEFAULT NULL,
  `upload18_vid` varchar(255) DEFAULT NULL COMMENT 'Upload18 video ID for API operations',
  `avdb_id` int(10) unsigned DEFAULT NULL,
  `avdb_code` varchar(100) DEFAULT NULL,
  `avdb_actor` varchar(500) DEFAULT NULL,
  `avdb_director` varchar(255) DEFAULT NULL,
  `avdb_writer` varchar(255) DEFAULT NULL,
  `avdb_country` varchar(100) DEFAULT NULL,
  `avdb_release_date` date DEFAULT NULL,
  `avdb_updated_at` timestamp NULL DEFAULT NULL,
  `avdb_content_hash` varchar(64) DEFAULT NULL,
  `xvid_id` int(10) unsigned DEFAULT NULL,
  `xvid_code` varchar(100) DEFAULT NULL,
  `xvid_quality` varchar(50) DEFAULT NULL,
  `xvid_actor` varchar(500) DEFAULT NULL,
  `xvid_author` varchar(255) DEFAULT NULL,
  `xvid_release_date` date DEFAULT NULL,
  `xvid_updated_at` timestamp NULL DEFAULT NULL,
  `xvid_content_hash` varchar(64) DEFAULT NULL,
  `provider` varchar(50) DEFAULT NULL,
  `original_file_name` varchar(255) NOT NULL,
  `original_mimetype` varchar(255) DEFAULT NULL,
  `duration` varchar(255) DEFAULT NULL,
  `avdb_duration_sync_attempted_at` timestamp NULL DEFAULT NULL,
  `avdb_duration_sync_failed_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `size` int(10) unsigned DEFAULT NULL COMMENT 'size in bytes',
  `user_id` bigint(20) unsigned NOT NULL,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `status` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `language` varchar(3) DEFAULT NULL,
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL COMMENT 'Date when video become public for the first time',
  `allow_comments` tinyint(1) NOT NULL DEFAULT 1,
  `default_comments_sort` varchar(255) NOT NULL DEFAULT 'top',
  `show_likes` tinyint(1) NOT NULL DEFAULT 1,
  `pinned_comment_id` bigint(20) unsigned DEFAULT NULL,
  `banned_at` timestamp NULL DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT NULL,
  `youtube_id` varchar(255) DEFAULT NULL COMMENT 'Youtube ID',
  `is_live` tinyint(1) NOT NULL DEFAULT 0,
  `is_short` tinyint(1) NOT NULL DEFAULT 0,
  `views` bigint(20) unsigned NOT NULL DEFAULT 0,
  `show_ad` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `videos_avdb_id_unique` (`avdb_id`),
  UNIQUE KEY `videos_xvid_id_unique` (`xvid_id`),
  KEY `videos_xvid_code_index` (`xvid_code`),
  KEY `videos_provider_index` (`provider`),
  KEY `videos_category_id_foreign` (`category_id`),
  KEY `videos_user_id_foreign` (`user_id`),
  KEY `videos_uuid_idx` (`uuid`),
  KEY `videos_status_published_id_idx` (`status`,`published_at`,`id`),
  KEY `videos_status_scheduled_id_idx` (`status`,`scheduled_at`,`id`),
  KEY `videos_user_status_published_id_idx` (`user_id`,`status`,`published_at`,`id`),
  KEY `videos_category_status_published_id_idx` (`category_id`,`status`,`published_at`,`id`),
  KEY `videos_status_views_id_idx` (`status`,`views`,`id`),
  KEY `videos_language_status_published_id_idx` (`language`,`status`,`published_at`,`id`),
  KEY `videos_language_status_views_id_idx` (`language`,`status`,`views`,`id`),
  KEY `videos_published_id_idx` (`published_at`,`id`),
  KEY `videos_views_id_idx` (`views`,`id`),
  KEY `videos_user_updated_id_idx` (`user_id`,`updated_at`,`id`),
  KEY `admin_videos_created_id_idx` (`created_at`,`id`),
  KEY `dashboard_videos_user_created_id_idx` (`user_id`,`created_at`,`id`),
  KEY `videos_avdb_code_index` (`avdb_code`),
  KEY `videos_avdb_release_date_index` (`avdb_release_date`),
  KEY `videos_status_uploaded_id_updated_idx` (`status`,`uploaded_at`,`id`,`updated_at`),
  KEY `videos_status_scheduled_uploaded_id_idx` (`status`,`scheduled_at`,`uploaded_at`,`id`),
  KEY `videos_language_status_uploaded_id_idx` (`language`,`status`,`uploaded_at`,`id`),
  KEY `videos_avdb_updated_at_index` (`avdb_updated_at`),
  KEY `videos_avdb_content_hash_index` (`avdb_content_hash`),
  KEY `videos_avdb_duration_sync_attempted_at_index` (`avdb_duration_sync_attempted_at`),
  FULLTEXT KEY `videos_title_description_fulltext` (`title`,`description`),
  CONSTRAINT `videos_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  CONSTRAINT `videos_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=102916 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `videos`
--

LOCK TABLES `videos` WRITE;
/*!40000 ALTER TABLE `videos` DISABLE KEYS */;
/*!40000 ALTER TABLE `videos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `views`
--

DROP TABLE IF EXISTS `views`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `views` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `video_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip` varchar(45) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `view_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `views_video_id_foreign` (`video_id`),
  KEY `views_user_id_foreign` (`user_id`),
  KEY `views_video_view_at_idx` (`video_id`,`view_at`,`id`),
  KEY `views_user_view_at_idx` (`user_id`,`view_at`,`id`),
  KEY `views_ip_video_view_at_idx` (`ip`,`video_id`,`view_at`),
  KEY `views_video_user_view_at_idx` (`video_id`,`user_id`,`view_at`),
  KEY `views_video_ip_view_at_idx` (`video_id`,`ip`,`view_at`),
  KEY `admin_views_view_at_id_idx` (`view_at`,`id`),
  CONSTRAINT `views_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `views_video_id_foreign` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=69 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `views`
--

LOCK TABLES `views` WRITE;
/*!40000 ALTER TABLE `views` DISABLE KEYS */;
/*!40000 ALTER TABLE `views` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2026-05-15 22:43:40
ALTER TABLE videos ADD INDEX videos_user_deleted_status_size_idx (user_id, deleted_at, status, size);
ALTER TABLE videos ADD INDEX videos_deleted_views_idx (deleted_at, views);
ALTER TABLE videos ADD INDEX videos_avdb_category_metric_idx (avdb_id, deleted_at, category_id);
ALTER TABLE videos ADD INDEX videos_avdb_missing_duration_metric_idx (duration, deleted_at, avdb_id, avdb_duration_sync_attempted_at, avdb_duration_sync_failed_count);
ALTER TABLE videos ADD INDEX videos_user_active_count_idx (user_id, deleted_at, status, language);
-- 3332317761746368
