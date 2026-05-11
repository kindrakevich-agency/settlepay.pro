# Settle — Crypto Invoicing for Freelancers

> **Project codename:** `settle` (working name — can be renamed before launch)
> **Tagline:** Get paid in stablecoins. Without banks, without waiting, without borders.
> **Status:** Greenfield — built from scratch.
> **Owner:** Vitalii (solo founder + lead engineer).

---

## 1. What we are building

A **SaaS platform** that lets freelancers send professional invoices and get paid in stablecoins (USDC, USDT, DAI) on cheap L2 networks (Base, Polygon, Arbitrum, Optimism). Like Stripe — but for crypto, and specifically for freelancers who hate SWIFT delays, frozen wire transfers, and 5% bank fees.

### Core flow (must work flawlessly)

1. **Freelancer** signs up, connects a wallet (or pastes their address), creates an invoice with amount, client info, due date.
2. System generates a **unique payment link** like `settle.app/pay/inv_abc123`.
3. Freelancer sends the link to their client (email, Telegram, anywhere).
4. **Client** opens the link → sees a clean checkout page with the invoice → connects their wallet → pays in any supported stablecoin on any supported chain.
5. Our **listener** detects the on-chain payment, marks invoice as paid, sends confirmation emails to both parties, and generates a PDF receipt.
6. Freelancer sees the payment in dashboard within 30 seconds of confirmation.

### Non-goals (NOT in MVP)

- **No custody.** We never hold user funds. Payments go directly from client wallet to freelancer wallet. We are a software facilitator, not a money transmitter. This is critical for legal simplicity.
- **No fiat conversion.** Freelancer receives stablecoins; off-ramping to fiat is their responsibility (we may add partner integrations later).
- **No subscriptions / recurring billing** in MVP. Single-shot invoices only.
- **No multi-user teams** in MVP. Single-user accounts only.
- **No smart contracts of our own.** We use existing ERC-20 tokens (USDC, USDT, DAI) and read on-chain events. No custom Solidity in MVP.
- **No on-ramp built-in** in MVP. Client must already have crypto. Phase 2: integrate MoonPay/Transak.

---

## 2. Target users

- **Primary:** solo freelancers in countries with poor or expensive banking (Ukraine, Argentina, Nigeria, Brazil, Mexico, Colombia, Philippines, Vietnam, Egypt, Turkey) earning $500–$10K per invoice.
- **Secondary:** Web3-native freelancers (designers, developers, writers) in EU/US who already get paid in crypto but use awkward tools.
- **Tertiary:** small agencies billing international clients.

**Important:** end users are **not crypto experts**. UX must hide blockchain complexity. Words like "gas", "nonce", "approval" should rarely appear.

---

## 3. Tech stack (locked in)

### Backend
- **PHP 8.3+** with **Symfony 7.x** (LTS)
- **Doctrine ORM 3.x**
- **Symfony Messenger** for async jobs (with Doctrine transport, Redis later)
- **Symfony Mailer** with Resend or Postmark transport
- **API Platform 4.x** for REST API (or pure controllers — decide per case)

### Database
- **MariaDB 10.11+** (already on Hetzner) — primary store
- **Redis 7+** — cache, rate limiting, job queue (install on the same server)

### Frontend
- **Twig** for marketing pages and the public payment checkout page (server-rendered, fast, SEO-friendly)
- **React 18 + Vite** for the authenticated dashboard (SPA mode), bundled and served from Symfony
- **TypeScript** strict mode for all React code
- **Tailwind CSS 4.x** for all styling
- **viem** + **wagmi** + **RainbowKit** for wallet connection on the payment page

### Infrastructure
- **Hetzner Cloud server** (existing) — Ubuntu 24.04 LTS
- **nginx** as reverse proxy, PHP-FPM
- **systemd** for the listener daemon and Symfony Messenger workers
- **Cloudflare** in front for DDoS / caching static assets
- **Let's Encrypt** for TLS

### External services
- **Alchemy** (or QuickNode) — RPC provider for Ethereum L2 chains. Free tier for MVP.
- **CoinGecko API** — price feeds (free tier)
- **Resend** or **Postmark** — transactional email
- **Sentry** — error tracking (free tier)
- **PostHog** (self-hosted on the same server later, or cloud free tier) — product analytics

### Crypto-specific
- **web3.php** (sc0vu/web3.php) for low-level RPC + ABI decoding
- Direct JSON-RPC via **Symfony HttpClient** for simple cases (avoid web3.php where it adds complexity)
- We never write to chain from PHP. We only read.

---

## 4. Supported chains and tokens (MVP)

We focus on cheap, fast, EVM-compatible L2s. **No Ethereum mainnet in MVP** (gas too expensive for invoice-size payments).

| Chain | Chain ID | Why |
|---|---|---|
| Base | 8453 | Coinbase-backed, growing fast, deep USDC liquidity |
| Polygon PoS | 137 | Mature, cheap, wide adoption |
| Arbitrum One | 42161 | Largest L2 by TVL |
| Optimism | 10 | OP Stack ecosystem |

**Tokens accepted:**

| Symbol | Decimals | Chains |
|---|---|---|
| USDC | 6 | All four (native or bridged) |
| USDT | 6 | All four |
| DAI | 18 | All four |

Token contract addresses must be stored in a config file (`config/tokens.yaml`) — never hardcoded. We always verify the contract address against an allowlist before crediting an invoice.

**Testnets used during development:**
- Base Sepolia (84532)
- Optimism Sepolia (11155420)
- Arbitrum Sepolia (421614)

---

## 5. Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    Hetzner server                           │
│                                                             │
│  ┌──────────┐   ┌─────────────┐   ┌──────────────────────┐  │
│  │  nginx   │──▶│ Symfony app │──▶│  MariaDB             │  │
│  │  + TLS   │   │  (PHP-FPM)  │   │  invoices/users/...  │  │
│  └──────────┘   └──────┬──────┘   └──────────────────────┘  │
│                        │                                    │
│                        ▼                                    │
│                  ┌──────────┐    ┌──────────────────────┐   │
│                  │  Redis   │    │  systemd:            │   │
│                  │  (cache, │    │   ↳ messenger worker │   │
│                  │   queue) │    │   ↳ chain-listener   │   │
│                  └──────────┘    └──────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
                           │
                           │ HTTPS
                           ▼
              ┌────────────────────────┐
              │ Alchemy / QuickNode    │
              │ (RPC for Base, Polygon │
              │  Arbitrum, Optimism)   │
              └────────────────────────┘

  Browser flows:
    Marketing site (Twig)         → public, multilingual
    Dashboard (React SPA)         → authenticated, after login
    Payment page (Twig + viem JS) → public, single-purpose checkout
