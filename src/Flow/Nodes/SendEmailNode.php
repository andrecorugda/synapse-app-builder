<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Nodes;

use Andre\AiPageBuilder\Capabilities\CapabilityCategory;
use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Capabilities\CapabilityInput;
use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
use Andre\AiPageBuilder\Flow\Contracts\ProvidesNodeDefinition;
use Andre\AiPageBuilder\Flow\FlowContext;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Services\PageBuilderMailer;
use Throwable;

/**
 * Sends an email from a flow. The HTML body is a reusable builder page tagged
 * as an email template (pages with kind=email) — selected by slug — so no
 * separate email-authoring surface is needed. The page's html (+ css) is
 * interpolated against the flow context, so `{{ input.x }}` / `{{ vars.x }}` /
 * `{{ states.x }}` tokens in the template are filled in at send time.
 *
 * Sending goes through PageBuilderMailer (the builder's own SMTP transport,
 * configured in Settings) — never the host app's mailer.
 *
 * Node config shape:
 *   {
 *     "to":       "{{ input.email }}",   // required; a literal comma list or array ok, one address per token
 *     "subject":  "Welcome {{ input.name }}",
 *     "template": "welcome-email",        // slug of an email-template page (body source)
 *     "body":     "<p>...</p>",           // inline HTML fallback when no template
 *     "cc":       "ops@example.com",      // optional
 *     "bcc":      "",                      // optional
 *     "reply_to": "",                      // optional
 *     "output":   "email"                  // ctx var to receive {sent, error?}
 *   }
 *
 * Failure is non-fatal: a missing transport or a send error is recorded in the
 * output var ({ "sent": false, "error": "…" }) and the flow continues.
 */
class SendEmailNode implements FlowNodeHandler, ProvidesNodeDefinition
{
    public function __construct(private readonly PageBuilderMailer $mailer) {}

    public function type(): string
    {
        return 'send_email';
    }

