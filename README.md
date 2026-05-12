# Settle — Crypto Invoicing for Freelancers

> Get paid in stablecoins. Without banks. Without waiting. Without borders.

Settle is a SaaS platform that lets freelancers send professional invoices and get paid in **USDC / USDT / DAI** on cheap EVM L2s (**Base · Polygon · Arbitrum · Optimism**). It's built like Stripe, but on the rails freelancers in countries with broken banking actually want.

**Live domain (production):** [settlepay.pro](https://settlepay.pro)
**Status:** MVP shipped (May 2026). Mainnet listener running on Base / Polygon / Arbitrum / Optimism. End-to-end critical path proven: signup → connect wallet → create invoice → client pays → daemon detects on-chain payment → invoice flips to paid → email + PDF receipt delivered. Dogfooding for $1 real-USDC payments before public Product Hunt launch.

![Settle UI preview](docs/screenshot.png)

---

## Table of contents

1. [Why this exists](#why-this-exists)
2. [How it works (the 6-step payment flow)](#how-it-works)
3. [Architecture](#architecture)
4. [Tech stack](#tech-stack)
5. [Supported chains and tokens](#supported-chains-and-tokens)
6. [Local development with Docker](#local-development-with-docker)
7. [Project layout](#project-layout)
8. [Configuration & environment](#configuration--environment)
9. [Database schema](#database-schema)
10. [Money handling rules (read this)](#money-handling-rules-read-this)
11. [Internationalization](#internationalization)
12. [Design system](#design-system)
13. [Security model](#security-model)
14. [Sending email (Resend setup)](#sending-email-resend-setup)
15. [Google sign-in (optional)](#google-sign-in-optional)
16. [Running the chain listener (daemon)](#running-the-chain-listener-daemon)
17. [Deployment](#deployment)
18. [Roadmap](#roadmap)
19. [License](#license)

---

## Why this exists

Freelancers in **Ukraine, Argentina, Nigeria, Brazil, Mexico, Colombia, the Philippines, Vietnam, Egypt, Turkey** and similar markets lose **5–10% of every invoice** to SWIFT delays, frozen wires, currency conversion spreads, and platform fees. They already use crypto privately — but the tooling is awkward, custodial, or built for Web3 natives.

Settle removes the friction with a Stripe-grade UX layered over **non-custodial** stablecoin payments. We are a software facilitator, not a money transmitter — funds move directly from client wallet to freelancer wallet.

---

## How it works

![Settlepay payment flow](docs/diagrams/flow.svg)

Six steps from invoice to settled, end-to-end in under a minute. Two architectural rules hold the whole thing together:

1. **Symfony writes nothing on-chain. It only reads.** All transactions are signed in the client's browser by their own wallet. This keeps Settlepay out of money-transmitter regulation territory and dramatically simplifies the codebase.
2. **Funds move directly from client wallet → workspace payout wallet.** We never custody. The listener watches `Transfer` events on the workspace's payout address, matches them to open invoices by amount + token, and updates the database — that's it.

A workspace is the unit of "the business" — it owns invoices, payments, billing state, branding, and payout settings. Solo freelancers get exactly one workspace where they are the sole Owner. Agency-tier accounts can invite teammates (up to 10 seats) so multiple users share the same invoice pool.

---

## Architecture

![Settlepay architecture](docs/diagrams/architecture.svg)

### Why the stack looks this way

- **Twig for marketing, docs & checkout** → server-rendered, fast, SEO-friendly. The conversion-critical payment page is plain Twig + a thin `viem`/`wagmi`/`RainbowKit` JS island.
- **React SPA for the dashboard** → complex, stateful UI (invoice CRUD, real-time payment updates, team management) where SPA pays off. Workspace-scoped throughout.
- **MariaDB, not PostgreSQL** → existing Hetzner server already runs MariaDB; the invoicing workload doesn't need PG-specific features. Workspace as ownership scope: tables like `invoices`, `payments`, `billing_intents`, `api_tokens`, `webhooks` all FK to `workspaces.id`.
- **PHP for the listener** → keeps the whole codebase in one language. Latency budget is "seconds, not milliseconds." The same daemon watches BOTH invoice recipient wallets (for client payments) AND the Settlepay platform wallet (for Pro/Agency subscriptions + accumulated-fee settlements).
- **Symfony Messenger for async** → emails, PDF rendering, webhook deliveries, invitation emails. Doctrine transport in MVP; can flip to Redis when volume warrants.
- **No custom smart contracts** → we use existing ERC-20 tokens (USDC/USDT/DAI) and read on-chain events. Zero Solidity surface area = zero deploy risk.

---

## Tech stack

### Backend

| Layer | Choice |
|---|---|
| Language | **PHP 8.3+** |
| Framework | **Symfony 7.1 (LTS)** + Doctrine ORM 3.x + Twig 3.x |
| Async jobs | **Symfony Messenger** (Doctrine transport in dev, Redis later) |
| Email | **Symfony Mailer** with Resend or Postmark |
| Auth | Symfony Security + email/password (SIWE in phase 2) |
| Big numbers | `bcmath` and `gmp` extensions (no float math for money) |

### Frontend

| Layer | Choice |
|---|---|
| Marketing & checkout | **Twig** server-rendered, **Tailwind CSS 4.x** |
| Dashboard | **React 18** + **TypeScript strict** + **Vite 5** |
| State / data | TanStack Query, Zustand-style local store as needed |
| Wallet | **viem** + **wagmi** + **RainbowKit** (MetaMask, Coinbase Wallet, WalletConnect) |
| i18n | `react-i18next` (dashboard), Symfony Translator (server) |

### Infra

| Layer | Choice |
|---|---|
| Production host | Hetzner dedicated, Ubuntu 24.04 |
| Web server | nginx (aaPanel-managed) |
| Process supervisor | systemd (listener + messenger workers) |
| TLS / DDoS | Cloudflare in front, Let's Encrypt origin |
| RPC providers | Alchemy / QuickNode (free tier OK for MVP) |
| Errors / observability | Sentry + Symfony Monolog |
| Email | Resend (transactional) |

---

## Supported chains and tokens

We focus on cheap, fast EVM L2s. **No Ethereum mainnet in MVP** — gas is too expensive for invoice-size payments.

| Chain | Chain ID | Confirmations | Native USDC |
|---|---|---|---|
| Base | 8453 | 5 | ✅ |
| Polygon PoS | 137 | 30 | ✅ |
| Arbitrum One | 42161 | 5 | ✅ |
| Optimism | 10 | 5 | ✅ |

**Tokens accepted:** USDC (6 decimals), USDT (6), DAI (18). All addresses are **explicitly allowlisted** in [`config/tokens.yaml`](config/tokens.yaml). Any incoming `Transfer` event from a contract NOT on the allowlist is ignored — even if the symbol matches.

Testnets used during development: **Base Sepolia** (84532), **Optimism Sepolia** (11155420), **Arbitrum Sepolia** (421614). Configured in [`config/chains.yaml`](config/chains.yaml).

---

## Never used crypto before? Start here.

If words like "wallet", "gas", "testnet" don't mean anything yet, **start with [`docs/CRYPTO_BASICS.md`](docs/CRYPTO_BASICS.md)**. It's a 15-minute walkthrough that gets you from zero to "I just paid an invoice with stablecoins on Base" without spending a cent of real money. After that, the rest of this README will make sense.

Every entry in [`.env.example`](.env.example) is also extensively documented inline — what each variable does, **where to register** for the third-party service, what plan / free tier covers the MVP. No guesswork required.

---

## Local development with Docker

### Prerequisites

- Docker Desktop or OrbStack
- `make` (every system already has it)

### One-time setup

```bash
git clone https://github.com/kindrakevich-agency/settlepay.pro.git
cd settlepay.pro

cp .env.example .env.local
# Edit .env.local and set APP_SECRET (any random 32-char hex) + RPC URLs if you have Alchemy keys

make up           # Start db, redis, php-fpm, nginx, node containers
make install      # composer install + pnpm install + db create + migrate
```

You'll get:

| URL | What |
|---|---|
| http://localhost:8080 | Symfony app (marketing, payment page, dashboard shell) |
| http://localhost:5173 | Vite dev server (HMR for Tailwind + React) — start with `make dev-front` |

### Day-to-day commands

```bash
make help          # full command list
make up            # start the stack
make down          # stop everything
make logs s=php    # tail logs for one service
make shell         # bash inside the PHP container
make migrate       # apply pending migrations
make migration     # generate a migration from entity diff
make cc            # symfony cache:clear
make test          # phpunit
make dev-front     # vite dev (HMR)
make build-front   # vite build → public/build/
make worker        # foreground messenger worker
make listener      # foreground chain listener (testnet)
```

### Local docker stack

`compose.yaml` defines:

- **php** — `php:8.3-fpm-alpine` with `pdo_mysql`, `bcmath`, `gmp`, `intl`, `mbstring`, `redis`, `opcache`. Composer 2 baked in.
- **nginx** — `nginx:1.27-alpine`, serves `public/` on `:8080`.
- **db** — `mariadb:10.11`, healthcheck-gated startup.
- **redis** — `redis:7-alpine`, persistent (`appendonly`).
- **node** — `node:22-alpine` for Vite/Tailwind builds, exposes `:5173`.
- **worker** — `messenger:consume async` (started on demand).
- **listener** — `app:chain:listen --testnet` (started on demand).

---

## Project layout

```
settlepay.pro/
├── assets/                      # Frontend source (Tailwind + React + checkout TS)
│   ├── styles/app.css           # Tailwind 4 entry + design tokens
│   ├── checkout/checkout.ts     # Public payment page (viem + wagmi)
│   └── dashboard/main.tsx       # React SPA entry
├── bin/console                  # Symfony CLI
├── compose.yaml                 # Local docker stack
├── config/
│   ├── packages/                # framework, doctrine, security, twig, mailer, ...
│   ├── chains.yaml              # RPC URLs, confirmations, block times per chain
│   ├── tokens.yaml              # Allowlisted ERC-20 contracts (public, safe to commit)
│   ├── routes.yaml
│   └── services.yaml
├── docker/
│   ├── nginx/default.conf       # nginx config for local
│   └── php/Dockerfile           # PHP 8.3-fpm + extensions
├── docs/
│   ├── DESIGN_SYSTEM.md         # Tokens, components, accessibility floor
│   ├── design-preview.html      # Self-contained visual showcase
│   └── screenshot.png/.webp     # README hero
├── migrations/                  # Doctrine migrations
├── public/index.php             # Front controller
├── src/
│   ├── Controller/              # Marketing, Auth, Public, Api, Dashboard
│   ├── Entity/                  # Doctrine entities (User, Invoice, Payment, ...)
│   ├── Service/                 # Domain logic
│   │   ├── Blockchain/          # RpcClient, EventDecoder, BlockListener
│   │   ├── Invoice/             # Factory, NumberGenerator, PdfRenderer
│   │   ├── Payment/             # Matcher, Validator, StatusUpdater
│   │   └── Pricing/             # CoinGecko client
│   ├── Message/                 # Symfony Messenger commands
│   ├── MessageHandler/
│   ├── Command/                 # bin/console app:chain:listen, etc.
│   └── Kernel.php
├── templates/
│   ├── base.html.twig
│   ├── marketing/               # Multilingual marketing pages
│   ├── payment/checkout.html.twig  # The public payment page (sacred)
│   ├── pdf/                     # PDF templates
│   └── emails/
├── translations/
│   ├── messages.en.yaml
│   ├── messages.uk.yaml
│   └── messages.es.yaml
├── tailwind.config.js
├── tsconfig.json
├── vite.config.ts
├── Makefile
├── CLAUDE.md                    # Source-of-truth spec for AI agents and contributors
└── README.md
```

---

## Configuration & environment

Two files:

| File | Purpose | Committed? |
|---|---|---|
| `.env` | Non-sensitive defaults (public RPC URLs, `APP_ENV=dev`, container hostnames) | ✅ yes |
| `.env.local` | Real secrets (`APP_SECRET`, DB password, Alchemy/Resend keys, `PLATFORM_WALLET_ADDRESS`) | ❌ **never** |

This is the standard Symfony [`.env` workflow](https://symfony.com/doc/current/configuration.html#configuration-environments). The `.gitignore` excludes `.env.local*`, `*.pem`, `*.key`, and SSH keys outright. Production secrets travel via **GitHub Actions Secrets** for CI/CD and live in `.env.local` on the server.

### Generate a fresh `APP_SECRET`

```bash
php -r 'echo bin2hex(random_bytes(16));'
```

---

## Database schema

Initial schema lives in `migrations/Version20260509120000.php`. Tables (MariaDB, `utf8mb4_unicode_ci`, InnoDB):

| Table | Purpose |
|---|---|
| `users` | Account, payout wallet/chain/token, plan |
| `invoices` | Invoice header, status enum, accepted chains/tokens (JSON), recipient address snapshot |
| `invoice_line_items` | Itemized rows |
| `payments` | On-chain receipts, unique by `(chain_id, tx_hash, log_index)` |
| `chain_cursors` | Per-chain "last processed block" — listener resume point |
| `webhooks` | User-configurable outgoing event hooks (HMAC-signed) |
| `audit_log` | Every state-changing action |

See [`CLAUDE.md` §6](CLAUDE.md) for column-by-column rationale and indexing.

---

## Money handling rules (read this)

These are non-negotiable. Money bugs caused by floats are the #1 incident class in payment systems.

1. **Always store amounts as integer cents.** Never `FLOAT` or `DECIMAL` for fiat money in PHP/JS interop.
2. **For on-chain amounts**, store the raw uint256 as a **string** in `payments.amount_raw`. Compute display values from `amount_raw` + `token_decimals` at the application layer.
3. **All money math uses integer arithmetic.** Use `bcmath` or `gmp` for big numbers. Never `*` or `/` floats for money.
4. **USD conversion** uses snapshot price at confirmation time, stored in `amount_usd_cents`. We never recalculate retroactively.
5. **Token allowlist is enforced** on every match. Symbol-only match is forbidden — contract address must be in `config/tokens.yaml`.
6. **Wallet addresses are validated** with EIP-55 checksum, then stored lowercase. Tx hashes are stored lowercase.

---

## Internationalization

Three locales from day one: **English (en)**, **Ukrainian (uk)**, **Spanish (es)**.

- URL strategy: `/{locale}/...` (`/en/pricing`, `/uk/pricing`, `/es/pricing`).
- Translation files: [`translations/messages.{en,uk,es}.yaml`](translations/) — kept in sync, identical key trees.
- Money/date formatting: **always `Intl.NumberFormat` / `format_currency` Twig filter**. Never concatenate currency symbols (`1 234,56 €` vs `$1,234.56` is locale-dependent).
- React dashboard: `react-i18next` with JSON files in `assets/dashboard/i18n/{en,uk,es}.json` (mirror server keys).
- Pluralization uses ICU MessageFormat where needed; Slavic plural rules require it.

---

## Design system

A complete visual & token reference is in [`docs/DESIGN_SYSTEM.md`](docs/DESIGN_SYSTEM.md), and a self-contained interactive showcase is at [`docs/design-preview.html`](docs/design-preview.html) — open it directly in a browser to see every component, both light and dark mode, with a working locale switcher.

### Quick reference

- **Brand:** deep teal `#0d9488` (`brand-600`). Distinct from generic SaaS blue, signals fintech trust without crypto-bro neon.
- **Reference points:** Stripe (clarity), Linear (refinement), Vercel (typography), Coinbase (crypto credibility without clichés).
- **Type:** Inter (UI/body, weights 400/500/600/700), Inter Display (large headings), JetBrains Mono (hashes, addresses, invoice numbers).
- **Geometry:** rounded-xl/2xl/3xl. 4/8 spacing rhythm.
- **Status colors:** `success`, `warning`, `danger`, `info` — only on badges, alerts, explicit indicators. Never decoration.
- **Dark mode:** designed in parallel from day one. Both modes verified for WCAG AA contrast (4.5:1 body / 3:1 large).
- **Motion:** 150ms `ease-out` for hover/focus, 240ms `cubic-bezier(0.16, 1, 0.3, 1)` for layout. `prefers-reduced-motion` always respected.
- **Accessibility floor:** 44pt touch targets, focus rings everywhere, label-for everywhere, semantic HTML, color never the only carrier of meaning.

---

## Security model

| Concern | Mitigation |
|---|---|
| HTTPS | TLS only, HSTS preload (production) |
| CSP | Strict policy; inline scripts only where the wallet checkout requires them |
| CSRF | Symfony CSRF tokens on all forms |
| SQL injection | Doctrine ORM only — raw queries reviewed |
| Password hashing | Argon2id, min 12 chars |
| Rate limiting | Symfony RateLimiter + Redis: 5 login attempts / 15 min / IP, 60 public-page hits / min / IP |
| Token contract allowlist | Enforced on every payment match (config/tokens.yaml) |
| Wallet address validation | EIP-55 checksum, then stored lowercase |
| Secrets | `.env.local` only (chmod 600 in prod), never committed |
| PII in logs | Email + wallet addresses masked in production logs |
| Webhook signatures | HMAC-SHA256 |
| PDF generation | All user input sanitized — no XSS via SVG |
| CORS | Strictly configured to dashboard origin only |
| Audit log | Every state-changing operation logged with user/IP/UA |

---

## Sending email (Resend setup)

Settlepay sends transactional email at four moments: **email verification**, **password reset**, **invoice delivered to client**, and **payment receipt**. We use [Resend](https://resend.com) for delivery — chosen for the free tier (3k/month, no card), modern API, and inbox-placement quality competitive with Postmark. The integration is one line of DSN.

### One-time setup

**1.** Create a free account at https://resend.com/signup. No credit card required.

**2.** In the Resend dashboard, click **Domains** → **Add Domain** → enter `settlepay.pro` (or your domain). Resend shows you 3-4 DNS records to publish:

| Type | Host | Value |
|---|---|---|
| `MX` | `send.settlepay.pro` | `feedback-smtp.eu-west-1.amazonses.com` (priority 10) |
| `TXT` | `send.settlepay.pro` | `v=spf1 include:amazonses.com ~all` |
| `TXT` | `resend._domainkey.settlepay.pro` | `p=MIGfMA0G…` (long DKIM public key, ~250 chars) |
| `TXT` | `_dmarc.settlepay.pro` *(optional but recommended)* | `v=DMARC1; p=none;` |

**3.** Add those records in your DNS provider (we use Cloudflare). They normally propagate in 30 seconds. Resend's domain page shows a green "Verified" badge once DKIM signatures match.

**4.** Generate an API key: Resend dashboard → **API Keys** → **Create API Key** → name it `Settlepay production`, scope `Sending access`, restricted to the verified domain. Copy the key (starts with `re_…`). **It's a secret — only paste into `.env.local` on the server, never the repo.**

**5.** SSH to the server and write the key into `.env.local`:

```bash
ssh root@settle-server
cd /www/wwwroot/settlepay.pro
chattr -i .env.local 2>/dev/null    # in case immutable from aaPanel
sed -i '/^MAILER_DSN=/d' .env.local
cat >> .env.local <<'ENV'

# Mailer (production: Resend)
MAILER_DSN=resend+api://re_xxxxxxxxxxxxxxxxxxx@default
ENV
chmod 600 .env.local
chown www:www .env.local

# Reload PHP-FPM so the new env is picked up
/etc/init.d/php-fpm-83 restart
```

The From address is **not** in `.env.local` — it lives in the committed `.env` as `MAILER_FROM_ADDRESS` + `MAILER_FROM_NAME` since it's not a secret. Override per-environment in `.env.local` if you want a different sender on staging vs prod.

**6.** Verify it works with a one-shot test:

```bash
APP_ENV=prod php bin/console app:email:test you@example.com
# → [OK] Email sent to you@example.com
```

Check your inbox. The first time, Gmail might spam-filter; "Mark as not spam" once and the domain is permanently allowlisted.

### How it's wired

- The `symfony/resend-mailer` bridge reads the `resend+api://` DSN scheme. Composer require: `composer require symfony/resend-mailer`.
- `config/packages/mailer.yaml` reads `MAILER_DSN`. The envelope sender uses `MAILER_FROM_ADDRESS` (must match a verified Resend domain).
- The visible `From: Display Name <addr>` is set explicitly by `AuthMailer` and `SendTestEmailCommand` using `Symfony\Component\Mime\Address($MAILER_FROM_ADDRESS, $MAILER_FROM_NAME)` injected via DI from `.env`. Single source of truth — change in `.env`, every email follows.
- `src/Service/Auth/AuthMailer.php` sends the verify + reset templates with `TemplatedEmail` so we get auto-rendering of the matching Twig template under `templates/emails/auth/`.
- Email addresses and tokens are masked in monolog logs per CLAUDE.md PII rules.

### Sender address — what to put in `MAILER_FROM_ADDRESS`

**Don't use `noreply@`.** Three reasons:
1. Gmail / Outlook spam filters give `noreply@` lower trust scores. Resend and Postmark both publicly recommend against it for transactional. Inbox placement drops measurably.
2. It signals "we don't care if you reply" — bad for fintech where users WILL try to reply when something looks wrong with a payment.
3. Modern best practice (2024+) is reply-friendly addresses, paired with auto-routing or a real support inbox.

Recommended pattern for Settlepay:

| Purpose | Address | Notes |
|---|---|---|
| **Default** (verify, welcome, reset, receipts) | `hello@settlepay.pro` | Friendly, modern, on-brand. Matches Stripe / Linear / Vercel. |
| Password reset *(when split)* | `security@settlepay.pro` | RFC convention for security-sensitive emails. |
| Invoice notifications *(when split)* | `billing@settlepay.pro` | Functional, clearly transactional. |
| Display name | `Settlepay` | Inbox shows "Settlepay" not "hello". |

For MVP one address (`hello@`) is sufficient. Split when volume justifies separate inboxes. Whatever you pick, set it in `.env` as `MAILER_FROM_ADDRESS=hello@settlepay.pro` and `MAILER_FROM_NAME=Settlepay`. AuthMailer + SendTestEmailCommand pick it up automatically — no code change.

### What goes out the door

| Trigger | Template | Locale |
|---|---|---|
| User registers | `emails/auth/verify_email.html.twig` | `app.request.locale` (or user's `default_locale`) |
| User clicks "forgot password" | `emails/auth/reset_password.html.twig` | same |
| Invoice paid (listener flips status) | `emails/invoices/paid.html.twig` *(planned)* | invoice's stored locale |
| Invoice sent | `emails/invoices/sent.html.twig` ✅ live, PDF attached | client's preferred locale |

### Local development

In dev, `MAILER_DSN=null://null` (the default in `.env`). Outgoing emails go nowhere — but the Symfony Profiler's "Email" tab shows the rendered HTML for every queued message at http://localhost:8080/_profiler. So you can preview templates without spending Resend quota.

To preview against a real inbox during dev, drop a Resend "test" mode key into `.env.local` and your dev box will start sending real emails. Don't ship test-mode keys to prod.

### Quotas, costs

- **Free tier:** 100 emails/day, 3,000/month.
- **Pro ($20/mo):** 50,000/month, multiple domains, 7-day log retention.
- **Inbox placement:** Resend signs every outgoing email with DKIM and SPF, so deliverability against Gmail/Outlook should be 95%+ once the domain is warm.

### Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `The "resend+api" scheme is not supported` | Bridge not installed | `composer require symfony/resend-mailer` |
| `403 Forbidden` from Resend | API key wrong scope or revoked | Generate a fresh key, scope = "Sending access" |
| Email received but in spam | DKIM/SPF not propagated yet, or first-touch from a cold domain | Wait 1 hour, mark as not-spam once. Subsequent emails will inbox |
| `domain not verified` | DNS records missing or wrong | Run `dig +short TXT resend._domainkey.settlepay.pro` and compare against Resend dashboard |

---

## Google sign-in (optional)

Optional "Continue with Google" button alongside the email/password flow. Uses **Google Identity Services (GIS)** with the **ID-token flow** (`ux_mode=redirect`) — no client secret in the browser, no OAuth-redirect-back-to-callback dance. Google posts a signed JWT to `/auth/google`, the backend verifies the signature against Google's JWKS, then logs the user in.

Disabled by default. Flip `GOOGLE_AUTH_ENABLED=1` in `.env.local` to turn it on.

### What you get when enabled

- **"Continue with Google" button** above email/password on `/login`, `/register`, and the workspace-invitation accept page.
- **Google One Tap** auto-prompt on the marketing home for logged-out visitors who are already signed into Google in another browser tab.
- **Auto-provisioning** — first sign-in creates the user (with `email_verified_at=now`, since Google verified the email), a personal `Workspace`, and an owner `WorkspaceMember` row. Same code path as the email-registration `provisionWorkspace()` helper.
- **Auto-accept pending invites** — if a teammate was invited by email and signs up via Google with the same address, they're added to the inviting workspace immediately.
- **Auto-link by email** — existing email/password account picks up `google_sub` on first Google sign-in. One human = one account.
- **Kill switch** — set `GOOGLE_AUTH_ENABLED=0`, `cache:clear`, and the button hides everywhere + `/auth/google` returns 404. Useful if Google rotates keys or suspends your project.

### One-time setup (Google Cloud Console)

1. **Create a project** at <https://console.cloud.google.com/>: top-left dropdown → New Project → name it whatever you want.
2. **Configure the OAuth consent screen**:
   - User type: **External**
   - App name, support email, app home URL, authorized domains (`settlepay.pro`), developer contact.
   - Scopes: tick `openid`, `email`, `profile`.
   - **Publish app** (Audience tab) so it leaves "Testing" status and any Google user can sign in.
3. **Create the OAuth 2.0 Client ID**: Credentials → Create Credentials → OAuth client ID.
   - Application type: **Web application**
   - **Authorized JavaScript origins**:
     - `https://settlepay.pro`
     - `https://www.settlepay.pro`
     - `http://localhost:8080` *(dev only)*
   - **Authorized redirect URIs** (required because we use `ux_mode=redirect`):
     - `https://settlepay.pro/auth/google`
     - `https://www.settlepay.pro/auth/google`
     - `http://localhost:8080/auth/google` *(dev only)*
4. **Copy the Client ID** — looks like `123456789012-xxxxxxxxxx.apps.googleusercontent.com`. The Client Secret is NOT used (ID-token flow doesn't need it).

### `.env.local` config

```env
GOOGLE_AUTH_ENABLED=1
GOOGLE_CLIENT_ID=123456789012-xxxxxxxxxx.apps.googleusercontent.com
```

The Client ID is **public-by-design** — it ships in the production JS bundle (Google needs it client-side to initialize the GIS library). The real security boundary is the **Authorized JavaScript origins** allowlist you configured in step 3 — only browsers loading the page from those origins can use the Client ID. Same model as a Stripe publishable key. Do NOT commit `GOOGLE_CLIENT_ID` to the repo; keep it in `.env.local` and GitHub Actions Secrets only.

### How it's wired

- `src/Controller/Auth/GoogleAuthController.php` — `POST /auth/google`. Validates the double-submit CSRF (Google sets a `g_csrf_token` cookie + posts the same value in the body). On success, verifies the JWT, finds-or-creates the user, provisions workspace + auto-accepts invites, then `Security::login($user, firewallName: 'main')`.
- `src/Service/Auth/GoogleTokenVerifier.php` — native JWT-against-JWKS verifier (~60 lines, no external library). Caches Google's JWKS for 1 hour, busts the cache + retries once on `kid` rotation. Validates `iss`, `aud`, `exp`, `iat`, and `email_verified` claims.
- `templates/auth/_google_button.html.twig` — official GIS button widget + "or" divider, conditionally rendered via `{% if google_auth_enabled() %}` (Twig function from `App\Twig\GoogleAuthExtension`).
- `users.google_sub VARCHAR(64) UNIQUE` — stable Google user id, stamped on first sign-in. We use this (not the email) for re-authentication to protect against email-change attacks.

### Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `Error 400: redirect_uri_mismatch` | The `/auth/google` URL is missing from **Authorized redirect URIs** in your OAuth client | Add `https://<your-domain>/auth/google` (and `https://www.<your-domain>/auth/google`) in Google Cloud Console → Credentials → your client. Wait ~30s for propagation. |
| Button doesn't appear on `/login` | `GOOGLE_AUTH_ENABLED=0` or `GOOGLE_CLIENT_ID` is empty | Set both in `.env.local`, then `bin/console cache:clear` + reload PHP-FPM. |
| `auth.csrf_mismatch` JSON error | Some browser extension or proxy stripped the `g_csrf_token` cookie | Try again in an incognito window without extensions; cookie is `SameSite=Lax`, set first-party by Google for our domain. |
| `auth.google_invalid` with "JWT signature invalid" | Google rotated keys mid-request — JWKS cache miss + new key | The verifier auto-busts the cache + retries once. If it still fails, the user's session token may have been tampered with. |
| `auth.google_invalid` with "JWT aud does not match" | Wrong `GOOGLE_CLIENT_ID` in `.env.local` (e.g., dev client ID on production) | Confirm the client ID matches the one you intend to use; one per environment is fine. |
| User can sign in but lands on a 500 | Workspace provisioning failed | Should not happen — same path as the regular registration flow (fixed in `c2a7eed`). Check Sentry. |

---

## Running the chain listener (daemon)

The on-chain listener is **the heart of Settlepay** — it polls every supported chain every few seconds, decodes ERC-20 Transfer events, and matches them to open invoices. Without it running, no payment ever flips an invoice from `sent` → `paid`.

In dev you can run it ad-hoc:

```bash
make listener                                          # foreground, --testnet
docker compose exec php bin/console app:chain:listen --chain=base_sepolia --once
```

In production it must run **24/7** as a long-running process. We use systemd because it's already on the box, gives you auto-restart on crash, and writes to a stable log file.

### The systemd unit

[`deploy/systemd/settle-listener.service`](deploy/systemd/settle-listener.service) ships with the repo. Excerpt:

```ini
[Unit]
Description=Settlepay blockchain listener (eth_getLogs polling per chain)
After=network.target mariadb.service redis.service
Wants=network-online.target

[Service]
Type=simple
User=www
Group=www
WorkingDirectory=/www/wwwroot/settlepay.pro
Environment=APP_ENV=prod
ExecStart=/www/server/php/83/bin/php bin/console app:chain:listen --testnet
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=30
StandardOutput=append:/var/log/settle/listener.log
StandardError=append:/var/log/settle/listener-error.log
MemoryLimit=256M

[Install]
WantedBy=multi-user.target
```

Notable choices: `Restart=always` so a crashed daemon comes right back; `MemoryLimit=256M` so a runaway process can't take the box down; `SIGTERM` shutdown signal because the listener handles it gracefully (finishes the current iteration, exits cleanly).

### Install in 4 commands

```bash
# 1. Copy the unit file
sudo cp deploy/systemd/settle-listener.service /etc/systemd/system/

# 2. Create the log directory the unit writes to
sudo mkdir -p /var/log/settle
sudo chown www:www /var/log/settle
sudo chmod 755 /var/log/settle

# 3. Reload systemd so it sees the new unit, then enable + start it
sudo systemctl daemon-reload
sudo systemctl enable --now settle-listener.service

# 4. Verify it's running
sudo systemctl status settle-listener.service
```

You should see `Active: active (running)` plus a PID. The daemon is now polling Base / Polygon / Arbitrum / Optimism (or their Sepolia testnets if `--testnet` is in the `ExecStart`) every few seconds.

### Verify the loop works end-to-end

```bash
# Tail the live log
sudo tail -f /var/log/settle/listener.log

# In a separate shell: seed an invoice that pays your own wallet
APP_ENV=prod php bin/console app:invoice:create-sample \
  --testnet --amount-cents=30 \
  --recipient=YOUR_WALLET_ADDRESS

# Open the URL it prints, click Connect Wallet, click Pay.
# Within ~30 seconds you should see in the log:
#   [base_sepolia] matched 1 new payment(s)
# And in the DB:
#   SELECT status FROM invoices WHERE uuid = '...';   --> paid
```

If you've never used a crypto wallet before, follow [`docs/CRYPTO_BASICS.md`](docs/CRYPTO_BASICS.md) to install MetaMask and get free testnet funds first.

### Operate

```bash
# After a deploy that changed listener code:
sudo systemctl restart settle-listener.service

# Pause for maintenance (e.g. DB upgrade):
sudo systemctl stop settle-listener.service

# Inspect history of starts/stops:
sudo journalctl -u settle-listener.service --since="1 hour ago"

# Tail the structured stdout log:
sudo tail -f /var/log/settle/listener.log

# Tail errors only:
sudo tail -f /var/log/settle/listener-error.log
```

### Mainnet vs testnet

The shipped unit (`deploy/systemd/settle-listener.service`) runs against **mainnet** — Base, Polygon, Arbitrum, Optimism. A second unit (`deploy/systemd/settle-listener-testnet.service`) handles Sepolia testnets and runs alongside without conflict, since each chain owns its own `chain_cursors` row.

Enable both:
```bash
systemctl enable --now settle-listener            # mainnet
systemctl enable --now settle-listener-testnet    # sepolia testnets (dev / dogfood)
```

### Why systemd and not Supervisor / PM2 / cron?

- **Already on the box.** Ubuntu ships with systemd; nothing to install.
- **Auto-restart.** `Restart=always` survives crashes, OOM kills, RPC outages.
- **Resource caps.** `MemoryLimit=256M` is enforced by the kernel cgroup, not the app.
- **Native shutdown.** `SIGTERM` + `TimeoutStopSec=30` lets the listener finish its current iteration before dying — important so we don't double-process the same block range on the next start.
- **Logs to flat files.** No log rotation pipeline needed; just point logrotate at `/var/log/settle/*.log`.

See [`deploy/README.md`](deploy/README.md) for a one-page cheatsheet.

---

## Deployment

Deploys are handled by **GitHub Actions** (`.github/workflows/ci.yml`) using `appleboy/ssh-action` to SSH into the Hetzner server. CI and deploy live in a single workflow with three jobs (`php`, `frontend`, `deploy`); the deploy job has `needs: [php, frontend]` so a broken commit can never reach prod.

**On every push to `main`:**

1. SSH into the production server
2. Backup any production-only data (uploads, .env.local)
3. `git fetch && git reset --hard origin/main`
4. `composer install --no-dev --optimize-autoloader`
5. `php bin/console doctrine:migrations:migrate --no-interaction`
6. `php bin/console cache:clear` + `cache:warmup`
7. `pnpm install --frozen-lockfile && pnpm build`
8. `php bin/console asset-map:compile`
9. Restart messenger workers + listener via systemd

**Required GitHub Secrets** (set under repo → Settings → Secrets):

- `SERVER_IP` — Hetzner public IP
- `SSH_PRIVATE_KEY` — deploy key with access to the server's `settle` user

**Server-side setup** (one-off): a deploy SSH key at `/root/.ssh/github_settle_deploy`, an aaPanel site for `settlepay.pro`, a MariaDB database, and systemd units for `settle-listener` and `settle-worker@1` (templates in `CLAUDE.md` §15).

---

## Billing

Settlepay's billing model is **crypto-native**: subscriptions AND per-invoice platform fees are paid in **USDC** to a platform-controlled wallet — the same wallet listener that watches freelancer payout wallets for invoice payments also watches the platform wallet for Settlepay's own revenue. **No Stripe. No cards. No fiat funnel.**

### Why not Stripe

The earlier plan (CLAUDE.md §13 v1) used Stripe to charge $19/mo + accumulated %-fees in fiat. We pivoted because:

- Charging in fiat for a crypto product looks hypocritical
- Stripe + cards adds regional friction (Ukrainian / Argentine / Nigerian freelancers can't always get cards Stripe accepts — same blockers we hit with MetaMask Buy)
- Stripe fees (~2.9% + $0.30) eat into a $19/mo line item
- The on-chain listener we already built is *exactly* what we need

### Plans

| Plan | Cost | Per-invoice fee | How paid |
|---|---|---|---|
| **Free** | $0 | 1% | Accumulates as `users.fees_owed_cents` → freelancer pays in USDC when they choose |
| **Pro** | $19 USDC / month | 0.5% | Freelancer sends $19 USDC to the platform wallet to extend Pro by 30 days |
| **Pro · Lifetime** | $299 USDC one-time | 0.5% | Single USDC transfer → Pro forever (no renewal) |

### How payments are matched

Every billing payment runs through a `BillingIntent`:

1. Freelancer clicks **Upgrade to Pro** / **Pay fees** in `/app/billing`
2. `BillingIntentFactory` creates a row with kind, amount, accepted chains, recipient = platform wallet
3. Browser redirects to `/{locale}/billing/pay/{uuid}` — manual-transfer page (v1) with address + amount + accepted networks
4. Freelancer sends USDC from any wallet
5. The **same listener daemon** that watches invoice recipients also watches the platform wallet. On a Transfer match, `BillingPaymentMatcher` flips the intent to `paid` and `SubscriptionManager` updates the user (`plan`, `plan_renews_at`, `fees_owed_cents`)

### Per-invoice fees

When `PaymentMatcher` confirms an invoice payment, `SubscriptionManager::accrueInvoiceFee()` adds the freelancer's share to `users.fees_owed_cents`:

- Free plan: `floor(invoice_cents * 100 / 10000)` → 1%
- Pro plan: `floor(invoice_cents * 50 / 10000)` → 0.5%

The freelancer settles whenever they want via a `fee_settlement` BillingIntent.

### Required server-side config

In `/www/wwwroot/settlepay.pro/.env.local`:

```
PLATFORM_WALLET_ADDRESS=0x... # the wallet Settlepay receives subscriptions + fees at
```

If unset, the dashboard `/app/billing` page shows a "platform wallet not configured" warning and intent creation throws — preventing orphan payments to a placeholder.

**Same wallet as your invoice payout is fine.** The listener disambiguates by the on-chain `from` address: billing intents are locked to your own payout wallet as `expected_payer_address`, so self-payments (your Pro renewals, your fee settlements) match the billing flow, and client invoice payments (different `from`) fall through to the invoice flow.

### Architectural note

Settlepay still **writes nothing on-chain**. The platform-side fee collection is exactly the same pattern as invoice payments: read incoming Transfers, match by amount + chain, update state. This keeps the system under the "software facilitator, not money transmitter" framing in CLAUDE.md.

---

## Status & roadmap

### Shipped

- Foundation: Symfony 7 skeleton, Doctrine entities, migrations, email/password auth (Argon2id, email verification, password reset)
- Marketing site in en / uk / es with full SEO (hreflang, sitemap, JSON-LD, OG) + embedded YouTube demo video
- Dashboard (server-rendered Twig): invoice CRUD with edit/void, paginated list with filters, payments table, settings (profile / payout wallet / security), **billing page**
- Public payment page with viem + wagmi + RainbowKit, WalletConnect (Reown)
- Chain listener daemon: `eth_getLogs` polling, payment matching with ±0.5% tolerance, idempotent on `(chain_id, tx_hash, log_index)`
- Mainnet live on Base / Polygon / Arbitrum / Optimism via Alchemy. Sepolia testnets run on a separate systemd unit
- Resend email with DKIM + SPF + DMARC, plaintext fallback, List-Unsubscribe headers, PDF receipt attached to outgoing invoices, paid-invoice notification emails to both client + freelancer
- PDF invoice/receipt rendering with dompdf (Cyrillic + Latin coverage), branded template
- Sentry error reporting wired (PHP + browser-JS) with custom `before_send` filters
- **Crypto-native billing** (see [Billing](#billing) below) — Pro subscriptions + per-invoice fees paid in USDC, no Stripe
- GitHub Actions CI + Deploy in a single workflow, deploy gated on lint + typecheck + build

### Next

- Email reminders for upcoming Pro renewals + auto-downgrade after 7-day grace
- Wallet-connect on the billing payment page (currently manual transfer)
- Webhooks (entity exists, dispatcher pending)
- SIWE (Sign-In With Ethereum) login
- Browser-side Sentry project for checkout-time errors
- SIWE login (phase 2)
- Multi-user team seats (Agency plan)

---

## License

MIT — see [LICENSE](LICENSE).

---

## Contributing & contact

Solo founder build by **Vitalii** ([@kindrakevich-agency](https://github.com/kindrakevich-agency)). PRs and issues welcome. For freelancers who want early access, sign up at [settlepay.pro](https://settlepay.pro) — free up to $1,000 invoiced per month.
