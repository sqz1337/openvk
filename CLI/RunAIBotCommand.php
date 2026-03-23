<?php

declare(strict_types=1);

namespace openvk\CLI;

use openvk\Web\Util\AIBotRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RunAIBotCommand extends Command
{
    protected static $defaultName = "run-ai-bot";

    protected function configure(): void
    {
        $this->setDescription("Runs one AI bot decision cycle")
            ->addOption("bot-id", null, InputOption::VALUE_OPTIONAL, "Specific AI bot ID to run")
            ->addOption("dry-run", null, InputOption::VALUE_NONE, "Only ask the model and print the decision");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = (new AIBotRunner())->run(
                $input->getOption("bot-id") !== null ? (int) $input->getOption("bot-id") : null,
                (bool) $input->getOption("dry-run")
            );
        } catch (\Throwable $e) {
            $output->writeln("AI bot runner failed: " . $e->getMessage());

            return Command::FAILURE;
        }

        $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
