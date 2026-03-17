ALTER TABLE `conversations`
  ADD COLUMN `avatar_file` varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL AFTER `title`;
