<?php

declare(strict_types=1);

namespace Webconsulting\Skills\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webconsulting\Skills\Service\RepositoryImportService;
use Webconsulting\Skills\Service\SkillFinder;
use Webconsulting\Skills\Service\SkillImportService;
use Webconsulting\Skills\Support\Typed;

#[AsCommand(
    name: 'webconskills:sync',
    description: 'Import/update skills from the configured skills folder and all skill repositories'
)]
final class SyncSkillsCommand extends Command
{
    public function __construct(
        private readonly SkillImportService $skillImportService,
        private readonly RepositoryImportService $repositoryImportService,
        private readonly SkillFinder $skillFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('repository', 'r', InputOption::VALUE_REQUIRED, 'Only sync the repository with this uid')
            ->addOption('skip-folder', null, InputOption::VALUE_NONE, 'Skip the local skills folder import');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $hasErrors = false;

        if (!$input->getOption('skip-folder') && $input->getOption('repository') === null) {
            $result = $this->skillImportService->importFromConfiguredFolder();
            $io->section('Folder: ' . $this->skillImportService->getConfiguredFolder());
            $io->writeln($result->summary());
            $hasErrors = $result->errors !== [];
        }

        $repositoryUid = $input->getOption('repository');
        $repositories = $repositoryUid !== null
            ? array_filter([$this->skillFinder->findRepositoryByUid(Typed::int($repositoryUid))])
            : $this->skillFinder->findAllRepositories();

        foreach ($repositories as $repository) {
            $io->section(sprintf('Repository: %s (%s)', Typed::string($repository['title']), Typed::string($repository['url'])));
            try {
                $result = $this->repositoryImportService->sync(Typed::int($repository['uid']));
                $io->writeln($result->summary());
                $hasErrors = $hasErrors || $result->errors !== [];
            } catch (\Throwable $e) {
                $io->error($e->getMessage());
                $hasErrors = true;
            }
        }

        return $hasErrors ? Command::FAILURE : Command::SUCCESS;
    }
}
