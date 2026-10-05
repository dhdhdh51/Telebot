# 🚀 BharatPlay Deployment Checklist

Complete this checklist before going live with your production system.

> Some items (payments, subscription purchase, rewarded ads, admin video upload UI) cover
> features that are **not built yet** – see PROJECT_SUMMARY.md. Skip them until they exist.
> Create the first admin with `install.php`, not by hand-written SQL.

## ✅ Pre-Deployment (Local/Development)

### Code Preparation
- [ ] All files uploaded to server
- [ ] Git repository initialized (if using version control)
- [ ] .gitignore configured properly
- [ ] config.php created from config.example.php
- [ ] Sensitive data NOT in version control

### Testing Completed
- [ ] Admin login tested
- [ ] Video upload tested
- [ ] Video streaming tested
- [ ] Telegram authentication tested
- [ ] Subscription system tested
- [ ] Wallet transactions tested
- [ ] Withdrawal process tested
- [ ] Ad display tested
- [ ] Referral system tested

## ✅ Server Setup (cPanel/Hosting)

### Hosting Requirements
- [ ] PHP 8.0+ installed and active
- [ ] MySQL/MariaDB 5.7+ available
- [ ] HTTPS/SSL certificate installed and working
- [ ] Required PHP extensions enabled:
  - [ ] PDO
  - [ ] PDO_MySQL
  - [ ] cURL
  - [ ] GD or Imagick
  - [ ] OpenSSL
  - [ ] JSON
  - [ ] mbstring

### File Structure
- [ ] All files uploaded to correct directory
- [ ] Folder permissions set correctly:
  - [ ] `uploads/` = 755
  - [ ] `uploads/videos/` = 755
  - [ ] `uploads/thumbnails/` = 755
  - [ ] `logs/` = 755
- [ ] .htaccess file present and active
- [ ] mod_rewrite enabled

## ✅ Database Configuration

### Database Setup
- [ ] MySQL database created
- [ ] Database user created with strong password
- [ ] User granted ALL PRIVILEGES on database
- [ ] database.sql imported successfully
- [ ] All 25+ tables created
- [ ] Default data seeded:
  - [ ] 6 categories created
  - [ ] 4 subscription plans created
  - [ ] 4 reward rules created
  - [ ] ~40 settings configured

### Database Verification
- [ ] Can connect from application
- [ ] Tables have correct structure
- [ ] Foreign keys working
- [ ] Indexes created

## ✅ Application Configuration

