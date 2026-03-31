# WooCommerce UCP Plugin - Constitution

## Purpose

This document defines the governing principles, values, and non-negotiable rules
that guide the development and operation of the WooCommerce UCP plugin.

---

## 1. Mission

Enable any WooCommerce store to participate in the agentic commerce ecosystem
by implementing the Universal Commerce Protocol (UCP) and Agent Payments Protocol
(AP2) standards — making the store discoverable, negotiable, and transactable by
AI agents.

## 2. Core Values

### 2.1 Spec Compliance First

The UCP specification is the source of truth. All implementation decisions MUST
align with the published spec at [ucp.dev](https://ucp.dev). When the spec
evolves, the plugin MUST be updated to maintain compliance.

### 2.2 Security Is Non-Negotiable

- All API keys and tokens MUST be stored hashed (SHA-256 minimum), never plaintext
- All endpoints serving commerce data MUST require HTTPS (TLS 1.3+)
- All user inputs MUST be validated and sanitized before processing
- Authentication MUST be enforced on all checkout and customer operations
- Rate limiting MUST be active on all endpoints
- Session ownership MUST be verified on every access
- No sensitive data in error messages or logs

### 2.3 Leverage WooCommerce, Don't Replace It

This plugin extends WooCommerce — it does NOT bypass it. All commerce operations
MUST flow through WooCommerce's native APIs:

- Products via `wc_get_product()`
- Orders via `wc_create_order()` and WC Order objects
- Shipping via `WC_Shipping` zones and methods
- Payments via WC payment gateways
- Customers via `WC_Customer`
- Tax via `$order->calculate_totals()`
- Coupons via `WC_Coupon` and `$order->apply_coupon()`

This ensures compatibility with WooCommerce's ecosystem of extensions, reports,
and admin tools.

### 2.4 Registered Users Only

Only registered WooCommerce customers can complete purchases through UCP.
Anonymous checkout is NOT supported. Agents MUST use the customer lookup and
registration endpoints to ensure a valid customer account exists before
completing a checkout session.

### 2.5 Open Standards

This plugin implements open, non-proprietary protocols:

- UCP (Universal Commerce Protocol) — Apache 2.0
- AP2 (Agent Payments Protocol) — Apache 2.0
- OAuth 2.0 (RFC 6749) with PKCE (RFC 7636)
- Token Revocation (RFC 7009)
- JSON-RPC 2.0 for MCP binding
- ISO 4217 for currency codes

### 2.6 Privacy by Design

- PII (buyer information) is stored only in WooCommerce orders, following WC's
  existing data retention policies
- No buyer data is transmitted to external services beyond what WooCommerce
  already sends to payment gateways
- Agent profile URLs are stored but never fetched without explicit purpose
- Audit logs redact sensitive fields (tokens, keys, emails)

## 3. Architecture Rules

### 3.1 No Global Cart Manipulation

WooCommerce's cart is session-based and designed for browser use. This plugin
MUST NOT touch `WC()->cart`. Shipping rates are calculated by building package
arrays directly. Tax is calculated via `$order->calculate_totals()`.

### 3.2 Transport Agnosticism

The core business logic (session management, order mapping, validation) MUST
be independent of the transport layer. Both the REST API and MCP binding call
the same underlying service methods. Adding new transports (A2A, embedded)
MUST NOT require duplicating business logic.

### 3.3 Clean Separation of Concerns

| Layer | Responsibility |
|---|---|
| Controllers (rest/, mcp/) | HTTP/RPC request handling, response formatting |
| Checkout (checkout/) | Session state machine, UCP Card building, fulfillment |
| Auth (auth/) | Authentication, authorization, token management |
| Payments (payments/) | AP2 mandate verification, PSP gateway bridging, delegated PSP intent helper |
| Security (security/) | Validation, rate limiting, logging |
| Discovery (discovery/) | Manifest generation, capability negotiation |
| Admin (admin/) | Settings UI, API key management |

### 3.4 Database Integrity

- All DB queries MUST use `$wpdb->prepare()` for parameterized queries
- Custom tables are created via `dbDelta()` on activation
- Clean uninstall removes all custom tables, options, and transients
- Session expiry is enforced via scheduled cleanup

### 3.5 Backward Compatibility

- Plugin requires WordPress 6.0+ and WooCommerce 7.0+
- PHP 7.4+ minimum
- No dependencies on specific payment gateway plugin versions
- Payment bridge gracefully handles missing gateways

## 4. Development Standards

### 4.1 Code Style

- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- Use WordPress naming conventions (snake_case for functions, Title_Case for classes)
- All classes prefixed with `WC_UCP_` for namespace isolation
- All functions prefixed with `wc_ucp_` to avoid conflicts

### 4.2 Testing

- Unit tests for all service classes (state machine, card builder, validators)
- Integration tests for full checkout flow via REST and MCP
- Security tests for auth bypass, injection, and session isolation
- All PRs must pass existing tests before merge

### 4.3 Logging

- All checkout lifecycle events MUST be logged (create, update, complete, cancel)
- All auth events MUST be logged (success, failure, revocation)
- All AP2 mandate events MUST be logged (validation, processing, errors)
- Logs use WooCommerce's `wc_get_logger()` with source `wc-ucp`
- Sensitive data MUST be redacted in logs

## 5. Contributor Agreement

By contributing to this project, you agree that:

1. Your contributions will be licensed under the MIT License
2. You will follow the architecture rules and development standards above
3. You will not introduce dependencies on proprietary services or protocols
4. Security vulnerabilities will be disclosed responsibly and fixed promptly

## 6. Versioning

This plugin follows [Semantic Versioning](https://semver.org/):

- **MAJOR** — Breaking changes to the UCP API contract
- **MINOR** — New capabilities, extensions, or features
- **PATCH** — Bug fixes and security patches

The UCP spec version tracked by the plugin is independent of the plugin version
and is declared in the `WC_UCP_SPEC_VERSION` constant.

---

## Authors

**Eduardo Arana & Soda**

This constitution is a living document. Changes require review and approval
from the project maintainers.
