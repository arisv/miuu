<?php

namespace App\Command;

use App\Service\ThumbnailService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:thumbnails:create-missing',
    description: 'Add a short description for your command',
)]
class ThumbnailsCreateMissingCommand extends Command
{
    public function __construct(
        private ThumbnailService $thumbnailService
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {

    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $output->writeln("Preparing to generate thumbnails for all files");
            $this->thumbnailService->generateMissingThumbnails();
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln($e->getMessage());
            return Command::FAILURE;
        }
    }
}
