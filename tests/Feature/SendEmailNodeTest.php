<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Flow\FlowRunner;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Services\PageBuilderMailer;
use Andre\AiPageBuilder\Services\Settings;

/**
 * A recording fake mailer so the node can be tested without a real SMTP server.
 */
function fakeMailer(bool $configured = true): object
{
    $fake = new class(app(Settings::class)) extends PageBuilderMailer
    {
        public bool $isConfigured = true;

        /** @var array<int,array<string,mixed>> */
        public array $sent = [];

        public function configured(): bool
        {
            return $this->isConfigured;
        }

        public function send(string|array $to, string $subject, string $html, array $opts = []): void
        {
            $this->sent[] = compact('to', 'subject', 'html', 'opts');
        }
    };

    $fake->isConfigured = $configured;
    app()->instance(PageBuilderMailer::class, $fake);

    return $fake;
}

it('sends an email using an email-template page as the body, interpolated', function (): void {
    $mailer = fakeMailer();

    Page::factory()->create([
        'slug' => 'welcome-email',
        'kind' => 'email',
        'html' => '<p>Hi {{ input.name }}</p>',
        'css' => '.x{color:red}',
    ]);

    $def = [
        'start' => 'e',
        'nodes' => [
            'e' => ['type' => 'send_email', 'config' => [
                'to' => '{{ input.email }}',
                'subject' => 'Welcome {{ input.name }}',
                'template' => 'welcome-email',
                'output' => 'email',
            ]],
        ],
    ];

    $ctx = app(FlowRunner::class)->run($def, ['name' => 'Ada', 'email' => 'ada@example.com']);

    expect($ctx->vars['email'])->toBe(['sent' => true])
        ->and($mailer->sent)->toHaveCount(1)
        ->and($mailer->sent[0]['to'])->toBe(['ada@example.com'])
        ->and($mailer->sent[0]['subject'])->toBe('Welcome Ada')
        ->and($mailer->sent[0]['html'])->toContain('<p>Hi Ada</p>')
        ->and($mailer->sent[0]['html'])->toContain('<style>.x{color:red}</style>');
});

it('falls back to inline HTML when no template is set', function (): void {
    $mailer = fakeMailer();

    $def = [
        'start' => 'e',
        'nodes' => [
            'e' => ['type' => 'send_email', 'config' => [
                'to' => 'ops@example.com',
                'subject' => 'Ping',
                'body' => '<b>{{ input.msg }}</b>',
                'output' => 'email',
            ]],
        ],
    ];

    $ctx = app(FlowRunner::class)->run($def, ['msg' => 'hello']);

    expect($ctx->vars['email'])->toBe(['sent' => true])
        ->and($mailer->sent[0]['html'])->toBe('<b>hello</b>');
});

it('records a non-fatal error when the mailer is not configured', function (): void {
    $mailer = fakeMailer(configured: false);

    $def = [
        'start' => 'e',
        'nodes' => [
            'e' => ['type' => 'send_email', 'config' => [
                'to' => 'x@example.com', 'subject' => 'x', 'body' => 'x', 'output' => 'email',
            ]],
        ],
    ];

    $ctx = app(FlowRunner::class)->run($def);

    expect($ctx->vars['email']['sent'])->toBeFalse()
        ->and($ctx->vars['email']['error'])->toContain('not configured')
        ->and($mailer->sent)->toBeEmpty();
});

it('parses comma-separated and cc/bcc recipients', function (): void {
    $mailer = fakeMailer();

    $def = [
        'start' => 'e',
        'nodes' => [
            'e' => ['type' => 'send_email', 'config' => [
                'to' => 'a@example.com, b@example.com',
                'subject' => 's',
                'body' => 'body',
                'cc' => 'c@example.com',
                'reply_to' => 'r@example.com',
                'output' => 'email',
            ]],
        ],
    ];

    app(FlowRunner::class)->run($def);

    expect($mailer->sent[0]['to'])->toBe(['a@example.com', 'b@example.com'])
        ->and($mailer->sent[0]['opts']['cc'])->toBe(['c@example.com'])
        ->and($mailer->sent[0]['opts']['reply_to'])->toBe('r@example.com');
});