```

### Off-chain / on-chain split

- **Symfony writes nothing to chain.** It only reads.
- **All transactions are signed in the user's browser** by their connected wallet.
- **Symfony's job:** match incoming on-chain payments to invoices in our database.

This split is the single most important architectural decision. It keeps us out of money-transmitter regulation territory and dramatically simplifies the codebase.

---

## 6. Database schema (MariaDB)

All tables use:
- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `created_at`, `updated_at` timestamps
- `utf8mb4_unicode_ci` collation
- InnoDB engine

### Core tables

Phase 2 model: **Workspace is the ownership scope.** A workspace owns
invoices, payments, billing state, branding, payout settings, API tokens,
and webhooks. A User is the human (auth + display name + locale). They
join via `workspace_members` with a role of `owner` or `member`. Solo
freelancers get one workspace where they are the sole Owner; Agency-tier
accounts can invite teammates.

```sql
-- users (auth + identity only)
CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  email_verified_at DATETIME NULL,
  email_verification_token VARCHAR(64) NULL,
  email_verification_expires_at DATETIME NULL,
  password_reset_token VARCHAR(64) NULL,
  password_reset_expires_at DATETIME NULL,
  last_login_at DATETIME NULL,
  display_name VARCHAR(120) NULL,
  default_locale VARCHAR(5) DEFAULT 'en',  -- en, uk, es — user UI preference
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_email (email)
);
-- Note: the legacy `business_name`, `tax_id`, `payout_*`, `brand_*`,
-- `plan`, `fees_owed_cents` columns still exist on users for backward
-- compat during Phase 2 rollout. They are no longer read; reads go
-- through the workspace. They'll be dropped in a Phase 3 cleanup.

-- workspaces (the business unit — invoices, billing, branding all owned here)
CREATE TABLE workspaces (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  name VARCHAR(180) NOT NULL,             -- "Vitalii's Studio", backfilled from business_name
  plan VARCHAR(20) NOT NULL DEFAULT 'free',  -- free, pro, agency
  plan_renews_at DATETIME NULL,           -- NULL on pro = lifetime; set + plan='pro' = monthly
  plan_canceled_at DATETIME NULL,         -- soft cancel; keeps access until plan_renews_at
  fees_owed_cents BIGINT UNSIGNED NOT NULL DEFAULT 0, -- per-paid-invoice accrual
  business_name VARCHAR(180) NULL,        -- shown on invoices
  business_address TEXT NULL,
  tax_id VARCHAR(60) NULL,                -- VAT/NIE/EIN
  default_currency CHAR(3) NOT NULL DEFAULT 'USD',
  default_locale VARCHAR(5) NOT NULL DEFAULT 'en',
  payout_address VARCHAR(64) NOT NULL,    -- workspace's payout wallet
  payout_chain_id INT UNSIGNED NOT NULL,
  payout_token VARCHAR(20) NOT NULL DEFAULT 'USDC',
  brand_logo_path VARCHAR(255) NULL,      -- Pro custom branding
  brand_color VARCHAR(7) NULL,            -- #RRGGBB
  seat_limit INT UNSIGNED NOT NULL DEFAULT 1,  -- 1 for solo/Pro, 5 for Agency
  owner_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_workspaces_owner (owner_user_id),
  INDEX idx_workspaces_plan (plan)
);

-- workspace_members (pivot — N users per workspace)
CREATE TABLE workspace_members (
  workspace_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(16) NOT NULL DEFAULT 'member',  -- 'owner' | 'member'
  joined_at DATETIME NOT NULL,
  PRIMARY KEY (workspace_id, user_id),
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_wm_user (user_id),
  INDEX idx_wm_role (role)
);

-- workspace_invitations (email invite flow, signed token, 14-day expiry)
CREATE TABLE workspace_invitations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workspace_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(255) NOT NULL,
  role VARCHAR(16) NOT NULL DEFAULT 'member',
  token VARCHAR(64) NOT NULL UNIQUE,
  invited_by_user_id BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  accepted_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  FOREIGN KEY (invited_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_wi_workspace (workspace_id),
  INDEX idx_wi_email (email)
);

-- invoices (workspace-owned; user_id is "created_by" attribution)
CREATE TABLE invoices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  number VARCHAR(40) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,        -- who created it (audit)
  workspace_id BIGINT UNSIGNED NOT NULL,   -- who OWNS it (Phase 2)
  status ENUM('draft','sent','viewed','paid','partially_paid','overdue','void','refunded') NOT NULL DEFAULT 'draft',
  amount_cents BIGINT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL,
  client_name VARCHAR(180) NOT NULL,
  client_email VARCHAR(255) NULL,
  client_address TEXT NULL,
  description TEXT NULL,
  notes TEXT NULL,
  due_date DATE NULL,
  issued_at DATE NOT NULL,
  paid_at DATETIME NULL,
  viewed_at DATETIME NULL,
  accepted_chains JSON NOT NULL,           -- [8453, 137, 42161, 10]
  accepted_tokens JSON NOT NULL,           -- ["USDC", "USDT", "DAI"]
  recipient_address VARCHAR(64) NOT NULL,  -- snapshot of workspace.payout_address
  metadata JSON NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE RESTRICT,
  INDEX idx_inv_workspace (workspace_id),
  INDEX idx_status (status),
  INDEX idx_uuid (uuid)
);

-- invoice_line_items (unchanged)
CREATE TABLE invoice_line_items (...);

-- payments (on-chain receipts — invoice_id NULL = orphan)
CREATE TABLE payments (
  -- unchanged from MVP except payments link to invoices (which link to workspaces),
  -- so payment ownership flows through invoice.workspace_id
  ...
);

-- chain_cursors (listener bookmarks per chain)
CREATE TABLE chain_cursors (...);

-- api_tokens (Pro/Agency programmatic access, Argon2id hashed)
CREATE TABLE api_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,        -- who minted (audit)
  workspace_id BIGINT UNSIGNED NOT NULL,   -- scope of access
  name VARCHAR(80) NOT NULL,
  token_prefix VARCHAR(16) NOT NULL,       -- "sk_pro_abc12345" — indexed for fast lookup
  token_hash VARCHAR(255) NOT NULL,        -- Argon2id of full plaintext
  scopes JSON NOT NULL,                    -- ["read","write"]
  last_used_at DATETIME NULL,
  expires_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  INDEX idx_apit_workspace (workspace_id),
  INDEX idx_apit_prefix (token_prefix),
  INDEX idx_apit_revoked (revoked_at)
);

-- webhooks (Pro/Agency outgoing event callbacks)
CREATE TABLE webhooks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,        -- who registered (audit)
  workspace_id BIGINT UNSIGNED NOT NULL,   -- scope
  url VARCHAR(500) NOT NULL,
  signing_secret VARCHAR(128) NOT NULL,    -- HMAC-SHA256 key
  events JSON NOT NULL,                    -- ["invoice.sent","invoice.paid","invoice.voided","payment.received"]
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_success_at DATETIME NULL,
  last_failure_at DATETIME NULL,
  last_failure_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
);

-- webhook_deliveries (delivery log per attempt — for replay + debugging)
CREATE TABLE webhook_deliveries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  webhook_id BIGINT UNSIGNED NOT NULL,
  event VARCHAR(60) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_status_code INT NULL,
  last_response_body TEXT NULL,
  last_error VARCHAR(255) NULL,
  delivered_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
);

