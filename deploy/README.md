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

The shipped unit runs `--testnet`. Once mainnet invoices exist, swap
the `ExecStart=` line to drop `--testnet` (which means it watches
all configured mainnets). To watch BOTH simultaneously, copy the unit
to `settle-listener-testnet.service`, leave the original on mainnet,
and enable both.
