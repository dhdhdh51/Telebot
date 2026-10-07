-- BharatPlay Database Schema
-- MySQL/MariaDB Compatible
-- Production-ready schema with proper indexing and constraints

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- Import this file INTO the database you created in cPanel (phpMyAdmin → select DB → Import).
-- It intentionally does not CREATE/USE a database: cPanel DB users cannot create databases
-- and cPanel database names are prefixed (e.g. cpuser_bharatplay).

-- --------------------------------------------------------
-- Table structure for table `admins`
-- --------------------------------------------------------

CREATE TABLE `admins` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `role` ENUM('SUPER_ADMIN', 'ADMIN', 'MODERATOR') NOT NULL DEFAULT 'ADMIN',
  `status` ENUM('ACTIVE', 'INACTIVE', 'SUSPENDED') NOT NULL DEFAULT 'ACTIVE',
  `last_login` DATETIME NULL,
  `login_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `admin_sessions`
-- --------------------------------------------------------

CREATE TABLE `admin_sessions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` INT UNSIGNED NOT NULL,
  `session_id` VARCHAR(128) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `user_agent` VARCHAR(255) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_id` (`session_id`),
  KEY `admin_id` (`admin_id`),
  KEY `expires_at` (`expires_at`),
  CONSTRAINT `fk_admin_sessions_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `users`
-- --------------------------------------------------------

CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `telegram_user_id` BIGINT UNSIGNED NOT NULL,
  `username` VARCHAR(50) NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NULL,
  `language_code` VARCHAR(10) NULL DEFAULT 'en',
  `photo_url` VARCHAR(500) NULL,
  `status` ENUM('ACTIVE', 'BANNED', 'SUSPENDED') NOT NULL DEFAULT 'ACTIVE',
  `is_premium` TINYINT(1) NOT NULL DEFAULT 0,
  `referred_by` INT UNSIGNED NULL,
  `referral_code` VARCHAR(20) NOT NULL,
  `total_videos_watched` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_watch_time` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'in seconds',
  `last_active` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `telegram_user_id` (`telegram_user_id`),
  UNIQUE KEY `referral_code` (`referral_code`),
  KEY `username` (`username`),
  KEY `status` (`status`),
  KEY `is_premium` (`is_premium`),
  KEY `referred_by` (`referred_by`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `fk_users_referrer` FOREIGN KEY (`referred_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `categories`
-- --------------------------------------------------------

CREATE TABLE `categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(100) NOT NULL,
  `description` TEXT NULL,
  `icon` VARCHAR(50) NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `status` ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `status` (`status`),
  KEY `display_order` (`display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `videos`
-- --------------------------------------------------------

CREATE TABLE `videos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `category_id` INT UNSIGNED NOT NULL,
  `thumbnail` VARCHAR(255) NOT NULL,
  `video_path` VARCHAR(255) NOT NULL,
  `hls_path` VARCHAR(255) NULL,
  `duration` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'in seconds',
  `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'in bytes',
  `access_type` ENUM('FREE', 'PREMIUM') NOT NULL DEFAULT 'FREE',
  `status` ENUM('DRAFT', 'PUBLISHED', 'UNPUBLISHED', 'ARCHIVED') NOT NULL DEFAULT 'DRAFT',
  `views` INT UNSIGNED NOT NULL DEFAULT 0,
  `unique_views` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_watch_time` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'in seconds',
  `completion_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `tags` VARCHAR(500) NULL,
  `published_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `category_id` (`category_id`),
  KEY `access_type` (`access_type`),
  KEY `status` (`status`),
  KEY `views` (`views`),
  KEY `published_at` (`published_at`),
  KEY `created_at` (`created_at`),
  FULLTEXT KEY `search` (`title`, `description`, `tags`),
  CONSTRAINT `fk_videos_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `video_views`
-- --------------------------------------------------------

CREATE TABLE `video_views` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `video_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `watch_duration` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'in seconds',
  `completed` TINYINT(1) NOT NULL DEFAULT 0,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `video_id` (`video_id`),
  KEY `user_id` (`user_id`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `fk_video_views_video` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_video_views_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `watch_history`
-- --------------------------------------------------------

CREATE TABLE `watch_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `video_id` INT UNSIGNED NOT NULL,
  `last_position` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'in seconds',
  `watch_duration` INT UNSIGNED NOT NULL DEFAULT 0,
  `completed` TINYINT(1) NOT NULL DEFAULT 0,
  `last_watched_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_video` (`user_id`, `video_id`),
  KEY `user_id` (`user_id`),
  KEY `video_id` (`video_id`),
  KEY `last_watched_at` (`last_watched_at`),
  CONSTRAINT `fk_watch_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_watch_history_video` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `subscription_plans`
-- --------------------------------------------------------

CREATE TABLE `subscription_plans` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(100) NOT NULL,
  `description` TEXT NULL,
  `duration_days` INT UNSIGNED NOT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'INR',
  `features` TEXT NULL COMMENT 'JSON array of features',
  `display_order` INT NOT NULL DEFAULT 0,
  `is_popular` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `status` (`status`),
  KEY `display_order` (`display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `subscriptions`
-- --------------------------------------------------------

CREATE TABLE `subscriptions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `plan_id` INT UNSIGNED NOT NULL,
  `payment_id` INT UNSIGNED NULL,
  `start_date` DATETIME NOT NULL,
  `end_date` DATETIME NOT NULL,
  `status` ENUM('ACTIVE', 'EXPIRED', 'CANCELLED', 'PENDING') NOT NULL DEFAULT 'PENDING',
  `auto_renew` TINYINT(1) NOT NULL DEFAULT 0,
  `cancelled_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `plan_id` (`plan_id`),
  KEY `payment_id` (`payment_id`),
  KEY `status` (`status`),
  KEY `end_date` (`end_date`),
  CONSTRAINT `fk_subscriptions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_subscriptions_plan` FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `payments`
-- --------------------------------------------------------

CREATE TABLE `payments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `plan_id` INT UNSIGNED NULL,
  `payment_id` VARCHAR(100) NULL COMMENT 'Gateway payment ID',
  `order_id` VARCHAR(100) NOT NULL,
  `gateway` VARCHAR(50) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'INR',
  `status` ENUM('PENDING', 'SUCCESS', 'FAILED', 'REFUNDED') NOT NULL DEFAULT 'PENDING',
  `payment_method` VARCHAR(50) NULL,
  `gateway_response` TEXT NULL COMMENT 'JSON response from gateway',
  `verified` TINYINT(1) NOT NULL DEFAULT 0,
  `verified_at` DATETIME NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_id` (`order_id`),
  KEY `user_id` (`user_id`),
  KEY `plan_id` (`plan_id`),
  KEY `payment_id` (`payment_id`),
  KEY `status` (`status`),
  KEY `gateway` (`gateway`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `fk_payments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `ads`
-- --------------------------------------------------------

CREATE TABLE `ads` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `type` ENUM('BANNER', 'INTERSTITIAL', 'VIDEO', 'REWARDED') NOT NULL,
  `creative_html` TEXT NULL,
  `image_url` VARCHAR(500) NULL,
  `video_url` VARCHAR(500) NULL,
  `destination_url` VARCHAR(500) NULL,
  `priority` INT NOT NULL DEFAULT 0,
  `frequency` INT NOT NULL DEFAULT 1 COMMENT 'Show after N videos',
  `status` ENUM('ACTIVE', 'INACTIVE', 'SCHEDULED') NOT NULL DEFAULT 'ACTIVE',
  `start_date` DATETIME NULL,
  `end_date` DATETIME NULL,
  `impressions` INT UNSIGNED NOT NULL DEFAULT 0,
  `clicks` INT UNSIGNED NOT NULL DEFAULT 0,
  `completions` INT UNSIGNED NOT NULL DEFAULT 0,
  `targeting_rules` TEXT NULL COMMENT 'JSON',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `type` (`type`),
  KEY `status` (`status`),
  KEY `priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `ad_impressions`
-- --------------------------------------------------------

CREATE TABLE `ad_impressions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ad_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `video_id` INT UNSIGNED NULL,
  `clicked` TINYINT(1) NOT NULL DEFAULT 0,
  `completed` TINYINT(1) NOT NULL DEFAULT 0,
  `rewarded` TINYINT(1) NOT NULL DEFAULT 0,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ad_id` (`ad_id`),
  KEY `user_id` (`user_id`),
  KEY `video_id` (`video_id`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `fk_ad_impressions_ad` FOREIGN KEY (`ad_id`) REFERENCES `ads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ad_impressions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `ad_clicks`
-- --------------------------------------------------------

CREATE TABLE `ad_clicks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ad_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `impression_id` BIGINT UNSIGNED NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ad_id` (`ad_id`),
  KEY `user_id` (`user_id`),
  KEY `impression_id` (`impression_id`),
  CONSTRAINT `fk_ad_clicks_ad` FOREIGN KEY (`ad_id`) REFERENCES `ads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ad_clicks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `reward_rules`
-- --------------------------------------------------------

CREATE TABLE `reward_rules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reward_type` VARCHAR(50) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `daily_limit` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = unlimited',
  `cooldown_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reward_type` (`reward_type`),
  KEY `enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `reward_transactions`
-- --------------------------------------------------------

CREATE TABLE `reward_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `reward_type` VARCHAR(50) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `reference_id` VARCHAR(100) NOT NULL COMMENT 'Unique transaction reference',
  `reference_type` VARCHAR(50) NULL COMMENT 'ad_impression, referral, etc',
  `reference_data` TEXT NULL COMMENT 'JSON metadata',
  `status` ENUM('PENDING', 'COMPLETED', 'FAILED', 'REVERSED') NOT NULL DEFAULT 'PENDING',
  `verified` TINYINT(1) NOT NULL DEFAULT 0,
  `verified_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_id` (`reference_id`),
  KEY `user_id` (`user_id`),
  KEY `reward_type` (`reward_type`),
  KEY `status` (`status`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `fk_reward_transactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `wallets`
-- --------------------------------------------------------

CREATE TABLE `wallets` (
  `user_id` INT UNSIGNED NOT NULL,
  `balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `lifetime_earned` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `lifetime_withdrawn` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `pending_withdrawal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_wallets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `wallet_transactions`
-- --------------------------------------------------------

CREATE TABLE `wallet_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `type` ENUM('REWARD', 'REFERRAL', 'WITHDRAWAL', 'ADJUSTMENT', 'REFUND', 'REVERSAL') NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL COMMENT 'Positive for credit, negative for debit',
  `balance_before` DECIMAL(10,2) NOT NULL,
  `balance_after` DECIMAL(10,2) NOT NULL,
  `reference_id` VARCHAR(100) NULL,
  `reference_type` VARCHAR(50) NULL,
  `description` VARCHAR(255) NOT NULL,
  `metadata` TEXT NULL COMMENT 'JSON',
  `status` ENUM('PENDING', 'COMPLETED', 'FAILED', 'REVERSED') NOT NULL DEFAULT 'COMPLETED',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `type` (`type`),
  UNIQUE KEY `reference_id` (`reference_id`) COMMENT 'Idempotency: one ledger entry per reference',
  KEY `status` (`status`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `fk_wallet_transactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `withdrawals`
-- --------------------------------------------------------

CREATE TABLE `withdrawals` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `net_amount` DECIMAL(10,2) NOT NULL,
  `method` VARCHAR(50) NOT NULL,
  `account_details` TEXT NOT NULL COMMENT 'Encrypted JSON',
  `status` ENUM('PENDING', 'PROCESSING', 'PAID', 'REJECTED', 'CANCELLED') NOT NULL DEFAULT 'PENDING',
  `processed_by` INT UNSIGNED NULL,
  `processed_at` DATETIME NULL,
  `rejection_reason` TEXT NULL,
  `transaction_id` VARCHAR(100) NULL COMMENT 'Payment transaction ID',
  `notes` TEXT NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`),
  KEY `processed_by` (`processed_by`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `fk_withdrawals_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_withdrawals_admin` FOREIGN KEY (`processed_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `referrals`
-- --------------------------------------------------------

CREATE TABLE `referrals` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `referrer_id` INT UNSIGNED NOT NULL,
  `referred_id` INT UNSIGNED NOT NULL,
  `reward_earned` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `reward_paid` TINYINT(1) NOT NULL DEFAULT 0,
  `reward_paid_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `referred_id` (`referred_id`),
  KEY `referrer_id` (`referrer_id`),
  KEY `reward_paid` (`reward_paid`),
  CONSTRAINT `fk_referrals_referrer` FOREIGN KEY (`referrer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_referrals_referred` FOREIGN KEY (`referred_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `telegram_posts`
-- --------------------------------------------------------

CREATE TABLE `telegram_posts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `video_id` INT UNSIGNED NOT NULL,
  `message_id` BIGINT NULL,
  `chat_id` VARCHAR(64) NOT NULL COMMENT '@channelname or numeric -100... id',
  `post_type` ENUM('VIDEO', 'PHOTO', 'TEXT') NOT NULL DEFAULT 'PHOTO',
  `caption` TEXT NULL,
  `inline_keyboard` TEXT NULL COMMENT 'JSON',
  `status` ENUM('SENT', 'FAILED', 'DELETED', 'EDITED') NOT NULL DEFAULT 'SENT',
  `error_message` TEXT NULL,
  `sent_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `video_id` (`video_id`),
  KEY `message_id` (`message_id`),
  KEY `status` (`status`),
  CONSTRAINT `fk_telegram_posts_video` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `settings`
-- --------------------------------------------------------

CREATE TABLE `settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category` VARCHAR(50) NOT NULL,
  `key` VARCHAR(100) NOT NULL,
  `value` TEXT NULL,
  `type` ENUM('STRING', 'INTEGER', 'BOOLEAN', 'JSON', 'TEXT') NOT NULL DEFAULT 'STRING',
  `is_secret` TINYINT(1) NOT NULL DEFAULT 0,
  `description` TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_key` (`category`, `key`),
  KEY `category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `notifications`
-- --------------------------------------------------------

CREATE TABLE `notifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `type` VARCHAR(50) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `data` TEXT NULL COMMENT 'JSON',
  `read_status` TINYINT(1) NOT NULL DEFAULT 0,
  `read_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `read_status` (`read_status`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `audit_logs`
-- --------------------------------------------------------

CREATE TABLE `audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` INT UNSIGNED NULL,
  `user_id` INT UNSIGNED NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(50) NULL,
  `entity_id` INT UNSIGNED NULL,
  `description` TEXT NOT NULL,
  `old_values` TEXT NULL COMMENT 'JSON',
  `new_values` TEXT NULL COMMENT 'JSON',
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `admin_id` (`admin_id`),
  KEY `user_id` (`user_id`),
  KEY `action` (`action`),
  KEY `entity_type` (`entity_type`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `fk_audit_logs_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `analytics_daily`
-- --------------------------------------------------------

CREATE TABLE `analytics_daily` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `date` DATE NOT NULL,
  `total_users` INT UNSIGNED NOT NULL DEFAULT 0,
  `new_users` INT UNSIGNED NOT NULL DEFAULT 0,
  `active_users` INT UNSIGNED NOT NULL DEFAULT 0,
  `premium_users` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_videos` INT UNSIGNED NOT NULL DEFAULT 0,
  `video_views` INT UNSIGNED NOT NULL DEFAULT 0,
  `unique_viewers` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_watch_time` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `ad_impressions` INT UNSIGNED NOT NULL DEFAULT 0,
  `ad_clicks` INT UNSIGNED NOT NULL DEFAULT 0,
  `rewards_paid` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `subscription_revenue` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `withdrawals_paid` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Insert default categories
-- --------------------------------------------------------

INSERT INTO `categories` (`name`, `slug`, `description`, `icon`, `display_order`, `status`) VALUES
('Movies', 'movies', 'Full-length movies', '🎬', 1, 'ACTIVE'),
('Web Series', 'web-series', 'Episodic web series content', '📺', 2, 'ACTIVE'),
('Short Videos', 'short-videos', 'Short form content', '🎞️', 3, 'ACTIVE'),
('Education', 'education', 'Educational content', '📚', 4, 'ACTIVE'),
('Entertainment', 'entertainment', 'Entertainment videos', '🎭', 5, 'ACTIVE'),
('Trending', 'trending', 'Trending videos', '🔥', 6, 'ACTIVE');

-- --------------------------------------------------------
-- Insert default subscription plans
-- --------------------------------------------------------

INSERT INTO `subscription_plans` (`name`, `slug`, `description`, `duration_days`, `price`, `currency`, `features`, `display_order`, `is_popular`, `status`) VALUES
('Weekly', 'weekly', 'Perfect for trying out premium features', 7, 49.00, 'INR', '["Ad-free experience", "Premium videos", "HD quality", "Unlimited viewing"]', 1, 0, 'ACTIVE'),
('Monthly', 'monthly', 'Best value for regular viewers', 30, 149.00, 'INR', '["Ad-free experience", "Premium videos", "HD quality", "Unlimited viewing", "Download videos"]', 2, 1, 'ACTIVE'),
('Quarterly', 'quarterly', 'Save more with quarterly plan', 90, 399.00, 'INR', '["Ad-free experience", "Premium videos", "HD quality", "Unlimited viewing", "Download videos", "Early access"]', 3, 0, 'ACTIVE'),
('Yearly', 'yearly', 'Maximum savings for dedicated users', 365, 1499.00, 'INR', '["Ad-free experience", "Premium videos", "HD quality", "Unlimited viewing", "Download videos", "Early access", "Priority support"]', 4, 0, 'ACTIVE');

-- --------------------------------------------------------
-- Insert default reward rules
-- --------------------------------------------------------

INSERT INTO `reward_rules` (`reward_type`, `name`, `description`, `amount`, `daily_limit`, `cooldown_seconds`, `enabled`) VALUES
('WATCH_AD', 'Watch Rewarded Ad', 'Earn rewards by watching advertisements', 2.00, 10, 300, 1),
('REFERRAL', 'Referral Bonus', 'Earn when someone signs up using your referral link', 10.00, 0, 0, 1),
('DAILY_CHECKIN', 'Daily Check-in', 'Earn rewards for daily app usage', 1.00, 1, 86400, 1),
('VIDEO_COMPLETION', 'Complete Video', 'Earn rewards for watching complete videos', 0.50, 5, 0, 0);

-- --------------------------------------------------------
-- Insert default settings
-- --------------------------------------------------------

INSERT INTO `settings` (`category`, `key`, `value`, `type`, `is_secret`, `description`) VALUES
('general', 'app_name', 'BharatPlay', 'STRING', 0, 'Application name'),
('general', 'app_url', 'https://yourdomain.com', 'STRING', 0, 'Application base URL'),
('general', 'timezone', 'Asia/Kolkata', 'STRING', 0, 'Application timezone'),
('general', 'maintenance_mode', '0', 'BOOLEAN', 0, 'Enable maintenance mode'),

('telegram', 'bot_token', '', 'STRING', 1, 'Telegram Bot Token'),
('telegram', 'bot_username', '', 'STRING', 0, 'Telegram Bot Username'),
('telegram', 'channel_id', '', 'STRING', 0, 'Telegram Channel/Group ID for posting'),
('telegram', 'mini_app_url', '', 'STRING', 0, 'Telegram Mini App URL'),
('telegram', 'webhook_secret', '', 'STRING', 1, 'Webhook secret token'),
('telegram', 'mini_app_short_name', '', 'STRING', 0, 'Direct link Mini App short name'),
('system', 'schema_version', '1', 'INTEGER', 0, 'Database schema version'),

('video', 'max_upload_size', '524288000', 'INTEGER', 0, 'Maximum video upload size in bytes (500MB)'),
('video', 'allowed_formats', '["mp4", "mkv", "avi", "mov", "webm"]', 'JSON', 0, 'Allowed video formats'),
('video', 'thumbnail_max_size', '2097152', 'INTEGER', 0, 'Maximum thumbnail size in bytes (2MB)'),
('video', 'default_access_type', 'FREE', 'STRING', 0, 'Default video access type'),

('ads', 'enabled', '1', 'BOOLEAN', 0, 'Enable advertisements'),
('ads', 'free_user_frequency', '3', 'INTEGER', 0, 'Show ad after N videos for free users'),
('ads', 'banner_enabled', '1', 'BOOLEAN', 0, 'Show banner ads'),
('ads', 'skip_after_seconds', '5', 'INTEGER', 0, 'Interstitial skip delay'),
('ads', 'rewarded_ads_enabled', '0', 'BOOLEAN', 0, 'Enable rewarded ads (only works with a server-verified provider)'),
('ads', 'rewarded_provider', '', 'STRING', 0, 'Rewarded ad provider with signed server-side callbacks. Empty = Earn disabled'),

('payment', 'gateway', '', 'STRING', 0, 'Active payment gateway (empty = off, razorpay)'),
('payment', 'razorpay_webhook_secret', '', 'STRING', 1, 'Razorpay webhook secret'),
('payment', 'currency', 'INR', 'STRING', 0, 'Payment currency'),
('payment', 'razorpay_key_id', '', 'STRING', 1, 'Razorpay Key ID'),
('payment', 'razorpay_key_secret', '', 'STRING', 1, 'Razorpay Key Secret'),

('wallet', 'min_withdrawal', '100.00', 'STRING', 0, 'Minimum withdrawal amount'),
('wallet', 'max_withdrawal_daily', '10000.00', 'STRING', 0, 'Maximum daily withdrawal amount'),
('wallet', 'withdrawal_fee_percent', '0', 'STRING', 0, 'Withdrawal fee percentage'),
('wallet', 'withdrawal_fee_fixed', '0.00', 'STRING', 0, 'Fixed withdrawal fee'),

('referral', 'reward_amount', '10.00', 'STRING', 0, 'Referral reward amount'),
('referral', 'min_referred_watch_time', '300', 'INTEGER', 0, 'Minimum watch time in seconds for referral reward eligibility'),

('security', 'session_lifetime', '86400', 'INTEGER', 0, 'Admin session lifetime in seconds'),
('security', 'max_login_attempts', '5', 'INTEGER', 0, 'Maximum login attempts before lockout'),
('security', 'lockout_duration', '1800', 'INTEGER', 0, 'Account lockout duration in seconds'),
('security', 'telegram_auth_timeout', '3600', 'INTEGER', 0, 'Max age of Telegram initData (auth_date) in seconds');

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