-- billing_intents (Pro/Agency subscription + fee-settlement payment requests)
CREATE TABLE billing_intents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,           -- public-facing /billing/pay/{uuid}
  user_id BIGINT UNSIGNED NOT NULL,        -- who clicked Upgrade (audit)
  workspace_id BIGINT UNSIGNED NOT NULL,   -- whose plan extends
  kind VARCHAR(40) NOT NULL,               -- 'pro_monthly' | 'pro_lifetime' | 'agency_monthly' | 'fee_settlement'
  status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending | paid | expired
  amount_cents BIGINT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  accepted_chains JSON NOT NULL,
  accepted_tokens JSON NOT NULL,           -- always ["USDC"] in current build
  recipient_address VARCHAR(64) NOT NULL,  -- PLATFORM_WALLET_ADDRESS snapshot
  expected_payer_address VARCHAR(64) NULL, -- locks intent to workspace.payout_address
  expires_at DATETIME NOT NULL,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  INDEX idx_bi_workspace (workspace_id),
  INDEX idx_bi_status (status)
);

-- fee_payments (on-chain receipts at the platform wallet, FK to billing_intents)
CREATE TABLE fee_payments (...);

-- audit_log (support / debugging trail — workspace_id-aware in Phase 3)
CREATE TABLE audit_log (...);
```

### Money handling rules (CRITICAL)

1. **Always store amounts as integer cents.** Never use `FLOAT` or `DECIMAL` for money in PHP/JS interop — floating point is the #1 cause of money bugs.
2. **For on-chain amounts**, store the raw uint256 as a string in `amount_raw`. Compute display values from `amount_raw` and `token_decimals` at the application layer.
3. **All money calculations use integer arithmetic.** Use `bcmath` or `gmp` extensions for big numbers. Never `*` or `/` floats for money.
4. **USD conversion** uses snapshot price at confirmation time, stored in `amount_usd_cents`. We never recalculate retroactively.

---

## 7. Symfony application structure

```
src/
├── Controller/
│   ├── Api/
│   │   ├── InvoiceController.php       # POST/GET/PATCH /api/invoices
│   │   ├── PaymentController.php       # GET /api/payments
│   │   ├── UserController.php          # GET/PATCH /api/me
│   │   └── WebhookController.php
│   ├── Marketing/
│   │   ├── HomeController.php          # /, /pricing, /docs
│   │   └── BlogController.php
│   ├── Auth/
│   │   ├── RegistrationController.php
│   │   ├── LoginController.php
│   │   └── PasswordResetController.php
│   ├── Public/
│   │   └── PaymentCheckoutController.php  # /pay/{uuid} — the public checkout
│   └── Dashboard/
│       └── DashboardController.php     # /app/* — serves the React SPA shell
├── Entity/
│   ├── User.php
│   ├── Invoice.php
│   ├── InvoiceLineItem.php
│   ├── Payment.php
│   ├── ChainCursor.php
│   ├── Webhook.php
│   └── AuditLog.php
├── Repository/
│   └── ...
├── Service/
│   ├── Invoice/
│   │   ├── InvoiceFactory.php          # builds Invoice entities from DTOs
│   │   ├── InvoiceNumberGenerator.php  # INV-2026-0001
│   │   ├── InvoiceUrlGenerator.php
│   │   └── InvoicePdfRenderer.php
│   ├── Payment/
│   │   ├── PaymentMatcher.php          # core matching logic
│   │   ├── PaymentValidator.php        # contract allowlist, amount tolerance
│   │   └── PaymentStatusUpdater.php
│   ├── Blockchain/
│   │   ├── RpcClient.php               # JSON-RPC over HttpClient
│   │   ├── EventDecoder.php            # decodes ERC-20 Transfer logs
│   │   ├── ChainRegistry.php           # chain configs (RPC URLs, USDC addresses, ...)
│   │   └── BlockListener.php           # iterates blocks, finds Transfers
│   ├── Pricing/
│   │   └── CoinGeckoClient.php
│   ├── Notification/
│   │   ├── EmailService.php
│   │   └── WebhookDispatcher.php
│   └── Auth/
│       └── SiweVerifier.php            # Sign-In With Ethereum (phase 2)
├── Message/                             # Symfony Messenger commands
│   ├── SendInvoiceEmailMessage.php
│   ├── SendWebhookMessage.php
│   ├── ProcessChainBlocksMessage.php
│   └── GeneratePdfMessage.php
├── MessageHandler/
│   └── ...                              # one handler per Message
├── EventListener/
│   ├── InvoicePaidListener.php          # triggers email + webhook
│   └── ...
├── Event/
│   ├── InvoicePaidEvent.php
│   ├── InvoiceViewedEvent.php
│   └── PaymentReceivedEvent.php
├── Dto/
│   ├── CreateInvoiceDto.php
│   └── ...
├── Command/                             # CLI commands
│   ├── ChainListenCommand.php           # bin/console app:chain:listen
│   ├── BackfillBlocksCommand.php
│   └── ReconcilePaymentsCommand.php
└── Security/
    ├── Voter/
    │   └── InvoiceVoter.php             # row-level access control
    └── ...

config/
├── tokens.yaml                          # allowlisted token contracts per chain
├── chains.yaml                          # RPC endpoints, confirmations needed
├── packages/
└── translations/
    ├── messages.en.yaml
    ├── messages.uk.yaml
    └── messages.es.yaml

templates/
├── base.html.twig
├── marketing/
│   ├── home.html.twig
│   ├── pricing.html.twig
│   └── docs/
├── auth/
├── payment/
│   └── checkout.html.twig               # the public payment page
├── pdf/
│   └── invoice.html.twig                # PDF template
└── emails/
    ├── invoice_sent.html.twig
    ├── invoice_paid.html.twig
    └── ...

assets/                                  # frontend source
├── dashboard/                           # React SPA for /app/*
│   ├── main.tsx
│   ├── App.tsx
│   ├── pages/
│   ├── components/
│   ├── hooks/
│   ├── api/                             # fetchers for our REST API
│   └── i18n/
│       ├── en.json
│       ├── uk.json
│       └── es.json
├── checkout/                            # JS for the public payment page (Twig + viem)
│   └── checkout.ts
├── styles/
│   └── tailwind.css
└── images/
```

---

## 8. API design

REST, JSON-only, versioned via URL: `/api/v1/...`. Authenticated routes use a session cookie (dashboard) or a personal access token (programmatic access — phase 2).

### Conventions
- Response format: `{ "data": ..., "meta": {...} }` or `{ "error": { "code": "...", "message": "..." } }`.
- Error codes are stable strings (e.g., `invoice.not_found`, `wallet.invalid_address`).
- Timestamps: ISO 8601 UTC.
- Money fields: always two fields — `amount_cents` (integer) and `currency` (ISO 4217). Frontend formats for display.
- Pagination: cursor-based via `?cursor=...&limit=...`.
- Idempotency: `Idempotency-Key` header on POST/PATCH. Store the response for 24h, return cached response on retry.

### Endpoints (MVP)

```
# Auth
POST   /api/v1/auth/register
POST   /api/v1/auth/login
POST   /api/v1/auth/logout
POST   /api/v1/auth/forgot-password
POST   /api/v1/auth/reset-password

