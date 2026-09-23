<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Webconsulting\Skillflow\Domain\AbilityFinding;
use Webconsulting\Skillflow\Service\SkillAbilityResolver;
use Webconsulting\Skillflow\Service\SkillAbilityStore;
use Webconsulting\Skillflow\Service\SkillFinder;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Checks the abilities contract of the synchronized skills: every ability a
 * skill declares must be registered (ability_missing) and allowed on the MCP
 * surface for the running backend user (ability_denied).
 */
#[AsCommand(
    name: 'skillflow:skills:check',
    description: 'Check the abilities skills declare: ability_missing (not registered) and ability_denied (denied for the user)',
    aliases: ['skillflow:check'],
)]
final class CheckSkillsCommand extends Command
{
    public function __construct(
        private readonly SkillFinder $skillFinder,
        private readonly SkillAbilityResolver $abilityResolver,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('enabled', 'e', InputOption::VALUE_NONE, 'Only enabled, non-orphaned skills')
            ->addOption('as-user', null, InputOption::VALUE_REQUIRED, 'Check for this backend user (user name) instead of the running one')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the findings as JSON')
            ->setHelp(<<<'HELP'
Skills declare the abilities they need in their SKILL.md front matter:

  abilities: [news/list, solr/index-queue]

This command resolves every declaration against the typo3-abilities
registry, the site policy (config/abilities-policy.yaml) and the scopes of
the backend user:

  <info>ability_missing</info>  declared, but not registered in this installation
                   (or typo3-abilities is not active)
  <info>ability_denied</info>   registered, but the policy, the ability's MCP exposure
                   or the user's scopes deny it

  <info>%command.full_name%</info>
  <info>%command.full_name% --enabled --as-user=editor</info>
  <info>%command.full_name% --json</info>

Exit code 1 when there is at least one finding. Declarations are stored by
<info>skillflow:skills:sync</info>; skills synchronized elsewhere are read from their
front matter.
HELP);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $asUser = trim(Typed::string($input->getOption('as-user')));
        $enabledOnly = (bool)$input->getOption('enabled');

        $checked = 0;
        $rows = [];
        foreach ($this->skillFinder->findAllSkills(!$enabledOnly) as $skill) {
            $abilities = SkillAbilityStore::fromSkillRow($skill);
            if ($abilities === []) {
                continue;
            }
            $checked++;
            try {
                $findings = $asUser !== ''
                    ? $this->abilityResolver->checkAsUser($abilities, $asUser)
                    : $this->abilityResolver->check($abilities, $this->backendUser());
            } catch (\InvalidArgumentException $e) {
                $io->error($e->getMessage());
                return Command::INVALID;
            }
            foreach ($findings as $finding) {
                $rows[] = ['skill' => Typed::string($skill['name'] ?? ''), 'uid' => Typed::int($skill['uid'] ?? 0)] + $finding->toArray();
            }
        }

        if ((bool)$input->getOption('json')) {
            $output->writeln(json_encode(['checked' => $checked, 'findings' => $rows], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $rows === [] ? Command::SUCCESS : Command::FAILURE;
        }

        if (!$this->abilityResolver->isAvailable()) {
            $io->warning('The extension typo3-abilities is not active; every declared ability is reported as missing.');
        }
        if ($checked === 0) {
            $io->note('No skill declares abilities.');
            return Command::SUCCESS;
        }
        if ($rows === []) {
            $io->success(sprintf('%d skill(s) declare abilities; every ability is registered and allowed.', $checked));
            return Command::SUCCESS;
        }

        $io->table(
            ['Skill', 'Ability', 'Finding', 'Message'],
            array_map(static fn(array $row): array => [
                sprintf('%s (%d)', $row['skill'], $row['uid']),
                $row['ability'],
                $row['code'],
                $row['message'],
            ], $rows),
        );
        $missing = count(array_filter($rows, static fn(array $row): bool => $row['code'] === AbilityFinding::MISSING));
        $io->error(sprintf(
            '%d finding(s) in %d skill(s): %d ability_missing, %d ability_denied.',
            count($rows),
            count(array_unique(array_column($rows, 'uid'))),
            $missing,
            count($rows) - $missing,
        ));

        return Command::FAILURE;
    }

    private function backendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication && is_array($backendUser->user) ? $backendUser : null;
    }
}
