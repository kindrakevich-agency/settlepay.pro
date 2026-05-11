// Public payment-page bootstrap. Mounts the wallet flow over the
// server-rendered checkout in templates/payment/checkout.html.twig.
//
// This file is the entire client-side. No React, no RainbowKit.
// We use @wagmi/core's vanilla actions for connect / switch chain /
// writeContract, plus viem's `parseUnits` for amount conversion.
//
// Stage 2 (this file): connect wallet, switch chain, render Pay state
// Stage 3 (this file): writeContract → POST tx hash → poll status
//
// See CLAUDE.md §9 — payment page is sacred, treat changes carefully.

import '../styles/app.css';

/// <reference types="vite/client" />

import * as Sentry from '@sentry/browser';
import { http, createConfig } from '@wagmi/core';
import { connect, getAccount, getChainId, switchChain, writeContract, watchAccount, waitForTransactionReceipt } from '@wagmi/core';
import { injected, walletConnect } from '@wagmi/connectors';
import {
    base, polygon, arbitrum, optimism,
    baseSepolia, optimismSepolia, arbitrumSepolia,
} from 'viem/chains';
import { parseUnits, type Address, type Hex } from 'viem';

// ─── 0. Sentry init ────────────────────────────────────────────────
//
// Browser-side errors land in a SEPARATE Sentry project from the PHP
// backend (different runtimes, different DSNs). When VITE_SENTRY_DSN_JS
// is empty the SDK is a no-op. Set it in .env.local for prod.
//
// We filter the most common non-actionable client errors before they
// ship so the "errors/month" quota stays focused on real bugs:
//   - User rejected wallet prompt
//   - Wallet not installed (provider not injected)
//   - Network switch denied / rejected
const sentryDsn = import.meta.env.VITE_SENTRY_DSN_JS;
if (sentryDsn) {
    Sentry.init({
        dsn: sentryDsn,
        environment: import.meta.env.MODE,
        tracesSampleRate: 0.1, // 10% — checkout traffic is the highest-volume page
        beforeSend(event, hint) {
            const msg = String((hint?.originalException as Error | undefined)?.message ?? '');
            // MetaMask cancellations + wallet-not-installed are user state, not bugs.
            if (msg.includes('User rejected') || msg.includes('user rejected')) return null;
            if (msg.includes('No provider injected')) return null;
            if (msg.includes('switchEthereumChain') && msg.toLowerCase().includes('reject')) return null;
            return event;
        },
    });
}

// ─── 1. Read the JSON island the Twig template emits ──────────────

interface TokenInfo { chain_id: number; token: string; address: string; decimals: number; label?: string; }
interface ChainInfo { chain_id: number; name: string; key: string; tokens: Record<string, TokenInfo>; }
interface CheckoutData {
    invoice: {
        uuid: string;
        number: string;
        status: string;
        amount_cents: number;
        currency: string;
        recipient_address: string;
        due_date: string | null;
    };
    available_chains: ChainInfo[];
    polling: { url: string; interval_ms: number };
    tx_endpoint: string;
}

const dataEl = document.getElementById('settlepay-checkout-data');
if (!dataEl) {
    console.warn('[settlepay] no #settlepay-checkout-data island, checkout JS will not initialise');
} else {
    bootstrap(JSON.parse(dataEl.textContent || '{}'));
}

// ─── 2. Wagmi configuration ───────────────────────────────────────

