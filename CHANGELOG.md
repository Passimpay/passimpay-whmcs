# Changelog

## v1.0.4 — Security logging hardening

- Added `Debug Logging` gateway setting to control verbose logs
- Callback logs now store a safe minimal context by default (`eventType`, `platformId`, `orderId`, `paymentId`, `txhash`)
- Full webhook payload is logged only when debug mode is enabled
- Signature is masked in debug logs; raw signature and raw body are no longer logged

## v1.0.3 — UX and metadata polish

- Payment gateway settings: `Notification URL` restored as non-editable info row (no unnecessary input field)
- Apps & Integrations metadata moved into module directory and updated to use PNG logo for reliable rendering
- Added module-level `whmcs.json` and `logo.png` to distribution package
- Release builder updated to include module metadata assets in ZIP

## v1.0.2 — Distribution tooling

- Added `scripts/build-release.sh` to build release ZIP and SHA256 checksum
- Added `INSTALL_GUIDE.md` for client-facing install/update instructions
- Added `install-check.php` to validate server prerequisites and file layout quickly
- Updated README with distribution flow and diagnostics instructions

## v1.0.1 — Package layout

- Repository layout matches WHMCS: `modules/gateways/passimpay.php`, `modules/gateways/callback/passimpay.php`, `modules/gateways/passimpay/PassimpayMerchantAPI.php`
- Webhook callback: reject callbacks when `platformId` in payload does not match gateway configuration (after signature check)
- `whmcs.json`: logo file should be placed in package root next to `whmcs.json`; not required on WHMCS server for payment processing

## v1.0.0 — Initial Release

- Payment gateway module for WHMCS (Third Party Gateway type)
- Invoice Link method via PassimPay v2 API (`/v2/createorder`)
- Automatic payment processing via webhook callbacks
- HMAC-SHA256 signature verification on all API calls and webhooks
- Double-check payment status via `/v2/orderstatus` API on webhook receipt
- Configurable payment types: Card + Crypto, Crypto Only, Card Only
- Client information forwarding (name, email) for fraud prevention
- Full Gateway Log integration for admin debugging
- Partial payment handling (logged but not marked as paid until fully paid)
