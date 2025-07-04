# zientkowski.pl

This repository contains the public website for **Jerzy Zientkowski**.

## Configuration

The site uses `config.json` for runtime settings. To enable Przelewy24 payments you must provide your merchant credentials in this file (or via `config.html`). Without these values payment initialization will fail and meeting reservations will be saved without redirecting to the payment gateway.

1. Open `config.html` in your browser.
2. Fill in `Merchant ID`, `Pos ID`, `CRC` and adjust the sandbox flag.
3. Click **Zapisz** to update `config.json`.

After configuration the `Warsztaty online` option will redirect to Przelewy24 where the payment can be completed.

