<?php

namespace App\Console\Commands;

use App\Application\DemoAi\OwnerReportDelivery;
use App\Domain\DemoAi\DemoAiException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('demo-ai:deliver-owner {run : Existing synthetic report ID}
    {--recover : Classify interrupted delivery without sending}
    {--receipt= : Opaque reference to explicit owner receipt evidence; never sends}')]
#[Description('Send a stored synthetic report once to the protected owner; no automatic retries')]
class DeliverOwnerReportCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(OwnerReportDelivery $delivery): int
    {
        try {
            if ($this->option('recover') && $this->option('receipt') !== null) {
                throw new DemoAiException('demo_ai.delivery_conflicting_action');
            }
            $id = (string) $this->argument('run');
            $status = match (true) {
                (bool) $this->option('recover') => $delivery->recover($id),
                $this->option('receipt') !== null => $delivery->receipt($id, (string) $this->option('receipt')),
                default => $delivery->send($id),
            };
        } catch (DemoAiException $exception) {
            $this->error($exception->failureCode);

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('demo_ai.delivery_operation_failed: inspect durable state; do not blindly resend.');

            return self::FAILURE;
        }
        $this->line('Owner delivery: '.$status.'. Transport acceptance is not recipient receipt.');

        return in_array($status, ['accepted', 'receipt_operator_reported'], true) ? self::SUCCESS : self::FAILURE;
    }
}
