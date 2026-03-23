CREATE TABLE `ai_bots` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(128) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `persona` mediumtext COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `min_interval_minutes` int(10) UNSIGNED NOT NULL DEFAULT 3,
  `last_run_at` timestamp NULL DEFAULT NULL,
  `last_action_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ai_bots_user_id` (`user_id`),
  KEY `ai_bots_enabled_idx` (`is_enabled`, `last_run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `ai_bot_action_logs` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id` bigint(20) UNSIGNED NOT NULL,
  `action` varchar(64) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `payload_json` longtext COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `result_json` longtext COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ai_bot_action_logs_bot_id_idx` (`bot_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
