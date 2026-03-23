<?php

declare(strict_types=1);

namespace openvk\CLI;

use Chandler\Database\DatabaseConnection;
use openvk\Web\Util\TelegramNewsFetcher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class FetchTelegramNewsCommand extends Command
{
    protected static $defaultName = "fetch-telegram-news";

    protected function configure(): void
    {
        $this->setDescription("Fetches public Telegram channel posts into the global news feed")
             ->setHelp("This command pulls fresh posts from enabled Telegram sources, stores them locally and removes news older than 3 days.");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ctx     = DatabaseConnection::i()->getContext();
        $sources = $ctx->table("tg_news_sources")->where("is_enabled", 1);

        if (sizeof($sources) === 0) {
            $output->writeln("No enabled Telegram news sources found.");

            return Command::SUCCESS;
        }

        $fetcher        = new TelegramNewsFetcher();
        $insertedTotal  = 0;
        $processedTotal = 0;

        foreach ($sources as $source) {
            try {
                $result = $fetcher->fetchChannel($source->telegram_handle);
            } catch (\Throwable $e) {
                $output->writeln(sprintf("Failed to fetch @%s: %s", $source->telegram_handle, $e->getMessage()));
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

            $output->writeln(sprintf(
                "@%s: processed %d items, inserted %d new",
                $source->telegram_handle,
                sizeof($result["items"]),
                $insertedForSource
            ));
        }

        $deleted = $ctx->table("tg_news_items")
                       ->where("published_at < ?", date("Y-m-d H:i:s", time() - 3 * 24 * 60 * 60))
                       ->delete();

        $output->writeln(sprintf("Done. Processed: %d, inserted: %d, removed expired: %d", $processedTotal, $insertedTotal, $deleted));

        return Command::SUCCESS;
    }
}
