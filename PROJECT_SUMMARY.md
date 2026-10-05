# BharatPlay – Implementation Status

What exists today, verified by an end-to-end run against MariaDB 10.5 + PHP 8.4
(65 checks: auth, streaming, ledger, withdrawals, referrals, crons, admin login, installer).

## Implemented

- **Database**: 24 tables, seed categories/plans/reward rules/settings. Imports into a cPanel-prefixed database as a non-root user.
- **Telegram Mini App auth**: initData HMAC validation, freshness check, signed `start_param`, session with `SameSite=None; Secure`.
- **Video streaming**: protected endpoint, FREE/PREMIUM + subscription check, Range/206/416, session lock released before streaming, raw files blocked.
- **Video API**: list, get, categories, category, search, trending, related, continue watching, progress (server-clock watch time), ads (impression/click).
- **Telegram**: send message/photo/video, edit/delete, webhook with secret token, `/start` + referral deep link, channel publishing with a working URL button.
- **Wallet ledger**: paise-integer math, row locks, idempotent references, nested-transaction safe.
- **Withdrawals**: request (fee, min, daily limit, encrypted details), admin PROCESSING/PAID/REJECTED/CANCELLED with single refund.
- **Referrals**: trusted sources only, new users only, paid by cron after eligibility, exactly once.
- **Admin**: installer (self-locking), login (CSRF, rate limit, lockout, session regeneration, constant-time miss), dashboard.
- **Admin videos**: list/filter/search, upload with progress bar (MIME-checked, random filenames, thumbnail resize, browser-read duration), edit, publish/unpublish, post to Telegram channel, delete (role-gated, CSRF on every action).
- **Mini App UI**: home (continue watching, trending, latest, deep-link routing) and player (resume, progress, interstitial ad).
- **Cron**: subscription expiry, analytics aggregation, cleanup, referral payouts (CLI-only).

## Not implemented yet

| Area | Missing |
|---|---|
| Admin pages | categories, telegram (webhook status page), users, subscriptions, ads, rewards, wallet adjustments, withdrawals queue, referrals, reports/charts, settings, audit log viewer |
| Mini App pages | search, categories, profile, subscription, wallet, earn, withdrawal form |
| APIs | `subscription.php`, `wallet.php`, `withdrawal.php`, `rewards.php`, `categories.php` (categories are served by `videos.php`) |
| Payments | `PaymentGatewayInterface` + Razorpay/Cashfree/PayU adapters, order creation, signed webhook → subscription activation |
| Rewarded ads | provider adapter with signed server-side callbacks (deliberately disabled until one exists) |
| Other | HLS/quality selector, admin 2FA, configurable role permissions, app-level error log viewer, request IDs |

The backend helpers for the missing screens (e.g. `Video::uploadVideo/create/update/delete`,
`Telegram::publishVideo`, `Wallet::processWithdrawal`) already exist; the screens need to call them.