# Me
GET    /api/v1/me
PATCH  /api/v1/me                     # update profile / payout settings

# Invoices
GET    /api/v1/invoices               # list
POST   /api/v1/invoices               # create (idempotent)
GET    /api/v1/invoices/{uuid}
PATCH  /api/v1/invoices/{uuid}        # only drafts can be edited
DELETE /api/v1/invoices/{uuid}        # only drafts; otherwise use void
POST   /api/v1/invoices/{uuid}/send   # mark as sent, send email
POST   /api/v1/invoices/{uuid}/void
GET    /api/v1/invoices/{uuid}/pdf    # download PDF

# Public (no auth) — used by payment page
GET    /api/v1/public/invoices/{uuid} # limited view for client
POST   /api/v1/public/invoices/{uuid}/track-view  # mark as viewed
POST   /api/v1/public/invoices/{uuid}/tx          # client tells us their tx hash (optimization)

# Payments
GET    /api/v1/payments               # list (filter by invoice)
GET    /api/v1/payments/{id}

# Webhooks
GET    /api/v1/webhooks
POST   /api/v1/webhooks
DELETE /api/v1/webhooks/{id}

# Health (for monitoring)
GET    /api/v1/health                 # checks DB, Redis, RPC connectivity
```

---

## 9. Crypto integration — concrete behavior

### Listener (the heart of the system)

A long-running PHP process started by systemd: `bin/console app:chain:listen`.

**Loop** (per chain):
1. Fetch `chain_cursors.last_processed_block` for this chain.
2. Fetch current head block via RPC (`eth_blockNumber`).
3. If `head - last_processed - REQUIRED_CONFIRMATIONS < 0`, sleep 5s and continue. We only process blocks that are sufficiently confirmed.
4. Define range: `from = last_processed + 1`, `to = min(from + 500, head - REQUIRED_CONFIRMATIONS)`. Cap at 500 blocks per call to stay within RPC limits.
5. Call `eth_getLogs` with:
   - `address` = list of allowlisted token contracts (USDC/USDT/DAI for this chain)
   - `topics[0]` = Transfer event signature `0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef`
   - `topics[2]` = list of recipient addresses (all distinct `recipient_address` from open invoices on this chain). Pad each to 32 bytes.
6. For each log:
   - Decode `from`, `to`, `value` from topics + data
   - Look up open invoice by `recipient_address` (= `to`) and matching token
   - Compute USD value from `value` * token decimals * spot price
   - If invoice expected amount matches within tolerance (default ±0.5% to allow for minor price fluctuations on stablecoins) → mark invoice paid
   - If amount is less → store as `partially_paid` (sum of payments < expected)
   - If amount exceeds → still mark paid; surplus is logged for support
   - Insert row into `payments` (unique key prevents duplicates on retry)
7. Update `chain_cursors.last_processed_block = to`.
8. Sleep 5–15s based on chain block time.

**Required confirmations per chain:**

| Chain | Confirmations | Rationale |
|---|---|---|
| Base | 5 | ~2 second blocks, ~10s finality buffer |
| Optimism | 5 | similar |
| Arbitrum | 5 | similar |
| Polygon | 30 | ~2 second blocks but reorg risk higher |

After confirmation, the invoice is `paid`. Before that, the system can show "payment detected, confirming…" on the dashboard for UX.

### The public payment page

Server-rendered Twig + a small TypeScript bundle. Flow:

1. User opens `/{locale}/pay/{uuid}`. Server fetches invoice, renders the page with:
   - Invoice details (amount, freelancer name, line items)
   - Big "Connect Wallet" button
   - List of accepted chains/tokens
2. JS bundle runs: instantiates wagmi config, RainbowKit modal.
3. User connects wallet (MetaMask, Coinbase Wallet, WalletConnect, etc.).
4. UI shows "You are paying X USDC on Base to wallet 0xABC...".
5. User clicks "Pay". `viem` calls `writeContract` on the chosen token's `transfer(recipient, amount)` function.
6. Wallet pops up. User signs.
7. We `POST /api/v1/public/invoices/{uuid}/tx` with the returned hash so we can show "Detected, waiting for confirmations" without waiting for the listener to find it.
8. The page polls `GET /api/v1/public/invoices/{uuid}` every 5s. Once `status: paid`, show success state and a "Download receipt" button.

**Wallet-side gotchas (handle in JS):**
- Network mismatch: prompt `wallet_switchEthereumChain` if needed.
- Not enough gas: detect, show "Top up gas" hint with a link to a bridge.
- User rejected: graceful message, "Try again" button.
- Wrong token: pre-validate before submitting.

### Token contract allowlist (`config/tokens.yaml`)

```yaml
tokens:
  base:
    chain_id: 8453
    USDC:
      address: '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913'  # native USDC on Base
      decimals: 6
    USDT:
      address: '0xfde4C96c8593536E31F229EA8f37b2ADa2699bb2'
      decimals: 6
  polygon:
    chain_id: 137
    USDC:
      address: '0x3c499c542cEF5E3811e1192ce70d8cC03d5c3359'  # native USDC.e
      decimals: 6
  arbitrum:
    chain_id: 42161
    USDC:
      address: '0xaf88d065e77c8cC2239327C5EDb3A432268e5831'
      decimals: 6
  optimism:
    chain_id: 10
    USDC:
      address: '0x0b2C639c533813f4Aa9D7837CAf62653d097Ff85'
      decimals: 6
```

**Hard rule:** any incoming Transfer log from a contract NOT in this allowlist is ignored. We never credit invoices for unknown tokens, even if symbol matches.

---

## 10. Authentication & authorization

### MVP auth: email + password

- Symfony Security with custom UserProvider on the `users` table.
- Bcrypt or Argon2id for password hashing.
- CSRF tokens on all forms.
- Email verification required before sending invoices.
- Rate limiting on login (Symfony RateLimiter, Redis backend): 5 attempts per IP per 15 min.

### Phase 2: Sign-In With Ethereum (SIWE)

User can link their wallet, then log in by signing a message. Implement `EIP-4361` verification on the backend.

### Authorization

- `InvoiceVoter`: a user can only view/edit/delete their own invoices.
- API tokens (phase 2): scoped per-user, hashed in DB, `Bearer` header.

### Public endpoints

The payment page must be accessible without auth — these endpoints accept the invoice UUID as the only identifier:
- `GET /api/v1/public/invoices/{uuid}` — returns LIMITED data (no user PII beyond business_name)
- `POST /api/v1/public/invoices/{uuid}/track-view`
- `POST /api/v1/public/invoices/{uuid}/tx`

Rate-limit these heavily by IP.

---

## 11. Internationalization (uk / en / es)

### Goals
- Full UI translation to **Ukrainian (uk)**, **English (en)**, **Spanish (es)**.
- Marketing site, dashboard, payment page, emails, PDFs — all localized.
- URL strategy: `/{locale}/...` (e.g., `/en/pricing`, `/uk/pricing`, `/es/pricing`).
- Default locale per user is set on signup (browser-detect), stored in `users.default_locale`, can be changed in settings.
- For public payment pages, the locale is taken from the URL or `Accept-Language`. The freelancer can also pre-set it per invoice (so a Spanish-speaking client gets the page in Spanish even if the freelancer's account is Ukrainian).

### Symfony setup

```yaml
# config/packages/translation.yaml
framework:
  default_locale: en
  translator:
    default_path: '%kernel.project_dir%/translations'
    fallbacks: ['en']
    enabled_locales: ['en', 'uk', 'es']