function buildWagmiConfig() {
    const projectId = (import.meta.env.VITE_WALLETCONNECT_PROJECT_ID as string | undefined)?.trim();

    const connectors = [
        injected({ shimDisconnect: true }),
        ...(projectId ? [walletConnect({ projectId, showQrModal: true, metadata: {
            name: 'Settlepay',
            description: 'Get paid in stablecoins.',
            url: 'https://settlepay.pro',
            icons: ['https://settlepay.pro/favicon.svg'],
        }})] : []),
    ];

    // Pin reliable RPCs. The default http() falls back to each chain's
    // "official" sequencer URL, but those rate-limit hard on eth_call
    // (the simulation viem runs before sending a contract tx) and
    // 503'd intermittently in May 2026. publicnode + drpc are
    // aggregator-style routes that have held up consistently.
    // Override per-environment via VITE_*_RPC_URL if you've got an
    // Alchemy / QuickNode / Infura key for production.
    const env = import.meta.env;
    return createConfig({
        chains: [base, polygon, arbitrum, optimism, baseSepolia, optimismSepolia, arbitrumSepolia],
        connectors,
        transports: {
            [base.id]:             http(env.VITE_BASE_RPC_URL              || 'https://base.publicnode.com'),
            [polygon.id]:          http(env.VITE_POLYGON_RPC_URL           || 'https://polygon-bor.publicnode.com'),
            [arbitrum.id]:         http(env.VITE_ARBITRUM_RPC_URL          || 'https://arbitrum-one.publicnode.com'),
            [optimism.id]:         http(env.VITE_OPTIMISM_RPC_URL          || 'https://optimism.publicnode.com'),
            [baseSepolia.id]:      http(env.VITE_BASE_SEPOLIA_RPC_URL      || 'https://base-sepolia.drpc.org'),
            [optimismSepolia.id]:  http(env.VITE_OP_SEPOLIA_RPC_URL        || 'https://optimism-sepolia.drpc.org'),
            [arbitrumSepolia.id]:  http(env.VITE_ARB_SEPOLIA_RPC_URL       || 'https://arbitrum-sepolia.drpc.org'),
        },
    });
}

const wagmi = buildWagmiConfig();

// ─── 3. ERC-20 Transfer ABI (only what we need) ───────────────────

const ERC20_TRANSFER_ABI = [{
    type: 'function',
    name: 'transfer',
    stateMutability: 'nonpayable',
    inputs: [
        { name: 'to',     type: 'address' },
        { name: 'amount', type: 'uint256' },
    ],
    outputs: [{ name: '', type: 'bool' }],
}] as const;

// ─── 4. UI helpers ────────────────────────────────────────────────

function $<T extends HTMLElement = HTMLElement>(sel: string): T | null {
    return document.querySelector(sel);
}

function setCtaLabel(text: string) {
    const el = $('[data-cta-label]');
    if (el) el.textContent = text;
}

function setCtaDisabled(disabled: boolean) {
    const btn = $<HTMLButtonElement>('#settlepay-cta');
    if (btn) btn.disabled = disabled;
}

function showStatus({ text, progressPct, txHash, tone }: { text: string; progressPct?: number; txHash?: string; tone?: 'pending' | 'success' | 'error' }) {
    const wrap = $('#settlepay-status');
    if (!wrap) return;
    wrap.classList.remove('hidden');

    // Reset tone classes so they don't accumulate across state changes.
    wrap.className = wrap.className
        .replace(/(border-(success|brand|danger)-[\w/]+)/g, '')
        .replace(/(bg-(success|brand|danger)-[\w/]+)/g, '')
        .trim();

    const toneClasses = {
        pending: 'border-brand-200 dark:border-brand-700/50 bg-brand-50/60 dark:bg-brand-500/10',
        success: 'border-success-200 dark:border-success-500/30 bg-success-50 dark:bg-success-500/10',
        error:   'border-danger-200 dark:border-danger-500/30 bg-danger-50 dark:bg-danger-500/10',
    }[tone || 'pending'];
    wrap.classList.add(...toneClasses.split(' '));

    const txt = $('[data-status-text]'); if (txt) txt.textContent = text;
    const bar = $<HTMLElement>('[data-status-bar]');
    if (bar && typeof progressPct === 'number') bar.style.width = `${Math.min(100, Math.max(0, progressPct))}%`;
    const tx = $<HTMLElement>('[data-status-tx]');
    if (tx) tx.textContent = txHash ? `tx ${txHash}` : '';
}

// ─── 5. Selected token + chain (driven by the radio + select inputs) ──

type Selection = { tokenSymbol: string; chainId: number; tokenInfo: TokenInfo };

