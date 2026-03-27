# CLAUDE.md - WooCommerce UCP Plugin

## Project Overview

This is a WordPress/WooCommerce plugin implementing the **Universal Commerce Protocol (UCP)** v2026-01-23 with **AP2 (Agent Payments Protocol)** support. It enables AI agents to discover, negotiate, and complete purchases on WooCommerce stores.

**Developer:** Eduardo Arana & Soda
**License:** MIT
**Branch:** `claude/woocommerce-ucp-integration-2Ccd8`

## Quick Reference

### Plugin Structure

```
woocommerce-ucp.php          → Main bootstrap (checks WC, autoloader, hooks)
includes/
  class-wc-ucp-plugin.php    → Singleton orchestrator
  class-wc-ucp-activator.php → DB table creation (5 tables)
  discovery/                  → /.well-known/ucp manifest + negotiation
  rest/                       → REST controllers (checkout, products, customers)
  mcp/                        → JSON-RPC 2.0 handler (wraps REST)
  checkout/                   → Session state machine, UCP Card builder, fulfillment
  auth/                       → API key + OAuth 2.0 (PKCE, token rotation)
  payments/                   → AP2 mandates + Stripe/PayPal/WCPay bridge
  security/                   → Validator, rate limiter, audit logger
admin/                        → WooCommerce settings tab + API key UI
```

### Key Constants

- `WC_UCP_VERSION` = `1.0.0`
- `WC_UCP_SPEC_VERSION` = `2026-01-23`
- REST namespace: `ucp/v1`

### Database Tables (prefixed with `{wpdb->prefix}`)

| Table | Purpose |
|---|---|
| `ucp_checkout_sessions` | Session state machine (links to WC orders) |
| `ucp_api_keys` | SHA-256 hashed API keys |
| `ucp_oauth_tokens` | OAuth access/refresh tokens |
| `ucp_oauth_codes` | Authorization codes (5-min TTL) |
| `ucp_oauth_clients` | Registered OAuth clients |

### REST Endpoints

| Method | Route | Auth | Purpose |
|---|---|---|---|
| GET | `/.well-known/ucp` | Public | Discovery manifest |
| POST | `/ucp/v1/checkout-sessions` | Required | Create session |
| GET | `/ucp/v1/checkout-sessions/{id}` | Required | Get session |
| PUT | `/ucp/v1/checkout-sessions/{id}` | Required | Update session |
| POST | `/ucp/v1/checkout-sessions/{id}/complete` | Required | Complete order |
| POST | `/ucp/v1/checkout-sessions/{id}/cancel` | Required | Cancel session |
| GET | `/ucp/v1/products` | Public* | List products |
| GET | `/ucp/v1/products/{id}` | Public* | Get product |
| POST | `/ucp/v1/customers/lookup` | Required | Check customer exists |
| POST | `/ucp/v1/customers/register` | Required | Register new customer |
| GET | `/ucp/v1/customers/me` | Required | Get profile |
| POST | `/ucp/v1/mcp` | Required | MCP JSON-RPC 2.0 |
| GET | `/ucp/v1/oauth/authorize` | WP Login | OAuth authorization |
| POST | `/ucp/v1/oauth/token` | Public | Token exchange |
| POST | `/ucp/v1/oauth/revoke` | Public | Token revocation |

*Public access configurable in admin settings.

## Development Guidelines

### Architecture Rules

1. **NEVER manipulate `WC()->cart`** — Use WC Order objects and direct shipping calculation
2. **All commerce through WooCommerce native APIs** — `wc_get_product()`, `wc_create_order()`, `WC_Shipping`, etc.
3. **Transport-agnostic business logic** — REST and MCP both call the same session/checkout logic
4. **Auth required for checkout** — Only registered WC customers can purchase
5. **All DB queries via `$wpdb->prepare()`** — No raw SQL interpolation

### Security Checklist

- [ ] API keys stored as SHA-256 hashes only
- [ ] OAuth tokens hashed, rotated on refresh
- [ ] All inputs sanitized (`sanitize_text_field()`, `sanitize_email()`, `absint()`)
- [ ] Session ownership verified on every access
- [ ] Rate limiting active (60/min authenticated, 30/min unauthenticated)
- [ ] No sensitive data in error responses
- [ ] PKCE enforced for OAuth (S256 only)

### Checkout Flow

```
Agent creates session (POST /checkout-sessions)
  → Status: incomplete (messages say what's missing)

Agent updates session (PUT /checkout-sessions/{id})
  → Adds buyer info, shipping address, selects fulfillment
  → Status transitions: incomplete → ready_for_complete

Agent completes checkout (POST /checkout-sessions/{id}/complete)
  → AP2 mandate verified → Payment processed via WC gateway
  → WC order: draft → processing
  → Status: completed
```

### WooCommerce Mapping

| UCP Concept | WC Implementation |
|---|---|
| Checkout Session | WC Order (checkout-draft status) |
| Line Items | `$order->add_product()` |
| Buyer | `$order->set_billing_*()` / `set_shipping_*()` |
| Fulfillment | `WC_Shipping::calculate_shipping()` |
| Discounts | `$order->apply_coupon()` |
| Totals | `$order->calculate_totals()` |
| Payment | WC Payment Gateway `process_payment()` |

### Common Tasks

**Check PHP syntax:**
```bash
find . -name '*.php' -not -path './.git/*' -exec php -l {} \;
```

**Generate API key (programmatic):**
```php
$result = WC_UCP_API_Keys::generate( 'My Agent', 'read_write', $customer_id );
// $result['key'] contains the raw key (show once)
```

**Test discovery endpoint:**
```bash
curl -s https://yourstore.com/.well-known/ucp | jq .
```

**Test checkout flow:**
```bash
# Create session
curl -X POST https://yourstore.com/wp-json/ucp/v1/checkout-sessions \
  -H "X-API-Key: ucp_..." \
  -H "Content-Type: application/json" \
  -d '{"line_items":[{"item":{"id":"123"},"quantity":1}],"currency":"USD"}'
```

### Adding a New UCP Capability

1. Add capability ID to `WC_UCP_Discovery::get_capabilities()`
2. Add extension (if applicable) to `WC_UCP_Discovery::get_extensions()`
3. Create handler class in appropriate `includes/` subdirectory
4. Wire into `WC_UCP_Checkout_Controller` or create new controller
5. Add MCP tool mapping in `WC_UCP_MCP_Handler::$method_map`
6. Update `WC_UCP_Negotiation::$merchant_capabilities`
7. Add admin toggle in `WC_UCP_Admin::get_settings()`

### Key Spec References

- [UCP Spec Overview](https://ucp.dev/specification/overview/)
- [Checkout REST Binding](https://ucp.dev/specification/checkout-rest/)
- [MCP Binding](https://ucp.dev/specification/checkout-mcp/)
- [AP2 Protocol](https://ap2-protocol.org/)
- [UCP GitHub](https://github.com/Universal-Commerce-Protocol/ucp)
