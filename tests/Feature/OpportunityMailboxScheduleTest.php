<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OpportunityMailboxScheduleTest extends TestCase
{
    #[Test]
    public function it_schedules_the_poll_every_five_minutes_without_overlap_when_enabled(): void
    {
        config()->set('opportunity_mailbox.enabled', true);

        $event = $this->mailboxPollEvent();

        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
        $this->assertFalse($event->runInBackground);
        $this->assertTrue($event->filtersPass($this->app));
    }

    #[Test]
    public function it_does_not_schedule_network_work_when_disabled(): void
    {
        config()->set('opportunity_mailbox.enabled', false);

        $event = $this->mailboxPollEvent();

        $this->assertFalse($event->filtersPass($this->app));
    }

    private function mailboxPollEvent(): Event
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains(
                (string) $event->command,
                'opportunity:poll-mailbox',
            ));

        $this->assertInstanceOf(Event::class, $event);

        return $event;
    }
}
