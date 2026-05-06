# MauticMailgunCallbackBundle

Mailgun callback handler plugin for **Mautic 7**.

Processes Mailgun webhook events (bounces, spam complaints, unsubscribes) and updates Mautic DNC (Do Not Contact) lists automatically.

---

## Features

- ✅ Hard bounce (`failed/permanent`) → DNC BOUNCED
- ✅ Spam complaint (`complained`) → DNC UNSUBSCRIBED
- ✅ Unsubscribe (`unsubscribed`) → DNC UNSUBSCRIBED
- ✅ Soft bounces (`failed/temporary`) → **ignored** (Mailgun retries automatically)
- ✅ Other events (`delivered`, `opened`, `clicked`) → **ignored** (handled by Mautic native tracking)
- ✅ HMAC-SHA256 signature validation on every request
- ✅ Hash-ID lookup when available (preserves Mautic email stat accuracy)
- ✅ No extra Composer dependencies (uses Mautic 7 core only)

---

## Installation

Copy the plugin folder to your Mautic `plugins/` directory:

```bash
cp -r MauticMailgunCallbackBundle /path/to/mautic/plugins/
```

Then clear the Mautic cache:

```bash
php bin/console cache:clear
php bin/console mautic:plugins:reload
```

---

## Configuration

### 1. Webhook signing key

Set the environment variable:

```bash
MAILGUN_WEBHOOK_SIGNING_KEY=your-signing-key
```

> **Where to find it**: Mailgun → *Sending* → *Webhooks* → *HTTP webhook signing key*  
> This key is **different** from the API key used for sending.

The plugin reads this via Mautic's configuration system. You can also set it via the Mautic admin UI under *Configuration → Email Settings* (the parameter name is `mailgun_webhook_signing_key`).

### 2. Configure the webhook in Mailgun

In Mailgun → *Sending* → *Webhooks*, add a webhook for your domain pointing to:

```
https://your-mautic.example.com/mailer/callback
```

Enable the following event types:
- `failed` (permanent bounces)
- `complained` (spam complaints)
- `unsubscribed` (Mailgun unsubscribes)

### 3. Sending configuration (no plugin required)

Sending via Mailgun uses native Mautic/Symfony support. Just set your DSN:

```bash
# API (recommended — fastest)
MAUTIC_MAILER_DSN=mailgun+api://SENDING_KEY:DOMAIN@default?region=eu

# HTTP
MAUTIC_MAILER_DSN=mailgun+https://SENDING_KEY:DOMAIN@default?region=eu

# SMTP
MAUTIC_MAILER_DSN=mailgun+smtp://SMTP_LOGIN:SMTP_PASSWORD@default?region=eu
```

> **Note**: `SENDING_KEY` and `MAILGUN_WEBHOOK_SIGNING_KEY` are **two different keys**.

---

## How it works

Mautic core exposes a public endpoint `POST /mailer/callback` that dispatches a
`mautic.email.on_transport_webhook` event. This plugin listens to that event,
validates the Mailgun HMAC-SHA256 signature, parses the payload, and calls
`TransportCallback::addFailureByAddress()` or `addFailureByHashId()` to add
the contact to Mautic's DNC list.

```
Mailgun → POST /mailer/callback
           ↓
    [Signature validation]
           ↓
    [Event routing]
    ┌──────────────────────────────┐
    │ failed/permanent → DNC BOUNCED      │
    │ complained       → DNC UNSUBSCRIBED │
    │ unsubscribed     → DNC UNSUBSCRIBED │
    │ others           → ignored          │
    └──────────────────────────────┘
           ↓
    TransportCallback → Mautic DNC
```

---

## Running tests

```bash
bin/phpunit plugins/MauticMailgunCallbackBundle/Tests --testdox
```

---

## License

MIT