```

```yaml
# config/routes.yaml — locale prefix
controllers:
  resource: ../src/Controller/
  type: attribute
  prefix:
    en: ''
    uk: '/uk'
    es: '/es'
```

### Translation file structure

`translations/messages.en.yaml`, `messages.uk.yaml`, `messages.es.yaml` — kept in sync.

### Sample translations (use exactly these keys for the core UI)

```yaml
# messages.en.yaml
common:
  app_name: Settle
  tagline: Get paid in stablecoins. Without banks. Without waiting.
  cta_primary: Get started — it's free
  cta_secondary: How it works
  sign_in: Sign in
  sign_up: Sign up
  log_out: Log out

nav:
  dashboard: Dashboard
  invoices: Invoices
  payments: Payments
  settings: Settings
  docs: Docs

invoice:
  status:
    draft: Draft
    sent: Sent
    viewed: Viewed
    paid: Paid
    overdue: Overdue
    void: Voided
  new: New invoice
  client: Client
  amount: Amount
  due_date: Due date
  description: Description
  send: Send invoice
  pay_now: Pay now
  paid_with: Paid with {token} on {chain}
  view_receipt: View receipt

payment:
  connect_wallet: Connect wallet
  switch_network: Switch network
  confirming: Confirming on-chain…
  success_title: Payment received
  success_subtitle: A receipt has been sent to your email.
  rejected: Transaction rejected. You can try again.
  insufficient_balance: Not enough balance for this token.

errors:
  invalid_email: Please enter a valid email
  password_too_short: Password must be at least 12 characters
  network_mismatch: Your wallet is on the wrong network
```

```yaml
# messages.uk.yaml
common:
  app_name: Settle
  tagline: Отримуй оплату в стейблкоїнах. Без банків. Без чекання.
  cta_primary: Почати — безкоштовно
  cta_secondary: Як це працює
  sign_in: Увійти
  sign_up: Зареєструватися
  log_out: Вийти

nav:
  dashboard: Кабінет
  invoices: Інвойси
  payments: Платежі
  settings: Налаштування
  docs: Документація

invoice:
  status:
    draft: Чернетка
    sent: Надіслано
    viewed: Переглянуто
    paid: Оплачено
    overdue: Прострочено
    void: Скасовано
  new: Новий інвойс
  client: Клієнт
  amount: Сума
  due_date: Термін оплати
  description: Опис
  send: Надіслати інвойс
  pay_now: Оплатити
  paid_with: Оплачено в {token} у мережі {chain}
  view_receipt: Переглянути квитанцію

payment:
  connect_wallet: Підключити гаманець
  switch_network: Змінити мережу
  confirming: Підтвердження в блокчейні…
  success_title: Оплату отримано
  success_subtitle: Квитанцію надіслано на твою електронну пошту.
  rejected: Транзакцію відхилено. Можна спробувати ще раз.
  insufficient_balance: Недостатньо балансу для цього токена.

errors:
  invalid_email: Введи коректну електронну адресу
  password_too_short: Пароль має бути не коротшим за 12 символів
  network_mismatch: Твій гаманець у неправильній мережі
```

```yaml
# messages.es.yaml
common:
  app_name: Settle
  tagline: Cobra en stablecoins. Sin bancos. Sin esperas.
  cta_primary: Empezar — es gratis
  cta_secondary: Cómo funciona
  sign_in: Iniciar sesión
  sign_up: Crear cuenta
  log_out: Cerrar sesión

nav:
  dashboard: Panel
  invoices: Facturas
  payments: Pagos
  settings: Ajustes
  docs: Documentación

invoice:
  status:
    draft: Borrador
    sent: Enviada
    viewed: Vista
    paid: Pagada
    overdue: Vencida
    void: Anulada
  new: Nueva factura
  client: Cliente
  amount: Importe
  due_date: Fecha de vencimiento
  description: Descripción
  send: Enviar factura
  pay_now: Pagar ahora
  paid_with: Pagada con {token} en {chain}
  view_receipt: Ver recibo

payment:
  connect_wallet: Conectar billetera
  switch_network: Cambiar de red
  confirming: Confirmando en la blockchain…
  success_title: Pago recibido
  success_subtitle: Te hemos enviado el recibo por correo.
  rejected: Transacción rechazada. Puedes intentarlo de nuevo.
  insufficient_balance: Saldo insuficiente para este token.

errors:
  invalid_email: Introduce un correo válido
  password_too_short: La contraseña debe tener al menos 12 caracteres
  network_mismatch: Tu billetera está en la red equivocada
```

### React (dashboard) i18n

Use **react-i18next** with JSON files in `assets/dashboard/i18n/{en,uk,es}.json`. Keys mirror the Symfony YAML structure.

### Date / number formatting

- Use **`Intl.NumberFormat`** and **`Intl.DateTimeFormat`** with the user's locale on the frontend.
- On backend, use Symfony's `IntlExtension` for Twig: `{{ amount|format_currency('USD', locale=app.request.locale) }}`.
- **Never concatenate currency symbols manually.** `1 234,56 €` (Spanish/Ukrainian) vs `$1,234.56` (English) requires `Intl`.

### RTL / pluralization
- None of our locales are RTL.
- All three languages have non-trivial plural rules. Use ICU MessageFormat where needed (Symfony Translator supports it).

---

## 12. Design system — Tailwind

### Philosophy

We aim for a **clean, fintech-grade, slightly playful** aesthetic. Reference points:
- **Stripe** — clarity, hierarchy, trust
- **Linear** — refinement, motion, minimalism
- **Vercel** — typography, whitespace
- **Coinbase** — fintech with crypto credibility

We avoid:
- Web3 clichés (neon gradients, glassmorphism overload, glitch effects)
- Bro-coded hype aesthetics
- Stock fintech (overly corporate blue)

### Tailwind config (`tailwind.config.js`)

```js
import defaultTheme from 'tailwindcss/defaultTheme';