    public function definition(): CapabilityDefinition
    {
        return new CapabilityDefinition(
            key: $this->type(),
            label: 'Send Email',
            category: CapabilityCategory::Communication,
            description: 'Sends an email through the builder\'s own SMTP transport. The body is an email-template page selected by slug, or inline HTML; both are interpolated against the flow. Failures are non-fatal and reported in the output variable.',
            usage: 'to "{{ input.email }}", subject "Welcome {{ input.name }}", template "welcome-email", output "email" → sends, then {{ vars.email.sent }} is true on success.',
            icon: 'envelope',
            inputs: [
                new CapabilityInput('to', 'To', 'string', required: true, help: 'Recipient(s) — a single address, a comma-separated list, or an array. Only a list you type yourself adds several addresses: each {{ token }} adds one address (the first in its value, which must be valid, or nothing is sent). To + CC + BCC are capped by flow.email_max_recipients (default 10).'),
                new CapabilityInput('subject', 'Subject', 'string', help: 'Email subject line (interpolated).'),
                new CapabilityInput('template', 'Template page', 'string', help: 'Slug of an email-template page used as the HTML body. Takes precedence over the inline body.'),
                new CapabilityInput('body', 'Inline body', 'text', help: 'Inline HTML body used when no template slug is given. {{ tokens }} are HTML-escaped; {{{ token }}} inserts trusted HTML as-is.'),
                new CapabilityInput('cc', 'CC', 'string', help: 'Optional carbon-copy recipient(s).'),
                new CapabilityInput('bcc', 'BCC', 'string', help: 'Optional blind carbon-copy recipient(s).'),
                new CapabilityInput('reply_to', 'Reply-To', 'string', help: 'Optional reply-to address.'),
                new CapabilityInput('output', 'Output variable', 'string', default: 'email', help: 'Context variable receiving {sent: bool, error?: string} (default "email").'),
            ],
            outputHandles: ['next'],
        );
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<int,string>
     */
    public function run(array $node, FlowContext $context): array
    {
        $config = (array) ($node['config'] ?? []);
        $output = (string) ($config['output'] ?? 'email');

        $to = $this->addresses($config['to'] ?? '', $context);
        $subject = (string) $context->interpolate((string) ($config['subject'] ?? ''));
        $html = $this->resolveBody($config, $context);

        if ($to === null) {
            $context->set($output, ['sent' => false, 'error' => 'Invalid recipient address.']);

            return (array) ($node['next'] ?? []);
        }

        if ($to === [] || $html === '') {
            $context->set($output, ['sent' => false, 'error' => 'Missing recipient or empty body.']);

            return (array) ($node['next'] ?? []);
        }

        if (! $this->mailer->configured()) {
            $context->set($output, ['sent' => false, 'error' => 'Email is not configured. Set the SMTP transport in Page Builder Settings.']);

            return (array) ($node['next'] ?? []);
        }

        $opts = [];
        foreach (['cc', 'bcc', 'reply_to'] as $key) {
            $list = $this->addresses($config[$key] ?? '', $context);
            if ($list === null) {
                $context->set($output, ['sent' => false, 'error' => 'Invalid '.$key.' address.']);

                return (array) ($node['next'] ?? []);
            }
            if ($list !== []) {
                $opts[$key] = $key === 'reply_to' ? $list[0] : $list;
            }
        }

        $total = count($to) + count((array) ($opts['cc'] ?? [])) + count((array) ($opts['bcc'] ?? []));
        if ($total > $this->maxRecipients()) {
            $context->set($output, ['sent' => false, 'error' => 'Too many recipients (the limit is '.$this->maxRecipients().').']);

            return (array) ($node['next'] ?? []);
        }

        try {
            $this->mailer->send($to, $subject, $html, $opts);
            $context->set($output, ['sent' => true]);
        } catch (Throwable $e) {
            $context->set($output, ['sent' => false, 'error' => $e->getMessage()]);
        }

        return (array) ($node['next'] ?? []);
    }

    /**
     * The interpolated HTML body: an email-template page's html (+ its css
     * inlined as a <style> block) when a template slug is given, else the
     * inline `body` config. Interpolated against the flow context either way.
     *
     * @param  array<string,mixed>  $config
     */
    private function resolveBody(array $config, FlowContext $context): string
    {
        $slug = trim((string) ($config['template'] ?? ''));

        if ($slug !== '') {
            /** @var class-string<Page> $model */
            $model = config('ai-page-builder.models.page', Page::class);
            $page = $model::query()->where('slug', $slug)->first();

            if ($page !== null) {
                $css = is_string($page->css) && $page->css !== '' ? '<style>'.$page->css.'</style>' : '';
                $html = $css.(is_string($page->html) ? $page->html : '');

                return $context->interpolateHtml($html);
            }
        }

        return $context->interpolateHtml((string) ($config['body'] ?? ''));
    }

    /**
     * Resolve an address field (a string template, or an array of them) to a
     * recipient list, or null to refuse the send.
     *
     * Only the AUTHOR can write a list: commas in the template text itself
     * (`"ops@x.com, sales@x.com"`) or several array entries. A `{{ token }}`
     * contributes exactly ONE address whatever it resolves from — `input`, a
     * State, or a `vars` value a previous step copied from the visitor — the
     * first one in its value, which must be valid (otherwise nothing is sent).
     * Taint cannot be tracked through vars, so the rule applies to every token:
     * one request can't fan a mail out to any number of recipients.
     *
     * The final list is then validated whatever its source: every entry must
     * be a single valid address with no CR / LF / `;` / `,` in it.
     *
     * @return list<string>|null
     */
    private function addresses(mixed $raw, FlowContext $context): ?array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [$raw] as $template) {
            if (! is_scalar($template)) {
                continue;
            }

            // Split the author's literal text on commas FIRST, then resolve the
            // tokens inside each entry, so a token value can never add an entry.
            foreach (explode(',', (string) $template) as $entry) {
                $invalid = false;
                $resolved = (string) preg_replace_callback(
                    '/\{\{\{?\s*([a-zA-Z0-9_.]+)\s*\}?\}\}/',
                    function (array $m) use ($context, &$invalid): string {
                        $single = $this->firstAddress($context->stringValue($m[1]));
                        if ($single === null) {
                            $invalid = true;

                            return '';
                        }

                        return $single;
                    },
                    $entry,
                );

                if ($invalid) {
                    return null;
                }

                $address = trim($resolved);
                if ($address === '') {
                    continue;
                }
                if (! $this->isSingleAddress($address)) {
                    return null;
                }

                $out[] = $address;
            }
        }

        return array_values(array_unique($out));
    }

    /** The first address in a token value, or null when it isn't a valid one. */
    private function firstAddress(string $value): ?string
    {
        $first = (string) (preg_split('/[\s,;]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY)[0] ?? '');

        return $this->isSingleAddress($first) ? $first : null;
    }

    /** One plain, valid address: no list separators, no header-breaking characters. */
    private function isSingleAddress(string $address): bool
    {
        if ($address === '' || preg_match('/[\r\n,;\s<>]/', $address) === 1) {
            return false;
        }

        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** The most recipients (to + cc + bcc) one send may have; see `flow.email_max_recipients`. */
    private function maxRecipients(): int
    {
        return max(1, (int) config('ai-page-builder.flow.email_max_recipients', 10));
    }
}
