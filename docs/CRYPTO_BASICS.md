# Crypto basics for first-time users

> If you've never used crypto before, **this guide gets you from zero to "I just paid an invoice with stablecoins"** in about 15 minutes. No real money required — we use a free testnet first.

If something doesn't make sense, that's normal. Crypto has a lot of jargon. Read [§7 Glossary](#7-glossary) at the bottom anytime.

---

## Table of contents

1. [What you're about to do](#1-what-youre-about-to-do)
2. [Install a wallet (MetaMask)](#2-install-a-wallet-metamask)
3. [Add the Base Sepolia testnet](#3-add-the-base-sepolia-testnet)
4. [Get free test ETH (for gas)](#4-get-free-test-eth-for-gas)
5. [Get free test USDC](#5-get-free-test-usdc)
6. [Pay a real Settlepay invoice on testnet](#6-pay-a-real-settlepay-invoice-on-testnet)
7. [Glossary](#7-glossary)
8. [Going to mainnet (real money)](#8-going-to-mainnet-real-money)
9. [Where each .env variable is registered](#9-where-each-env-variable-is-registered)

---

## 1. What you're about to do

Settlepay is built on **public blockchains** — no banks involved. To test the payment flow, you need to act like a customer paying an invoice. To do that you need:

| Need | What it does | Where you get it |
|---|---|---|
| **A wallet** | Holds your private key, signs transactions. Like a bank app, but you own it. | MetaMask browser extension (free) |
| **A network** | Which blockchain you're on. We test on **Base Sepolia** (a free testnet — fake money, real software). | Already exists; we just point your wallet at it |
| **Test ETH** | The "stamp" you pay validators to include your transaction. Tiny amounts. | Free faucet (drips of fake ETH per day) |
| **Test USDC** | The actual stablecoin you use to pay the invoice. Pegged 1:1 to USD. | Free faucet from Circle |

Once you've done it on testnet, **mainnet is the same flow with real money** — Settlepay does NOT change.

---

## 2. Install a wallet (MetaMask)

1. Open [https://metamask.io/download](https://metamask.io/download) in **Chrome, Brave, or Firefox**.
2. Click **"Install for [your browser]"**.
3. After install, click the fox icon in the top-right of the browser → **"Create a new wallet"**.
4. Set a password (this only protects MetaMask on your computer; it does NOT protect the wallet itself).
5. **Write down the 12-word Secret Recovery Phrase on paper.** This is the master key. Anyone who has these words owns your money. **Never type them into any website. Never store them in a password manager. Never email them. Never share them in chat.**
6. Confirm the phrase by clicking the words in order.

Done — you have a wallet. The address is the long string starting with `0x...` you'll see at the top.

> **Sanity check:** if a website *ever* asks you for your 12-word phrase, it's a scam. Real websites only ever ask MetaMask to **sign** a message; they never see the phrase.

---

## 3. Add the Base Sepolia testnet

By default, MetaMask shows Ethereum Mainnet. We need Base Sepolia for testing.

**The easiest way:** open https://chainlist.org/chain/84532 → click **"Add to MetaMask"** → approve in the popup.

**Manual way** (if Chainlist doesn't load):

1. MetaMask → click the network dropdown at the top (shows "Ethereum Mainnet").
2. **"Add network"** → **"Add a network manually"** at the bottom.
3. Fill in:
   - Network name: `Base Sepolia`
   - RPC URL: `https://sepolia.base.org`
   - Chain ID: `84532`
   - Currency symbol: `ETH`
   - Block explorer: `https://sepolia.basescan.org`
4. Save → switch to it.

You should now see "Base Sepolia" in the network dropdown.

---

## 4. Get free test ETH (for gas)

Every transaction on a blockchain costs a tiny fee called **gas**. On Base Sepolia gas is fake but you still need a few cents' worth to send any transaction. Get free testnet ETH from a **faucet** (a website that drips small amounts to anyone who asks).

1. Copy your wallet address from MetaMask (click the account name → it copies to clipboard).
2. Open one of these faucets:
   - **https://www.alchemy.com/faucets/base-sepolia** — easiest, requires free Alchemy signup.
   - **https://www.coinbase.com/faucets/base-ethereum-sepolia-faucet** — alternative.
3. Paste your address → request → wait 30 seconds.
4. Back in MetaMask you should see your ETH balance > 0.

> If a faucet rate-limits you, try the other one. Faucets are often a single drop per address per day — that's normal.

---

## 5. Get free test USDC

Now you need the actual money — testnet USDC.

1. Open **https://faucet.circle.com**.
2. Choose chain → **Base Sepolia**.
3. Paste your wallet address.
4. Solve the captcha → request.

You should now see 10 USDC in your wallet. To make MetaMask display it:

- MetaMask → **"Tokens"** tab → **"Import tokens"**.
- Token contract: `0x036CbD53842c5426634e7929541eC2318f3dCF7e` (Circle's testnet USDC on Base Sepolia)
- Symbol auto-fills as USDC, decimals auto-fill as 6.
- **Add custom token** → done.

---

## 6. Pay a real Settlepay invoice on testnet

**The only thing left is paying**. Open the sample invoice URL we generate when you run:

```bash
make console c="app:invoice:create-sample --testnet"
```

(or in production, an SSH terminal:)

```bash
APP_ENV=prod php bin/console app:invoice:create-sample --testnet
```

The command prints a `Public payment URL` like `https://settlepay.pro/en/pay/019e0cb8-198b-74a9-9f48-28942167d1e4`.

1. Open that URL in the browser where MetaMask is installed.
2. The page shows "Connect wallet". Click it.
3. MetaMask pops up → **Connect**.
4. The button now says **"Pay 2,450 USDC"** (or "Switch to Base Sepolia" first if you're on the wrong network — click it to switch).
5. Click → MetaMask asks you to confirm a USDC transfer for 2,450 USDC. **Wait** — the demo invoice is for 2,450 USDC, but you only have 10 from the faucet. To actually pay, **first edit the invoice amount to be smaller**:

```bash
APP_ENV=prod php bin/console app:invoice:create-sample --testnet --amount-cents=500 --recipient=YOUR_WALLET_ADDRESS
```

That makes a $5.00 invoice paying YOUR own wallet (so you can claim back the test USDC). Click Pay.

6. MetaMask asks you to confirm — gas is shown in fake ETH (~$0.00). Click **Confirm**.
7. Page shows "Confirming on-chain… 5/5 confirmations" → **"Payment received"**.

You just sent your first stablecoin payment. The flow on mainnet is identical except it's real money.

---

## 7. Glossary

| Term | What it means |
|---|---|
| **Wallet** | Software (MetaMask) that holds your **private key** and signs transactions. Anyone with the private key controls the wallet, no recovery — that's why nobody but you should ever see the 12-word phrase. |
| **Address** | The public ID of your wallet (`0x...`). Like an email address — safe to share, used to receive funds. The same address works on every EVM chain (Ethereum, Base, Polygon, etc.). |
| **Network / chain** | A specific blockchain. Ethereum is one. Base is another. They share the same address format but balances are separate. |
| **Mainnet** | The "production" chain where real-money transactions live. |
| **Testnet** | A free copy of a chain used for testing. Tokens have no value. |
| **Gas** | The fee paid to validators to include a tx in a block. On Base mainnet, ~$0.01–0.05 per tx. On testnets, free (you pay in fake ETH from a faucet). |
| **Stablecoin** | A token that's worth exactly $1 (or another fiat unit). USDC, USDT, DAI all = $1. |
| **USDC** | The most reputable stablecoin, issued by Circle. Available on every major chain. |
| **EVM** | "Ethereum Virtual Machine" — the runtime smart contracts run on. Ethereum, Base, Polygon, Arbitrum, Optimism are all EVM-compatible: same code works on all of them. |
| **L2** | "Layer 2" — a chain built on top of Ethereum that's much cheaper. Base, Arbitrum, Optimism are L2s. We use these because gas on Ethereum mainnet itself is too expensive for invoice-size payments. |
| **Tx hash** | The unique ID of a transaction, like an order number. Looks like `0xabc...`. You can paste it into a block explorer (e.g. https://sepolia.basescan.org/tx/0x...) to see the tx publicly. |
| **Confirmation** | A block added on top of the block containing your tx. More confirmations = harder to reverse. Settlepay waits for 5 on Base, 30 on Polygon. |
| **Faucet** | A website that gives you small amounts of testnet tokens for free. |
| **Smart contract** | A program that lives on a blockchain. USDC is a smart contract. We don't write our own — we read events from existing ones. |

---

## 8. Going to mainnet (real money)

Once testnet works for you, mainnet is identical. The only differences:

- You buy real USDC instead of getting it from a faucet:
  - Coinbase, Binance, Kraken etc. let you buy USDC and "withdraw to Base" (or Polygon, Arbitrum, Optimism).
  - On-ramps like MoonPay, Transak buy USDC directly to your wallet (5–15 min, ~3% fee).
  - Or someone sends USDC to your wallet (peer-to-peer).
- You pay real gas (~$0.01–0.10 on L2s).
- You're the freelancer being paid — clients send you USDC; you buy a coffee with it via a debit card service like Coinbase Card, or convert to fiat via a bank service.

Settlepay's job stops at "USDC arrives in your wallet." What you do with it after is up to you — that's the whole point of being non-custodial.

---

## 9. Where each .env variable is registered

See [`.env.example`](../.env.example) for the up-to-date list with inline notes. Quick index:

| Variable | What it's for | Where you sign up |
|---|---|---|
| `APP_SECRET` | Internal Symfony secret for CSRF + sessions. | Generate locally: `php -r "echo bin2hex(random_bytes(16));"` |
| `DATABASE_URL` | MariaDB connection string. | Local: docker compose handles it. Prod: aaPanel → Databases tab. |
| `REDIS_URL` | Redis connection. | Local: docker. Prod: already running on the server. |
| `MAILER_DSN` | Outgoing email (invoices, receipts, password resets). | Free tier at https://resend.com (100 emails/day) — recommended. Or https://postmarkapp.com. |
| `BASE_RPC_URL` and similar | Connection to each blockchain. Public fallback works for tests; production needs higher rate limits. | Free tier at https://www.alchemy.com or https://www.quicknode.com (300M requests/month free). |
| `COINGECKO_API_BASE` | Token price feed for USD conversion. | Free tier (no signup) at https://api.coingecko.com — already set. |
| `SENTRY_DSN` | Error tracking. | Free tier at https://sentry.io (5k errors/month). Optional. |
| `PLATFORM_WALLET_ADDRESS` | The Settlepay-owned EVM wallet that receives Pro/Agency subscriptions + per-invoice fee settlements in USDC. Same listener watches it for incoming Transfers. | An EVM address you control (MetaMask / Coinbase Wallet). **Required** for billing. |
| `BILLING_ALLOW_TESTNETS` | Dev-only flag (`1` to accept Sepolia testnets for billing intents). Production must stay unset so faucet USDC can't pay for real subscriptions. | Local `.env.local` only. Default empty. |
| `VITE_WALLETCONNECT_PROJECT_ID` | Lets mobile wallet users (Trust, Rainbow, Phantom) scan a QR code to connect. Without it, only browser-extension wallets work. | Free at https://cloud.reown.com/sign-in (formerly walletconnect.com). |
