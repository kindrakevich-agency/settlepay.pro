# Product Hunt launch — first comment

Paste this as the first comment the moment "Launch" goes live. Comment
velocity in the first 4 hours is what drives PH ranking, so post it
within seconds of the product going live and reply to every reply.

---

Hey PH 👋

Vitalii here — Settlepay maker.

I built this after watching a friend in Kyiv lose $400 of a $5,000 invoice to wire fees, FX spread, and his bank's 9-day "international transfer review." He's a senior backend engineer working for a US fintech. Stripe doesn't operate in Ukraine, Wise's KYC takes weeks, and the crypto "solutions" his clients tried were a wallet address in a Telegram message.

The fix turned out to be technically simple: generate a payment page, listen for the on-chain Transfer event, flip the invoice to Paid, send a receipt. Stripe-style UX, crypto-native plumbing, non-custodial by design — we never touch the money.

Three things I'd love your feedback on:

1. Onboarding — is "paste wallet → send invoice" the 60-second flow I think it is, or do you bounce somewhere?
2. The /about page — is the "why we built this" clear, or does it read as crypto-bro?
3. Pricing — Free $1k/mo cap, Pro $19/mo, Agency $49/mo for 10 seats, all paid in USDC. Fair, too aggressive, or too generous?

First 50 PH commenters get Pro for life — DM with the email you signed up with.

Roadmap next quarter:
- Sign-in with Ethereum (SIWE) — wallet-only auth
- Recurring invoices for retainer clients
- Built-in on-ramp via Transak so clients without crypto can still pay

Built solo in 12 days. €20/mo to run. Open to every flavor of feedback, especially from freelancers in Ukraine, Argentina, Nigeria, Brazil, Vietnam, Philippines, Turkey — anywhere getting paid internationally is harder than it should be.

Will reply to every comment today. AMA.

---

## Notes for launch day

- Open the PH page in one tab, your dashboard in another. When the "first 50 commenters get Pro" promise triggers, grant Pro manually via the admin route (`/app/admin/users` → find user → DB update to set `workspace.plan='pro'`, `plan_renews_at=NULL` for lifetime).
- Keep the AMA promise. Reply to every comment within ~2 hours, ideally faster early on.
- Pin the Twitter thread the same day. Cross-link in replies when relevant.
- Have a "screenshot wall" ready (dashboard, checkout, agency, receipt PDF) to drop into replies when people ask "what does it look like?"
- If asked about competitors: Request Finance, Coinbase Commerce, BTCPay Server. Differentiator is the non-custodial + multi-chain + L2-cheap + invoicing-shaped wrapper combo, not any one feature.
