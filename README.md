# Landenberg Carriage House — direct booking website

A marketing and booking website for the carriage house. Guests can:

- browse the house, photos, amenities, house rules and local area
- see a live availability calendar that **includes Airbnb bookings**, so nothing gets double-booked
- get an instant price, including discounts and Pennsylvania taxes
- pay **50% by card when they book**. The other **50% is charged automatically 7 days before check-in**.
- cancel for a **full refund** up to the day the final payment is due, using a link in their confirmation email

Bookings made on the site are shared back to Airbnb through a calendar export link.

Everything you upload is inside the [`site/`](site) folder.

---

## 1. Pricing rules (already set up)

| Rule | Setting |
|---|---|
| Sun–Thu nights | $120 |
| Fri & Sat nights | $140 |
| US federal holidays + holiday-weekend nights | $140 |
| Cleaning fee | $90 per stay |
| Minimum stay | 2 nights, or 3 if the stay includes a holiday weekend |
| 7+ nights | 15% off the nightly total |
| 28+ nights (a month) | 30% off the nightly total |
| Max guests | 3. No pets, no smoking. |
| Taxes | PA Hotel Occupancy Tax 6% + Chester County Hotel Room Rental Tax 5% = **11%**, on rent + cleaning fee. Not charged on stays of 30+ nights. |
| Payment | 50% at booking, 50% charged 7 days before check-in. Bookings made within 7 days of check-in are paid in full. |
| Refunds | Full refund if cancelled before the final payment date. No online refund after that, but you can still refund from the admin page. |

**What counts as a holiday weekend:**
- For a Monday holiday (e.g. Memorial Day, Labor Day), the Fri, Sat and Sun nights.
- For a Friday holiday, the Thu, Fri and Sat nights.
- For Thanksgiving, the Wed to Sat nights.
- Holidays that fall on a Saturday or Sunday move to their observed Friday or Monday.
- Christmas Eve and New Year's Eve are also priced as holidays.

You can change any of these settings in `site/api/config.php`. That includes adding or removing holidays (for example, dropping Columbus Day).

> **Taxes:** Airbnb collects and pays these taxes for you on Airbnb bookings. For direct bookings, **you** must register and pay them yourself:
> - the 6% state tax to the PA Department of Revenue (through myPATH)
> - the 5% county tax to the Chester County Treasurer
>
> The admin page lists the tax collected on each booking. Please confirm the rates with your accountant. The config file lets you change them.

---

## 2. What's in the folder

```
site/
  index.html          Home page + calendar + booking
  confirmation.html   Shown after payment
  manage.html         Guest's "manage my booking" page (view / cancel / pay balance)
  admin/              Your dashboard (password protected)
  images/             PHOTOS - replace these placeholders
  assets/             CSS + JavaScript
  api/                PHP back end (prices, Stripe, Airbnb sync, emails)
    config.sample.php -> copy to config.php and fill in
    data/             Booking database (created automatically, private)
```

The site needs **PHP 7.4 or newer**, with the `curl` and `pdo_sqlite` extensions. Standard GoDaddy cPanel (Linux) hosting has all of these. It doesn't need a separate database or any extra libraries.

---

## 3. Before you upload: personalise it

