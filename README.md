# BharatPlay – Telegram Video Streaming Mini App

PHP 8.2 + MySQL/MariaDB Telegram Mini App for cPanel/shared hosting (no Node.js, no framework).

**Install / re-install: follow [SETUP.md](SETUP.md)** (step by step, Hindi/English). Everything is done in
the browser through `install.php`: it checks the server, creates the tables, writes `config/config.php`,
creates the admin, connects the Telegram bot + webhook, shows the cron lines and deletes itself.

## Requirements

- PHP 8.1+ (8.2 recommended) with `pdo_mysql`, `curl`, `openssl`, `mbstring`, `fileinfo`, `gd`
- MySQL 5.7+ or MariaDB 10.3+, Apache with `mod_rewrite` (standard cPanel)
- **HTTPS** (required by Telegram)

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
| Wallet | manual credit/debit (audited), full ledger, total liabilities |
| Withdrawals | queue with UPI/bank details, ⏳ processing, ✔ paid with UTR, ✘ reject with reason (auto refund + Telegram message) |
| Rewards | Adsgram rewarded ads (server-verified), daily check-in, referral bonus, disable-all switch |
| Referrals | who invited whom, progress to eligibility, top referrers |
| Reports | daily charts + totals for any date range, popular videos |
| Audit Logs | admin actions + application error log |
| Settings | ads, payment gateway keys (Razorpay / PayU), withdrawals on/off, methods, limits, fees, referral |

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

### Rewarded ads (Earn)

Uses **Adsgram** (Telegram Mini App ad network). Money is credited only when Adsgram's
**server** calls `api/reward-callback.php` (secret key in the URL, shown in Admin → Rewards):
1. the app creates a PENDING "ad view" for the user (limits/cooldown checked),
2. Adsgram calls the reward URL with the Telegram user id after a fully watched ad,
3. that call turns the user's open ad view into a ledger credit — one call, one credit.
Without an ad view started in the app, or without the secret, nothing is credited.
Amounts always come from the admin's reward rules. Defaults are OFF / small.

## Testing checklist

See section 9 of [SETUP.md](SETUP.md).

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
