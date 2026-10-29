<?php

namespace Tests\Feature;

use App\Domain\DemoAi\DemoAiException;
use App\Infrastructure\Email\DemoEmailNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DemoEmailNormalizerTest extends TestCase
{
    #[Test]
    public function it_normalizes_the_entire_approved_corpus_without_transport_or_private_fields(): void
    {
        $parser = app(DemoEmailNormalizer::class);
        foreach (range(1, 20) as $number) {
            $source = $parser->parse(file_get_contents(base_path(sprintf('tests/Fixtures/Emails/demo/%03d.eml', $number))));
            $this->assertSame(sprintf('DEMO-%03d', $number), $source['source_id']);
            $this->assertArrayNotHasKey('source_url', $source['input']);
            $this->assertArrayNotHasKey('recipient', $source['input']);
            $this->assertArrayHasKey('client_country', $source['input']);
            $this->assertSame(64, strlen($source['input_sha256']));
        }
    }

    #[Test]
    public function it_rejects_changed_approved_content(): void
    {
        $raw = file_get_contents(base_path('tests/Fixtures/Emails/demo/001.eml'));
        $this->expectExceptionMessage('demo_ai.content_unapproved');

        app(DemoEmailNormalizer::class)->parse(str_replace('Eight hours', 'Forty hours', $raw));
    }

    #[Test]
    public function it_rejects_both_unapproved_adversarial_messages(): void
    {
        foreach (['invalid-json', 'injected-instructions'] as $name) {
            try {
                app(DemoEmailNormalizer::class)->parse(file_get_contents(base_path("tests/Fixtures/Emails/demo/adversarial/{$name}.eml")));
                $this->fail('An adversarial source was admitted.');
            } catch (DemoAiException $exception) {
                $this->assertStringStartsWith('demo_ai.', $exception->failureCode);
            }
        }
    }

    #[Test]
    public function it_rejects_raw_oversize_before_mime_parsing(): void
    {
        $this->expectExceptionMessage('demo_ai.message_size');
        app(DemoEmailNormalizer::class)->parse(str_repeat('x', 65_537));
    }

    #[Test]
    public function it_preserves_missing_facts_as_null(): void
    {
        $source = app(DemoEmailNormalizer::class)->parse(file_get_contents(base_path('tests/Fixtures/Emails/demo/020.eml')));

        $this->assertNull($source['input']['hours_per_week_max']);
        $this->assertNull($source['input']['payment_verified']);
    }
}
