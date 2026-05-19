# WhatsApp bot (VPS) + pre-screening (cPanel)

## Architecture (Namecheap)

| Host | Role |
|------|------|
| **xanderbot.site** (VPS) | Meta webhook, FAQ bot, forwards pre-screening to cPanel |
| **xanderglobalscholars.com** (cPanel) | Web admin, invites, `prescreening_submissions` list, `prescreening-inbound.php` |

## Meta Developer Console (required)

**WhatsApp → Configuration → Webhook**

| Field | Value |
|--------|--------|
| Callback URL | `https://xanderbot.site/api/webhook/meta` |
| Verify token | Same as `WHATSAPP_VERIFY_TOKEN` in VPS `.env` |
| Subscribe | **messages** |

Do **not** use `https://xanderglobalscholars.com/api/whatsapp-webhook.php` as the primary URL (that path only ran pre-screening and ignored "Hello").

### If Meta still points at cPanel

On **cPanel** `.env` add:

```env
XANDERBOT_WEBHOOK_URL=https://xanderbot.site/api/webhook/meta
```

Upload `helpers/webhook_forward_xanderbot.php` and updated `api/whatsapp-webhook.php`. All events proxy to the VPS.

## VPS deploy checklist

```bash
cd /var/www/xanderbot
php artisan config:clear
php artisan whatsapp:ensure-platform
php artisan config:cache
```

Test outbound (plain text, no template):

```bash
php artisan whatsapp:test-bot 2547XXXXXXXX --text=Hello
```

Watch webhooks:

```bash
tail -f storage/logs/webhook.log
tail -f storage/logs/webhook-hits.log
```

Send **Hello** on WhatsApp — `webhook-hits.log` must show a **new** line with today's time.

## Pre-screening (separate from bot)

1. Web admin sends invite (cPanel).
2. Student taps **START** on WhatsApp.
3. VPS forwards to `https://xanderglobalscholars.com/api/prescreening-inbound.php`.
4. On finish → row in web pre-screening list.

`PRESCREENING_WEB_INVITE_ONLY=true` — typing "prescreening" does **not** start a form.

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| No new `webhook-hits.log` lines | Meta callback URL → xanderbot, or set `XANDERBOT_WEBHOOK_URL` on cPanel |
| Only `META_SERVICE_INITIALIZED` in laravel.log | That's ads, not WhatsApp — ignore for bot |
| 403 on webhook | `WHATSAPP_APP_SECRET` = Meta App Secret |
| Platform missing | `php artisan whatsapp:ensure-platform` |
