<?php

namespace App\Command;

use App\Service\ApiKeyService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:apikeys:list',
    description: 'Lists all API Keys',
)]
class ApikeysListCommand extends Command
{
    public function __construct(private readonly ApiKeyService $apiKeyService)
    {
        parent::__construct();
    }

    protected function configure()
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $keys = $this->apiKeyService->listApiKeys();

        $table = new Table($output);
        $table->setHeaders(['Name', 'API Key']);
        foreach ($keys as $key) {
            $table->addRow([$key->getName(), $key->getApiToken()]);
        }
        $table->render();

        return (int) Command::SUCCESS;
    }
}
