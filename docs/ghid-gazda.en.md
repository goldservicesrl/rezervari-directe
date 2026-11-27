# Host guide

This guide shows you how to install and use **Cazo**, the direct booking software (“the software” for short): the direct booking site you put on your own domain and connect to EasyCheck. It is written for hosts, in plain language. If you can upload a file in the cPanel File Manager, you can install the software yourself.

[Versiunea în limba română](ghid-gazda.md)

## Contents

1. [What the software is](#1-what-the-software-is)
2. [What you need](#2-what-you-need)
3. [Installing on cPanel, step by step](#3-installing-on-cpanel-step-by-step)
4. [The link with EasyCheck](#4-the-link-with-easycheck)
5. [Online payment with Stripe](#5-online-payment-with-stripe)
6. [Bank transfer and payment on arrival](#6-bank-transfer-and-payment-on-arrival)
7. [The site: appearance, content and publishing](#7-the-site-appearance-content-and-publishing)
8. [Bookings](#8-bookings)
9. [Cancellations and refunds](#9-cancellations-and-refunds)
10. [Updates](#10-updates)
11. [Security](#11-security)
12. [Guest data (GDPR) and data retention periods](#12-guest-data-gdpr-and-data-retention-periods)
13. [The cron job](#13-the-cron-job)
14. [Frequently asked questions](#14-frequently-asked-questions)

---

## 1. What the software is

The software turns your domain into a direct booking site:

- guests see the property, the rooms, the prices and the free dates, choose their dates and book on the spot;
- they pay online, by card and with the other methods active in your Stripe account, or they send a request with payment on arrival;
- with EasyCheck Pro, every booking reaches EasyCheck, just like the bookings from the booking platforms, so you keep a single calendar.

A few important things about how it works:

- **The software is free.** The source code is public, under the Cazo License. The only condition of the license is the “Vacation Rental Software - Cazo.ro” link in the site footer.
- **The site is yours.** You install it on your hosting, on your domain. The money from online payments goes straight into your Stripe account.
- **It comes without warranty.** The software is provided “as is”, without any warranty, and we are not liable for how you use it. The full terms are in the Cazo License, article 6. You accept them with a tick box during installation.
- **It also works without EasyCheck.** On its own, the site keeps its rooms, prices, deposit and cancellation policy in its dashboard, and the calendar links to the platforms through iCal.
- **With EasyCheck Pro, everything comes in automatically.** An EasyCheck key links the site to your property: rooms, prices, rate plans, the deposit, the cancellation policy and availability are set in EasyCheck, and the site picks them up on its own.

## 2. What you need

### Hosting

Ordinary hosting with cPanel is enough. The software needs:

| Requirement | Value |
|---|---|
| PHP | 8.2 or newer (8.2, 8.3 and 8.4 are tested) |
| Required PHP extensions | `pdo_mysql` or `pdo_sqlite`, `curl`, `openssl`, `mbstring`, `json`, `zip`, `gd` |
| Useful PHP extensions | `sodium` (the site works without it, using the library included in the software; with the extension, encryption is faster), `intl`, `imagick`, `fileinfo` |
| Database | MySQL or MariaDB (recommended); SQLite also works, after a test run by the installer |
| Web server | Apache (with mod_php or PHP-FPM), LiteSpeed or OpenLiteSpeed |
| HTTPS | a valid certificate on the domain (the free one, Let's Encrypt or AutoSSL, is enough) |
| Cron job | recommended (chapter 13); the site also works without it |

The installer checks all these requirements on its own. When something is missing, it tells you exactly what and gives you a ready-made text to send to your hosting company.

**Recommended hosting.** If you are choosing hosting now, we recommend [SiteBunker](https://client.sitebunker.net/aff.php?aff=1302): cPanel hosting in Romania, with everything the software needs (PHP 8.2 or newer, MySQL, free HTTPS, cron). The SiteBunker link is an affiliate link: if you sign up through it, we receive a commission, at no extra cost to you.

### The EasyCheck account (optional)

The site also works without EasyCheck: during installation choose **I'll fill in the details here, on the site** („Completez datele aici, în site”) and enter the property and rooms directly on the site (chapter 4, “The standalone site”). For the automatic link you need an EasyCheck Pro account with the property set up: rooms, prices, rate plans and cancellation policy. You create the key for the site in EasyCheck, under **Website** („Site web”), in the **Website / API access** option („Site Web / Acces API”). We recommend starting with a test key: bookings made with it stay in the test space and are deleted automatically.

### The Stripe account (only for online payment)

For online payment you need your own Stripe account (company, PFA or II). If you only want bank transfer or payment on arrival, the site works without Stripe.

## 3. Installing on cPanel, step by step

Installation takes about 10 minutes.

### Before installing: the database

If you use MySQL or MariaDB (recommended), first create the database in cPanel, under **MySQL Databases**:

1. create a new database (for example `cont_rezervari`);
2. create a new user, with a long password;
3. add the user to the database, with **ALL PRIVILEGES**.

Write down the database name, the user and the password: the installer asks for them in step 3.

### Uploading the archive

1. Download the latest version of the archive (`.zip`) from the project's releases page.
2. In cPanel, open **File Manager** and go to the account folder (`/home/<cont>/`, the one that contains `public_html`).
3. Upload the archive there and click **Extract**.

The archive has two folders:

- `public_html/`: the site's public files (a single PHP file, plus the assets);
- `cazo-privat/`: the site's code and data. It sits outside the public folder, where nobody can read it from a browser.

If the domain already has a site in `public_html`, first move it somewhere safe or use a new subdomain.

### The setup wizard

Open `https://your-domain.com/instalare/` in your browser. The wizard is in Romanian and English; you choose the language on the first screen.

For safety, the wizard stays open for at most 72 hours from the first visit and closes for good at the end of the installation.

**Step 1. The installation code.** On the first visit, the wizard writes an 8-character code to the file `cazo-privat/COD-INSTALARE.txt`. Open the file in File Manager and copy the code into the wizard. This way, only someone with access to cPanel can install the site.

**Step 2. The checks.** The wizard checks the PHP version, the extensions, memory and execution time, the upload size limit, HTTPS, the secure connection to EasyCheck and Stripe, URL rewriting and the folders it needs to write to. Each problem comes with its explanation and a ready-made text to send to your hosting company.

**Where the data lives** (part of step 2). The wizard chooses where the site's data is stored:

- **option A** (preferred): the `cazo-privat` folder sits outside the public folder;
- **option B**: when the hosting only allows writing inside `public_html`, the wizard moves the data to a protected folder inside `public_html`.

Then it runs the **probe**: from the outside, it tries to read the files that must stay hidden. Any response other than “access denied” or “page not found” stops the installation, with the steps to follow. The probe runs again every day after installation.

**The encryption key** (part of step 2). The wizard creates the key used to encrypt access keys and guest data. The key is stored in the private folder; this step happens on its own.

**Step 3. The database.** Enter the details of the database you created above. The wizard tests the connection and the creation of a table, then prepares the database. For SQLite, the wizard first tests writing to and locking the file.

**Step 4. EasyCheck or the details on the site.** The wizard asks **Connect the site to EasyCheck?**. With **I'll fill in the details here, on the site**, you enter the property (name, type, address, hours, house rules, contact), the booking rule and the first room, with the nightly and weekend price; you add the other rooms, photos and calendars later from the dashboard. With **Yes, with the EasyCheck API key**, paste the key you created in EasyCheck. The wizard shows you the property, the key mode (test or live), the price source and what the key can do (chapter 4). It then registers the notifications from EasyCheck, sends a test notification and runs the first sync, with a progress bar. The photos keep downloading in the background. Once the site is connected, the key field is tucked under the **Use another key** („Folosiți altă cheie”) link: a key entered there replaces the current one.

**Step 5. Website address.** Without an EasyCheck key, the site uses **Your domain**, and the wizard only checks that the address saved for the site is the one it runs on. With a key, choose **Your domain** or **The free cazo.ro subdomain** (chapter 4, “The free cazo.ro subdomain”). When the site already runs on a cazo.ro subdomain, the wizard asks EasyCheck whether the subdomain is reserved for your key and connects it by itself.

**Step 6. Legal details and data retention periods.** With an EasyCheck key, the company details come prefilled: name, tax ID (CUI), trade register number, registered office, classification certificate. Fill in whatever is missing; the installation continues even with empty fields, and the dashboard reminds you of them. This is also where you choose **the four data retention periods for guest data** (chapter 12). The fields come filled in with the recommended values; keep them or change them, within the limits shown next to each one.

**Step 7. Payments.** Tick how guests pay: **By card, online** („Cu cardul, online”), **By bank transfer** („Prin transfer bancar”), **On arrival (cash)** („La sosire (numerar)”), one or more (chapters 5 and 6); at least one tick is required, and with more than one the guest chooses when booking. For bank transfer, enter the bank account: holder, IBAN and bank. Payment on arrival is offered only for rates with a 0% deposit: for a rate with a deposit, the guest pays the deposit when booking, by card or by bank transfer. For card payment, paste the restricted key from Stripe; the wizard checks it and creates the payment notification endpoint on its own. For keys with “EasyCheck price”, this is also where you choose **what the guest pays on the site** on top of the nights: the extra fees from EasyCheck and the local tax, with the moment you collect it (chapter 4).

**Step 8. The theme.** You see the themes rendered on the spot with your name, photos and prices, plus a suggestion based on the property type. Choose one; you can change it at any time from the dashboard.

**Step 9. The administrator.** Enter the email address, the password and the dashboard language. The password is checked against a list of compromised passwords. We recommend you also turn on two-step verification (2FA), with an authenticator app and backup codes.

**Step 10. The cron job.** Copy the line shown into cPanel, under **Cron Jobs**, then click **Check** („Verifică”) (chapter 13).

**Step 11. Done.** The installation is complete and the wizard closes for good. The site enters **preparation mode** (chapter 7): anyone can see it, search engines ignore it, and you see the “In preparation” („În pregătire”) banner. The “What's next” („Ce urmează”) page shows you the last steps: in EasyCheck, accept the terms for linking sites before your first live key, and enter the site address there.

### The site's dashboard

The dashboard (`https://your-domain.ro/admin/`) has four menus:

- **Home** („Acasă”): what needs your attention today. The alerts to resolve (their number shows next to “Home” in the menu), publishing the site while it is in preparation, today's and tomorrow's arrivals, the requests waiting for an answer and the site status: the site runs, the automatic tasks run, the emails go out.
- **Site**: **Rooms** („Camerele”; with a single apartment, **Apartment**, „Apartamentul”): the title, the description and the photos; **Pages and landmarks** („Pagini și repere”); **Appearance** („Aspect”): the theme, with its colours and fonts; **Google and languages** („Google și limbi”).
- **Bookings and payments** („Rezervări și plăți”): **Bookings** („Rezervări”), a single list showing on each row what was paid, how, and what is left to pay on arrival, plus the online payments, the deposits and the monthly report for your accountant; **How guests pay** („Cum plătesc oaspeții”): the payment methods, the bank account, the Stripe key and what guests pay on the site; **Messages to guests** („Mesajele către oaspeți”).
- **Settings** („Setări”): **EasyCheck connection** („Legătura cu EasyCheck”), with your business details, which come from EasyCheck; **Legal and guest data** („Legal și datele oaspeților”); **E-mail**; **Team** („Echipa”); **Maintenance** („Întreținere”): updates, backups, the log and the diagnostics for your hosting company.

Settings you rarely need sit on their page, under **Advanced** („Avansat”): your own theme, the copy of the theme settings, the addresses of your old website, the DKIM selector (only when the signature is missing), the map service name, the maximum length of stay, the guest e-mail confirmation, the diagnostics and the automatic tasks table. **My account** („Contul meu”) is at the top right. The old page addresses (for example `/admin/poze`) lead to the new page.

## 4. The link with EasyCheck

### What the site takes from EasyCheck

The site keeps a local copy of the data from EasyCheck and updates it on its own:

- the property, the description, the address, the stay times and the house rules;
- the rooms, with the number of guests, the spaces (bedrooms, bathrooms, kitchen, beds) and the photos, with their category;
- availability, every few minutes;
- prices, rate plans, the deposit and the cancellation policy;
- the operator's legal details and the published contact details;
- the ratings from the platforms, when EasyCheck has them.

When you change something in EasyCheck, EasyCheck notifies the site, and the change appears on the site within seconds. If the notifications don't arrive (usually because of a firewall at the hosting company), the site updates itself every few minutes, and the dashboard shows you the likely cause and the text for your hosting company.

### Key mode: test or live

- **Test key:** bookings and requests go to the EasyCheck test space and are deleted after 24 hours. The site shows the “Test site” („Site de probă”) banner, and search engines ignore it.
- **Live key:** bookings go to the real calendar.

An EasyCheck test key only works with a Stripe test key, and a live one only with a Stripe live key. The site refuses any mix.

### The price source

In EasyCheck you choose, for the key, where the price comes from:

- **“EasyCheck price”** („Prețul din EasyCheck”): EasyCheck calculates the total for each stay, exactly as it does for the other channels, with the rate plans and discounts set there. What goes into the total on top of the nights is your choice in the site's dashboard, under **Bookings and payments › How guests pay**, in the “Site prices” block, under **What the guest pays on the site** („Ce plătește oaspetele pe site”): the “Extra fees from EasyCheck (cleaning, linen, per-person fee)” tick and “Local tax”, with three options: “Shown in the price and charged online, with the rest of the payment”, “Shown in the price, but collected on arrival” or “Not shown in the price (you ask for it separately, on arrival)”. The charged-online option appears only with card payment ticked. At installation, the fees and the local tax are shown in the price. Whatever is left out of the price you ask for separately, on arrival; the pet fee is always asked for separately.
- **“My site's price”** („Prețul de pe site-ul meu”): the site calculates the total, starting from the price grid in EasyCheck, plus the discounts you define in the site's dashboard (early booking, last minute, long stay). EasyCheck keeps the total exactly as it receives it. When you add a second discount, the dashboard asks you to choose whether the discounts add up or only the largest one applies.

### The standalone site (without an EasyCheck key)

The site also works on its own, without the EasyCheck API key. At step 4 the installer asks “Connect the site to EasyCheck?” („Legați site-ul de EasyCheck?”). With “I'll fill in the details here, on the site”, you fill in the property and the first room right there, and the rest in the dashboard:

- **Site › The property** („Proprietatea”): the name, type, description in Romanian and English, the address with the map point, check-in and check-out hours, house rules, contact and the **booking rule**, the same for all rooms: “Minimum number of nights” („Număr minim de nopți”, 1 by default), the deposit as a percentage, how many days before check-in guests can cancel for free and “Time to answer a request” („Timpul pentru răspuns la o cerere”), between 2 and 72 hours (24 by default): how long you have to accept or decline a request with payment on arrival. A new time applies to requests received from then on; requests already sent keep their deadline;
- **Site › Rooms and prices** („Camerele și prețurile”): each room, with its name, how many identical units you have, how many guests fit, the description and two prices: “Price per night” („Preț pe noapte”) and “Price per night at the weekend (Friday and Saturday)” („Preț pe noapte în weekend (vineri și sâmbătă)”). A stay's total is the sum of its nights: Friday and Saturday at the weekend price, the other nights at the night price, rounded to the leu. No local tax and no other extras in the total. You upload the photos under **Site › Rooms** („Camerele”), as before. On the same page, under **Discounts** („Reducerile”), the same for all rooms: “Long stay discount” („Reducere pentru sejur lung”, for example 10% from 7 nights) and “Early booking discount” („Reducere pentru rezervare din timp”, for example 15% when check-in is at least 30 days away). Guests see the discounted rate with the discount note (“Standard rate, with the discount for stays of 7 nights or longer”), in the price, the total and the e-mails. When a stay gets both discounts, the larger one applies. Leave the percentage empty to turn a discount off;
- **Bookings and payments › Calendar** („Calendarul”): the platforms' iCal addresses (Booking, Airbnb, EasyCheck or any other), read every 15 minutes (you choose the interval), the site's calendar address for each room (you put it on the platforms) and the days you close by hand.

Payments work as in EasyCheck mode: by card (Stripe), by bank transfer or on arrival. The difference: the decisions are in the site's dashboard. On **Home** („Acasă”) you accept or refuse requests with payment on arrival and press “I received the money” („Am primit banii”) when a transfer arrives. While the transfer deadline runs, you can extend it by one day, 2, 3 or 7 days with “Extend the deadline” („Prelungiți termenul”): the days are added to the current deadline, and the guest receives the new deadline by e-mail. E-mails to guests and to you are sent by the site. Payment on arrival appears only when the deposit is 0%.

**EasyCheck as an iCal source.** EasyCheck can also be linked to the site through iCal, on any plan: you put the iCal address from EasyCheck in the site, under Calendar; you put the site's address in EasyCheck, under “Any other iCal calendar” („Orice alt calendar iCal”). On the Start plan, the iCal link only closes nights; prices and photos come into the site only through the API key, on the Pro plan.

**Switching between modes**, without reinstalling, from **Settings › EasyCheck connection** („Setări › Legătura cu EasyCheck”), with your password and a confirmation tick:

- *from EasyCheck to the standalone site*: the site keeps the rooms, photos, texts, company details and the upcoming bookings it received, all editable in the dashboard; the night and weekend prices are filled in from the base rate, and you check them and save each room. The nights booked in EasyCheck at that moment stay closed until you add the iCal calendar from EasyCheck;
- *from the standalone site to EasyCheck*: the site switches to the data from EasyCheck and sends the upcoming bookings made on the site to EasyCheck, once. Those paid on arrival arrive there as requests, which you accept in EasyCheck. What it cannot send (for example a room with no match in EasyCheck, or a price calculated differently there) appears on the same page, with the reason; you add those directly in the EasyCheck calendar.

### The free cazo.ro subdomain

With **EasyCheck Pro**, the site can use a free address like `chosen-name.cazo.ro`, one per key. The subdomain is reserved only in EasyCheck, under **Website**, on the site's key; Cazo recognises and connects it, without reserving it itself. The steps, in order:

1. **Reservation.** In EasyCheck, under **Website**, on the site's key, type the name you want (lowercase letters, digits and hyphens, 3 to 40 characters) and reserve it.
2. **Hosting.** With your hosting company, add the subdomain as the site's domain (in cPanel, under **Domains**), with the folder you upload Cazo to.
3. **IP address.** Enter the hosting server's IP address in EasyCheck, on the same key (you find it in cPanel, under **Server Information**, or ask your hosting company). EasyCheck then points the address to your server.
4. **Installation.** Open `https://chosen-name.cazo.ro/instalare/` and follow the wizard. At the **Website address** step, the wizard recognises the reserved subdomain and connects it.

A site already installed on your own domain moves to the reserved subdomain from the dashboard, under **Settings**, **EasyCheck connection**, **Website address**: the **Move the site to …** button adds the subdomain to your hosting by itself, where the hosting allows this without passwords; otherwise it shows you the cPanel steps. The site then checks by itself, every few minutes, when the new address answers over HTTPS and moves to it, and the old address sends visitors to the new one. After the move, sign in to the dashboard at the new address.

### Moving bookings from the EasyCheck page to the site

For a property, guests book directly in one place only: on the **EasyCheck page** or on the **site** (the **Website / API access** option). While you prepare the site, the EasyCheck page stays active. EasyCheck follows the preparation by itself, under **Website**: the key created, the site's first answer, the installation finished (the site reports it by itself at the end of the wizard), a test booking and the live key used by the site. When all are ready, the **Move bookings to the site** button appears: the EasyCheck page then sends visitors to the site.

When you bring bookings back to the EasyCheck page, the site's live keys are revoked: the site keeps showing its presentation, and at booking guests read “Bookings are paused at the moment.” and your contact details. The site's dashboard explains to you what happened.

### What the key can do

If the key only allows reading, the site shows the presentation and the contact details, without online booking. The installer and the dashboard show you the steps for a new key, with the right to book.

### When the key is no longer active

- If EasyCheck rejects the key twice in a row, at least 5 minutes apart, online bookings stop, and you receive an email and an alert in the dashboard. The presentation stays on the site, from the local copy, with no time limit, and guests read “Bookings are paused at the moment.” and your contact details. This usually happens after direct bookings move back to the EasyCheck page (the live keys are revoked then). Create a new key in EasyCheck, under **Website**, and enter it in the dashboard, under **Settings**, **EasyCheck connection**.
- If EasyCheck suspends access (the account, the property or the subscription), the presentation stays, bookings stop, and the site checks the key status every 30 minutes. After reactivation, bookings come back on their own. You receive an email both when they stop and when they come back.

## 5. Online payment with Stripe

Chapters 5-9 describe the site linked to EasyCheck. Without EasyCheck, payments and bookings work the same way, but you decide on requests, transfers and cancellations in the site's dashboard (chapter 4, “The standalone site”).

### Payment methods

You choose the methods, during installation and later in the dashboard, with three tick boxes: **By card, online**, **By bank transfer** (chapter 6) and **On arrival (cash)**. Tick one or more; at least one is required. With more than one ticked, the guest chooses how to pay when booking; payment on arrival is offered only for rates with a 0% deposit. Until you choose, the site only shows the presentation: guests see everything, but cannot book yet.

With “By card, online”, the guest pays on the secure Stripe page, with all the methods active in your Stripe account (card, digital wallets and any others you have turned on in the Dashboard). The money goes straight into your Stripe account. The site never sees or stores card details.

### The restricted key

The site works with a Stripe **restricted key** (it starts with `rk_test_` or `rk_live_`), with exactly the permissions it needs. The full secret key (`sk_`) is refused. Create the key in the Stripe Dashboard, under **Developers › API keys › Create restricted key**, with these permissions:

| Resource | Permission |
|---|---|
| Checkout Sessions | Write |
| Payment Intents | Write |
| Refunds | Write |
| Charges | Read |
| Events | Read |
| Webhook Endpoints | Write (optional: this lets the site create its own notification endpoint) |
| Disputes | Read (optional) |
| Balance Transactions | Read (optional) |
| everything else | None |

In Stripe, each resource has a single choice: None, Read or Write. Write includes Read.

The dashboard checks each permission and shows you a green or red list. The check runs again every day. The Stripe account should use RON as its currency; the dashboard warns you if it uses another currency.

Start with the test key (`rk_test_`) and make a few test bookings. Only enter the live key once the site has valid HTTPS.

### Payment notifications

The site creates, on its own, the notification endpoint `https://your-domain.com/stripe-anunt` in your Stripe account (Stripe calls this a webhook). With it, payments are confirmed within seconds. If the key does not have the Webhook Endpoints permission, the dashboard gives you the address and the list of events to create it manually, plus the field for the signing secret. Payments are confirmed without notifications too, just a little more slowly.

### The deposit

You set the deposit in EasyCheck, on the rate plan, as a percentage of the total:

- **100%**: the guest pays everything now;
- **between 1% and 99%**: under “How much to pay now” („Cât plătiți acum”), the guest chooses how much to pay now: the deposit (the rest on arrival), selected by default, or the full amount;
- **0%**: the guest pays nothing when booking, and the booking goes out as a booking request with payment on arrival, which you accept in EasyCheck.

### The Stripe fee

The payment is collected immediately. If EasyCheck then refuses the booking (for example, the night was taken at that very moment), the site automatically refunds the full amount, and you bear the Stripe fee.

### When online payment stops

Online payment stops on its own, temporarily, in a few situations: a rate plan without a cancellation policy, a Stripe key that fails the check, data from EasyCheck that is too old, the automatic surge protection. You immediately receive an email with the reason and what you need to do, plus the alert in the dashboard, and another email when payment is back. If you chose only “Online by card”, guests can send a booking request with payment on arrival in the meantime.

### Two-step verification

When you enter the live key, the dashboard recommends that you turn on two-step verification (2FA). Live payments start without it too, but we recommend using it.

## 6. Bank transfer and payment on arrival

### Bank transfer

With **By bank transfer** ticked, the guest books on the site and pays the deposit (or the full amount) into your bank account. In the dashboard, under **Bank transfer** („Transferul bancar”), enter the account holder, the IBAN, the bank and, for payments from abroad, the SWIFT/BIC code. There you also choose:

- **the payment deadline**, between 12 and 120 hours: how long the guest has to pay. After the deadline, the booking is cancelled and the nights are released;
- **up to how many days before arrival** bank transfer is offered (between 0 and 30). For a closer arrival, the guest only sees the other methods.

The guest gets the account, the amount, the deadline and the booking code by email, and writes the code in the payment details. When the money arrives, confirm it: with EasyCheck, under the site requests, with **I received the money** („Am primit banii”); without EasyCheck, in the dashboard, on the booking page, also with **I received the money**. The guest then receives the booking confirmation. If the money does not arrive by the deadline, the guest learns by email that the booking stayed unconfirmed.

### Payment on arrival

With “On arrival (cash)” ticked (alone, or together with card when the guest picks payment on arrival), the guest sends a **booking request**:

1. the request reaches EasyCheck, with the response time you set there (2, 12, 24 or 48 hours); without EasyCheck, it reaches the dashboard, on **Home**, with the response time chosen under The property;
2. you accept or decline it in EasyCheck or, without EasyCheck, in the dashboard;
3. the guest receives the answer by email: the confirmation, with payment on arrival, or the refusal, with a link to other free dates.

Good to know:

- With payment on arrival, no deposit is collected, so there is nothing to keep if the guest cancels. If you want a deposit you can keep on cancellation, use card payment, on a rate plan with a deposit.
- The guest can cancel a booking with payment on arrival on their own, from “My booking” („Rezervarea mea”), up to and including the day of arrival. After the day of arrival, the button disappears, and you agree on any early departure directly with the guest.
- To protect against abuse, the site can ask for a 6-digit code, sent to the guest's email, before the request. The code is off by default, turns on by itself when abuse is detected, and you can turn it on or off from the dashboard. In the same place you tick which payment methods ask for it: by default payment on arrival and bank transfer; tick card payment only if you want it there too.
- The maximum length of stay on the site is 30 nights by default. You change it under **Bookings and payments › How guests pay › Advanced**: any number of nights from 1 up, or an empty field for a stay of any length.

## 7. The site: appearance, content and publishing

### Preparation mode and publishing

After installation, the site is in preparation mode: anyone with the address can see it, search engines ignore it, and you, when signed in to the dashboard, see the “In preparation” banner.

When everything is ready, click **Publish the site** („Publică site-ul”) (in the dashboard, on **Home** („Acasă”), in the “Publishing the site” block). With all the settings filled in (the legal details and the sales details), publishing takes one click. You can also publish with missing settings, at your own responsibility: for each field required by law, the dashboard shows you what is missing and what the risk is, and you confirm “I accept publishing with this data missing” („Îmi asum publicarea cu aceste date lipsă”). The alert stays in the dashboard until you fill them in.

### Themes

The software comes with ready-made themes, each with its own style. In the dashboard, under **Appearance** („Aspect”), you can:

- see each theme with your data, including the checkout page and “My booking”;
- customise the colours, fonts and sections, with a preview;
- go back to an earlier version at any time;
- upload a theme made by someone else; the dashboard checks it before activation.

Whatever the theme, the price, the payment, the legal texts, the calendar and the booking form stay under the software's control: the theme arranges and colours them, but cannot change or hide them.

### Content

With EasyCheck, the property description, the rooms, the photos, the room spaces, the stay times and the house rules come from EasyCheck. In the dashboard, under **Site › Rooms** („Camerele”; with a single apartment, **Site › Apartment**), you fill in what belongs only to the site:

- the room titles and descriptions in English;
- photos uploaded directly to the site, for the gallery and the sections, with their category;
- your own pages (guide, frequently asked questions) and the menus;
- the short texts the theme asks for: “Getting here” („Cum ajungeți”), “About the host” („Despre gazdă”), nearby landmarks.

The texts are optional and short. Next to each one you see the rule: write about areas and landmarks, without materials, brands or measurements.

### Languages

The site is in Romanian and English: Romanian at the main address, English under `/en/`. The dashboard shows you which translations are still missing.

### Legal details

At the bottom of every page, the site shows the details required by law: the name, the tax ID (CUI), the trade register number, the registered office, the share capital, the classification certificate, the contact details and the link to alternative dispute resolution (ANPC). The details come from EasyCheck; in the dashboard, under **Legal**, you fill in whatever is missing.

The legal pages (terms and conditions, privacy with cookies, accessibility, legal information) and the footer start from templates filled in with your data. In the dashboard, under **Settings › Legal and guest data** („Legal și datele oaspeților”), you can change them entirely, to adapt them to a new law: you see the differences from the template and can go back to the current template at any time. When a new software version changes a template, the dashboard shows you what changed, and your text stays on the site. You are responsible for the texts; we recommend reading them with a lawyer before publishing.

### Emails

The site sends emails to guests (confirmation, request, cancellation) and to you (new bookings, alerts). In the dashboard, under **Settings › E-mail** (the message texts are under **Bookings and payments › Messages to guests**), you can:

- if you want, set up the SMTP server (with instructions for cPanel); without it, the site sends through the hosting's email function;
- choose the sender of the emails to guests: the site's address (for example `rezervari@your-domain.com`, with replies sent to you), your own address or another address;
- check SPF, DKIM and DMARC, so the emails don't end up in spam;
- see the preview, send a test email and follow the queue.

Gmail or Yahoo addresses sent from another server often end up in spam. Preferably, use an address on the site's domain.

When guests press “Reply”, the message reaches you. A message written directly to the site's address (for example `rezervari@your-domain.com`) lands in the hosting's default mailbox instead. To receive it, in cPanel, under **Email › Forwarders**, forward the site's address to the address you read.

Emails to you and your colleagues go out in each person's dashboard language.

## 8. Bookings

### What the guest does

1. They choose the dates and the number of guests. The site searches all the rooms and shows the total for the stay, with the breakdown one click away, plus the cancellation policy of each rate plan.
2. They fill in a single checkout page: name, email, phone, country, plus an optional note for you (for example about breakfast or an extra bed).
3. They pay online or send the request with payment on arrival.
4. They receive the confirmation by email, which is also the contract, with the calendar file (`.ics`) and the “My booking” link.

Everything works without JavaScript too, and from a phone.

### What you receive

- The booking appears in EasyCheck, like any other.
- You receive a short email for each paid booking.
- In the dashboard, under **Bookings and payments › Bookings** („Rezervări”), you see all the bookings, with what was paid and what is left to pay on arrival, each with its history.

### The checks before payment

Before payment, the site reads the total and the availability again. If the total has changed, the guest sees the new total and the “Continue with the new total” („Continuați cu noul total”) button; they only pay an amount they have seen. After payment, the site checks availability once more and sends the booking to EasyCheck.

### When something goes wrong

| Situation | What happens |
|---|---|
| The night was taken at the very moment of payment | the site automatically refunds the full amount and shows the guest the free rooms for the same dates |
| EasyCheck refuses the booking (price changed, minimum stay) | the site automatically refunds the full amount; if the price changed, the guest can continue with the new total |
| EasyCheck does not respond | the site retries for 6 hours, then checks whether the booking arrived after all; if it did not, it refunds the amount and notifies the guest |
| The outcome cannot be determined | the money stays untouched, the guest receives the message “We're checking the booking directly with your host” („Verificăm rezervarea direct cu gazda”), and after 24 hours you receive the alert and decide |
| Two bookings for the same night | the guest receives the normal confirmation; you receive the alert and sort it out in EasyCheck, as with the platforms |

### Abuse protection

The site has limits for booking attempts, payments and requests, plus automatic **surge protection** („Întrerupătorul”): when the site sees signs of abuse, online booking stops, guests see your contact details, the 6-digit code turns on for requests with payment on arrival, and you receive an email. You can change the limits from the dashboard. You restart online booking yourself from the dashboard, under **Requests** („Cereri”), with “Restart bookings” („Reporniți rezervările”), once the surge is over. Only the 6-digit code that turned on automatically turns itself off, after 24 hours without booking attempts above the threshold, and you receive the email “The email code for pay-on-arrival requests has switched off” („Codul pe e-mail pentru cererile cash s-a oprit”).

## 9. Cancellations and refunds

### Where you decide: in EasyCheck or in the panel

While the site is linked with an EasyCheck key and both the site software and EasyCheck know how to work together, bookings, money and messages to guests are decided in EasyCheck. The site panel shows them but no longer changes them:

- **Home** shows the line “Bookings, money and messages are decided in EasyCheck.”, with the **Open EasyCheck** button;
- under **Bookings**, every booking has **Open in EasyCheck**. That is where you cancel, decide a guest's cancellation request, refund to the card and resend the confirmation. The site makes the refund from your Stripe account and emails you each time;
- online payments become the **Card payments log**, read only; the monthly report for your accountant, with the Stripe fees, stays;
- under **How guests pay** you find the **Accept refunds instructed from EasyCheck** switch („Primesc rambursări comandate din EasyCheck”), on from the start, and the daily limit (5 refunds and 5,000 lei), which you change with your password. The “Can make refunds” right leaves the panel: in EasyCheck, refunds are made by whoever has the “Card refunds” permission.

The booking page keeps **Retry completion now** and **I settled the payment in Stripe**, which repair an order left half done on the site.

The panel stays as described below, with every decision in it, when the site has no EasyCheck key, when EasyCheck does not have these features yet and when the site software is not updated. With a test key, the page of a test booking has buttons that stand in for you in EasyCheck (for example “Return to card (test)” or “Resend the confirmation (test)”), so you can try the whole path without real money.

### Cancellation by the guest

- **Within the free cancellation period** (set in EasyCheck on the rate plan): the guest cancels on their own from “My booking”. On the same page they see the conditions and tick “I confirm I want to cancel my booking.”; if the conditions changed in the meantime, the page shows them again and the cancellation waits for the guest's confirmation. The site cancels the booking in EasyCheck and automatically refunds the full amount paid. You receive the email too.
- **After the free cancellation period**: the guest sees the conditions and sends you a cancellation request. In the dashboard, under **Bookings**, you decide: **Keep the booking** („Păstrați rezervarea”, no password needed, since no money moves), or cancel it and choose the amount to refund, from zero to the full amount paid (with your password).
- **Bookings with payment on arrival**: the guest cancels them on their own, up to and including the day of arrival (chapter 6).

### Cancellation made by you in EasyCheck

When you cancel in EasyCheck a booking that came from the site, the site sees the cancellation, and the booking appears in the dashboard as **“Cancelled: choose the refund”** („Anulată: alegeți suma returnată”). The money stays untouched until you decide how much to refund:

- the dashboard shows how much was paid online and how much is still in the Stripe account, plus a suggestion based on the rate rule (how much to refund and how much to keep);
- choose **Refund the full amount** („Returnați toată suma”), **Keep the full amount** („Păstrați toată suma”), or type another amount and click **Complete the cancellation with this amount** („Încheiați anularea cu suma scrisă”);
- the guest receives the cancellation email after your decision.

Until you decide, you see the alert in the dashboard, and after one day you receive a reminder by email. The rule applies at any time, including after arrival (for example, for a no-show).

When the cancellation is for reasons on your side (for example an overbooking or a fault), the guest is entitled to the full amount. Keeping an amount under the rate rule fits when the guest gave up the stay or did not show up.

A cancellation started on the site and completed in EasyCheck (for example, a cancellation approved in the dashboard with RON 700 refunded out of a RON 1,000 payment) ends with the amount chosen on the site.

### Refunds from the dashboard

In the dashboard, under **Bookings**, you refund the full amount or part of it, also without a cancellation. Choose the reason from a list (the guest's request, a problem during the stay, a goodwill gesture, another reason) and, if you like, write an internal note, stored encrypted. The guest receives an email for every successful refund. After a cancellation, you can also refund from the dashboard the amount you kept, in full or in part.

Refunds are made by the owner and by the staff members the owner has given this right, in the dashboard, under **Who can make refunds** („Cine poate face rambursări”), on the **How guests pay** page. Each refund asks you to confirm your password again. Only the owner and the staff members with this permission can see the list of payments; only the owner sees the payments export for the accountant and the list of deposits collected for future stays.

The Stripe fee on the original payment stays with Stripe, even when you refund.

### Delayed refunds

- **A failed refund** (for example, the Stripe balance is too low): the site retries 3 times within 24 hours, then you receive the alert, with the steps to take in the Stripe Dashboard.
- **A refund that stays in processing at the bank for more than 48 hours**: you receive an alert by email and in the dashboard, then a reminder every day until it is resolved. The usual cause is the Stripe account balance; the site sees the result on its own.

### Changing the dates

The guest can change the dates on their own **once** per booking, from “My booking”, with the **Change the dates** („Schimbați datele”) button, up to and including the end of the free cancellation period. After that, the guest contacts you directly.

- The site checks availability for the new dates and recalculates the price.
- **The new stay costs more:** the guest pays the difference by card, on the spot, before the change.
- **The new stay costs less:** the site automatically refunds the difference.
- **With payment on arrival:** only the total to pay on arrival changes.
- The cancellation rule from the day of the booking stays, calculated for the new dates: the free period, the deposit, the non-refundable rate.

In EasyCheck, the change appears as a new booking and the cancellation of the old one; for the guest it stays the same booking, with the same “My booking” link. If the new dates overlap the old ones and the new booking fails, the site restores the old booking and lets the guest know.

## 10. Updates

New versions are published on the project's releases page, on GitHub, and announced on cazo.ro. The site does not update itself and downloads nothing: once a day it reads the public file `https://cazo.ro/versiune.json`, and when a newer version appears, the dashboard shows the notice “There is a new version”, on **Home** and under **Settings › Maintenance** („Întreținere”), with the date, what it brings and the download link. The site's owners also get an email, once for each version. The request to cazo.ro carries no data about the site or the guests.

You install updates yourself, from File Manager:

1. In the dashboard, under **Settings › Maintenance**, make a copy of the database.
2. Download the new version's archive (`.zip`) from the releases page and unzip it on your computer.
3. In the archive, under `cazo-privat/lansari/`, find the new version's folder (for example `0.2.0`). Upload it into the `lansari/` folder of the site's private folder (with option A, `cazo-privat`, next to `public_html`, or `pragul-privat` for sites installed before the software was called Cazo; with option B, the hard-to-guess folder inside `public_html`).
4. Replace the `curent.php` file in the site's private folder with the one from the archive. From that moment, the site runs the new version; on the first visit it applies the database changes on its own.
5. Open a few pages of the site and the dashboard. If something doesn't work, put the old `curent.php` back: the previous version stays in `lansari/`.

The files in `public_html` and the cron line do not change with an update. You can delete the folders of old versions from `lansari/` once the new version works.

## 11. Security

- **A single public PHP file.** The rest of the code and all the data sit in the private folder, checked at installation and every day by the protection probe.
- **Sensitive data is protected:** access keys and guest data are encrypted, and passwords are kept only as a hash.
- **The dashboard** asks for a password, two-step verification for every user who turns it on, and password reconfirmation for important operations. Sign-in limits failed attempts.
- **Roles:** the owner has access to everything; staff members see bookings, requests and content. The owner grants the refund permission to each person individually.
- **The audit log** keeps the important operations, in a chain that can be verified.
- **Backups:** the site makes a copy of the database every day (the last 7 days), and before an update you make one from the dashboard too. You find them under **Settings › Maintenance**.

One thing is worth saying openly: anyone with full access to your cPanel account also has access to the site. Keep your cPanel password safe, and turn on two-step verification there too, if your hosting offers it.

## 12. Guest data (GDPR) and data retention periods

### Who is responsible for the data

You are the controller of your guests' data. The software runs on your hosting and sends no usage data (no telemetry). Booking data goes only to EasyCheck, as processor, and, for online payment, to Stripe. The booking keeps only the data needed for the booking; the guest fills in the registration form through EasyCheck online check-in.

### The four data retention periods

You choose the periods, during installation and later in the dashboard, under **Settings › Legal and guest data**. The fields come filled in with the recommended values; keep them or change them, within the limits below:

| Period | Limits | Recommended |
|---|---|---|
| **confirmed bookings**, after departure | 180-1095 days | 1095 days (3 years) |
| **abandoned orders** (started and left halfway, or with the payment declined by the bank) | 24-720 hours | 72 hours |
| **refunded or declined orders** (money returned, requests declined or expired) | 30-1095 days | 180 days |
| **the full IP address in consents** | 6-36 months | 36 months (3 years) |

When the period ends, the site anonymises the data: only the booking code, the stay dates, the amounts and the payment identifiers remain, for accounting. When the data is anonymised, the booking's “My booking” page closes too.

If you installed the site before these limits and one of your periods lies outside them, it stays in force until you change it; the dashboard shows a warning next to the field.

This is also where you choose how long the dashboard keeps the **alert log**: between 30 and 3650 days, 365 to begin with.

For the period that suits you, ask your accountant.

### Cookies

By default, the site only uses strictly necessary cookies (the booking in progress and the dashboard), so it works without a banner. If you turn on Google Analytics (optional, off by default), the cookie banner appears, with “Decline” („Refuz”) as visible as “Accept”.

### Guests' rights

In the dashboard, under **Settings › Legal and guest data** („Legal și datele oaspeților”), you can find a guest's data by email address, export it and delete it. Each operation goes into the audit log.

### Consent to news

On the checkout page, the guest can tick consent to receive news (the box is unticked by default). The site keeps the consent with its text and version, and the guest withdraws it with one click, from “My booking” or from any offer they receive.

You find the subscribers in the panel under **Bookings and payments › Offer subscribers** (the page belongs to the owner): how many there are, their names, addresses, language, where the consent came from and its date. From there you remove an address from the list and download the list as a CSV file (with your password).

That is also where you write an offer: the subject and the text, with an empty line between paragraphs. The site adds the greeting, the signature and the unsubscribe link. Preview the offer, send yourself a test, then tick the confirmation and send it to all subscribers (with your password). Offers go out through the site’s email queue, gradually, at most 60 per hour, so the mail server delivers them safely (the value can be changed in `config.php`, with `oferte_pe_ora`). Every message has a personal unsubscribe link that works without an account, plus the one-click unsubscribe headers that Gmail and Outlook show next to the sender. The page keeps a log of sends: the date, the subject, the number of recipients, how many messages went out and how many failed. For sending to many subscribers we recommend your domain’s SMTP server (**Settings › Email › Optional: sending through your domain’s e-mail account**).

With an EasyCheck key, once EasyCheck has the subscriber list turned on, the consent given on the site travels with the booking, and the page shows the combined list: subscribers from the site and those from EasyCheck (for example, from online check-in), once per address. An unsubscribe on the site also reaches EasyCheck, and offers go to the whole list. The “The list in EasyCheck” link opens the list in your EasyCheck account. Without an EasyCheck key, the list holds only the consents collected on the site.

## 13. The cron job

The cron job starts the background tasks at regular intervals: confirming payments, following up requests, syncing with EasyCheck, emails, photos, backups, updates.

In cPanel, under **Cron Jobs**, add the line shown by the installer. It looks like this:

```
*/5 * * * * php -q /home/<cont>/cazo-privat/cron.php
```

If your hosting has no command-line PHP, use the version with the address (you find the token in the dashboard):

```
*/5 * * * * curl -s https://your-domain.com/_cron/<token>
```

Without a cron job, the site still works: the tasks also start after guest visits and when you open the dashboard. The cron job, however, runs them on time. The dashboard shows you when it last ran and warns you after 30 minutes without a run. When the tasks have paused for an hour or more (at night, for example, with no visitors), the dashboard shows the pause on Home for 24 hours and asks you for the cron line. Online payment carries on: before stopping it, the site reads the availability from EasyCheck again and stops it only if that read fails.

## 14. Frequently asked questions

**How much does the software cost?**
The software is free. You need hosting and a domain; an EasyCheck account is optional. For online payment, Stripe takes its own fee on each payment.

**Where do I change prices and availability?**
With EasyCheck, in EasyCheck: the site picks them up on its own, within seconds. Without EasyCheck, in the site's dashboard.

**Can I use the site with payment on arrival only?**
Yes. Tick only “On arrival (cash)” during installation or in the dashboard: payment on arrival is enough, without Stripe.

**What happens if the same night is booked on the site and on a platform?**
The site checks availability before and after payment, so the remaining window is a few seconds. If it still happens, the guest receives the confirmation, and you receive the alert and sort it out in EasyCheck, as with the platforms.

**Do I receive the money directly?**
Yes. When the guest pays, the amount is put on hold on their card and charged once EasyCheck confirms the booking, usually within seconds; if the booking cannot be confirmed within 10 minutes, the hold is released, with no fee. Charged payments go straight into your Stripe account, and from there into your bank account, according to the Stripe payout schedule.

**Can I do a test run before I receive real bookings?**
Yes. Use an EasyCheck test key and a Stripe test key. Test bookings are deleted automatically after 24 hours. The Stripe test card is `4242 4242 4242 4242`, with any future date and any code.

**When does the site appear in Google?**
After you click “Publish the site”. In preparation mode and with the test key, the site asks search engines to leave it out.

**Can I change the theme after publishing?**
Yes, at any time, from the dashboard, under **Appearance**. The preview shows you the theme with your data before you activate it.

**What do I do if the notifications from EasyCheck don't reach the site?**
The site still works, updating every few minutes. The dashboard shows you the likely cause (firewall, mod_security, a redirect to HTTPS or to www) and a ready-made text to send to your hosting company.

**Who is responsible for the site's legal texts?**
You are, as the site's operator. The software gives you templates filled in with your data, which you can change entirely from the dashboard; we recommend checking them with a lawyer.

**Who helps me with the site?**
The software is offered free of charge, as is. For help with installation or hosting, you can turn to your hosting company or to a developer.

**Can I modify the software?**
Yes. Cazo is free and its source code is public, under the Cazo License (the `LICENSE` file). You may use, copy, modify and distribute it freely, including modified versions. The only condition is the footer link, “Software Regim Hotelier - Cazo.ro” (on the English pages, “Vacation Rental Software - Cazo.ro”), to https://cazo.ro/, which stays visible on every public page. The position and style of the link may change, its text and target may not.

**Can I remove the footer link?**
No. The link is part of the license, so the dashboard has no checkbox for it and it shows with every theme. If it is missing, you have 30 days from the notice to put it back; otherwise the license ends.