function getSelection(data: CheckoutData): Selection | null {
    const tokenInput = $<HTMLInputElement>('[data-token-selector] input[name="token"]:checked')
                      ?? $<HTMLInputElement>('[data-token-selector] input[name="token"]');
    const chainSelect = $<HTMLSelectElement>('[data-chain-selector]');
    if (!tokenInput || !chainSelect) return null;

    const tokenSymbol = tokenInput.value;
    const chainId = parseInt(chainSelect.value, 10);
    const chain = data.available_chains.find(c => c.chain_id === chainId);
    if (!chain) return null;
    const tokenInfo = chain.tokens[tokenSymbol];
    if (!tokenInfo) return null;

    return { tokenSymbol, chainId, tokenInfo };
}

// Wire token radios to add the brand outline on selection.
function bindTokenSelector() {
    const labels = document.querySelectorAll<HTMLLabelElement>('[data-token-selector] label');
    labels.forEach(label => {
        const input = label.querySelector<HTMLInputElement>('input[name="token"]');
        if (!input) return;
        input.addEventListener('change', () => {
            labels.forEach(l => {
                const active = l.querySelector<HTMLInputElement>('input')?.checked === true;
                l.classList.toggle('border-2', active);
                l.classList.toggle('border-brand-500', active);
                l.classList.toggle('bg-brand-50/60', active);
                l.classList.toggle('dark:bg-brand-500/10', active);
                l.classList.toggle('border', !active);
                l.classList.toggle('border-slate-200', !active);
                l.classList.toggle('dark:border-slate-800', !active);
                l.classList.toggle('bg-white', !active);
                l.classList.toggle('dark:bg-slate-900', !active);
                const text = l.querySelector('div');
                text?.classList.toggle('text-brand-700', active);
                text?.classList.toggle('dark:text-brand-400', active);
            });
        });
    });
}

// ─── 6. Bootstrap & state machine ─────────────────────────────────

async function bootstrap(data: CheckoutData) {
    bindTokenSelector();

    const cta = $<HTMLButtonElement>('#settlepay-cta');
    if (!cta) return;

    if (data.invoice.status === 'paid') {
        setCtaLabel('Payment received');
        setCtaDisabled(true);
        return;
    }

    let busy = false; // Prevents double-submit.

    // The CTA button drives the whole flow. Behaviour depends on wallet state.
    cta.addEventListener('click', async () => {
        if (busy) return;
        busy = true;
        try {
            await onCtaClick(data);
        } catch (err: unknown) {
            console.error(err);
            const msg = err instanceof Error ? err.message : 'Something went wrong.';
            showStatus({ text: msg, tone: 'error' });
            setCtaLabel('Try again');
        } finally {
            busy = false;
        }
    });

    // Reflect connected/disconnected state in the CTA label.
    watchAccount(wagmi, {
        onChange(account) {
            updateCtaForAccountState(data, account.status, account.chainId);
        },
    });
    updateCtaForAccountState(data, getAccount(wagmi).status, getAccount(wagmi).chainId);

    // If the page was reopened after the customer already paid in another tab,
    // poll once to surface the success state.
    pollStatus(data, /*onceQuick=*/true);
}