export default {
  content: [
    './templates/**/*.html.twig',
    './assets/**/*.{ts,tsx,js,jsx}',
  ],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        // Brand: deep teal — trustworthy, fintech, distinct from typical SaaS blue
        brand: {
          50:  '#f0fdfa',
          100: '#ccfbf1',
          200: '#99f6e4',
          300: '#5eead4',
          400: '#2dd4bf',
          500: '#14b8a6',
          600: '#0d9488',  // primary
          700: '#0f766e',
          800: '#115e59',
          900: '#134e4a',
          950: '#042f2e',
        },
        // Status colors
        success: { 500: '#10b981', 600: '#059669', 50: '#ecfdf5' },
        warning: { 500: '#f59e0b', 600: '#d97706', 50: '#fffbeb' },
        danger:  { 500: '#ef4444', 600: '#dc2626', 50: '#fef2f2' },
        info:    { 500: '#3b82f6', 600: '#2563eb', 50: '#eff6ff' },
        // Neutral — we use slate as the base
        // (just rely on default slate-* from Tailwind)
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', ...defaultTheme.fontFamily.sans],
        mono: ['JetBrains Mono', 'ui-monospace', ...defaultTheme.fontFamily.mono],
        display: ['Inter Display', 'Inter', ...defaultTheme.fontFamily.sans],
      },
      fontSize: {
        '2xs': ['0.6875rem', { lineHeight: '1rem' }],
      },
      borderRadius: {
        'xl': '0.875rem',  // slightly softer
        '2xl': '1.125rem',
      },
      boxShadow: {
        'card': '0 1px 2px rgba(15,23,42,0.04), 0 4px 12px rgba(15,23,42,0.04)',
        'card-hover': '0 2px 4px rgba(15,23,42,0.06), 0 8px 24px rgba(15,23,42,0.08)',
      },
      animation: {
        'fade-in': 'fadeIn 200ms ease-out',
        'slide-up': 'slideUp 240ms cubic-bezier(0.16, 1, 0.3, 1)',
      },
    },
  },
};
```

### Core color usage rules

- **Primary brand** (`brand-600`) — main CTAs, active nav items, focus rings. Use sparingly so it stays meaningful.
- **Slate** — body text (`slate-900`), secondary text (`slate-600`), borders (`slate-200`), backgrounds (`slate-50`).
- **Status colors** — only on badges, alerts, and explicit status indicators. Never as decoration.
- **Dark mode** is supported from day one. Use `dark:` variants. Backgrounds: `slate-950` / `slate-900`. Borders: `slate-800`.

### Typography scale

| Use | Class |
|---|---|
| Display / hero | `text-5xl md:text-6xl font-display font-semibold tracking-tight` |
| H1 | `text-3xl md:text-4xl font-display font-semibold tracking-tight` |
| H2 | `text-2xl font-semibold tracking-tight` |
| H3 | `text-lg font-semibold` |
| Body | `text-base text-slate-700 dark:text-slate-300` |
| Small | `text-sm text-slate-600 dark:text-slate-400` |
| Mono (addresses, hashes) | `font-mono text-sm` |

### Component patterns

#### Button

```html
<!-- Primary -->
<button class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl
               bg-brand-600 hover:bg-brand-700 active:bg-brand-800
               text-white font-medium text-sm
               shadow-sm hover:shadow
               transition-all duration-150
               focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2
               disabled:opacity-50 disabled:cursor-not-allowed">
  Send invoice
</button>

<!-- Secondary -->
<button class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl
               bg-white hover:bg-slate-50 active:bg-slate-100
               border border-slate-200 hover:border-slate-300
               text-slate-900 font-medium text-sm
               transition-all duration-150
               focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2
               dark:bg-slate-900 dark:border-slate-700 dark:hover:bg-slate-800 dark:text-slate-100">
  Cancel
</button>

<!-- Ghost -->
<button class="inline-flex items-center gap-2 px-3 py-2 rounded-lg
               text-slate-600 hover:text-slate-900 hover:bg-slate-100
               dark:text-slate-400 dark:hover:text-slate-100 dark:hover:bg-slate-800
               text-sm font-medium transition-colors">
  Skip
</button>
```

#### Input

```html
<label class="block">
  <span class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">
    Email
  </span>
  <input type="email"
         class="block w-full px-3.5 py-2.5 rounded-xl
                bg-white dark:bg-slate-900
                border border-slate-200 dark:border-slate-700
                text-slate-900 dark:text-slate-100 placeholder-slate-400
                focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20
                focus:outline-none transition-colors">
</label>
```

#### Card

```html
<div class="rounded-2xl bg-white dark:bg-slate-900
            border border-slate-200 dark:border-slate-800
            shadow-card hover:shadow-card-hover transition-shadow
            p-6">
  ...
</div>
```

#### Status badge

```html
<!-- Paid -->
<span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full
             bg-success-50 text-success-600 dark:bg-success-500/10
             text-xs font-medium">
  <span class="w-1.5 h-1.5 rounded-full bg-success-500"></span>
  Paid
</span>

<!-- Sent -->
<span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full
             bg-info-50 text-info-600 dark:bg-info-500/10 text-xs font-medium">
  <span class="w-1.5 h-1.5 rounded-full bg-info-500"></span>
  Sent
</span>

<!-- Overdue -->
<span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full
             bg-danger-50 text-danger-600 dark:bg-danger-500/10 text-xs font-medium">
  <span class="w-1.5 h-1.5 rounded-full bg-danger-500"></span>
  Overdue
