CREATE TABLE `tg_news_sources` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` varchar(191) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `telegram_handle` varchar(64) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `avatar_url` varchar(1024) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `last_fetched_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `tg_news_sources_handle` (`telegram_handle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `tg_news_items` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_id` bigint(20) UNSIGNED NOT NULL,
  `external_id` bigint(20) UNSIGNED NOT NULL,
  `text` mediumtext COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `image_url` varchar(2048) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `original_url` varchar(1024) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `published_at` timestamp NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `tg_news_unique` (`source_id`,`external_id`),
  KEY `tg_news_published_at` (`published_at`),
  KEY `tg_news_source_id` (`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

INSERT INTO `tg_news_sources` (`id`, `title`, `telegram_handle`, `avatar_url`, `is_enabled`, `last_fetched_at`, `created_at`, `updated_at`)
VALUES (NULL, 'Медуза — LIVE', 'meduzalive', NULL, 1, NULL, CURRENT_TIMESTAMP(), CURRENT_TIMESTAMP());
