# Email

[← Docs index](README.md)

Synapse sends email through its **own isolated SMTP transport** — never the host app's mailer or global mail config. The transport is configured in the admin **Settings** screen, the body of an email is any page tagged `kind=email`, and a [`send_email` flow node](flows.md#send_email) fires it. This keeps a built app's email fully self-contained and portable across host apps.

## SMTP settings

Configured on the **Settings** admin page (`PageBuilderSettings`) and stored via the `Settings` service in `page_builder_settings`. Keys:

| Setting key | Meaning |
|---|---|
| `mail_host` | SMTP server hostname (e.g. `smtp.example.com`) |
| `mail_port` | SMTP port (default 587) |
| `mail_username` | SMTP username (optional — omitted from the DSN if unset) |
| `mail_password` | SMTP password — **encrypted at rest** (`Settings::setEncrypted`/`getEncrypted`) |
| `mail_encryption` | `tls` (STARTTLS), `ssl` (implicit TLS), or `''` (none) |
| `mail_from_address` | Sender address |
| `mail_from_name` | Sender display name (optional) |

The password field on the Settings form shows a masked placeholder and is only overwritten when you type a new value (leaving it blank keeps the stored one).

## The isolated transport

`src/Services/PageBuilderMailer.php` builds a fresh Symfony Mailer transport on **every** send — it never mutates `config('mail')` or borrows the host mailer, so a builder send can't interfere with the surrounding application.

- `configured()` → true once `mail_host` **and** `mail_from_address` are set.
- The DSN is assembled by hand: scheme `smtps://` when `mail_encryption=ssl` (implicit TLS), otherwise `smtp://` (Symfony negotiates STARTTLS). Credentials are URL-encoded and omitted entirely when no username is set: `{scheme}://{user}:{pass}@{host}:{port}`.
- The password is read back through `Settings::getEncrypted('mail_password')` so it is never held in plaintext at rest.

### Send a test email

On the Settings page, the **Send test email** header action (visible once `configured()`) opens a prompt for an address and calls `PageBuilderMailer::sendTest($to)` — a one-line "Your Page Builder SMTP settings work" message. Use it to confirm the transport before wiring a flow.

## Email-template pages (`kind=email`)

There's no separate email-authoring surface — an email body **is** a page with `kind = 'email'`. Build it like any page (you can use the editor), then reference it by slug from a `send_email` node. The page's `css` is inlined as a `<style>` block ahead of its `html`.

Mark a page as an email template by setting `kind` to `email` in the Pages form (the `emailTemplates()` scope lists them). The AI applier also auto-marks any page referenced as a `send_email` template, even if the plan forgot to tag it.

## The `send_email` flow node

`src/Flow/Nodes/SendEmailNode.php`. Config:

```json
{ "type": "send_email", "config": {
  "to": "{{ input.record.email }}",
  "subject": "Welcome {{ input.record.name }}",
  "template": "welcome-email",
  "body": "<p>Inline fallback when no template.</p>",
  "cc": "ops@example.com",
  "bcc": "",
  "reply_to": "",
  "output": "email"
} }
```

- `to` — required; string (comma-separated) or array; interpolated.
- `template` — slug of a `kind=email` page used as the body. If omitted/not found, falls back to the inline `body`.
- `cc` / `bcc` / `reply_to` — optional; interpolated; cc/bcc accept lists.
- `output` — context var that receives the result (default `email`).

**Non-fatal by design:** a missing recipient, empty body, or unconfigured transport records `{ "sent": false, "error": "..." }` in the output var and the flow continues; a successful send records `{ "sent": true }`.

## Mustache interpolation in templates

The body (template page HTML or inline `body`) is interpolated against the **flow context** before sending — using `{{ ... }}` tokens, **not** Alpine (emails have no JS):

| Token | Resolves to |
|---|---|
| `{{ input.x }}` | The trigger input |
| `{{ vars.x }}` | A var a prior node wrote |
| `{{ states.x }}` | A persistent [State](functions-and-states.md) |

For a **collection-triggered** flow the changed row is at `{{ input.record.<field> }}` (plus `{{ input.event }}` and `{{ input.collection }}`). A typical "email on signup" template:

```html
<h1>Welcome {{ input.record.name }}</h1>
<p>Thanks for joining — we'll be in touch at {{ input.record.email }}.</p>
```

### Pattern: notify on a record event

1. Create a `kind=email` page (e.g. `welcome-email`).
2. Create a flow with `trigger_type: "collection"`, `trigger_config: { "collection": "signups", "events": ["created"] }`.
3. Add a `send_email` node whose `template` is `welcome-email` and `to` is `{{ input.record.email }}`.

See [Flows → Collection trigger](flows.md#collection-trigger). This is exactly the example the AI emits for "a waitlist that emails a welcome on signup" — see [AI](ai.md).

Next: [AI](ai.md).
