# BharatPlay – Telegram Video Streaming Mini App

PHP 8.2 + MySQL/MariaDB Telegram Mini App for cPanel/shared hosting (no Node.js, no framework).

> **Status: work in progress.** The backend core (auth, streaming, ledger, withdrawals,
> referrals, Telegram publishing) is implemented and tested end-to-end; most admin
> CRUD screens, several Mini App pages and payment gateways are **not built yet**.
> See [PROJECT_SUMMARY.md](PROJECT_SUMMARY.md) for the exact list.

## Requirements

- PHP 8.1+ (8.2 recommended) with `pdo_mysql`, `curl`, `openssl`, `mbstring`, `fileinfo`, `gd`
- MySQL 5.7+ or MariaDB 10.3+
- Apache with `mod_rewrite` and `mod_headers` (standard on cPanel)
- **HTTPS** (Telegram requires it for Mini Apps and webhooks)

## Installation (cPanel)

1. **Upload** the repository contents to `public_html/` (or a subdomain's document root).
   Ensure `uploads/videos`, `uploads/thumbnails` and `logs` are writable (755).
2. **Create the database** in cPanel → *MySQL Databases*: a database, a user, and
   *ALL PRIVILEGES* for that user on that database.
3. **Import** `database.sql` via phpMyAdmin → select your database → *Import*.
   (The file does not create a database itself, so it works with cPanel's prefixed names.)
4. **Configure**: copy `config/config.example.php` to `config/config.php` and set
   `DB_*`, `APP_URL`, a random `SECRET_KEY` (32+ chars), `TELEGRAM_BOT_TOKEN`,
   `TELEGRAM_BOT_USERNAME`, `TELEGRAM_CHANNEL_ID`, `TELEGRAM_MINI_APP_URL`
   (`https://yourdomain.com/app`) and a random `TELEGRAM_WEBHOOK_SECRET`.
   Keep `APP_ENV` = `production` on the live site.
5. **PHP limits**: `.user.ini` sets a 500 MB upload limit. If your host ignores it, use
   cPanel → *MultiPHP INI Editor*.
6. **Create the first admin**: open `https://yourdomain.com/install.php`. It checks the
   environment and asks for the database password from `config.php` (proving you own
   the install) before creating a SUPER_ADMIN. It locks itself once an admin exists.
   **Delete `install.php` afterwards.**
7. **Telegram** – everything is set from **Admin → Telegram** (no config editing needed):
   - Paste the bot token from @BotFather, your channel (`@channel` or `-100…`), keep Mini App URL
     `https://<your-domain>/app`, click **Save & check**. The check tells you exactly what is wrong
     (bad token, channel not found, bot not admin, webhook missing) and how to fix it.
   - Click **🔗 Set webhook** (needed for `/start`, referrals and the WATCH button).
   - Recommended: @BotFather → `/mybots` → bot → *Bot Settings → Configure Mini App → Enable* →
     URL `https://<your-domain>/app/`. Then channel posts open the app directly; without it the
     WATCH button opens the bot, which replies with a "▶ WATCH VIDEO" button.
   - Add the bot as **admin of the channel** with *Post Messages*.
8. **Payments** – **Admin → Settings → Payment**: choose Razorpay, paste Key ID / Key Secret and a
   webhook secret. In Razorpay → Webhooks add `https://<your-domain>/api/payment-webhook.php?gateway=razorpay`
   (events `payment.captured`, `order.paid`, same secret). Plans/prices: **Admin → Subscriptions**.
9. **Cron jobs** (cPanel → *Cron Jobs*; check your PHP path with `which php`, often `/usr/local/bin/php`):
   ```
   0 * * * *   /usr/local/bin/php /home/USER/public_html/cron/subscription-expiry.php
   15 * * * *  /usr/local/bin/php /home/USER/public_html/cron/referral-rewards.php
   0 1 * * *   /usr/local/bin/php /home/USER/public_html/cron/analytics.php
   0 3 * * *   /usr/local/bin/php /home/USER/public_html/cron/reward-cleanup.php
   ```
   (Steps 7–8 used to need `config.php` edits; values set in the admin panel now take priority.)
10. **Force HTTPS**: uncomment the two `RewriteCond/RewriteRule` lines at the top of `.htaccess`.

## Uploading and publishing videos

1. Admin panel → **Videos** → **+ Upload Video** (or **⬆ Upload Video** on the dashboard).
2. Pick the video (MP4/H.264 plays everywhere) and a 16:9 thumbnail, fill in title, category,
   FREE/PREMIUM, and status. Max size shown on the form = the smaller of `MAX_UPLOAD_SIZE`
   and your host's PHP limits.
3. In the list, click **📱 Post to Telegram** on a PUBLISHED video. The post (thumbnail, title,
   description, category, ▶ WATCH VIDEO) goes to `TELEGRAM_CHANNEL_ID`; errors are shown and
   stored in `telegram_posts.error_message`.

Shared hosts often cap uploads at 50–256 MB and time out long uploads. If a file is too big,
compress it (e.g. 720p H.264, ~1–2 Mbps) before uploading.

## Admin panel

| Page | Controls |
|---|---|
| Videos | upload (progress bar, optional auto-post to Telegram), edit, publish, 📱 post to channel, delete |
| Categories | add / rename / reorder / hide |
| Telegram | bot token, channel, Mini App URL, **Check everything**, set webhook, test messages, post errors |
| Users | search, ban/unban, give premium for N days, remove premium |
| Subscriptions | plans (price, days, features, popular), active subscribers, payments |
| Ads | banner / interstitial / video ads: own image+link or ad-network code, schedule, pause, impressions/clicks/CTR |
| Settings | ads on/off & frequency, payment gateway keys, withdrawal limits, referral bonus |

Telegram and Settings are SUPER_ADMIN only; MODERATOR cannot delete or add ad scripts.

### Ads
- **Own ads**: Admin → Ads → New ad → upload image, set the click link (https).
- **Ad networks** (Adsterra, Monetag, …): paste their banner/HTML code in "Ad network code". It runs in
  a sandboxed iframe (`api/ad-frame.php`) that cannot read user sessions.
- BANNER shows on Home and under the player; INTERSTITIAL/VIDEO before a video every N videos
  (Settings → Ads). Premium users never get ads (enforced on the server).

### Subscriptions
User taps 💎 Premium → picks a plan → payment page opens in the phone browser (so UPI apps work) →
Razorpay → server verifies the signature **and** fetches the payment from Razorpay → premium is
granted (renewals add days). The webhook is a second, independent confirmation; both are idempotent.

## How it works

| Concern | Where | Notes |
|---|---|---|
| Mini App login | `api/auth.php`, `includes/telegram.php` | Validates `initData` HMAC (official WebApp algorithm), rejects stale `auth_date`, then creates a server session. The client never sends a user id. |
| Session cookie | `includes/bootstrap.php` | `SameSite=None; Secure; HttpOnly` – required because Telegram Web/Desktop iframe the app. |
| Video access | `api/video-stream.php` | Session → video published → FREE or active subscription → streams with HTTP Range (206/416). Raw files in `uploads/videos/` are denied by `.htaccess`. |
| Channel publishing | `Telegram::publishVideo()` | Thumbnail + caption + **URL** button (`web_app` buttons are only allowed in private chats). |
| Money | `addWalletTransaction()` in `includes/functions.php` | Integer-paise arithmetic, wallet row lock, ledger row per change, UNIQUE `reference_id` (idempotent). |
| Withdrawals | `includes/wallet.php` | Amount held on request, refunded once on reject/cancel; row locks prevent double processing; account details AES-256-GCM encrypted. |
| Referrals | `applyReferral()`, `cron/referral-rewards.php` | Only from trusted sources (signed `start_param` or webhook with secret), new users only, no self-referral; bonus paid by cron after real watch time ≥ configured minimum. |
| Watch time | `Video::updateWatchProgress()` | Credited from the server clock (max 30 s per update), not from client claims. |

### Rewarded ads

Rewards are **disabled** and cannot be credited from the frontend. To enable them you
must integrate an ad network that sends **signed server-to-server reward callbacks**:
write a callback endpoint that verifies the provider signature and then calls
`Wallet::creditVerifiedAdReward($userId, $provider, $providerTxId)` (idempotent per
provider transaction), define a `RewardedAdProviderVerifier` class, and set
`ads.rewarded_provider` + `ads.rewarded_ads_enabled` in the `settings` table.

## Testing checklist

- [ ] `install.php` shows all green, creates the admin, then returns 403
- [ ] Admin login works; wrong password and missing CSRF token are rejected; 5 failures lock the account
- [ ] Opening the Mini App from Telegram shows your name; opening `/app/` in a normal browser shows "open from Telegram"
- [ ] FREE video plays and seeking works; PREMIUM video shows the lock for non-subscribers
- [ ] Insert an ACTIVE subscription row → premium plays; set `end_date` in the past → blocked again
- [ ] `https://yourdomain.com/uploads/videos/<file>` returns 403
- [ ] Publishing a video posts thumbnail + "▶ WATCH VIDEO" to the channel, and the button opens that video
- [ ] `/start` in the bot replies with an Open button; a `?start=ref_<code>` link records the referral for a new user
- [ ] Crons run from cPanel without errors

## Troubleshooting

- **Every API call returns 401 inside Telegram Desktop/Web** – the site must be HTTPS (the session cookie is `Secure; SameSite=None`).
- **"Session expired, please reopen the Mini App"** – `initData` is older than `security.telegram_auth_timeout` (default 3600 s); reopen from Telegram.
- **Publishing fails** – check `telegram_posts.error_message`; usually the bot isn't a channel admin or `TELEGRAM_CHANNEL_ID` is wrong (`@name` or `-100…`).
- **500 error after upload** – some hosts reject `php_value` in `.htaccess`; this project uses `.user.ini` instead. Check `logs/php-errors.log`.

## Layout

```
admin/      admin panel (login, dashboard)        api/     JSON endpoints + video stream
app/        Mini App pages                        bot/     Telegram webhook
config/     config.example.php, database.php      cron/    scheduled jobs
includes/   bootstrap, auth, security, csrf, telegram, video, wallet, ads, functions
uploads/    thumbnails/ (public), videos/ (denied) assets/  shared JS
database.sql  install.php  .htaccess  .user.ini
```

Only upload content you own or are licensed to distribute.
