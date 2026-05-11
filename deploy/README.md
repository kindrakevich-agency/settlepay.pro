# Production deployment artifacts

Files used in the live Settlepay deployment on Hetzner. Treat as
templates — adjust paths/users for your environment.

## `systemd/settle-listener.service`

The on-chain listener daemon. Polls Base/Polygon/Arbitrum/Optimism (or
their testnets, with `--testnet`) every 5 seconds, decodes ERC-20
Transfer events to allowlisted recipients, and matches them to open
invoices.

### Install

```bash
# Copy the unit file to systemd
sudo cp deploy/systemd/settle-listener.service /etc/systemd/system/

# Make sure the log directory exists and is writable by the runtime user
sudo mkdir -p /var/log/settle
sudo chown www:www /var/log/settle
sudo chmod 755 /var/log/settle

# Enable + start
sudo systemctl daemon-reload
sudo systemctl enable --now settle-listener.service
```

### Verify

```bash
sudo systemctl status settle-listener.service        # active (running)
sudo tail -f /var/log/settle/listener.log            # live logs
sudo journalctl -u settle-listener.service -f        # full systemd journal
```

### Operate

```bash
sudo systemctl restart settle-listener.service       # after a deploy
sudo systemctl stop    settle-listener.service       # maintenance
sudo systemctl disable settle-listener.service       # turn off permanently
```

### Mainnet vs testnet

Prod runs **two listener units side by side**:

- `settle-listener.service` — mainnet only (the shipped unit, no `--testnet` flag). Watches Base, Polygon, Arbitrum, Optimism.
- `settle-listener-testnet.service` — copies the unit, adds `--testnet` to `ExecStart=`. Watches Base Sepolia, Optimism Sepolia, Arbitrum Sepolia. Used for staging + dev dogfooding via `BILLING_ALLOW_TESTNETS=1`.

Both listeners share the same database and platform wallet matcher — they just look at different chain IDs. The deploy workflow `restart`s both with one glob:

```bash
sudo systemctl restart 'settle-listener*.service'
```

This avoids the stale-cache bug where one listener kept running with the previous compiled-container hash after a deploy (see `memory/gotchas.md`).

### Messenger worker

Async jobs (invitation emails, webhook deliveries, PDF rendering) run on `settle-worker@N.service`, a templated unit. Enable one or two instances:

```bash
sudo systemctl enable --now settle-worker@1
sudo systemctl enable --now settle-worker@2
```
