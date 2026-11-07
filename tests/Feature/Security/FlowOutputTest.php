<?php

declare(strict_types=1);

/*
 * Regression tests for flow output hardening in 2.0 (GHSA-hj3q-8gc4-6mpw):
 * HTML fields escape interpolated values, navigation accepts only http(s) or
 * relative URLs, and send_email cannot be fanned out or used for HTML phishing
 * by the visitor.
 */

use Andre\AiPageBuilder\Flow\FlowRunner;
use Andre\AiPageBuilder\Models\Partial;
use Andre\AiPageBuilder\Services\Data\VariableStore;
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

it('does not let a visitor value become a javascript: link inside setHtml', function (string $url): void {
    $html = resultActions([['type' => 'setHtml', 'target' => '#profile', 'html' => '<a href="{{ input.website }}">Website</a>']], ['website' => $url])[0]['html'];

    expect(strtolower($html))->not->toContain('javascript:')->not->toContain('data:text')
        ->and($html)->toContain('Website');
})->with([
    'javascript' => ['javascript:alert(document.domain)'],
    'entity-encoded scheme' => ['jav&#x61;script:alert(1)'],
    'tab inside scheme' => ["java\tscript:alert(1)"],
    'data document' => ['data:text/html,<script>alert(1)</script>'],
]);

it('keeps http(s), relative, mailto and tel links built from a value', function (string $url): void {
    $html = resultActions([['type' => 'setHtml', 'target' => '#x', 'html' => '<a href="{{ input.u }}">go</a>']], ['u' => $url])[0]['html'];

    expect($html)->toContain('href=');
})->with(['https://example.com/a?b=1', '/p/thanks', 'mailto:hi@example.com', 'tel:+3212345']);

it('does not let a visitor value break out of a JS string in an event handler attribute', function (): void {
    $html = resultActions([['type' => 'setHtml', 'target' => '#x', 'html' => '<button onclick="greet(\'{{ input.name }}\')">Hi</button>']], ['name' => "');alert(document.domain);('"])[0]['html'];

    $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
    expect($decoded)->not->toContain('alert(document.domain)')
        ->and($html)->toContain('Hi');
});

it('drops an Alpine directive whose expression contains a token', function (): void {
    $html = resultActions([['type' => 'setHtml', 'target' => '#x', 'html' => '<span x-text="\'Hi {{ input.name }}\'" class="c-{{ input.name }}">x</span>']], ['name' => "'+alert(1)+'"])[0]['html'];

    expect($html)->not->toContain('x-text')
        ->and($html)->toContain('class=');
});

it('keeps an author-written Alpine directive without tokens', function (): void {
    $html = resultActions([['type' => 'setHtml', 'target' => '#x', 'html' => '<div x-data="{ open: false }" x-show="open">{{ input.name }}</div>']], ['name' => 'Ann'])[0]['html'];

    expect($html)->toContain('x-data=')->toContain('x-show=')->toContain('Ann');
});

it('strips script, handlers and Alpine from a {{{ raw }}} value', function (): void {
    $raw = '<b onmouseover="alert(1)">bold</b><script>alert(2)</script><div x-data="{ a: alert(3) }">d</div><a href="javascript:alert(4)">l</a>';
    $html = resultActions([['type' => 'setHtml', 'target' => '#x', 'html' => '<div>{{{ vars.snippet }}}</div>']], [])[0]['html'];
    expect($html)->toBe('<div></div>');

    $definition = ['start' => 's', 'nodes' => [
        's' => ['type' => 'set_variable', 'config' => ['key' => '', 'value' => '{{ input.raw }}', 'output' => 'snippet'], 'next' => ['r']],
        'r' => ['type' => 'result', 'config' => ['actions' => [['type' => 'setHtml', 'target' => '#x', 'html' => '<div>{{{ vars.snippet }}}</div>']]]],
    ]];
    $html = app(FlowRunner::class)->run($definition, ['raw' => $raw])->actions[0]['html'];

    expect($html)->toContain('<b>bold</b>')
        ->not->toContain('onmouseover')->not->toContain('<script')
        ->not->toContain('x-data')->not->toContain('javascript:');
});

