# BharatPlay – Fresh Install Guide (cPanel)

Poora setup browser se hota hai. Kisi file ko edit karne ki zaroorat nahi.
Domain example: `https://bharatseo.site` (apna domain lagao).

---

## 0. Purana install hatao (sirf re-install ke liye)

1. cPanel → **File Manager** → `public_html/`
2. Agar purani videos chahiye to `uploads/` folder download karke backup le lo.
3. `public_html/` ke andar ki **saari purani BharatPlay files delete** karo (khaas kar `config/config.php`).
4. Database: ya to **naya khali database** banao (Step 2), ya wizard mein
   **"Erase existing BharatPlay data"** tick karna.

## 1. SSL (HTTPS) on karo

cPanel → **SSL/TLS Status** → `bharatseo.site` select → **Run AutoSSL**.
Browser mein `https://bharatseo.site` khul jana chahiye. (Telegram bina HTTPS ke kaam nahi karta.)

## 2. PHP version

cPanel → **MultiPHP Manager** → domain select → **PHP 8.2** → Apply.
cPanel → **Select PHP Version → Extensions**: `pdo_mysql`, `curl`, `mbstring`, `fileinfo`, `gd`, `openssl` tick hon.

## 3. Database banao

cPanel → **MySQL® Databases**:
1. **Create New Database** → `bharatplay` (cPanel naam banayega jaise `cpuser_bharatplay`)
2. **Add New User** → username + strong password (password yaad rakho)
3. **Add User To Database** → dono select → **ALL PRIVILEGES** → Make Changes

## 4. Files upload karo

1. GitHub: https://github.com/dhdhdh51/Telebot → **Code → Download ZIP**
2. cPanel → File Manager → `public_html/` → **Upload** → ZIP → **Extract**
3. Extract ke baad ek folder banega (jaise `Telebot-feature-bharatplay-core`). Uske **andar ki saari files**
   select karke **Move** → `/public_html`. Final check: `public_html/install.php` hona chahiye
   (`public_html/Telebot-.../install.php` nahi). Hidden files (`.htaccess`, `.user.ini`) bhi move hon:
   File Manager → Settings → **Show Hidden Files**.

## 5. Install wizard chalao

Browser: **`https://bharatseo.site/install.php`**

| Step | Kya karna hai |
|---|---|
| 1. Check | Sab ✔ hona chahiye. ✘ ho to wahan likha fix karo, Reload. |
| 2. Database | Step 3 wala DB name, user, password. Host = `localhost`. Site URL = `https://bharatseo.site`. Tables apne aap ban jayengi, `config.php` apne aap likha jayega. |
| 3. Admin | Apna admin username, email, password (12+ characters). |
| 4. Telegram | Neeche "Telegram bot" dekho. Token + channel daalo → **Connect bot**. Webhook apne aap set hoga. |
| 5. Finish | Cron jobs copy karo (Step 6), phir **Finish** → installer khud delete ho jayega. |

### Telegram bot (Step 4 se pehle)
1. Telegram → **@BotFather** → `/newbot` → naam do → **token copy** karo.
2. Apna channel banao → **Administrators → Add Admin** → apna bot → **Post Messages** ON.
3. @BotFather → `/mybots` → bot → **Bot Settings → Configure Mini App → Enable Mini App** → URL:
   `https://bharatseo.site/app/`
4. (Optional) **Bot Settings → Menu Button** → wahi URL, text "Open".

## 6. Cron jobs

cPanel → **Cron Jobs** → har line ke liye "Add New Cron Job". Wizard ke Step 5 mein
aapke server ka exact path wali lines dikhti hain – wahi copy karo. Format:

```
0 * * * *  /usr/local/bin/php /home/USER/public_html/cron/subscription-expiry.php >/dev/null 2>&1
15 * * * * /usr/local/bin/php /home/USER/public_html/cron/referral-rewards.php >/dev/null 2>&1
0 1 * * *  /usr/local/bin/php /home/USER/public_html/cron/analytics.php >/dev/null 2>&1
0 3 * * *  /usr/local/bin/php /home/USER/public_html/cron/reward-cleanup.php >/dev/null 2>&1
```

## 7. HTTPS force karo

File Manager → `public_html/.htaccess` → Edit → upar ki ye 2 lines ke aage se `#` hatao:
```
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

## 8. Admin panel setup – `https://bharatseo.site/admin/`

| Order | Page | Kya karo |
|---|---|---|
| 1 | **Telegram** | **🔍 Check everything** → sab ✔? ✘ par fix likha hota hai. **📣 Send test message** se channel test karo. |
| 2 | **Categories** | Naam/icon/order theek karo. |
| 3 | **Videos → + Upload Video** | Video + thumbnail, FREE/PREMIUM, "Post to Telegram" tick → Upload. |
| 4 | **Subscriptions** | Plans ke price/din set karo. |
| 5 | **Settings → Payment** | Razorpay Key ID + Secret + Webhook secret (pehle `rzp_test_` keys). Razorpay → Webhooks mein page par likha URL daalo, events `payment.captured`, `order.paid`. |
| 6 | **Ads** | Apni image ad ya Adsterra/Monetag ka code. |
| 7 | **Rewards** | Adsgram (Watch ad & earn), daily check-in, referral bonus. Default sab safe/OFF hain. |
| 8 | **Settings → Wallet** | Min withdrawal, fee, UPI/Bank, withdrawals ON/OFF. |

## 9. Test karo

- [ ] Bot mein `/start` → "Open BharatPlay" + "Get Premium" buttons aaye
- [ ] Mini App khula, upar aapka naam dikha
- [ ] Channel post ka **▶ WATCH VIDEO** dabane par wahi video khuli
- [ ] FREE video chali, seek kaam kiya; PREMIUM par lock dikha
- [ ] Premium → plan → Razorpay **test** payment → "Premium activated" + Telegram message
- [ ] Premium user ko ads nahi dikhe; free user ko banner dikha
- [ ] Admin → Users → kisi ko 💎 Give 7 days → Mini App mein PREMIUM badge
- [ ] Admin → Wallet → apne Telegram ID par ₹200 credit → Mini App Wallet mein ₹100 withdraw → Admin → Withdrawals → UTR daal ke ✔ Paid
- [ ] (Agar Adsgram setup kiya) Earn → WATCH → poora ad dekho → balance badha
- [ ] `https://bharatseo.site/uploads/videos/` → 403 (videos direct download nahi honi chahiye)

## Problem aaye to

| Problem | Fix |
|---|---|
| Mini App mein "Please open from Telegram" | Browser se nahi, Telegram bot ke button se kholo. |
| Telegram Desktop/Web mein har cheez 401 | Site HTTPS par honi chahiye (Step 1, 7). |
| Channel par post nahi gaya | Admin → Telegram → Check everything; red line mein fix likha hai. Admin → Telegram → Recent posts mein error. |
| Upload "larger than server limit" | cPanel → MultiPHP INI Editor → `upload_max_filesize` 512M, `post_max_size` 512M; ya video 720p mein compress karo. |
| Koi aur error | Admin → Audit Logs → **⚠️ Error log** |
| install.php "already installed" | Normal hai. Re-install ke liye Step 0 se shuru karo. |
