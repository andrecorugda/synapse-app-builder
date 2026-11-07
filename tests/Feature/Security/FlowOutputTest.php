<?php

declare(strict_types=1);

/*
 * Regression tests for flow output hardening in 2.0 (GHSA-hj3q-8gc4-6mpw):
 * HTML fields escape interpolated values, navigation accepts only http(s) or
 * relative URLs, and send_email cannot be fanned out or used for HTML phishing
 * by the visitor.
 */

use Andre\AiPageBuilder\Flow\FlowRunner;
use Andre\AiPageBuilder\Services\PageBuilderMailer;
use Andre\AiPageBuilder\Services\Settings;

const FLOW_OUTPUT_XSS = '<img src=x onerror=alert(document.domain)>';

function resultActions(array $actions, array $input = []): array
{
    $definition = ['start' => 'r', 'nodes' => ['r' => ['type' => 'result', 'config' => ['actions' => $actions]]]];

    return app(FlowRunner::class)->run($definition, $input)->actions;
}

/** A mailer double that records what would be sent. */
function recordingMailer(): object
{
    $fake = new class(app(Settings::class)) extends PageBuilderMailer
    {
        public array $sent = [];

        public function configured(): bool
        {
            return true;
        }

        public function send(string|array $to, string $subject, string $html, array $opts = []): void
        {
            $this->sent[] = compact('to', 'subject', 'html', 'opts');
        }
    };
    app()->instance(PageBuilderMailer::class, $fake);

    return $fake;
}

function runSendEmail(array $config, array $input = [], array $overlay = []): array
{
    $ctx = app(FlowRunner::class)->run(['start' => 'e', 'nodes' => ['e' => ['type' => 'send_email', 'config' => $config + ['output' => 'email']]]], $input, $overlay);

    return (array) $ctx->get('vars.email');
}

// --- HTML fields ---------------------------------------------------------------

it('HTML-escapes interpolated values in a setHtml action', function (): void {
    $actions = resultActions([['type' => 'setHtml', 'target' => '#greet', 'html' => '<b>Hello {{ input.name }}</b>']], ['name' => FLOW_OUTPUT_XSS]);

    expect($actions[0]['html'])->toBe('<b>Hello &lt;img src=x onerror=alert(document.domain)&gt;</b>');
});

it('HTML-escapes interpolated values in a modal body', function (): void {
    $actions = resultActions([['type' => 'modal', 'target' => '#m', 'html' => '<p>{{ input.msg }}</p>']], ['msg' => FLOW_OUTPUT_XSS]);

    expect($actions[0]['html'])->not->toContain('<img');
});

it('inserts a value unescaped only through the explicit {{{ }}} form', function (): void {
    $actions = resultActions([['type' => 'setHtml', 'target' => '#out', 'html' => '<div>{{{ input.trusted }}}|{{ input.trusted }}</div>']], ['trusted' => '<em>hi</em>']);

    expect($actions[0]['html'])->toBe('<div><em>hi</em>|&lt;em&gt;hi&lt;/em&gt;</div>');
});

it('leaves plain-text fields unescaped (the runtime renders them as text)', function (): void {
    $actions = resultActions([['type' => 'notify', 'message' => 'Hi {{ input.name }} & co']], ['name' => 'Tom <3']);

    expect($actions[0]['message'])->toBe('Hi Tom <3 & co');
});

// --- Navigation ----------------------------------------------------------------

it('drops a redirect to a script URL', function (string $url): void {
    $actions = resultActions([['type' => 'redirect', 'url' => '{{ input.next }}']], ['next' => $url]);

    expect($actions)->toBe([]);
})->with([
    'javascript' => ['javascript:alert(document.domain)'],
    'mixed case + whitespace' => [" JaVa\tScRiPt:alert(1)"],
    'data' => ['data:text/html,<script>alert(1)</script>'],
    'vbscript' => ['vbscript:msgbox(1)'],
]);

it('keeps http(s) and relative redirects', function (string $url): void {
    $actions = resultActions([['type' => 'redirect', 'url' => $url]]);

    expect($actions[0]['url'])->toBe($url);
})->with(['https://example.com/next', 'http://example.com', '/p/thanks', 'thanks?x=1', '#done']);

it('signs out to the login page when a logout URL is unsafe', function (): void {
    $actions = resultActions([['type' => 'logout', 'url' => 'javascript:alert(1)']]);

    expect($actions[0]['type'])->toBe('logout')->and($actions[0])->not->toHaveKey('url');
});

// --- send_email ----------------------------------------------------------------

it('sends to one address only when a visitor token holds a list', function (string $to): void {
    $mailer = recordingMailer();

    runSendEmail(['to' => '{{ input.email }}', 'subject' => 'Thanks', 'body' => '<p>Hi</p>'], ['email' => $to]);

    expect($mailer->sent)->toHaveCount(1)->and($mailer->sent[0]['to'])->toBe(['a@victim.test']);
})->with([
    'comma list' => ['a@victim.test,b@victim.test,c@victim.test'],
    'semicolon list' => ['a@victim.test;b@victim.test'],
    'space list' => ['a@victim.test b@victim.test'],
    'header injection' => ["a@victim.test\r\nBcc: b@victim.test"],
]);

it('refuses to send when a visitor token is not an address', function (string $to): void {
    $mailer = recordingMailer();

    $result = runSendEmail(['to' => '{{ input.email }}', 'subject' => 'Thanks', 'body' => '<p>Hi</p>'], ['email' => $to]);

    expect($mailer->sent)->toBe([])->and($result['sent'])->toBeFalse();
})->with(['nobody', '', 'not an address, b@victim.test']);

it('limits a visitor token to one address in cc and bcc as well', function (): void {
    $mailer = recordingMailer();

    runSendEmail(['to' => 'owner@shop.test', 'bcc' => '{{ input.email }}', 'subject' => 'S', 'body' => '<p>x</p>'], ['email' => 'a@x.test,b@x.test']);

    expect($mailer->sent[0]['opts']['bcc'])->toBe(['a@x.test']);
});

it('limits a State the page sent for this run to one address', function (): void {
    $mailer = recordingMailer();

    runSendEmail(['to' => '{{ states.contact }}', 'subject' => 'S', 'body' => '<p>x</p>'], [], ['contact' => 'a@x.test,b@x.test']);

    expect($mailer->sent[0]['to'])->toBe(['a@x.test']);
});

it('sends to one valid visitor address and to author-written lists', function (): void {
    $mailer = recordingMailer();

    runSendEmail(['to' => '{{ input.email }}, owner@shop.test, sales@shop.test', 'subject' => 'Thanks', 'body' => '<p>Hi</p>'], ['email' => 'buyer@x.test']);

    expect($mailer->sent[0]['to'])->toBe(['buyer@x.test', 'owner@shop.test', 'sales@shop.test']);
});

it('HTML-escapes interpolated values in the email body', function (): void {
    $mailer = recordingMailer();

    runSendEmail(['to' => 'owner@shop.test', 'subject' => 'New lead', 'body' => '<p>From {{ input.name }}</p>'], [
        'name' => '<a href="https://phish.test">Reset your password</a>',
    ]);

    expect($mailer->sent[0]['html'])->not->toContain('<a href')
        ->toContain('&lt;a href=&quot;https://phish.test&quot;&gt;');
});
