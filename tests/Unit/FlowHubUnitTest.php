<?php

namespace Tests\Unit;

use App\Services\Pages\HtmlRenderer;
use App\Services\Pages\HtmlSanitizer;
use App\Services\Tracking\FraudScorer;
use App\Services\Tracking\PostbackParser;
use App\Support\CopyGuard;
use Tests\TestCase;

class FlowHubUnitTest extends TestCase
{
    public function test_copy_guard_replaces_sales_language_and_dashes(): void
    {
        $clean = CopyGuard::clean("A special \u{2014} price, then buy now.");

        $this->assertStringNotContainsString('price', strtolower($clean));
        $this->assertStringNotContainsString("\u{2014}", $clean);
        $this->assertStringContainsString('value', $clean);
        $this->assertStringContainsString('take the next step', $clean);
    }

    public function test_renderer_strips_scripts_and_keeps_text(): void
    {
        $html = (new HtmlRenderer(new HtmlSanitizer))->render([
            'headline' => 'A clear <script>alert(1)</script> headline',
            'subheadline' => 'Excellent value',
            'callsToAction' => [['label' => 'Take the next step']],
        ]);

        $this->assertStringNotContainsString('<script', strtolower($html));
        $this->assertStringContainsString('A clear', $html);
        $this->assertStringContainsString('data-outbound="1"', $html);
    }

    public function test_sanitizer_removes_event_handlers_and_external_links(): void
    {
        $html = (new HtmlSanitizer)->sanitize('<a href="https://evil.test" onclick="alert(1)">Next</a><p>Kept</p>');

        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('https://evil.test', $html);
        $this->assertStringContainsString('Kept', $html);
    }

    public function test_digistore_signature_and_clickbank_fields(): void
    {
        $parser = new PostbackParser;
        $fields = [
            'order_id' => 'A1',
            'amount' => '12.50',
            'currency' => 'USD',
        ];
        $sign = $parser->digistoreSignature($fields, 'secret');
        $body = http_build_query([...$fields, 'sha_sign' => $sign]);
        $parsed = $parser->parse($body, [], 'secret');

        $this->assertSame('A1', $parsed->orderId);
        $this->assertTrue($parsed->signaturePresent);
        $this->assertTrue($parsed->signatureValid);
        $this->assertSame(12.5, $parsed->amount);

        $bad = $parser->parse(http_build_query([...$fields, 'sha_sign' => 'NOPE']), [], 'secret');
        $this->assertFalse($bad->signatureValid);

        $clickbank = $parser->parse(http_build_query([
            'receipt' => 'CB1',
            'accountAmount' => '9.00',
            'currency' => 'USD',
            'tid' => 'session-1',
            'transactionType' => 'SALE',
        ]), [], 'secret');
        $this->assertSame('CB1', $clickbank->orderId);
        $this->assertSame('session-1', $clickbank->sessionToken);
        $this->assertSame('sale', $clickbank->eventType);
        $this->assertFalse($clickbank->signaturePresent);
    }

    public function test_datacenter_and_bot_detection(): void
    {
        $scorer = new FraudScorer;

        $this->assertTrue($scorer->isBot('Mozilla/5.0 Googlebot/2.1'));
        $this->assertFalse($scorer->isBot('Mozilla/5.0 (Linux; Android 14) Chrome/120.0.0.0 Mobile'));
        $this->assertTrue($scorer->isDatacenter('3.1.2.3'));
        $this->assertFalse($scorer->isDatacenter('8.8.8.8'));
        $this->assertTrue($scorer->inCidr('52.1.2.3', '52.0.0.0/10'));
    }
}