it('does not let a token in a tag or attribute name create markup', function (): void {
    $html = resultActions([['type' => 'setHtml', 'target' => '#x', 'html' => '<{{ input.t }}>a</{{ input.t }}><p {{{ input.a }}}>b</p>']], [
        't' => 'div x-data="alert(1)"',
        'a' => 'x-init="alert(2)"',
    ])[0]['html'];

    expect($html)->not->toContain('alert')->toContain('b');
});

it('sanitises a modal Partial after its tokens are filled in', function (): void {
    Partial::query()->create(['name' => 'Card', 'slug' => 'card', 'html' => '<a href="{{ input.link }}">open</a>']);

    $html = resultActions([['type' => 'modal', 'target' => '#m', 'partial' => 'card']], ['link' => 'javascript:alert(1)'])[0]['html'];

    expect($html)->not->toContain('javascript:')->toContain('open');
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

it('limits a visitor list to one address even after it passes through a vars step', function (): void {
    $mailer = recordingMailer();

    app(FlowRunner::class)->run(['start' => 'v', 'nodes' => [
        'v' => ['type' => 'set_variable', 'config' => ['key' => '', 'value' => '{{ input.email }}', 'output' => 'recipient'], 'next' => ['e']],
        'e' => ['type' => 'send_email', 'config' => ['to' => '{{ vars.recipient }}', 'subject' => 'Thanks', 'body' => '<p>Hi</p>', 'output' => 'email']],
    ]], ['email' => 'a@victim.test,b@victim.test,c@victim.test']);

    expect($mailer->sent)->toHaveCount(1)
        ->and($mailer->sent[0]['to'])->toBe(['a@victim.test']);
});

it('limits a server-side State token to one address too', function (): void {
    $mailer = recordingMailer();
    app(VariableStore::class)->set('ops', 'a@x.test,b@x.test');

    runSendEmail(['to' => '{{ states.ops }}', 'subject' => 'S', 'body' => '<p>x</p>']);

    expect($mailer->sent[0]['to'])->toBe(['a@x.test']);
});

it('refuses an author-written entry that is not a single valid address', function (string $to): void {
    $mailer = recordingMailer();

    $result = runSendEmail(['to' => $to, 'subject' => 'S', 'body' => '<p>x</p>']);

    expect($mailer->sent)->toBe([])->and($result['sent'])->toBeFalse();
})->with([
    'semicolon list' => ['a@x.test;b@x.test'],
    'CRLF' => ["a@x.test\r\nBcc: b@x.test"],
    'not an address' => ['owner at shop'],
]);

it('refuses a send above the recipient limit', function (): void {
    $mailer = recordingMailer();
    config(['ai-page-builder.flow.email_max_recipients' => 3]);

    $result = runSendEmail(['to' => 'a@x.test, b@x.test', 'cc' => 'c@x.test', 'bcc' => 'd@x.test', 'subject' => 'S', 'body' => '<p>x</p>']);

    expect($mailer->sent)->toBe([])->and($result['sent'])->toBeFalse()
        ->and($result['error'])->toContain('Too many recipients');

    runSendEmail(['to' => 'a@x.test, b@x.test', 'cc' => 'c@x.test', 'subject' => 'S', 'body' => '<p>x</p>']);
    expect($mailer->sent)->toHaveCount(1);
});

it('HTML-escapes interpolated values in the email body', function (): void {
    $mailer = recordingMailer();

    runSendEmail(['to' => 'owner@shop.test', 'subject' => 'New lead', 'body' => '<p>From {{ input.name }}</p>'], [
        'name' => '<a href="https://phish.test">Reset your password</a>',
    ]);

    expect($mailer->sent[0]['html'])->not->toContain('<a href')
        ->toContain('&lt;a href=&quot;https://phish.test&quot;&gt;');
});
