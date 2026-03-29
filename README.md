# WooCommerce UCP - Universal Commerce Protocol

**Developers:** Eduardo Arana and Soda 🥤
**License:** MIT
**Requires WordPress:** 6.0+
**Requires WooCommerce:** 7.0+
**Requires PHP:** 7.4+
**UCP Spec Version:** 2026-01-23

> **Disclaimer:** This project is an independent, community-driven plugin and has no affiliation with WooCommerce or Automattic.

## Description

WooCommerce UCP implements the [Universal Commerce Protocol](https://ucp.dev) for WooCommerce stores, enabling AI agents to discover, negotiate, and complete transactions with your store via standardized APIs.

### Features

- **UCP Discovery** at `/.well-known/ucp` — AI agents auto-discover your store's capabilities
- **REST API** — Full checkout session lifecycle (create, update, complete, cancel)
- **MCP Binding** — JSON-RPC 2.0 transport for LLM-native tool calling
- **AP2 Payment Support** — Agent Payments Protocol mandate verification with Stripe/PayPal bridge
- **OAuth 2.0 Identity Linking** — RFC 6749 with PKCE (RFC 7636) support
- **API Key Authentication** — Simple key-based auth for agent integrations
- **OAuth Client Management** — Point-and-click UI to register clients and redirect URIs
- **Product Catalog** — AI-optimized product browsing with search, filters, and variations
- **Customer Management** — Lookup and registration (only registered users can purchase)
- **Rate Limiting** — Configurable per-key and per-IP rate limits
- **Admin Settings** — WooCommerce settings tab for full configuration

### UCP Capabilities Supported

| Capability | Description |
|---|---|
| `dev.ucp.shopping.checkout` | Full checkout session management |
| `dev.ucp.shopping.fulfillment` | Shipping method calculation and selection |
| `dev.ucp.shopping.discount` | Coupon code application |
| `dev.ucp.shopping.order` | Order lifecycle management |

### REST API Endpoints

| Method | Endpoint | Description |
|---|---|---|
| GET | `/.well-known/ucp` | Discovery manifest |
| POST | `/wp-json/ucp/v1/checkout-sessions` | Create checkout session |
| GET | `/wp-json/ucp/v1/checkout-sessions/{id}` | Get session status |
| PUT | `/wp-json/ucp/v1/checkout-sessions/{id}` | Update session |
| POST | `/wp-json/ucp/v1/checkout-sessions/{id}/complete` | Complete checkout |
| POST | `/wp-json/ucp/v1/checkout-sessions/{id}/cancel` | Cancel session |
| GET | `/wp-json/ucp/v1/products` | List products |
| GET | `/wp-json/ucp/v1/products/{id}` | Get product details |
| POST | `/wp-json/ucp/v1/customers/lookup` | Check if customer exists |
| POST | `/wp-json/ucp/v1/customers/register` | Register new customer |
| GET | `/wp-json/ucp/v1/customers/me` | Get authenticated profile |
| POST | `/wp-json/ucp/v1/mcp` | MCP JSON-RPC 2.0 endpoint |

### Authentication

- **API Key:** Send `X-API-Key` header (keys may be scoped to a WooCommerce customer or left unassigned for guest checkouts)
- **OAuth 2.0:** Send `Authorization: Bearer {token}` header; customers see a built-in consent screen after logging in so they can approve or deny access before tokens issue
- Discovery and product catalog endpoints are public by default

## Installation

1. Upload the `woocommerce-ucp` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Go to WooCommerce > Settings > UCP to configure
4. Generate API keys for your AI agent integrations

### CDN / WAF Considerations

If you proxy your store through a CDN or Web Application Firewall (Cloudflare, Fastly, etc.), make sure that requests to the REST namespace are **never cached** and that cookies are forwarded. In particular, `/wp-json/ucp/*` must reach WordPress with the `wordpress_logged_in_*` cookie intact so the OAuth authorize endpoint can detect logged-in users. Create a bypass rule for `https://<your-domain>/wp-json/*` (or Host = `<your-domain>`, Path starts with `/wp-json/`) and disable any “strip cookies” or login-challenge features for that route. Otherwise OAuth flows will loop on the login page and session-aware endpoints will fail.

## Sources

- [UCP Specification](https://ucp.dev/specification/overview/)
- [AP2 Protocol](https://ap2-protocol.org/)
- [UCP GitHub](https://github.com/Universal-Commerce-Protocol/ucp)
