<?php

namespace App\Console\Commands;

use App\Application\DemoAi\DemoAiConfiguration;
use App\Application\DemoAi\DemoAiLedger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('demo-ai:recover')]
#[Description('Recover admitted demo AI work and expire feature content without refunding unresolved attempts')]
class RecoverDemoAiCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DemoAiLedger $ledger, DemoAiConfiguration $configuration): int
    {
        $configuration->workspaceId();
        $ledger->recover();

        $this->info('Demo AI recovery completed; unresolved liabilities remain reserved.');

        return self::SUCCESS;
    }
}