</span>
```

### Page layout templates

#### Marketing pages (Twig)

- Centered, max-width `max-w-6xl mx-auto px-6`.
- Hero: 3-column on desktop, headline + subhead + CTA on left, illustration/screenshot on right.
- Footer with locale switcher, links to docs, social.

#### Dashboard (React SPA)

- Two-column layout: sidebar (`w-64`) + main content.
- Sidebar: nav links, user menu at bottom.
- Main: padded `p-8`, max-width `max-w-6xl`.
- Mobile: collapsible sidebar (slide-over from left).

#### Public payment page

- **Single, focused screen.** No nav, no distractions.
- Centered card, `max-w-md mx-auto`, vertical center.
- Logo top-left only. Optional language switcher top-right.
- Hierarchy: client logo (if any) → amount (huge) → description → "Connect wallet" button.

### Iconography

- **Lucide icons** (lucide-react) — consistent, open-source, weighted to match Inter.
- Sizes: 16px in dense UI, 20px in default, 24px in headers.
- Always `stroke-width="1.75"` for visual consistency with Inter.

### Motion

- Respect `prefers-reduced-motion`.
- Default transition: 150ms `ease-out` for hover/focus, 240ms `cubic-bezier(0.16, 1, 0.3, 1)` for layout changes.
- Page transitions: simple opacity fade (no slide, no scale).

### Accessibility (must)

- Semantic HTML (`<button>`, not `<div onclick>`).
- Color contrast WCAG AA minimum (verify with checker).
- Keyboard navigation for all interactive elements.
- Visible focus rings — never `outline: none` without alternative.
- Form labels properly associated.
- ARIA only when semantic HTML isn't enough.

---

## 13. Pricing model — crypto-native (no Stripe)

| Plan | Cost | Per-invoice fee | Includes |
|---|---|---|---|
| Free | $0 | 1.0% | Up to $1,000 invoiced/month, all 4 chains, USDC/USDT/DAI |
| Pro | $19 USDC / 30 days | 0.5% | Unlimited volume, custom branding, API access, webhooks |
| Pro · Lifetime | $299 USDC one-time | 0.5% | All of Pro, forever (no renewal) |
| Agency | $49 USDC / 30 days | 0.5% | Pro + multi-user (10 seats), email invite flow, shared invoices/payments/billing |

**How fees are taken** (Phase 2 model — workspace as the ownership unit):

- **Per-invoice fees** (1% Free, 0.5% Pro/Agency) accumulate in `workspaces.fees_owed_cents` whenever the listener confirms an invoice payment. Funds still flow client → workspace payout wallet directly; we never touch the money on-chain.
- **Settlement** is a separate USDC payment from the workspace owner to a platform-controlled wallet (`PLATFORM_WALLET_ADDRESS` env var). The same listener daemon that watches workspace payout wallets ALSO watches the platform wallet — on Transfer match, `BillingPaymentMatcher` clears `workspaces.fees_owed_cents` or extends `workspaces.plan_renews_at`.
- **Subscriptions** (Pro monthly, Pro lifetime, Agency monthly) use the same `BillingIntent` mechanism: owner clicks Upgrade → backend creates an intent → owner pays USDC → listener matches → workspace upgraded.
- **No auto-debit.** Every renewal is a fresh USDC transfer the owner authorizes. Soft cancel (`workspaces.plan_canceled_at`) keeps Pro/Agency access until `plan_renews_at`, then drops to Free.
- **Owner-only.** Members can create + send + void invoices but only the Owner role can change billing, payout, branding, API tokens, or webhooks.

**Why not Stripe** (the original plan): charging in fiat for a crypto product is hypocritical; cards add regional friction (Stripe blocks many Ukrainian / Argentine / Nigerian cards — same blockers we hit with MetaMask Buy); the listener we already built does exactly what we need.

**Settlepay still writes nothing on-chain.** Platform fee collection is the same read-only pattern as invoice payments — listen for Transfers, match by amount + chain, update DB state. Keeps the "software facilitator, not money transmitter" framing intact.

Implementation files:
- `src/Service/Billing/{PlatformWallet, BillingIntentFactory, SubscriptionManager, BillingPaymentMatcher}.php`
- `src/Service/Workspace/{WorkspaceContext, InvitationManager}.php`
- `src/Entity/{Workspace, WorkspaceMember, WorkspaceInvitation, BillingIntent, FeePayment, ApiToken, Webhook, WebhookDelivery}.php`
- `src/Enum/BillingIntent{Kind, Status}.php` (`Kind`: `pro_monthly` | `pro_lifetime` | `agency_monthly` | `fee_settlement`)
- `src/Controller/Dashboard/{BillingController, TeamController, BrandingController, ApiTokensController, WebhooksController}.php`
- `src/Controller/PublicArea/BillingCheckoutController.php` → `/billing/pay/{uuid}`
- `src/Controller/Workspace/InvitationAcceptController.php` → `/workspaces/accept/{token}`
- Migrations: `Version20260511180000` (billing), `Version20260511220000` (workspaces backfill), `Version20260511230000` (Phase 2 NOT NULL)

---

## 14. Local development

### Required tooling
- PHP 8.3+ (with `pdo_mysql`, `bcmath`, `gmp`, `intl`, `mbstring`)
- Composer 2.x
- Node 20+, pnpm 9+
- MariaDB 10.11+ (or Docker)
- Redis 7+ (or Docker)
- Foundry's `cast` (optional, for ad-hoc on-chain debugging)

### Setup

```bash
# Clone & install
git clone ...
cd settle
composer install
pnpm install

# Configure
cp .env .env.local
# Fill in DATABASE_URL, REDIS_URL, RPC URLs, RESEND_API_KEY

# Database
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

# Frontend
pnpm dev             # Vite dev server with HMR

# Symfony
symfony serve        # or php -S 127.0.0.1:8000 -t public

# Workers
php bin/console messenger:consume async -vv
php bin/console app:chain:listen --testnet
```

### Testing strategy

- **PHPUnit** for unit + integration tests. Target 70%+ coverage on Service/ classes.
- **Pest** style if preferred (optional).
- **Playwright** for E2E tests of critical flows (signup → create invoice → pay on testnet).
- For chain tests: use **anvil** (`foundryup`) to run a local fork of Base, mock USDC transfers.

---

## 15. Deployment to Hetzner

Live production runs out of **`/www/wwwroot/settlepay.pro`** (aaPanel
default vhost root) under user **`www`**. Deploys come in via GitHub
Actions (`.github/workflows/ci.yml`) — push to `main`, the workflow
SSHes in, pulls, runs `composer install`, `pnpm build`, migrations,
clears cache, and `systemctl restart`s every listener + worker unit
with one glob to avoid the stale-cache bug.

```
/www/wwwroot/settlepay.pro/
├── .env.local        # PLATFORM_WALLET_ADDRESS, RPC keys, DB creds
├── bin/, src/, config/, public/, templates/, vendor/, ...
├── public/uploads/branding/   # workspace logos
└── var/log/, var/cache/
```

### Process supervisor

systemd units (live):

```ini
# /etc/systemd/system/settle-listener.service  (mainnet)
[Unit]
Description=Settlepay mainnet blockchain listener
After=network.target mariadb.service redis.service

[Service]
Type=simple
User=www
WorkingDirectory=/www/wwwroot/settlepay.pro
ExecStart=/usr/bin/php bin/console app:chain:listen
Restart=always
RestartSec=5
StandardOutput=append:/var/log/settlepay/listener.log
StandardError=append:/var/log/settlepay/listener-error.log

[Install]
WantedBy=multi-user.target
```

```ini
# /etc/systemd/system/settle-listener-testnet.service  (Sepolias)
# Identical to above but ExecStart adds --testnet:
ExecStart=/usr/bin/php bin/console app:chain:listen --testnet
```

```ini
# /etc/systemd/system/settle-worker@.service  (templated)
[Unit]
Description=Settlepay messenger worker %i
After=network.target

[Service]
Type=simple
User=www
WorkingDirectory=/www/wwwroot/settlepay.pro
ExecStart=/usr/bin/php bin/console messenger:consume async --time-limit=3600 --memory-limit=128M
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Enable: `systemctl enable settle-worker@1 settle-worker@2 settle-listener settle-listener-testnet`

**Restart on deploy** uses a glob to catch both listener units in one
shot (memorialized in `memory/gotchas.md` after a stale-cache incident):

```bash
sudo systemctl restart 'settle-listener*.service'
sudo systemctl restart 'settle-worker@*.service'
```

### nginx config (excerpt)

```nginx
server {
    listen 443 ssl http2;
    server_name settlepay.pro www.settlepay.pro;

    root /www/wwwroot/settlepay.pro/public;
    index index.php;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }

    # Cache hashed assets aggressively
    location /assets/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # Rate limit API
    location /api/ {
        limit_req zone=api burst=20 nodelay;
        try_files $uri /index.php$is_args$args;
    }
}
```