function updateCtaForAccountState(data: CheckoutData, status: string, currentChainId?: number) {
    const sel = getSelection(data);
    if (status !== 'connected' || !sel) {
        setCtaLabel('Connect wallet');
        return;
    }
    if (sel.chainId !== currentChainId) {
        const chainName = data.available_chains.find(c => c.chain_id === sel.chainId)?.name || 'right network';
        setCtaLabel(`Switch to ${chainName}`);
        return;
    }
    const human = (data.invoice.amount_cents / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    setCtaLabel(`Pay ${human} ${sel.tokenSymbol}`);
}

async function onCtaClick(data: CheckoutData) {
    const sel = getSelection(data);
    if (!sel) {
        showStatus({ text: 'Pick a token and network.', tone: 'error' });
        return;
    }

    let acct = getAccount(wagmi);

    // ─── Connect ──────────────────────────────────────────────
    if (acct.status !== 'connected') {
        const connectors = wagmi.connectors;
        // Prefer injected if present, else fall back to WalletConnect.
        const preferred = connectors.find(c => c.type === 'injected') ?? connectors[0];
        if (!preferred) throw new Error('No wallet available. Install MetaMask or another injected wallet.');
        showStatus({ text: 'Opening wallet…', tone: 'pending' });
        await connect(wagmi, { connector: preferred });
        acct = getAccount(wagmi);
    }

    // ─── Switch chain if needed ───────────────────────────────
    const currentChainId = getChainId(wagmi);
    if (currentChainId !== sel.chainId) {
        showStatus({ text: `Switching to ${data.available_chains.find(c => c.chain_id === sel.chainId)?.name}…`, tone: 'pending' });
        await switchChain(wagmi, { chainId: sel.chainId as 8453 | 137 | 42161 | 10 | 84532 | 11155420 | 421614 });
    }

    // ─── Build & submit transfer ──────────────────────────────
    // amount_cents is integer USD cents. Token base units = amount_cents × 10^(decimals - 2).
    // Use viem's parseUnits with the exact decimal string to avoid float rounding.
    const dollars = (data.invoice.amount_cents / 100).toFixed(2);
    const amount = parseUnits(dollars, sel.tokenInfo.decimals);

    showStatus({ text: 'Confirm in your wallet…', tone: 'pending' });
    setCtaLabel('Waiting for signature…');
    setCtaDisabled(true);

    const txHash: Hex = await writeContract(wagmi, {
        chainId: sel.chainId as 8453 | 137 | 42161 | 10 | 84532 | 11155420 | 421614,
        address: sel.tokenInfo.address as Address,
        abi: ERC20_TRANSFER_ABI,
        functionName: 'transfer',
        args: [data.invoice.recipient_address as Address, amount],
    });

    showStatus({ text: 'Transaction submitted. Waiting for confirmations…', txHash, progressPct: 10, tone: 'pending' });
    await reportTxToBackend(data, txHash);

    // ─── Wait for the chain receipt (one confirmation), then start polling
    // our own backend until the listener marks it paid. ──────
    waitForTransactionReceipt(wagmi, { hash: txHash, chainId: sel.chainId as 8453 | 137 | 42161 | 10 | 84532 | 11155420 | 421614 })
        .then(() => {
            showStatus({ text: 'Confirmed on-chain. Finalising…', txHash, progressPct: 60, tone: 'pending' });
        })
        .catch(err => console.warn('[settlepay] waitForTransactionReceipt:', err));

    pollStatus(data);
}

async function reportTxToBackend(data: CheckoutData, txHash: string) {
    try {
        await fetch(data.tx_endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ tx_hash: txHash }),
        });
    } catch (err) {
        // Non-fatal; the listener will still detect the on-chain payment
        // even if our backend never sees the client-reported hash.
        console.warn('[settlepay] tx-report failed (continuing):', err);
    }
}

// ─── 7. Status polling ────────────────────────────────────────────

let pollTimer: number | null = null;

function pollStatus(data: CheckoutData, onceQuick = false) {
    if (pollTimer !== null) return;

    const tick = async () => {
        try {
            const res  = await fetch(data.polling.url, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error(`status ${res.status}`);
            const json = await res.json() as { data: { status: string; paid_at: string | null } };
            if (json.data.status === 'paid') {
                showStatus({ text: 'Payment received. Receipt sent to your email.', progressPct: 100, tone: 'success' });
                setCtaLabel('Payment received');
                setCtaDisabled(true);
                stopPolling();
                return;
            }
            if (json.data.status === 'partially_paid') {
                showStatus({ text: 'Partial payment received — sending the rest will complete the invoice.', tone: 'pending' });
            }
        } catch (err) {
            console.warn('[settlepay] poll:', err);
        }
        if (!onceQuick) {
            pollTimer = window.setTimeout(tick, data.polling.interval_ms);
        }
    };
    if (onceQuick) {
        tick();
    } else {
        pollTimer = window.setTimeout(tick, data.polling.interval_ms);
    }
}

function stopPolling() {
    if (pollTimer !== null) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }
}
