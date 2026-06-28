<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Nodes;

use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
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
 *     "to":       "{{ input.email }}",   // required (interpolated); array or comma list ok
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
class SendEmailNode implements FlowNodeHandler
{
    public function __construct(private readonly PageBuilderMailer $mailer) {}

    public function type(): string
    {
        return 'send_email';
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<int,string>
     */
    public function run(array $node, FlowContext $context): array
    {
        $config = (array) ($node['config'] ?? []);
        $output = (string) ($config['output'] ?? 'email');

        $to = $this->recipients($context->interpolateDeep($config['to'] ?? ''));
        $subject = (string) $context->interpolate((string) ($config['subject'] ?? ''));
        $html = $this->resolveBody($config, $context);

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
            $val = $context->interpolate((string) ($config[$key] ?? ''));
            if (trim($val) !== '') {
                $opts[$key] = $key === 'reply_to' ? $val : $this->recipients($val);
            }
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

                return (string) $context->interpolate($html);
            }
        }

        return (string) $context->interpolate((string) ($config['body'] ?? ''));
    }

    /**
     * Normalise a recipient value (string, comma list, or array) to a clean
     * list of addresses.
     *
     * @return list<string>
     */
    private function recipients(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = explode(',', (string) $value);
        }

        $out = [];
        foreach ($items as $item) {
            $addr = trim((string) $item);
            if ($addr !== '') {
                $out[] = $addr;
            }
        }

        return array_values(array_unique($out));
    }
}