1. **Photos.** Save your Airbnb photos into `site/images/`, replacing the placeholders and keeping the same file names:
   - `hero.jpg`: the large top photo. Use a wide exterior shot, about 1920px wide.
   - `about.jpg`: a portrait-shaped interior or detail photo.
   - `photo-1.jpg` … `photo-6.jpg`: the gallery. `photo-1` is shown largest.

   To get them from Airbnb, open your listing and click each photo. Right-click and choose "Save image as…". Keep each file under about 500 KB; [squoosh.app](https://squoosh.app) can shrink them.
2. **Wording.** Open `site/index.html` in any text editor and search for `EDIT:`. Paste in your Airbnb description, then fix the amenities list and the drive times so they match your listing. The rates on the page are filled in automatically from the config.

---

## 4. Upload to GoDaddy (the-kirks.com)

Put the site in a **sub-folder** so it doesn't disturb anything already on the-kirks.com. For example, use `carriagehouse` so the site lives at `https://the-kirks.com/carriagehouse/`.

### Upload the files
1. Log in to GoDaddy and go to **My Products → Web Hosting → Manage → cPanel Admin**.
2. Open **File Manager** and go into `public_html`.
3. Click **+ Folder** and create `carriagehouse`.
4. On your computer, zip the **contents** of the `site/` folder. To get it, download this repo from GitHub (**Code → Download ZIP**) and use the `site` folder inside.
5. In File Manager, open `public_html/carriagehouse`, click **Upload**, and upload the zip. Then right-click it and choose **Extract**. When you're done you should see `index.html`, `api/`, `admin/` and the other files directly inside `carriagehouse/`.
6. Turn on hidden files so you can check that the `.htaccess` files were extracted: **Settings → Show Hidden Files**. They're needed to keep `api/config.php` and the booking data private.

### Check PHP
In cPanel, go to **Software → Select PHP Version**. Choose 8.1 or newer, and make sure `curl` and `pdo_sqlite` are ticked.

### Create your config
1. In File Manager, go into `carriagehouse/api`.
2. Right-click `config.sample.php`, choose **Copy**, and name the copy `config.php`.
3. Right-click `config.php` and choose **Edit**. Set:
   - `site_url` → `https://the-kirks.com/carriagehouse`
   - `owner_email`: where booking alerts go.
   - `from_email`: a mailbox on your hosting domain, for example `bookings@the-kirks.com`. You can create one under cPanel → Email Accounts. If this address isn't on your domain, emails may go to spam.
   - `admin_password`, `cron_key`, `export_token`: change all three to long random strings.
   - Your Stripe **test** keys (see section 5).
   - Your Airbnb calendar link (see section 6).

### Set up the daily payments job
This job runs once a day. It charges the balance on bookings that are 7 days out, emails a reminder 3 days before that, and refreshes the Airbnb calendar.
1. In cPanel, go to **Advanced → Cron Jobs**.
2. Under Common Settings, choose **Once Per Day** (`0 0 * * *`). 9 am is friendlier, so you could change the hour to `9`.
3. Command:
   ```
   /usr/local/bin/php /home/YOUR_CPANEL_USERNAME/public_html/carriagehouse/api/cron.php
   ```
   The admin page shows the exact path to use for your account.

### Check your setup
Go to **https://the-kirks.com/carriagehouse/admin/** and log in. The **Setup checklist** at the bottom should show all green ticks.

---

## 5. Stripe: test first, then go live

1. Create or log in to your account at [dashboard.stripe.com](https://dashboard.stripe.com).
2. Turn on **Test mode** (the toggle at the top right). Then go to **Developers → API keys**.
3. Copy the **Publishable key** (`pk_test_…`) and **Secret key** (`sk_test_…`) into `config.php`:
   ```php
   'mode' => 'test',
   'test_publishable_key' => 'pk_test_...',
   'test_secret_key'      => 'sk_test_...',
   ```
   While you're in test mode, an orange **TEST MODE** bar shows on the site and no real money moves.
4. **Recommended:** add a webhook so a booking is confirmed even if the guest closes the browser while paying.
   - In Stripe, go to **Developers → Webhooks → Add endpoint**.
   - URL: `https://the-kirks.com/carriagehouse/api/webhook.php`
   - Event: `payment_intent.succeeded`
   - Copy the **Signing secret** (`whsec_…`) into `test_webhook_secret`.

### Test cards (any future expiry date, any 3-digit CVC, any ZIP)
| Card number | What happens |
|---|---|
| `4242 4242 4242 4242` | Payment succeeds |
| `4000 0025 0000 3155` | Asks for 3-D Secure authentication, then succeeds |
| `4000 0000 0000 9995` | Declined (insufficient funds) |
| `4000 0027 6000 3184` | Deposit succeeds after authentication, but the automatic balance charge **fails** because the bank wants authentication every time. Use it to test the failed-balance email and the "pay balance" page. |

### Test checklist
1. **Book with a 2-night weekday stay.** Use 4242… to book more than 7 days ahead.
   - You should land on the confirmation page.
   - The booking should show in `/admin/` and in Stripe → Payments, with 50% charged.
   - Two emails should arrive, one for you and one for the "guest".
2. **Try the minimum-stay rules.**
   - Choose 1 night, or 2 nights over Thanksgiving. You should get the minimum-stay message.
   - Choose 7 nights, then 30 nights. You should see the 15% and 30% discounts, and on 30 nights the taxes should be $0.
3. **Cancel as the guest.** Open the "manage booking" link in the confirmation email and cancel. The refund should appear in Stripe.
4. **Charge the balance.**
   - Make another booking and open `/admin/`.
   - Click **Charge balance now**. This does exactly what the daily job does on the due date.
   - Try it again with the `…3184` card to see the failure email. Then pay the balance from the guest's manage page.
5. **Test with a short lead time.** Book with check-in in fewer than 7 days. It should charge 100% and show as non-refundable.
6. **Check the Airbnb sync.** Block a date on Airbnb, wait about 10 minutes (or click **Sync now** in admin), and refresh the site. That date should now be struck through.

### Going live
1. Activate your Stripe account (business and bank details).
2. Turn Test mode off in Stripe and copy the **live** keys into `live_publishable_key` and `live_secret_key`.
3. Create the webhook again in live mode and put its secret in `live_webhook_secret`.
4. Set `'mode' => 'live'`.

Bookings made in test mode stay marked "test" in the admin. Cancel them before you go live so they stop blocking dates.

---

## 6. Airbnb calendar sync (both directions)

**Airbnb → website** (so the site never offers dates booked on Airbnb):
1. On Airbnb, go to **Listings → your listing → Availability → Connect calendars** (in the app it may appear as **Calendar sync**). Choose **Export calendar** and copy the link (`https://www.airbnb.com/calendar/ical/….ics?s=…`).
2. Paste it into `import_ical_urls` in `config.php`.

The site re-reads the Airbnb calendar:
- every 10 minutes while people are viewing the site
- again right before every booking
- daily through the cron job

If Airbnb can't be reached for 6 hours, online booking pauses itself to avoid double-booking.

**Website → Airbnb** (so Airbnb blocks dates booked on the site):
1. In the site's admin page, copy the link under **Calendar sync**. It looks like `…/api/calendar.php?token=…`.
2. On Airbnb, go to **Availability → Connect calendars → Import calendar**, paste the link, and name it "Direct bookings".

Airbnb refreshes imported calendars every few hours. There's a short window where a site booking hasn't reached Airbnb yet. For a booking made close to arrival, you may want to block the dates on Airbnb by hand.

---

## 7. Domain: landenbergcarriagehouse.com

I couldn't check availability from my build environment because outbound lookups were blocked. Web searches found no site at that address, so it **may** be available. To confirm, search for it at [godaddy.com/domains](https://www.godaddy.com/domains) or run a lookup at [lookup.icann.org](https://lookup.icann.org/en/lookup).

If you buy it later:
1. Add it to your hosting in cPanel. Use **Domains → Create a New Domain**, point the document root at `public_html/carriagehouse`, and untick "share document root" if asked.
2. Change `site_url` in `config.php` to `https://landenbergcarriagehouse.com`.
3. In Stripe, update the webhook URL. In Airbnb, update the import link (the admin page shows the new one).
4. Make sure SSL is on: cPanel → **SSL/TLS Status → Run AutoSSL**. Stripe requires https in live mode.

---

## 8. Day-to-day

- **New booking:** you get an email, and the booking appears in `/admin/` and in Stripe.
- **Guest cancels before the final payment date:** the refund is issued automatically and you get an email.
- **Owner cancellation:** in admin, use **Cancel + full refund** or **Cancel, no refund**. For a partial refund, use Stripe → Payments → Refund.
- **Failed balance charge:**
  - The guest is emailed a link to pay it.
  - The daily job retries up to 3 times.
  - You also get an email and can use **Charge balance now** in admin.
- **Blocking dates for yourself:** block them on Airbnb and they sync to the site.
- **Backups:** the booking database is `api/data/bookings.sqlite`. Download it from time to time. Stripe also keeps a full record of every payment.
- **Logs:** `api/data/app.log` (email and payment events).

## Running locally (optional)

```
cd site && php -S localhost:8000
```

Then open <http://localhost:8000>. The site works with the sample config, and booking turns on once Stripe test keys are in `api/config.php`.
