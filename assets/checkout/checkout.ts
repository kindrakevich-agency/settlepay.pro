// Public payment-page bootstrap. Loaded by templates/payment/checkout.html.twig.
// Phase 1: stub. Phase 2 plugs in viem + wagmi + RainbowKit.

import '../styles/app.css';

console.info('[settle] checkout bundle loaded');

// TODO: instantiate wagmi config (Base / Polygon / Arbitrum / Optimism mainnets + sepolia testnets)
// TODO: render RainbowKit Connect button
// TODO: token+chain selector → ERC-20 transfer(recipient, amount)
// TODO: POST /api/v1/public/invoices/{uuid}/tx with returned hash
// TODO: poll GET /api/v1/public/invoices/{uuid} for status updates
