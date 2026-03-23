<?php

declare(strict_types=1);

namespace openvk\Web\Models\Repositories;

use Chandler\Database\DatabaseConnection;
use openvk\Web\Util\TelegramNewsFetcher;

final class TelegramNews
{
    public function getFeedPage(int $page, int $perPage, ?array $sourceIds = null): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $offset  = ($page - 1) * $perPage;
        $params  = [];
        $filter  = "";

        if (is_array($sourceIds)) {
            $sourceIds = array_values(array_filter(array_map("intval", $sourceIds), static fn(int $id): bool => $id > 0));
            if (sizeof($sourceIds) > 0) {
                $filter = " AND s.id IN (" . implode(", ", array_fill(0, sizeof($sourceIds), "?")) . ")";
                $params = array_merge($params, $sourceIds);
            } else {
                $filter = " AND 0 = 1";
            }
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

    public function getFeedCount(?array $sourceIds = null): int
    {
        $params   = [];
        $filter   = "";
        if (is_array($sourceIds)) {
            $sourceIds = array_values(array_filter(array_map("intval", $sourceIds), static fn(int $id): bool => $id > 0));
            if (sizeof($sourceIds) > 0) {
                $filter = " AND s.id IN (" . implode(", ", array_fill(0, sizeof($sourceIds), "?")) . ")";
                $params = array_merge($params, $sourceIds);
            } else {
                $filter = " AND 0 = 1";
            }
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

    public function refresh(?callable $logger = null): array
    {
        $ctx            = DatabaseConnection::i()->getContext();
        $sources        = $ctx->table("tg_news_sources")->where("is_enabled", 1);
        $fetcher        = new TelegramNewsFetcher();
        $insertedTotal  = 0;
        $processedTotal = 0;

        foreach ($sources as $source) {
            try {
                $result = $fetcher->fetchChannel($source->telegram_handle);
            } catch (\Throwable $e) {
                if ($logger !== null) {
                    $logger(sprintf("Failed to fetch @%s: %s", $source->telegram_handle, $e->getMessage()));
                }

                continue;
            }

            $source->update([
                "title"           => $result["title"],
                "avatar_url"      => $result["avatar_url"],
                "last_fetched_at" => date("Y-m-d H:i:s"),
                "updated_at"      => date("Y-m-d H:i:s"),
            ]);

            $insertedForSource = 0;

            foreach ($result["items"] as $item) {
                $processedTotal++;

                $exists = $ctx->table("tg_news_items")->where([
                    "source_id"   => $source->id,
                    "external_id" => $item["external_id"],
                ])->fetch();

                if ($exists) {
                    continue;
                }

                $ctx->table("tg_news_items")->insert([
                    "source_id"    => $source->id,
                    "external_id"  => $item["external_id"],
                    "text"         => $item["text"],
                    "image_url"    => $item["image_url"],
                    "original_url" => $item["original_url"],
                    "published_at" => $item["published_at"],
                    "created_at"   => date("Y-m-d H:i:s"),
                ]);

                $insertedForSource++;
                $insertedTotal++;
            }

            if ($logger !== null) {
                $logger(sprintf(
                    "@%s: processed %d items, inserted %d new",
                    $source->telegram_handle,
                    sizeof($result["items"]),
                    $insertedForSource
                ));
            }
        }

        $deleted = $ctx->table("tg_news_items")
                       ->where("published_at < ?", date("Y-m-d H:i:s", time() - 3 * 24 * 60 * 60))
                       ->delete();

        if ($logger !== null) {
            $logger(sprintf("Done. Processed: %d, inserted: %d, removed expired: %d", $processedTotal, $insertedTotal, $deleted));
        }

        return [
            "processed" => $processedTotal,
            "inserted"  => $insertedTotal,
            "deleted"   => $deleted,
        ];
    }
}
