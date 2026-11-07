<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Console;

use Andre\AiPageBuilder\Models\Credential;
use Andre\AiPageBuilder\Services\CredentialHosts;
use Illuminate\Console\Command;

/**
 * List and set the hosts each Credential may be sent to.
 *
 * Since 2.0 an HTTP Request node attaches a credential only when the URL's
 * host is on that credential's allowed hosts; an empty list sends it nowhere.
 * With no option the command shows every credential, its allowed hosts, and
 * the hosts its flows call that are NOT allowed (those requests are refused).
 *
 *   ai-page-builder:credential-hosts                          # review
 *   ai-page-builder:credential-hosts stripe --add=api.stripe.com
 *   ai-page-builder:credential-hosts --from-flows             # allow what flows already call
 */
class CredentialHostsCommand extends Command
{
    protected $signature = 'ai-page-builder:credential-hosts
        {key? : Only this credential}
        {--add= : Comma-separated hosts (or *.example.com) to allow for the credential named by key}
        {--remove= : Comma-separated hosts to remove from the credential named by key}
        {--from-flows : Allow every host the stored flows already call with each credential}';

    protected $description = 'List the hosts each Credential may be sent to, or change them.';

    public function handle(CredentialHosts $hosts): int
    {
        /** @var class-string<Credential> $model */
        $model = config('ai-page-builder.models.credential', Credential::class);
        $key = $this->argument('key');
        $add = (string) ($this->option('add') ?? '');
        $remove = (string) ($this->option('remove') ?? '');

        if (($add !== '' || $remove !== '') && ! is_string($key)) {
            $this->error('Name the credential: ai-page-builder:credential-hosts <key> --add=host');

            return self::FAILURE;
        }

        $query = $model::query()->orderBy('key');
        if (is_string($key) && $key !== '') {
            $query->where('key', $key);
        }
        $credentials = $query->get();

        if (is_string($key) && $key !== '' && $credentials->isEmpty()) {
            $this->error("No credential with key \"{$key}\".");

            return self::FAILURE;
        }

        $used = $hosts->hostsUsedByFlows();

        foreach ($credentials as $credential) {
            /** @var Credential $credential */
            $allowed = $credential->allowed_hosts;
            $next = $allowed;

            if ($add !== '') {
                $next = array_merge($next, CredentialHosts::normalize($add));
            }
            if ($remove !== '') {
                $next = array_values(array_diff($next, CredentialHosts::normalize($remove)));
            }
            if ($this->option('from-flows')) {
                $next = array_merge($next, $used[$credential->key] ?? []);
            }

            $next = CredentialHosts::normalize($next);
            if ($next !== $allowed) {
                $credential->allowed_hosts = $next;
                $credential->save();
                $this->info("{$credential->key}: ".($next === [] ? '(none)' : implode(', ', $next)));
            }
        }

        $this->table(
            ['Credential', 'Allowed hosts', 'Called by flows but NOT allowed (refused)'],
            $credentials->map(function (Credential $c) use ($used): array {
                $refused = array_filter(
                    $used[$c->key] ?? [],
                    static fn (string $host): bool => ! $c->allowsHost($host),
                );

                return [
                    $c->key,
                    $c->allowed_hosts === [] ? '(none: never sent)' : implode(', ', $c->allowed_hosts),
                    implode(', ', $refused),
                ];
            })->all(),
        );

        return self::SUCCESS;
    }
}
