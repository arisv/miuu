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
    description: 'Generate missing thumbnails, or rebuild those of rotated JPEGs with --reorient',
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
        $this->addOption('reorient', null, InputOption::VALUE_NONE, 'Rebuild existing thumbnails of JPEGs with an EXIF rotation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            if ($input->getOption('reorient')) {
                $output->writeln("Rebuilding thumbnails of rotated JPEGs");
                $this->thumbnailService->regenerateReorientedThumbnails();
                return Command::SUCCESS;
            }
            $output->writeln("Preparing to generate thumbnails for all files");
            $this->thumbnailService->generateMissingThumbnails();
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln("Command exited!");
            $output->writeln($e->getMessage());
            $output->write($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}