### Backups
- MariaDB: `mariadb-dump` daily, encrypted, sent to Hetzner Storage Box (or S3-compatible).
- 30-day retention.
- Test restore monthly.

---

## 16. Security checklist

- [ ] HTTPS only, HSTS preload
- [ ] CSP header (no inline scripts except where needed for crypto checkout)
- [ ] CSRF protection on all forms
- [ ] All user input validated server-side
- [ ] Doctrine for SQL — no raw queries unless reviewed
- [ ] Argon2id for passwords, min 12 chars
- [ ] Rate limiting on auth endpoints (Symfony RateLimiter + Redis)
- [ ] Token contract allowlist enforced on every payment match
- [ ] Wallet addresses validated with checksum (EIP-55)
- [ ] No secrets in code — `.env.local` only, `chmod 600`
- [ ] All admin operations logged in `audit_log`
- [ ] Sentry DSN does not log request bodies (PII risk)
- [ ] Webhook signatures via HMAC-SHA256
- [ ] PDF generation — sanitize all user input before rendering (no XSS via SVG)
- [ ] CORS strictly configured — dashboard origin only

---

## 17. MVP roadmap (8-week target)

### Week 1: foundation
- Symfony skeleton, Doctrine entities, migrations
- Email/password auth, email verification
- Tailwind + base templates
- Marketing landing page (en/uk/es)

### Week 2: invoice CRUD
- Create/edit/list invoices in dashboard (React)
- Public invoice view (Twig)
- PDF generation
- Email sending (invoice sent, paid, overdue)

### Week 3: crypto checkout (testnet)
- viem + wagmi + RainbowKit on payment page
- Connect wallet, switch chain, send USDC on Base Sepolia
- Receive tx hash on backend, store as pending

### Week 4: chain listener
- `app:chain:listen` command
- `eth_getLogs` polling, ERC-20 Transfer decoding
- Payment matching, status updates
- Integration tests with anvil

### Week 5: mainnet + polish
- Switch to Base, Polygon, Arbitrum, Optimism mainnet
- Test with real $5–10 invoices
- Sentry, basic monitoring
- Webhooks v1

### Week 6: i18n complete
- All UI translated to uk + es
- Locale-aware routing
- Locale-aware money/date formatting
- PDF templates in 3 languages

### Week 7: beta with friends
- Invite 10–20 freelancers
- Feedback loop, bug fixes
- Onboarding polish (empty states, tooltips)

### Week 8: public launch
- Product Hunt launch
- Twitter/X thread
- Indie Hackers post
- Pricing page, billing v1 (Stripe)

---

## 18. Conventions

### Code style
- PHP: **PSR-12** + Symfony coding standards. Use `php-cs-fixer` with the Symfony preset.
- TypeScript: **strict** mode. ESLint + Prettier with default rules.
- Git commits: Conventional Commits (`feat:`, `fix:`, `chore:`, `refactor:`).
- Branches: `main` (prod), `develop` (staging), feature branches off `develop`.

### Testing convention
- One test class per service: `InvoiceFactoryTest` for `InvoiceFactory`.
- Integration tests in `tests/Integration/`, unit tests in `tests/Unit/`.
- Use Doctrine fixtures (`doctrine/data-fixtures`) for seed data.

### Naming
- Database: snake_case
- PHP classes: PascalCase, services suffix-named (`InvoiceFactory`, `PaymentMatcher`)
- TypeScript: camelCase for variables, PascalCase for components
- CSS classes: rely on Tailwind, custom classes in BEM only when necessary

### Comments
- Code should be self-documenting. Comments for **why**, not **what**.
- All public service methods have PHPDoc with parameter types and return type.

---

## 19. Working with Claude Code on this project

### When in doubt, prefer:
- **Boring over clever.** This is a money-handling system. Clarity > cleverness.
- **Doctrine over raw SQL.** Use raw SQL only for performance-critical reads.
- **Server-rendered Twig over client SPA.** Use React only for the dashboard, where complex interaction warrants it.
- **Integer cents over floats.** Always.
- **Allowlists over blocklists.** Especially for token contracts.
- **Idempotency over retries.** Every state-changing operation should be safe to retry.
- **Logs over silence.** Every payment match, every email sent, every webhook fired — logged.

### Specific patterns Claude should follow
- Use Symfony's dependency injection — never `new Service()` directly.
- Database changes always go through migrations, never `make:entity` against prod schema.
- Money in DTOs is always `amount_cents: int` + `currency: string`.
- Wallet addresses are stored as **lowercase strings** (after EIP-55 validation).
- Tx hashes stored as lowercase strings.
- Dates: store UTC, display in user's timezone.
- Never log full email addresses or wallet addresses in plain text — use a masking helper for production logs.

### Things to ask the user (Vitalii) about before deciding
- Adding new chains
- Changing pricing
- Adding custom smart contracts (escrow, etc.)
- Anything touching custody
- Major UI/UX changes to the public payment page (it converts revenue, treat as sacred)

### Files Claude should NEVER edit without explicit ask
- `.env.local` (only on local machine)
- `config/tokens.yaml` and `config/chains.yaml` — token addresses must be reviewed
- Migration files after they're committed (always create new ones)
- Anything in `var/`, `vendor/`, `node_modules/`

---

## 20. Glossary (for non-crypto readers / future teammates)

- **EVM** — Ethereum Virtual Machine. The runtime that executes smart contracts on Ethereum and EVM-compatible chains (Base, Polygon, Arbitrum, Optimism, etc.).
- **L2** — Layer 2. A blockchain that batches transactions and posts them to Ethereum L1 (mainnet) for security. Cheaper and faster than L1.
- **Stablecoin** — a token pegged to a fiat currency. USDC, USDT, DAI are pegged to USD.
- **ERC-20** — the standard interface for fungible tokens on Ethereum. USDC, USDT, DAI all implement ERC-20.
- **Transfer event** — an event emitted on-chain whenever an ERC-20 token moves between addresses. We listen for these to detect payments.
- **Gas** — the fee paid to validators to include a transaction in a block. Paid in the chain's native token (ETH on Base/Arbitrum/Optimism, MATIC on Polygon).
- **Wallet** — software (MetaMask, Coinbase Wallet) that holds the user's private key and signs transactions. We never see private keys.
- **Address** — the public identifier of a wallet, like a bank account number. Looks like `0x742d35Cc...`. Same address works across all EVM chains.
- **Confirmation** — a block added on top of the block containing your transaction. More confirmations = harder to reverse.
- **RPC** — Remote Procedure Call. The protocol for talking to a blockchain node (Alchemy, QuickNode are RPC providers).
- **Tx hash** — the unique identifier of a transaction. Like a receipt number.
- **Native USDC** — USDC issued directly by Circle on a chain (e.g., Base, Arbitrum). Distinct from "bridged" USDC, which is a wrapper.

---

**End of CLAUDE.md.** Update this document as architecture changes. Treat it as the single source of truth for new contributors and AI agents working on the codebase.