### config/config.php
- [ ] DB_HOST set correctly
- [ ] DB_NAME matches created database
- [ ] DB_USER has correct username
- [ ] DB_PASS has correct password
- [ ] APP_URL set to your domain (with https://)
- [ ] APP_ENV set to 'production'
- [ ] APP_TIMEZONE set correctly
- [ ] SECRET_KEY is random 32+ characters
- [ ] TELEGRAM_BOT_TOKEN from @BotFather
- [ ] TELEGRAM_BOT_USERNAME correct
- [ ] TELEGRAM_CHANNEL_ID correct format
- [ ] TELEGRAM_MINI_APP_URL correct
- [ ] Payment gateway credentials (if using)

### Security Settings
- [ ] PHP display_errors OFF in production
- [ ] Error logging enabled
- [ ] Session cookies set to secure
- [ ] HTTPS enforced in .htaccess
- [ ] Directory listing disabled

## ✅ Telegram Bot Setup

### Bot Configuration
- [ ] Bot created via @BotFather
- [ ] Bot token saved securely
- [ ] Bot commands set:
  ```
  start - Start the bot
  help - Get help
  ```
- [ ] Bot profile picture uploaded (optional)
- [ ] Bot description set (optional)

### Channel/Group Setup
- [ ] Channel or group created
- [ ] Bot added as administrator
- [ ] Bot has permission to post messages
- [ ] Channel ID verified (test post successful)

### Mini App Configuration
- [ ] Menu button URL set in @BotFather
- [ ] Mini App URL is correct
- [ ] Mini App opens from Telegram
- [ ] Authentication working in Mini App

## ✅ Admin Account

### First Admin
- [ ] Admin account created in database
- [ ] Password hashed with bcrypt
- [ ] Role set to SUPER_ADMIN
- [ ] Status set to ACTIVE
- [ ] Can login at /admin/login.php
- [ ] Dashboard loads properly
- [ ] All menu items accessible

### Admin Security
- [ ] Default password changed immediately
- [ ] Strong password policy enforced
- [ ] 2FA ready for future implementation

## ✅ Cron Jobs

### Scheduled Tasks
- [ ] Cron jobs configured in cPanel
- [ ] Paths are absolute and correct
- [ ] subscription-expiry.php runs hourly
- [ ] analytics.php runs daily at 1 AM
- [ ] Test run successful (execute manually first)
- [ ] Email notifications configured (optional)

```bash
# Verify these are set:
0 * * * * /usr/bin/php /full/path/to/cron/subscription-expiry.php
0 1 * * * /usr/bin/php /full/path/to/cron/analytics.php
```

## ✅ Payment Gateway (If Using)

### Gateway Configuration
- [ ] Gateway account created
- [ ] Test/sandbox mode tested
- [ ] API keys configured
- [ ] Webhook URL configured
- [ ] Webhook signature verification working
- [ ] Test payments successful
- [ ] Production mode enabled
- [ ] Refund process understood

### Supported Gateways
- [ ] Razorpay (if using)
- [ ] Cashfree (if using)
- [ ] PayU (if using)
- [ ] Manual approval working (fallback)

## ✅ Content & Settings

### Video Content
- [ ] Sample videos uploaded for testing
- [ ] Thumbnails optimized (< 2MB)
- [ ] Categories configured
- [ ] Access types set (FREE/PREMIUM)
- [ ] At least one video published to Telegram

### Subscription Plans
- [ ] Pricing reviewed and updated
- [ ] Plan durations correct
- [ ] Features listed properly
- [ ] Payment gateway linked

### Advertisements
- [ ] Ad system enabled/disabled as needed
- [ ] Sample ads created (if using)
- [ ] Ad frequency configured
- [ ] Rewarded ads tested

### Wallet & Rewards
- [ ] Minimum withdrawal amount set
- [ ] Withdrawal fee configured (if any)
- [ ] Daily withdrawal limits set
- [ ] Reward amounts configured
- [ ] Daily reward limits set
- [ ] Referral bonus amount set

## ✅ Testing (Production Environment)

### End-to-End Tests
- [ ] User can open bot
- [ ] Mini App opens successfully
- [ ] Authentication works
- [ ] Videos load in home page
- [ ] Video playback works
- [ ] Video seeking works
- [ ] Progress saves correctly
- [ ] Ads display for free users
- [ ] Premium users see no ads
- [ ] Subscription purchase works
- [ ] Payment confirmation received
- [ ] Premium access granted
- [ ] Wallet balance updates
- [ ] Withdrawal request created
- [ ] Admin can approve withdrawal
- [ ] Referral link generates
- [ ] Referral reward credits

### Admin Tests
- [ ] Admin login successful
- [ ] Dashboard shows real data
- [ ] Video upload works
- [ ] Video publish to Telegram works
- [ ] User list displays
- [ ] Subscription management works
- [ ] Withdrawal approval works
- [ ] Settings can be updated
- [ ] Audit logs working

## ✅ Security Hardening

### Application Security
- [ ] install.php disabled or deleted
- [ ] config.example.php kept as reference only
- [ ] Error messages don't leak sensitive info
- [ ] SQL injection tested (basic)
- [ ] XSS protection verified
- [ ] CSRF tokens working
- [ ] Rate limiting functional
- [ ] File upload restrictions working

### Server Security
- [ ] HTTPS enforced (no HTTP access)
- [ ] SSL certificate valid
- [ ] Config files not web-accessible
- [ ] Uploads directory secured
- [ ] Logs directory protected
- [ ] .git directory blocked (if using Git)
- [ ] phpinfo() disabled or removed

### Data Security
- [ ] Database backups configured
- [ ] File backups configured
- [ ] Backup retention policy set
- [ ] Backup restore tested
- [ ] Sensitive data encrypted
- [ ] Withdrawal details encrypted

## ✅ Legal & Compliance

### Documentation
- [ ] Terms of Service created
- [ ] Privacy Policy published
- [ ] Cookie Policy (if using cookies beyond essentials)
- [ ] Content Policy established
- [ ] DMCA takedown procedure ready
- [ ] Contact information visible

### Content Moderation
- [ ] Content moderation policy defined
- [ ] Copyright compliance process
- [ ] Age-appropriate content only
- [ ] Reporting mechanism (future)

### Financial Compliance
- [ ] Payment gateway terms accepted
- [ ] Tax implications understood
- [ ] Withdrawal verification process
- [ ] Anti-fraud measures in place

## ✅ Monitoring & Maintenance

### Logging
- [ ] PHP error logs location known
- [ ] Application logs directory writable
- [ ] Log rotation configured (optional)
- [ ] Critical error alerts (optional)

### Monitoring
- [ ] Admin dashboard bookmarked
- [ ] Key metrics identified:
  - [ ] Active users
  - [ ] Video views
  - [ ] Revenue
  - [ ] Pending withdrawals
  - [ ] Error rate
- [ ] Monitoring schedule established (daily check)

### Support
- [ ] Support email/channel setup
- [ ] FAQ prepared (optional)
- [ ] Documentation accessible
- [ ] Issue tracking system (optional)

## ✅ Performance Optimization

### Database
- [ ] Slow query log reviewed
- [ ] Indexes optimized
- [ ] Connection pooling (if VPS)
- [ ] Query caching enabled (if available)

### Files
- [ ] Video files optimized
- [ ] Thumbnails compressed
- [ ] Browser caching enabled (.htaccess)
- [ ] Compression enabled (.htaccess)

### Application
- [ ] Unnecessary logging disabled
- [ ] Session cleanup working
- [ ] Old data cleanup scheduled

## ✅ Launch Preparation

### Pre-Launch
- [ ] Soft launch to small group
- [ ] Feedback collected
- [ ] Issues resolved
- [ ] Final testing complete
- [ ] Backup created before launch

### Launch Day
- [ ] Announcement prepared
- [ ] Bot started
- [ ] Channel active
- [ ] Admin monitoring
- [ ] Support ready

### Post-Launch (First 24 Hours)
- [ ] Monitor error logs
- [ ] Check user registrations
- [ ] Verify video playbacks
- [ ] Monitor payment transactions
- [ ] Check server load
- [ ] Respond to user issues
- [ ] Fix critical bugs immediately

## ✅ Post-Launch (First Week)

### Daily Checks
- [ ] Review dashboard statistics
- [ ] Check pending withdrawals
- [ ] Monitor error logs
- [ ] Verify cron job execution
- [ ] Check disk space usage
- [ ] Review user feedback

### Weekly Tasks
- [ ] Database backup verification
- [ ] Security updates check
- [ ] Performance review
- [ ] Content moderation
- [ ] Analytics review
- [ ] Plan next features

## ✅ Documentation

### For Admins
- [ ] Admin panel guide
- [ ] Video upload process
- [ ] Telegram publishing steps
- [ ] Withdrawal approval process
- [ ] Settings configuration

### For Users
- [ ] How to use Mini App
- [ ] How to subscribe
- [ ] How to earn rewards
- [ ] How to withdraw
- [ ] FAQ

### Technical
- [ ] Server configuration documented
- [ ] Database schema documented
- [ ] API endpoints documented
- [ ] Cron jobs documented
- [ ] Backup/restore procedure documented

## 🎉 Launch Checklist Complete!

Once all items are checked:

1. **Create final backup**
2. **Announce launch** on your channel
3. **Monitor actively** for first 24-48 hours
4. **Respond quickly** to any issues
5. **Collect feedback** from early users
6. **Iterate and improve**

## 📞 Emergency Contacts

Keep these handy:
- [ ] Hosting support contact
- [ ] Database admin (if separate)
- [ ] Payment gateway support
- [ ] Telegram support (if needed)
- [ ] Domain registrar support

## 🔄 Regular Maintenance Schedule

### Daily
- Check dashboard
- Review pending actions
- Monitor errors

### Weekly
- Backup verification
- Performance check
- User feedback review

### Monthly
- Security updates
- Database optimization
- Analytics deep dive
- Feature planning

---

## ✨ Success Metrics (First Month)

Set your goals:
- [ ] ____ registered users
- [ ] ____ videos uploaded
- [ ] ____ total views
- [ ] ____ premium subscribers
- [ ] ₹____ revenue generated
- [ ] ____% user retention
- [ ] ____% ad click-through rate
- [ ] ____% conversion rate (free to premium)

---

**Remember**: A successful launch is just the beginning. Continuous monitoring, user feedback, and iterative improvements are key to long-term success!

**Good luck with your launch! 🚀**
