# SakayTa - Full System Setup Guide

This is the complete version, tested end-to-end before being sent to you. It has:
- A real website (homepage, booking with a map, live rides dashboard, driver/passenger tracking)
- REST API with a real SQLite database
- A third-party fare API (with a working fallback so it runs even without a key)
- A SOAP/WSDL service written in **PHP** (different stack from your Node.js API)
- A message queue (**RabbitMQ**) for asynchronous driver assignment
- Two data stores (SQLite + a JSON audit log)
- Real-time GPS tracking (same as before)

There are **4 separate things** that need to be running at the same time. That sounds like a lot, but each one is one command, and you open one terminal per thing.

---

## Step 1: Install what you need

| Tool | Why | Get it |
|---|---|---|
| Node.js | Runs your main server | nodejs.org (you already have this) |
| **XAMPP** | Gives you PHP with SOAP support, no config needed | apachefriends.org — install it, you only need the PHP part, don't worry about Apache/MySQL |
| **Docker Desktop** | Runs RabbitMQ (the message queue) with one command | docker.com/products/docker-desktop |

## Step 2: Set up the project

```
cd sakayta-website
npm install
copy .env.example .env
```
(On Mac, use `cp` instead of `copy`)

You don't need to edit `.env` yet — everything has safe defaults.

## Step 3: Start RabbitMQ (only needs to be done once, then it just keeps running)

With Docker Desktop open, run this in any terminal:
```
docker run -d --name sakayta-rabbitmq -p 5672:5672 -p 15672:15672 rabbitmq:3-management
```
This also gives you a **management dashboard** at http://localhost:15672 (login: guest / guest) — this is genuinely useful for your defense, since your checklist asks for a "broker or queue dashboard" as evidence.

## Step 4: Start the SOAP service (Terminal 1)

```
cd soap-service
[path-to-xampp]/php/php.exe -S localhost:8080 driverVerification.php
```
On Windows, that's usually:
```
C:\xampp\php\php.exe -S localhost:8080 driverVerification.php
```
Leave this terminal open. You should see `PHP Development Server ... started`.

## Step 5: Start the main server (Terminal 2)

```
npm start
```
You should see `SakayTa website running: http://localhost:3000`.

## Step 6: Start the queue worker (Terminal 3)

```
npm run worker
```
You should see `Queue consumer started, waiting for ride requests...`

**Important:** this is a separate process from your main server on purpose — it's what makes the driver assignment *asynchronous* instead of instant, which is exactly what your course means by "queued/event-driven transaction."

---

## Step 7: Try it out

Open **http://localhost:3000** in your browser.

1. Go to **Book a Ride** — click the map once for pickup, once for drop-off, enter your name, click Book. You'll see a fare estimate.
2. Go to **All Rides** — refresh, you'll see your ride. Watch its status change from `pending` to `assigned` after a couple seconds (that's the queue worker doing its job in the background). Click JSON or XML next to it to see both formats.
3. Still on **All Rides**, try the "Test SOAP Driver Verification" box — type `d1` or `d2`, click Verify, and you'll see the raw SOAP XML envelope. Great to show at defense.
4. Open **Driver View** and **Passenger View** in two tabs with the same Ride ID, click "Simulate Movement" on the driver tab — same real-time tracking as before.

---

## How each piece maps to your course checklist

| Requirement | Where it is |
|---|---|
| CRUD (2 entities) | `routes/rides.js` + `routes/drivers.js`, saved in `data/sakayta.db` (SQLite) |
| Published REST API | Everything under `/api/rides` and `/api/drivers` (8+ endpoints) |
| SOAP/WSDL | `soap-service/driverVerification.php` + `.wsdl`, consumed from `services/soapClient.js` |
| Third-party API | `services/fareService.js` (OpenRouteService, with fallback) |
| JSON + XML | Compare `/api/rides/:id` vs `/api/rides/:id/xml` |
| Middleware/messaging | `services/queueProducer.js` (publishes) + `services/queueConsumer.js` (consumes), via RabbitMQ |
| Heterogeneity | Node.js (REST + queue) + PHP (SOAP) as two stacks; SQLite + JSON audit log as two data stores |
| Real-time tracking | Socket.io in `server.js`, `driver.html` + `passenger.html` |

## Getting a free fare API key (optional, for real distances instead of straight-line)

1. Sign up free at openrouteservice.org/dev/#/signup
2. Copy your API key
3. Paste it into `.env` as `ORS_API_KEY=your-key-here`
4. Restart the server (`npm start` again)

Until you do this, fare estimates use straight-line distance — which is fine for a demo, just mention it if asked.

## Troubleshooting

- **"Could not publish to RabbitMQ"** in the server log → Docker isn't running, or you haven't run the `docker run` command from Step 3 yet.
- **SOAP errors / verification always fails** → make sure the PHP service (Terminal 1) is actually running and reachable at http://localhost:8080/driverVerification.php?wsdl in your browser.
- **Ride stays "pending" forever** → check Terminal 3 (the worker) is running. If the worker isn't running, nothing ever picks up queued rides.
- **PHP says "Class SoapServer not found"** → your PHP doesn't have the SOAP extension enabled. This is why we recommended XAMPP — it's enabled by default there.
- **Port already in use** → something else is using that port. Close other terminals running the same service, or restart your computer.
