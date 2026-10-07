# BharatPlay – Implementation Status

What exists today, verified by an end-to-end run against MariaDB 10.5 + PHP 8.4
(281 checks across install wizard, auth, streaming, ledger, withdrawals, rewards, referrals, crons, admin, Telegram, payments, ads, reports).

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
- **Mini App UI**: home, search, categories, profile (premium status, referral link), premium plans + payment, player (resume, progress, ads).
- **Admin control**: Telegram (settings in DB, encrypted token, diagnostics, webhook button), Settings, Users (ban, give/remove premium), Subscriptions (plans, subscribers, payments), Ads (CRUD, image upload, network code in sandboxed iframe, stats), Categories.
- **Payments**: `PaymentGatewayInterface` + Razorpay (order from DB price, checkout signature + server-side payment fetch, signed webhook, amount check, idempotent grant, renewal stacking).
- **Cron**: subscription expiry, analytics aggregation, cleanup, referral payouts (CLI-only).

- **Wallet & withdrawals (Mini App + admin)**: balance/history, UPI or bank withdrawal with fee/min/daily limit, user cancel, admin queue (decrypted details, PROCESSING → PAID with UTR / REJECTED with refund), manual adjustments, Telegram notifications.
- **Earn**: Adsgram rewarded ads with server callback + per-user intent (no client-side crediting), daily check-in, referral info; admin Rewards page with kill switch.
- **Admin analytics**: Reports (date filters, 7 daily SVG charts, conversion, CTR, completion, popular videos), dashboard 14-day charts, Referrals, Audit/Error logs.
- **Installer wizard**: requirements → DB connect + import + config.php → admin → Telegram (token, channel check, webhook) → cron lines → self-delete; locked against strangers.

## Not implemented yet

| Area | Missing |
|---|---|
| Payments | Cashfree / PayU adapters (Razorpay done) |
| Video | HLS / quality selector, S3/R2 storage adapter |
| Admin | 2FA, per-role permission editor, multiple admins UI (add admins in DB for now) |
