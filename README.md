# WooCommerce UCP - Universal Commerce Protocol

**Contributors:** Eduardo Arana & Soda
**License:** GPL-2.0-or-later
**Requires WordPress:** 6.0+
**Requires WooCommerce:** 7.0+
**Requires PHP:** 7.4+
**UCP Spec Version:** 2026-01-23

## Description

WooCommerce UCP implements the [Universal Commerce Protocol](https://ucp.dev) for WooCommerce stores, enabling AI agents to discover, negotiate, and complete transactions with your store via standardized APIs.

### Features

- **UCP Discovery** at `/.well-known/ucp` — AI agents auto-discover your store's capabilities
- **REST API** — Full checkout session lifecycle (create, update, complete, cancel)
- **MCP Binding** — JSON-RPC 2.0 transport for LLM-native tool calling
- **AP2 Payment Support** — Agent Payments Protocol mandate verification with Stripe/PayPal bridge
- **OAuth 2.0 Identity Linking** — RFC 6749 with PKCE (RFC 7636) support
- **API Key Authentication** — Simple key-based auth for agent integrations
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

- **API Key:** Send `X-API-Key` header
- **OAuth 2.0:** Send `Authorization: Bearer {token}` header
- Discovery and product catalog endpoints are public by default

## Installation

1. Upload the `woocommerce-ucp` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Go to WooCommerce > Settings > UCP to configure
4. Generate API keys for your AI agent integrations

## Sources

- [UCP Specification](https://ucp.dev/specification/overview/)
- [AP2 Protocol](https://ap2-protocol.org/)
- [UCP GitHub](https://github.com/Universal-Commerce-Protocol/ucp)
