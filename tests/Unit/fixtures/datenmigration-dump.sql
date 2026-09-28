-- Automatisches Backup (#59) - 2026-09-28 00:00:00 UTC
-- Datenbank: hengst_fixture
SET FOREIGN_KEY_CHECKS=0;
SET NAMES utf8mb4;

-- Tabelle: addon_repos
DROP TABLE IF EXISTS `addon_repos`;
CREATE TABLE `addon_repos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `owner` varchar(100) NOT NULL,
  `repo` varchar(100) NOT NULL,
  `ref` varchar(100) DEFAULT NULL,
  `is_official` tinyint(1) NOT NULL DEFAULT 0,
  `added_by` int(11) DEFAULT NULL,
  `cached_catalog_json` mediumtext DEFAULT NULL,
  `cached_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `owner_repo` (`owner`,`repo`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `addon_repos` (`id`, `owner`, `repo`, `ref`, `is_official`, `added_by`, `cached_catalog_json`, `cached_at`, `created_at`) VALUES ('1', 'Celestial0579', 'Hengstverzeichnis_Addons', NULL, '1', NULL, NULL, NULL, '2026-09-28 07:05:34');

-- Tabelle: api_keys
DROP TABLE IF EXISTS `api_keys`;
CREATE TABLE `api_keys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `label` varchar(100) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `token_prefix` varchar(20) NOT NULL,
  `scope_permissions` text DEFAULT NULL,
  `issued_session_version` int(11) NOT NULL DEFAULT 1,
  `last_used_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL DEFAULT current_timestamp(),
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_api_keys_user` (`user_id`,`revoked_at`),
  CONSTRAINT `api_keys_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle: audit_logs
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(50) NOT NULL DEFAULT 'SYSTEM',
  `action` varchar(100) NOT NULL,
  `category` varchar(50) NOT NULL DEFAULT 'general',
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_at` (`created_at`),
  KEY `category` (`category`),
  KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=66 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle: contact_id_map
DROP TABLE IF EXISTS `contact_id_map`;
CREATE TABLE `contact_id_map` (
  `old_type` enum('person','station') NOT NULL,
  `old_id` int(11) NOT NULL,
  `contact_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`old_type`,`old_id`),
  KEY `idx_contact_id_map_contact` (`contact_id`),
  CONSTRAINT `contact_id_map_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle: contacts
DROP TABLE IF EXISTS `contacts`;
CREATE TABLE `contacts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `contact_info` text DEFAULT NULL,
  `street` varchar(150) DEFAULT NULL,
  `house_number` varchar(20) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `mobile` varchar(50) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `membership_status` varchar(100) DEFAULT NULL,
  `is_breeder` tinyint(1) NOT NULL DEFAULT 0,
  `contact_public` tinyint(1) NOT NULL DEFAULT 0,
  `is_published` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_contacts_deleted_name` (`deleted_at`,`name`),
  KEY `idx_contacts_is_breeder` (`is_breeder`,`is_published`,`deleted_at`),
  KEY `idx_contacts_published_name` (`is_published`,`deleted_at`,`name`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `contacts` (`id`, `name`, `contact_person`, `contact_info`, `street`, `house_number`, `postal_code`, `city`, `state`, `country`, `address`, `email`, `phone`, `mobile`, `website`, `membership_status`, `is_breeder`, `contact_public`, `is_published`, `created_at`, `updated_at`, `deleted_at`) VALUES ('1', 'WarnKontakt-6aba11cb45a52', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '0', '0', '1', '2026-09-28 07:05:47', '2026-09-28 07:05:47', NULL);
INSERT INTO `contacts` (`id`, `name`, `contact_person`, `contact_info`, `street`, `house_number`, `postal_code`, `city`, `state`, `country`, `address`, `email`, `phone`, `mobile`, `website`, `membership_status`, `is_breeder`, `contact_public`, `is_published`, `created_at`, `updated_at`, `deleted_at`) VALUES ('2', 'KAKontakt-6aba11cbab99d', NULL, NULL, NULL, NULL, NULL, 'Kontaktdorf-6aba11cbab99d', NULL, NULL, NULL, 'kontakt-6aba11cbab99d@example.test', NULL, NULL, NULL, NULL, '0', '0', '1', '2026-09-28 07:05:47', '2026-09-28 07:05:47', NULL);
INSERT INTO `contacts` (`id`, `name`, `contact_person`, `contact_info`, `street`, `house_number`, `postal_code`, `city`, `state`, `country`, `address`, `email`, `phone`, `mobile`, `website`, `membership_status`, `is_breeder`, `contact_public`, `is_published`, `created_at`, `updated_at`, `deleted_at`) VALUES ('3', 'KALimit-6aba11d23597c', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'limit-kontakt-6aba11d23597c@example.test', NULL, NULL, NULL, NULL, '0', '0', '1', '2026-09-28 07:05:54', '2026-09-28 07:05:54', NULL);
INSERT INTO `contacts` (`id`, `name`, `contact_person`, `contact_info`, `street`, `house_number`, `postal_code`, `city`, `state`, `country`, `address`, `email`, `phone`, `mobile`, `website`, `membership_status`, `is_breeder`, `contact_public`, `is_published`, `created_at`, `updated_at`, `deleted_at`) VALUES ('4', 'KAMigPerson-6aba11d2bf8c5', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '0', '0', '1', '2026-09-28 07:05:54', '2026-09-28 07:05:54', NULL);
INSERT INTO `contacts` (`id`, `name`, `contact_person`, `contact_info`, `street`, `house_number`, `postal_code`, `city`, `state`, `country`, `address`, `email`, `phone`, `mobile`, `website`, `membership_status`, `is_breeder`, `contact_public`, `is_published`, `created_at`, `updated_at`, `deleted_at`) VALUES ('5', 'KAMigStation-6aba11d2bf8c5', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '0', '0', '1', '2026-09-28 07:05:54', '2026-09-28 07:05:54', NULL);
INSERT INTO `contacts` (`id`, `name`, `contact_person`, `contact_info`, `street`, `house_number`, `postal_code`, `city`, `state`, `country`, `address`, `email`, `phone`, `mobile`, `website`, `membership_status`, `is_breeder`, `contact_public`, `is_published`, `created_at`, `updated_at`, `deleted_at`) VALUES ('6', 'KAMigSonde-6aba11d2bf8c5', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '0', '0', '1', '2026-09-28 07:05:54', '2026-09-28 07:05:54', NULL);

-- Tabelle: email_2fa_codes
DROP TABLE IF EXISTS `email_2fa_codes`;
CREATE TABLE `email_2fa_codes` (
  `user_id` int(11) NOT NULL,
  `purpose` varchar(20) NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`purpose`),
  CONSTRAINT `email_2fa_codes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle: gdpr_requests
DROP TABLE IF EXISTS `gdpr_requests`;
CREATE TABLE `gdpr_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `request_type` enum('info','deletion') NOT NULL,
  `message` text DEFAULT NULL,
  `status` enum('pending','processed','rejected') DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle: group_permissions
DROP TABLE IF EXISTS `group_permissions`;
CREATE TABLE `group_permissions` (
  `group_id` int(11) NOT NULL,
  `module` varchar(50) NOT NULL,
  `action` varchar(50) NOT NULL,
  PRIMARY KEY (`group_id`,`module`,`action`),
  CONSTRAINT `group_permissions_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `group_permissions` (`group_id`, `module`, `action`) VALUES ('2', 'contacts', 'create');
INSERT INTO `group_permissions` (`group_id`, `module`, `action`) VALUES ('2', 'contacts', 'delete');
INSERT INTO `group_permissions` (`group_id`, `module`, `action`) VALUES ('2', 'contacts', 'edit');
INSERT INTO `group_permissions` (`group_id`, `module`, `action`) VALUES ('2', 'contacts', 'publish');
INSERT INTO `group_permissions` (`group_id`, `module`, `action`) VALUES ('2', 'contacts', 'view');
INSERT INTO `group_permissions` (`group_id`, `module`, `action`) VALUES ('2', 'horses', 'create');
INSERT INTO `group_permissions` (`group_id`, `module`, `action`) VALUES ('2', 'horses', 'delete');
INSERT INTO `group_permissions` (`group_id`, `module`, `action`) VALUES ('2', 'horses', 'edit');
INSERT INTO `group_permissions` (`group_id`, `module`, `action`) VALUES ('2', 'horses', 'publish');
INSERT INTO `group_permissions` (`group_id`, `module`, `action`) VALUES ('2', 'horses', 'view');
INSERT INTO `group_permissions` (`group_id`, `module`, `action`) VALUES ('3', 'contacts', 'view');
INSERT INTO `group_permissions` (`group_id`, `module`, `action`) VALUES ('3', 'horses', 'view');

-- Tabelle: groups
DROP TABLE IF EXISTS `groups`;
CREATE TABLE `groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_builtin` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `require_2fa` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `groups` (`id`, `slug`, `name`, `description`, `is_builtin`, `created_at`, `require_2fa`) VALUES ('1', 'admin', 'Administrator', 'Hat systemseitig immer uneingeschränkt alle Berechtigungen.', '1', '2026-09-28 07:05:34', '1');
INSERT INTO `groups` (`id`, `slug`, `name`, `description`, `is_builtin`, `created_at`, `require_2fa`) VALUES ('2', 'editor', 'Editor', 'Vorlage für Bearbeiter mit Verwaltungszugriff - muss Benutzern wie jede andere Gruppe bewusst zugewiesen werden, kein automatischer Standard.', '1', '2026-09-28 07:05:34', '1');
INSERT INTO `groups` (`id`, `slug`, `name`, `description`, `is_builtin`, `created_at`, `require_2fa`) VALUES ('3', 'public', 'Gast (Öffentlich)', 'Gilt automatisch für nicht angemeldete Besucher. Über ihre Lese-Rechte steuert ein Admin, welche Bereiche im öffentlichen Teil der Website sichtbar sind. Backend-Zugriff (/admin/...) bleibt stets ausgeschlossen (siehe BaseController::checkAuth()).', '1', '2026-09-28 07:05:34', '1');

-- Tabelle: horse_media
DROP TABLE IF EXISTS `horse_media`;
CREATE TABLE `horse_media` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `horse_id` int(11) NOT NULL,
  `type` enum('image','video') NOT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `video_url` varchar(255) DEFAULT NULL,
  `caption` varchar(255) DEFAULT NULL,
  `is_main` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_horse_media_horse` (`horse_id`,`sort_order`,`id`),
  CONSTRAINT `horse_media_ibfk_1` FOREIGN KEY (`horse_id`) REFERENCES `horses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle: horse_persons
DROP TABLE IF EXISTS `horse_persons`;
CREATE TABLE `horse_persons` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `horse_id` int(11) NOT NULL,
  `contact_id` int(11) DEFAULT NULL,
  `role` enum('breeder','owner','keeper') NOT NULL DEFAULT 'owner',
  `station_contact_id` int(11) DEFAULT NULL,
  `breeding_station_text` varchar(255) DEFAULT NULL,
  `origin_country` varchar(100) DEFAULT NULL,
  `from_year` smallint(5) unsigned DEFAULT NULL,
  `until_year` smallint(5) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_horse_persons_horse_role` (`horse_id`,`role`),
  KEY `idx_horse_persons_contact` (`contact_id`,`horse_id`),
  KEY `idx_horse_persons_station_contact` (`station_contact_id`),
  CONSTRAINT `horse_persons_ibfk_1` FOREIGN KEY (`horse_id`) REFERENCES `horses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `horse_persons_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `horse_persons_ibfk_3` FOREIGN KEY (`station_contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle: horse_registrations
DROP TABLE IF EXISTS `horse_registrations`;
CREATE TABLE `horse_registrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `horse_id` int(11) NOT NULL,
  `registration_number` varchar(50) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_horse_registrations_horse` (`horse_id`,`sort_order`),
  KEY `idx_horse_registrations_number` (`registration_number`),
  CONSTRAINT `horse_registrations_ibfk_1` FOREIGN KEY (`horse_id`) REFERENCES `horses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle: horses
DROP TABLE IF EXISTS `horses`;
CREATE TABLE `horses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `ueln` varchar(50) DEFAULT NULL,
  `foreign_ueln` varchar(50) DEFAULT NULL,
  `sire_id` int(11) DEFAULT NULL,
  `sire_name` varchar(100) DEFAULT NULL,
  `sire_ueln` varchar(15) DEFAULT NULL,
  `dam_id` int(11) DEFAULT NULL,
  `dam_name` varchar(100) DEFAULT NULL,
  `dam_ueln` varchar(15) DEFAULT NULL,
  `birth_year` smallint(5) unsigned DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `birth_date_precision` enum('day','year') NOT NULL DEFAULT 'day',
  `color` varchar(50) DEFAULT NULL,
  `sex` enum('stallion','mare','gelding') DEFAULT NULL,
  `castration_date` date DEFAULT NULL,
  `breed` varchar(100) DEFAULT NULL,
  `height_cm` smallint(5) unsigned DEFAULT NULL,
  `breeding_station_id` int(11) DEFAULT NULL,
  `breeding_station` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `is_deceased` tinyint(1) NOT NULL DEFAULT 0,
  `death_year` smallint(5) unsigned DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 0,
  `image_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ueln` (`ueln`),
  KEY `sire_id` (`sire_id`),
  KEY `dam_id` (`dam_id`),
  KEY `breeding_station_id` (`breeding_station_id`),
  KEY `idx_horses_published_name` (`is_published`,`deleted_at`,`name`),
  KEY `idx_horses_deleted_name` (`deleted_at`,`name`),
  KEY `idx_horses_name` (`name`),
  KEY `idx_horses_foreign_ueln` (`foreign_ueln`),
  KEY `idx_horses_color` (`color`,`deleted_at`),
  KEY `idx_horses_breed` (`breed`,`deleted_at`),
  KEY `idx_horses_sire_unlinked` (`deleted_at`,`sire_id`),
  KEY `idx_horses_dam_unlinked` (`deleted_at`,`dam_id`),
  CONSTRAINT `horses_ibfk_1` FOREIGN KEY (`sire_id`) REFERENCES `horses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `horses_ibfk_2` FOREIGN KEY (`dam_id`) REFERENCES `horses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `horses_ibfk_3` FOREIGN KEY (`breeding_station_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `horses` (`id`, `name`, `ueln`, `foreign_ueln`, `sire_id`, `sire_name`, `sire_ueln`, `dam_id`, `dam_name`, `dam_ueln`, `birth_year`, `birth_date`, `birth_date_precision`, `color`, `sex`, `castration_date`, `breed`, `height_cm`, `breeding_station_id`, `breeding_station`, `description`, `status`, `is_deceased`, `death_year`, `is_published`, `image_url`, `created_at`, `updated_at`, `deleted_at`) VALUES ('1', 'MigrationsPferd-6aba11c1c9661', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2020', NULL, 'day', '', 'stallion', NULL, 'Fjordpferd', NULL, NULL, NULL, '', 'active', '0', NULL, '0', NULL, '2026-09-28 07:05:37', '2026-09-28 07:05:37', NULL);
INSERT INTO `horses` (`id`, `name`, `ueln`, `foreign_ueln`, `sire_id`, `sire_name`, `sire_ueln`, `dam_id`, `dam_name`, `dam_ueln`, `birth_year`, `birth_date`, `birth_date_precision`, `color`, `sex`, `castration_date`, `breed`, `height_cm`, `breeding_station_id`, `breeding_station`, `description`, `status`, `is_deceased`, `death_year`, `is_published`, `image_url`, `created_at`, `updated_at`, `deleted_at`) VALUES ('2', 'TeilarchivPferd-6aba11c71764e', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2019', NULL, 'day', '', 'stallion', NULL, 'Fjordpferd', NULL, NULL, NULL, '', 'active', '0', NULL, '0', NULL, '2026-09-28 07:05:43', '2026-09-28 07:05:43', NULL);
INSERT INTO `horses` (`id`, `name`, `ueln`, `foreign_ueln`, `sire_id`, `sire_name`, `sire_ueln`, `dam_id`, `dam_name`, `dam_ueln`, `birth_year`, `birth_date`, `birth_date_precision`, `color`, `sex`, `castration_date`, `breed`, `height_cm`, `breeding_station_id`, `breeding_station`, `description`, `status`, `is_deceased`, `death_year`, `is_published`, `image_url`, `created_at`, `updated_at`, `deleted_at`) VALUES ('3', 'WarnPferd-6aba11cb45a52', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2018', NULL, 'day', '', 'stallion', NULL, 'Fjordpferd', NULL, NULL, NULL, '', 'active', '0', NULL, '0', NULL, '2026-09-28 07:05:47', '2026-09-28 07:05:47', NULL);

-- Tabelle: login_attempts
DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `identifier` varchar(255) NOT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'login',
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `identifier` (`identifier`,`type`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle: match_labels
DROP TABLE IF EXISTS `match_labels`;
CREATE TABLE `match_labels` (
  `kind` enum('horse','contact') NOT NULL,
  `left_id` int(11) NOT NULL,
  `right_id` int(11) NOT NULL,
  `label` enum('merged','different','unclear') NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(50) NOT NULL DEFAULT 'SYSTEM',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`kind`,`left_id`,`right_id`),
  KEY `idx_match_labels_kind_label` (`kind`,`label`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle: password_resets
DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle: plugin_kontaktanfrage_config
DROP TABLE IF EXISTS `plugin_kontaktanfrage_config`;
CREATE TABLE `plugin_kontaktanfrage_config` (
  `config_key` varchar(64) NOT NULL,
  `config_value` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle: plugin_kontaktanfrage_optout
DROP TABLE IF EXISTS `plugin_kontaktanfrage_optout`;
CREATE TABLE `plugin_kontaktanfrage_optout` (
  `contact_id` int(11) NOT NULL,
  `disabled_by` varchar(100) DEFAULT NULL,
  `disabled_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle: plugin_kontaktanfrage_requests
DROP TABLE IF EXISTS `plugin_kontaktanfrage_requests`;
CREATE TABLE `plugin_kontaktanfrage_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contact_id` int(11) NOT NULL DEFAULT 0,
  `reason_key` varchar(64) NOT NULL,
  `reason_label` varchar(100) NOT NULL,
  `requester_name` varchar(150) NOT NULL,
  `requester_email` varchar(150) NOT NULL,
  `team_notified` tinyint(1) NOT NULL DEFAULT 0,
  `forwarded_at` datetime DEFAULT NULL,
  `forwarded_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ka_kontakt` (`contact_id`),
  KEY `idx_ka_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle: plugins
DROP TABLE IF EXISTS `plugins`;
CREATE TABLE `plugins` (
  `slug` varchar(100) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `installed_version` varchar(20) NOT NULL DEFAULT '0.0.0',
  `content_hash` varchar(64) DEFAULT NULL,
  `dir_stamp` varchar(64) DEFAULT NULL,
  `source` varchar(150) DEFAULT NULL,
  `activated_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle: settings
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `updated_at`) VALUES ('1', 'site_name', 'CI Addon-Testverband', '2026-09-28 07:05:34');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `updated_at`) VALUES ('2', 'primary_color', '#2c3e50', '2026-09-28 07:05:34');

