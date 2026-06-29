<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services;

use RuntimeException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Self-contained SMTP mailer the builder uses to send emails from flows.
 *
 * Transport config comes from the {@see Settings} service (the `mail_*` keys
 * the admin tunes on the Settings screen). Crucially this builds its own
 * isolated Symfony transport on every send — it never mutates the host app's
 * global mail config or borrows the host mailer, so a builder send can never
 * interfere with the surrounding application.
 *
 * The DSN is assembled by hand rather than read from env so the builder stays
 * portable across host apps; the SMTP password is read back through
 * {@see Settings::getEncrypted()} so it is never held in plaintext at rest.
 */
class PageBuilderMailer
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * True when host + from_address are set (enough to attempt a send).
     */
    public function configured(): bool
    {
        return $this->host() !== '' && $this->fromAddress() !== '';
    }

    /**
     * Send an HTML email through a freshly-built, isolated SMTP transport.
     *
     * @param  string|array<int,string>  $to
     * @param  array{cc?:string|array<int,string>,bcc?:string|array<int,string>,reply_to?:string,text?:string}  $opts
     *
     * @throws RuntimeException When the SMTP transport is not configured.
     */
    public function send(string|array $to, string $subject, string $html, array $opts = []): void
    {
        if (! $this->configured()) {
            throw new RuntimeException(
                'Synapse email is not configured — set the SMTP host and a from address in Settings first.',
            );
        }

        $email = (new Email)
            ->from(new Address($this->fromAddress(), $this->fromName() ?? ''))
            ->subject($subject)
            ->html($html);

        foreach ($this->normaliseRecipients($to) as $address) {
            $email->addTo($address);
        }

        if (isset($opts['cc'])) {
            foreach ($this->normaliseRecipients($opts['cc']) as $address) {
                $email->addCc($address);
            }
        }

        if (isset($opts['bcc'])) {
            foreach ($this->normaliseRecipients($opts['bcc']) as $address) {
                $email->addBcc($address);
            }
        }

        if (isset($opts['reply_to']) && $opts['reply_to'] !== '') {
            $email->replyTo($opts['reply_to']);
        }

        if (isset($opts['text']) && $opts['text'] !== '') {
            $email->text($opts['text']);
        }

        $mailer = new Mailer(Transport::fromDsn($this->dsn()));
        $mailer->send($email);
    }

    /**
     * Convenience: send a tiny test email to $to.
     *
     * @throws RuntimeException On a missing config or transport failure.
     */
    public function sendTest(string $to): void
    {
        $this->send(
            $to,
            'Synapse test email',
            '<p>Your Synapse SMTP settings work. 🎉</p>',
        );
    }

    /**
     * Assemble the Symfony transport DSN from the stored SMTP settings.
     *
     * `ssl` encryption uses the `smtps://` scheme (implicit TLS, port 465
     * style); everything else uses `smtp://` and lets Symfony negotiate
     * STARTTLS. Credentials are URL-encoded and omitted entirely when no
     * username is set.
     */
    private function dsn(): string
    {
        $scheme = $this->encryption() === 'ssl' ? 'smtps' : 'smtp';

        $userinfo = '';
        $username = (string) $this->settings->get('mail_username', '');
        if ($username !== '') {
            $password = (string) ($this->settings->getEncrypted('mail_password') ?? '');
            $userinfo = rawurlencode($username).':'.rawurlencode($password).'@';
        }

        return sprintf('%s://%s%s:%d', $scheme, $userinfo, $this->host(), $this->port());
    }

    private function host(): string
    {
        return trim((string) $this->settings->get('mail_host', ''));
    }

    private function port(): int
    {
        return (int) $this->settings->get('mail_port', 587);
    }

    private function encryption(): string
    {
        return strtolower(trim((string) $this->settings->get('mail_encryption', '')));
    }

    private function fromAddress(): string
    {
        return trim((string) $this->settings->get('mail_from_address', ''));
    }

    private function fromName(): ?string
    {
        $name = trim((string) $this->settings->get('mail_from_name', ''));

        return $name === '' ? null : $name;
    }

    /**
     * @param  string|array<int,string>  $recipients
     * @return array<int,string>
     */
    private function normaliseRecipients(string|array $recipients): array
    {
        $list = is_array($recipients) ? $recipients : [$recipients];

        return array_values(array_filter(array_map(
            static fn (string $address): string => trim($address),
            $list,
        ), static fn (string $address): bool => $address !== ''));
    }
}
