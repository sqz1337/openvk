<?php

declare(strict_types=1);

namespace openvk\Web\Models\Repositories;

use Chandler\Database\DatabaseConnection;

final class TelegramNews
{
    public function getFeedPage(int $page, int $perPage): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $offset  = ($page - 1) * $perPage;

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
            ORDER BY i.published_at DESC, i.id DESC
            LIMIT ? OFFSET ?
        SQL;

        return DatabaseConnection::i()->getConnection()->query($sql, $perPage, $offset)->fetchAll();
    }

    public function getFeedCount(): int
    {
        $sql = <<<'SQL'
            SELECT COUNT(*) AS cnt
            FROM tg_news_items i
            INNER JOIN tg_news_sources s ON s.id = i.source_id
            WHERE s.is_enabled = 1
        SQL;

        return (int) DatabaseConnection::i()->getConnection()->query($sql)->fetch()->cnt;
    }
}
