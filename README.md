# zientkowski.pl

This repository contains the public website for **Jerzy Zientkowski**.

## Configuration

The site uses `config.json` for runtime settings. Open `config.html` in your
browser to edit the available meeting types and toggle the debug box.


## WTL payment testing

The page `wtl_payment.html` embeds the payment widget provided by WTL. Two
simple webhook endpoints are available for debugging:

- `backend/wtl_before.php` &ndash; called before payment.
- `backend/wtl_after.php` &ndash; called after payment.

Both scripts append the received payload to `wtl_before.log` or
`wtl_after.log` respectively.
