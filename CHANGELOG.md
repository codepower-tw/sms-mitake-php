# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-06-01

Initial release.

### Added

- `Client` for the Mitake (三竹簡訊) SMS HTTP API, covering:
  - single send, with optional scheduling, validity window,
    delivery-receipt callback URL, and de-duplication client id;
  - bulk send;
  - delivery-status query;
  - account balance query;
  - cancellation of scheduled messages.
- `Callback` handler for parsing the delivery-receipt callback.
- `Message` and result value objects, response parser, and `StatusCode`.
- UTF-8 and Big5 charset support (Big5 requires `ext-mbstring`).
- Pluggable `Http\HttpClient` transport, defaulting to a cURL client.
- `Segmentation` helper for counting the SMS segments a message uses.
- Runnable usage example (`example.php`) and documentation.

[Unreleased]: https://github.com/codepower-tw/sms-mitake-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/codepower-tw/sms-mitake-php/releases/tag/v0.1.0