-- Tabelle: user_groups
DROP TABLE IF EXISTS `user_groups`;
CREATE TABLE `user_groups` (
  `user_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  PRIMARY KEY (`user_id`,`group_id`),
  KEY `group_id` (`group_id`),
  CONSTRAINT `user_groups_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_groups_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle: user_passkeys
DROP TABLE IF EXISTS `user_passkeys`;
CREATE TABLE `user_passkeys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `credential_id` varchar(512) NOT NULL,
  `credential` text NOT NULL,
  `label` varchar(100) NOT NULL,
  `sign_count` bigint(20) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_passkeys_credential` (`credential_id`(255)),
  KEY `idx_user_passkeys_user` (`user_id`),
  CONSTRAINT `user_passkeys_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle: users
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `totp_secret` varchar(255) DEFAULT NULL,
  `totp_enabled` tinyint(1) DEFAULT 0,
  `email_2fa_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `backup_codes` text DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `session_version` int(11) NOT NULL DEFAULT 1,
  `last_totp_timeslice` bigint(20) DEFAULT NULL,
  `email_verification_token` varchar(64) DEFAULT NULL,
  `email_verification_expires_at` datetime DEFAULT NULL,
  `pending_email` varchar(100) DEFAULT NULL,
  `pending_email_token` varchar(64) DEFAULT NULL,
  `pending_email_expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `deactivated_at` datetime DEFAULT NULL,
  `deactivated_reason` varchar(64) DEFAULT NULL,
  `unprotected_since` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_users_deleted` (`deleted_at`),
  KEY `idx_users_deactivated` (`deactivated_at`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
