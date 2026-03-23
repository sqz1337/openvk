CREATE TABLE IF NOT EXISTS `conversations` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `creator` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(128) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `avatar_file` varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `created` bigint(20) UNSIGNED NOT NULL,
  `updated` bigint(20) UNSIGNED NOT NULL,
  `last_message_id` bigint(20) UNSIGNED DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE IF NOT EXISTS `conversation_participants` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint(20) UNSIGNED NOT NULL,
  `participant_type` varchar(64) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `participant_id` bigint(20) UNSIGNED NOT NULL,
  `joined` bigint(20) UNSIGNED NOT NULL,
  `last_read_message_id` bigint(20) UNSIGNED DEFAULT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `left_at` bigint(20) UNSIGNED DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `conversation_member` (`conversation_id`,`participant_type`,`participant_id`,`deleted`),
  KEY `participant_lookup` (`participant_type`,`participant_id`,`deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

ALTER TABLE `messages`
  ADD COLUMN `conversation_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `recipient_id`,
  ADD KEY `conversation_lookup` (`conversation_id`, `created`);
