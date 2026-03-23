<?php

declare(strict_types=1);

namespace openvk\Web\Models\Repositories;

use Chandler\Database\DatabaseConnection;

final class TelegramNews
{
    public function getFeedPage(int $page, int $perPage, array $sourceIds = []): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $offset  = ($page - 1) * $perPage;
        $params  = [];
        $filter  = "";

        $sourceIds = array_values(array_filter(array_map("intval", $sourceIds), static fn(int $id): bool => $id > 0));
        if (sizeof($sourceIds) > 0) {
            $filter = " AND s.id IN (" . implode(", ", array_fill(0, sizeof($sourceIds), "?")) . ")";
            $params = array_merge($params, $sourceIds);
        }

        $sql = <<<'SQL'
            SELECT
                i.id,
                i.text,
                i.image_url,
                i.original_url,
                i.published_at,
                s.title AS source_title,
                s.telegram_handle,
                s.avatar_url AS source_avatar_url
            FROM tg_news_items i
            INNER JOIN tg_news_sources s ON s.id = i.source_id
            WHERE s.is_enabled = 1
        SQL;

        $sql .= $filter . " ORDER BY i.published_at DESC, i.id DESC LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;

        return DatabaseConnection::i()->getConnection()->query($sql, ...$params)->fetchAll();
    }

    public function getFeedCount(array $sourceIds = []): int
    {
        $params   = [];
        $filter   = "";
        $sourceIds = array_values(array_filter(array_map("intval", $sourceIds), static fn(int $id): bool => $id > 0));
        if (sizeof($sourceIds) > 0) {
            $filter = " AND s.id IN (" . implode(", ", array_fill(0, sizeof($sourceIds), "?")) . ")";
            $params = array_merge($params, $sourceIds);
        }

        $sql = <<<'SQL'
            SELECT COUNT(*) AS cnt
            FROM tg_news_items i
            INNER JOIN tg_news_sources s ON s.id = i.source_id
            WHERE s.is_enabled = 1
        SQL;

        return (int) DatabaseConnection::i()->getConnection()->query($sql . $filter, ...$params)->fetch()->cnt;
    }

    public function getSources(): array
    {
        return DatabaseConnection::i()
            ->getContext()
            ->table("tg_news_sources")
            ->order("title ASC")
            ->fetchAll();
    }

    public function addSource(string $title, string $telegramHandle): void
    {
        DatabaseConnection::i()
            ->getContext()
            ->table("tg_news_sources")
            ->insert([
                "title"           => $title,
                "telegram_handle" => $telegramHandle,
                "avatar_url"      => null,
                "is_enabled"      => 1,
                "last_fetched_at" => null,
                "created_at"      => date("Y-m-d H:i:s"),
                "updated_at"      => date("Y-m-d H:i:s"),
            ]);
    }
}
