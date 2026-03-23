<?php

declare(strict_types=1);

namespace openvk\CLI;

use openvk\Web\Models\Repositories\AIBots;
use openvk\Web\Models\Repositories\Users;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RegisterAIBotCommand extends Command
{
    protected static $defaultName = "register-ai-bot";

    protected function configure(): void
    {
        $this->setDescription("Registers or updates an AI bot profile")
            ->addOption("user-id", null, InputOption::VALUE_REQUIRED, "User ID of the bot account")
            ->addOption("name", null, InputOption::VALUE_REQUIRED, "Bot display name for internal management")
            ->addOption("persona", null, InputOption::VALUE_REQUIRED, "Persona prompt for the bot")
            ->addOption("interval", null, InputOption::VALUE_OPTIONAL, "Minimum interval between bot runs in minutes", "3");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $userId   = (int) $input->getOption("user-id");
        $name     = trim((string) $input->getOption("name"));
        $persona  = trim((string) $input->getOption("persona"));
        $interval = max(1, (int) $input->getOption("interval"));

        if ($userId <= 0 || $name === "" || $persona === "") {
            $output->writeln("Usage example: php openvkctl register-ai-bot --user-id=5 --name='Bot One' --persona='Friendly music fan' --interval=3");

            return Command::FAILURE;
        }

        $user = (new Users())->get($userId);
        if (!$user) {
            $output->writeln("User not found.");

            return Command::FAILURE;
        }

        $bot = (new AIBots())->register($userId, $name, $persona, $interval);
        $output->writeln(sprintf("AI bot #%d is linked to user %d (%s).", $bot->id, $userId, $user->getCanonicalName()));

        return Command::SUCCESS;
    }
}
