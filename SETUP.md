# BharatPlay – Fresh Install Guide (cPanel)

Poora setup browser se hota hai. Kisi file ko edit karne ki zaroorat nahi.
Domain example: `https://bharatseo.site` (apna domain lagao).

> **aaPanel use kar rahe ho?** Neeche "aaPanel guide" section follow karo (cPanel wale steps 1–4, 6, 7 ki jagah).

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
   (`public_html/Telebot-.../install.php` nahi). Hidden file `.htaccess` bhi move ho:
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
| 1 | **Telegram** | **🔍 Check everything** → sab ✔? ✘ par fix likha hota hai. **📣 Send test message** se channel test karo. "Join our channel" button `/start` par apne aap aata hai; private channel ho to **Channel join link** (`https://t.me/+…`) daalo. **Require users to join** tick karo to bina join kiye app ka button nahi milega. |
| 2 | **Categories** | Naam/icon/order theek karo. |
| 3 | **Videos → + Upload Video** | Video + thumbnail, FREE/PREMIUM, "Post to Telegram" tick → Upload. |
| 4 | **Subscriptions** | Plans ke price/din set karo. |
| 5 | **Settings → Payment** | **Razorpay** ya **PayU** choose karo. Razorpay: Key ID + Secret + Webhook secret. PayU: Merchant Key + Salt, mode **Test** pehle, test payment ho jaye to **Live**. Dono ke webhook URL usi page par likhe hain. |
| 6 | **Ads** | Apni image ad ya Adsterra/Monetag ka code. **Adsgram ad video se pehle:** partner.adsgram.ai → Ad unit type **Interstitial** → BlockID (`int-…`) → Admin → **Settings → Ads** → "Adsgram ad before every video" tick + block ID → Save. "Video ad every N videos" se kitni baar aaye ye set hota hai. |
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
| Video upload fail | Videos 8 MB parts mein jaati hain, PHP limit ka asar nahi. Settings → Video ka max size aur disk space check karo. |
| "Watch ad" se paise nahi aate | Admin → **Rewards → Status check** dekho. "No reward callback received" likha ho to verification **Adsgram SDK result** par switch karo. Adsgram platform ka **Web app url** bilkul BotFather wala URL hona chahiye aur platform **Active** ho. Test sirf Telegram ke andar se karo. |
| Adsterra/Monetag ka banner khali dikhta hai | Settings → Ads → **Ad subdomain**: cPanel mein `ads.bharatseo.site` subdomain banao (same document root), SSL lagao, URL yahan daalo. |
| Bot "join channel" nahi poochta | Telegram page par "Require users to join" tick karke **Save & check** dabao (webhook dobara register hota hai). Bot channel ka admin hona chahiye. |
| Koi aur error | Admin → Audit Logs → **⚠️ Error log** |
| install.php "already installed" | Normal hai. Re-install ke liye Step 0 se shuru karo. |

---

# aaPanel guide (Nginx)

aaPanel par Nginx chalta hai, jo `.htaccess` **nahi padhta**. Isliye Step C (security rules) zaroori hai,
warna premium videos aur `database.sql` koi bhi download kar sakta hai.

### A. Site + PHP
1. aaPanel → **App Store** → **PHP 8.2** install (agar nahi hai).
2. App Store → PHP 8.2 → **Setting → Install extensions** → **fileinfo** install karo (aaPanel mein default nahi hota, iske bina video upload fail hoga).
   `exif` optional. `curl`, `mbstring`, `openssl`, `pdo_mysql`, `gd` pehle se hote hain.
3. **Website → Add site** → Domain `bharatseo.site` → PHP version **82** → Database: **MySQL** (naam/user/password note karo) → Submit.
   Site folder banega: `/www/wwwroot/bharatseo.site`
4. Website → site → **SSL** → **Let's Encrypt** → Apply → **Force HTTPS** ON.

### B. Files
1. **Files** → `/www/wwwroot/bharatseo.site` → purani files delete karo (`.user.ini` delete nahi hogi, aaPanel ki hai, rehne do).
2. GitHub ZIP **Upload** → right-click → **Unzip** → andar wale folder ki saari files `/www/wwwroot/bharatseo.site` mein **Cut → Paste**.
   Check: `/www/wwwroot/bharatseo.site/install.php` hona chahiye.
3. `uploads`, `logs`, `config` folders ki permission **755**, owner **www**.

### C. Security rules (zaroori!)
Website → site → **URL rewrite** (Rewrite) → box mein `deploy/nginx-aapanel.conf` ka **poora content** paste karo → **Save**.
(File Files → `deploy/nginx-aapanel.conf` mein hai; installer ke Step 5 par bhi dikhta hai.)
Check: browser mein `https://bharatseo.site/database.sql` → **403** aana chahiye. Admin dashboard par red warning nahi aani chahiye.

### D. Installer
`https://bharatseo.site/install.php` → upar wale Step 5 jaisa. Database host: `localhost`, naam/user/password = Step A3 wale.

### E. Cron
aaPanel → **Cron** → **Add Task** → Type **Shell Script**, har ek ke liye:

| Name | Period | Script |
|---|---|---|
| Subscription expiry | Every hour, minute 0 | `/www/server/php/82/bin/php /www/wwwroot/bharatseo.site/cron/subscription-expiry.php` |
| Referral rewards | Every hour, minute 15 | `/www/server/php/82/bin/php /www/wwwroot/bharatseo.site/cron/referral-rewards.php` |
| Analytics | Every day, 01:00 | `/www/server/php/82/bin/php /www/wwwroot/bharatseo.site/cron/analytics.php` |
| Cleanup | Every day, 03:00 | `/www/server/php/82/bin/php /www/wwwroot/bharatseo.site/cron/reward-cleanup.php` |

(Installer Step 5 exact PHP path dikhata hai.)

### F. Badi videos
Videos 8 MB ke parts mein upload hoti hain, isliye aaPanel ki 50 MB PHP limit se farak nahi padta.
Max size: Admin → **Settings → Video** (default 2 GB). Asli limit disk space hai: aaPanel home par disk dekho.
Upload ke dauraan page band mat karna.

### aaPanel problems
| Problem | Fix |
|---|---|
| Dashboard par "private files can be downloaded" | Step C dobara karo, Save dabao |
| Upload "not a valid video" har file par | PHP **fileinfo** extension install karo (Step A2) |
| Upload "HTTP 413" | URL rewrite mein `client_max_body_size 64m;` hai? Save kiya? |
| 502 Bad Gateway | App Store → PHP 8.2 → Service → Restart |
| Video bufferring/slow start | Normal for first play on slow servers; MP4 ko "faststart" ke saath export karo (HandBrake: Web Optimized ✔) |
