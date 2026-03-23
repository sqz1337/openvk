<?php

declare(strict_types=1);

namespace openvk\CLI;

use openvk\Web\Models\Repositories\TelegramNews;
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
        $repo = new TelegramNews();
        if (sizeof($repo->getSources()) === 0) {
            $output->writeln("No enabled Telegram news sources found.");

            return Command::SUCCESS;
        }

        $repo->refresh(static function (string $message) use ($output): void {
            $output->writeln($message);
        });

        return Command::SUCCESS;
    }
}
