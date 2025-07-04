# zientkowski.pl

This repository contains the public website for **Jerzy Zientkowski**.

## Configuration

The site uses `config.json` for runtime settings. To enable Przelewy24 payments you must provide your merchant credentials in this file (or via `config.html`). Without these values payment initialization will fail and meeting reservations will be saved without redirecting to the payment gateway.

1. Open `config.html` in your browser.
2. Fill in `Merchant ID`, `Pos ID`, `CRC` and adjust the sandbox flag.
3. Click **Zapisz** to update `config.json`.

After configuration the `Warsztaty online` option will redirect to Przelewy24 where the payment can be completed.

### Debugging payments

To inspect the raw response from the Przelewy24 API enable the "Pokaż debug box"
option in `config.html`. When a payment request fails the response payload will
be logged in this box.

Additionally `backend/p24_init.php` and `backend/p24_return.php` append the sent
request and raw response from Przelewy24 to the file `backend/p24_debug.log`.
Check this log on the server for low level details.

