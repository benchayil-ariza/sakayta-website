# SakayTa

SakayTa is a ride booking website for tricycles, which are called **Taxicle** in our place (Pagadian City, Zamboanga del Sur). A commuter books a ride, a nearby driver accepts it, and both of them can follow each other on a map until the ride is done. When the ride is completed, both get a receipt in the website and in their email.

This is our IPT2 group project at JHCSC. Authors: Benchayil Ariza and Nuel Jay Dayhop.

## What it can do

**Commuter**
- Sign up with a verified email (a 6-digit code is sent to the email), log in, and reset a forgotten password.
- Book a ride by tapping the map or using "Use my location". Pickup and drop-off show as place names, for example "Macasing, Pagadian City, Zamboanga del Sur".
- See the fare before booking. The fare is based on the road distance.
- Follow the driver on a map. Only one active ride at a time.
- See ride history and receipts. A receipt is also sent to the commuter's email.

**Driver**
- Submit a license for verification, set available or unavailable, and accept or decline requests.
- Dashboard (today's rides and earnings), ride history, and an Active Ride page with a map, a Navigate button, and an "I've arrived" button.

**Admin**
- Overview, verify or reject drivers, change the fare settings, and read the action logs.

## How it is built

| Part | What it is | Address |
|---|---|---|
| Web pages | HTML, CSS, and JavaScript in `public/`, served by Node.js | http://localhost:3000 |
| API | PHP REST API (`php/public/api.php`) on Apache | http://localhost:8088/api |
| Database | MariaDB, database name `sakayta` | port 3306 |
| Live tracking | Socket.io on the Node.js server (no database in Node) | http://localhost:3000 |
| License check | PHP SOAP service in `soap-service/` (sample data) | http://localhost:8080 |

Outside services: OSRM (road distance and route line), Nominatim (place names), OpenStreetMap tiles with Leaflet (maps), and Brevo (emails: codes and receipts).

## What you need

- XAMPP with Apache, MariaDB, and PHP 8.2 or newer (the `curl` and `soap` extensions on)
- Node.js 18 or newer
- A free Brevo account with a verified sender and an API key (only needed for emails)

## Setup

1. **Get the code and install**
   ```
   git clone https://github.com/benchayil-ariza/sakayta-website.git
   cd sakayta-website
   npm install
   ```

2. **Database.** Start Apache and MariaDB in XAMPP. In phpMyAdmin create a database named `sakayta` and import `php/sql/schema.sql`.

3. **PHP settings.** Copy `php/config/.env.example` to `php/config/.env` and fill in the values (database login, `AUTH_TOKEN_SECRET` with at least 16 random characters, and the Brevo settings). This file is ignored by Git. Never commit it.

4. **Node settings.** Copy `.env.example` to `.env`. Set `JWT_SECRET` to the **same value** as `AUTH_TOKEN_SECRET`, and fill in `PHP_API_URL` and `SITE_ORIGIN`.

5. **Apache.** Point the Apache DocumentRoot to `php/public` on port 8088, as described in `php/README.md`. The API is then at http://localhost:8088/api/.

6. **SOAP service** (leave this terminal open):
   ```
   cd soap-service
   C:\xampp\php\php.exe -S localhost:8080 driverVerification.php
   ```

7. **Start the website:**
   ```
   npm start
   ```
   Open http://localhost:3000.

8. **Create the first admin** (public signup cannot make admins):
   ```
   $env:ADMIN_EMAIL="admin@example.com"
   $env:ADMIN_PASSWORD="a strong password"
   C:\xampp\php\php.exe php\bin\create-admin.php
   ```

## Useful commands

| Command | What it does |
|---|---|
| `php php\bin\mail-tools.php test you@gmail.com` | Sends a test email through Brevo |
| `php php\bin\mail-tools.php receipt <rideId>` | Sends the receipt of a ride |
| `php php\bin\mail-tools.php preview otp` | Makes a preview page of an email design |
| `node scripts/security-check.js` | Runs the login, role, and input checks on the local API (test accounts only) |

## Security

- Passwords are hashed with bcrypt (8 to 72 characters). One-time codes are saved only as hashes, work once, expire in 10 minutes, and are blocked after 5 wrong tries. There are limits per email and per IP address.
- Login gives a signed token. The server checks the token and the role on every protected route, and users can only open their own rides.
- All database queries use prepared statements. Place names are cleaned on the server.
- Secrets are only in `.env` files, which Git ignores. The repository holds `.env.example` files with empty or fake values only.
- Emails hold only the receipt data or the code. The Brevo key is used on the server and never reaches the browser.

## Folders

```
public/        web pages, CSS, JavaScript, images
server.js      Node.js: serves public/ and carries the live driver position (Socket.io)
php/           PHP API (Auth, Driver, Ride, Admin, Mail, Geo), config, sql, bin tools
soap-service/  PHP SOAP service for the license check
scripts/       security-check.js
```

## Known limits

- The site runs on http://localhost. HTTPS and a real domain are needed before real use.
- The fare estimate and place-name routes do not need a login yet.
- A failed receipt email is not sent again by itself. The receipt stays in the website.
- OSRM and Nominatim are free public services with fair-use limits. The app keeps caches to reduce the calls.
- Payment is cash only. There is no online payment.

More detail is in the SRS (Chapters 5 to 8).