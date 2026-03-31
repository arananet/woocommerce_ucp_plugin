# WooCommerce UCP Plugin - Specification Reference

## UCP Spec Version: 2026-01-23

This document captures the Universal Commerce Protocol (UCP) specification
details that this plugin implements, based on the official spec at
[ucp.dev](https://ucp.dev/specification/overview/).

---

## 1. Discovery (/.well-known/ucp)

Merchants MUST publish a JSON manifest at `/.well-known/ucp` on their domain.
This manifest declares everything an agent needs: supported services,
capabilities, extensions, payment handlers, and transports.

### Manifest Structure

```json
{
  "ucp": {
    "version": "2026-01-23",
    "spec": "https://ucp.dev/2026-01-23/specification/overview/",
    "supported_versions": {
      "draft": "https://example.com/.well-known/ucp/draft",
      "2026-01-23": "https://example.com/.well-known/ucp/2026-01-23"
    },
    "services": {
      "dev.ucp.shopping": [
        {
          "id": "dev.ucp.shopping",
          "version": "2026-01-23",
          "spec": "https://ucp.dev/2026-01-23/specification/overview/",
          "capabilities": [
            "dev.ucp.shopping.checkout",
            "dev.ucp.shopping.fulfillment",
            "dev.ucp.shopping.discount",
            "dev.ucp.shopping.order"
          ],
          "extensions": [
            "dev.ucp.shopping.fulfillment",
            "dev.ucp.shopping.discount"
          ],
          "payment_handlers": ["stripe"],
          "endpoints": {
            "rest": "https://example.com/wp-json/ucp/v1",
            "mcp": "https://example.com/wp-json/ucp/v1/mcp"
          },
          "schemas": {
            "rest": "https://ucp.dev/services/shopping/rest.openrpc.json",
            "mcp": "https://ucp.dev/services/shopping/openrpc.json"
          }
        }
      ]
    },
    "capabilities": {
      "dev.ucp.shopping.checkout": [
        {
          "version": "2026-01-23",
          "spec": "https://ucp.dev/2026-01-23/specification/checkout-rest/",
          "schema": "https://ucp.dev/2026-01-23/schemas/shopping/checkout.json"
        }
      ],
      "dev.ucp.shopping.fulfillment": [
        {
          "version": "2026-01-23",
          "spec": "https://ucp.dev/2026-01-23/specification/fulfillment",
          "schema": "https://ucp.dev/2026-01-23/schemas/shopping/fulfillment.json",
          "extends": "dev.ucp.shopping.checkout"
        }
      ],
      "dev.ucp.shopping.discount": [
        {
          "version": "2026-01-23",
          "spec": "https://ucp.dev/2026-01-23/specification/discount",
          "schema": "https://ucp.dev/2026-01-23/schemas/shopping/discount.json",
          "extends": "dev.ucp.shopping.checkout"
        }
      ],
      "dev.ucp.shopping.order": [
        {
          "version": "2026-01-23",
          "spec": "https://ucp.dev/2026-01-23/specification/order",
          "schema": "https://ucp.dev/2026-01-23/schemas/shopping/order.json"
        }
      ]
    },
    "payment_handlers": {
      "stripe": [
        {
          "id": "stripe",
          "type": "psp",
          "version": "2026-01-23",
          "spec": "https://ucp.dev/payment-handlers/stripe",
          "schema": "https://ucp.dev/payment-handlers/stripe/config.json",
          "config": {}
        }
      ]
    }
  },
  "business": {
    "name": "Store Name",
    "url": "https://example.com"
  },
  "authentication": {
    "methods": ["api_key", "oauth2"]
  }
}
```

Each service entry consolidates its transports under the `endpoints` object and
lists negotiated `capabilities`/`extensions`, giving agents a single record to
reason about before calling REST or MCP tools.

### Capability Naming

UCP uses reverse-domain naming: `dev.ucp.shopping.*` is hosted at ucp.dev.

---

## 2. Checkout REST API (HTTP/REST Binding)

All UCP REST endpoints are relative to the business's base URL discovered
via the `/.well-known/ucp` profile.

### Transport Requirements

- All endpoints MUST be served over HTTPS with minimum TLS 1.3
- Requests and responses MUST use `application/json` (RFC 8259)
- Implementations MUST use standard HTTP verbs and status codes

### Endpoints

#### POST /checkout-sessions — Create Session

Creates a new checkout session with line items and optional buyer info.

**Request:**
```json
{
  "line_items": [
    { "item": { "id": "123" }, "quantity": 2 }
  ],
  "buyer": {
    "email": "jane@example.com",
    "first_name": "Jane",
    "last_name": "Doe"
  },
  "currency": "USD"
}
```

For compatibility with lightweight agents, the plugin also accepts the shorthand
`{"id": 123, "quantity": 2}` form for `line_items`; it is normalized to the
canonical `item.id` structure shown above before validation.

**Response (201 Created):** UCP Card (see Section 4)

#### PUT /checkout-sessions/{id} — Update Session

Full replacement of the checkout session state. Clients MUST include all
previously set fields they wish to retain.

**Response (200):** Updated UCP Card

#### GET /checkout-sessions/{id} — Get Session

Retrieve current state of a checkout session.

**Response (200):** UCP Card

#### POST /checkout-sessions/{id}/complete — Complete Checkout

Finalize the checkout and place the order. Only valid when status is
`ready_for_complete`.

**Response (200):** UCP Card with status `completed`

#### POST /payments/intent — Delegated PSP Token

Creates a short-lived payment credential (currently Stripe PaymentIntent) using
the merchant's installed WooCommerce gateway. Agents can call this endpoint
after collecting shipping info to obtain a token they can immediately pass to
`/checkout-sessions/{id}/complete`.

**Request Body**

```json
{
  "amount": 129.99,
  "currency": "USD",
  "gateway": "stripe" // optional when only one supported gateway is active
}
```

**Response (201)**

```json
{
  "payment_method": "stripe",
  "payment_token": {
    "gateway": "stripe",
    "value": "pi_3OyUWZ...",
    "intent_id": "pi_3OyUWZ..."
  },
  "client_secret": "pi_3OyUWZ_secret_...",
  "status": "requires_payment_method",
  "expires_at": 1774970400
}
```

Agents MUST pass the `payment_token` object verbatim in the `payment`
payload for `/checkout-sessions/{id}/complete`. WooCommerce UCP stores the
intent ID (`_stripe_intent_id`) and lets the native Stripe gateway capture it.
Future revisions will include PayPal and WooCommerce Payments under the same
endpoint signature.

#### POST /checkout-sessions/{id}/cancel — Cancel Session

Cancel a checkout session. Valid from any non-terminal status.

**Response (200):** UCP Card with status `canceled`

---

## 3. Checkout Status State Machine

```
                    ┌─────────────┐
                    │  incomplete  │ ◄── Initial state
                    └──────┬──────┘
                           │
              ┌────────────┼────────────┐
              ▼            ▼            │
    ┌──────────────┐  ┌──────────────────────┐
    │ requires_     │  │  ready_for_complete  │
    │ escalation    │  └──────────┬───────────┘
    └──────┬───────┘             │
           │                     ▼
           │              ┌─────────────┐
           │              │  completed   │ ── Terminal
           │              └─────────────┘
           │
           ▼              ┌─────────────┐
    ┌──────────────┐      │  canceled    │ ── Terminal
    │ (any state)  │─────►│             │
    └──────────────┘      └─────────────┘
```

### Status Values

| Status | Description |
|---|---|
| `incomplete` | Required information is missing (messages indicate what) |
| `requires_escalation` | Needs info that can't be collected via UCP fields (human input required) |
| `ready_for_complete` | All required info provided; agent can finalize programmatically |
| `completed` | Order successfully placed |
| `canceled` | Session was canceled |

### Message Severity Levels

| Severity | Meaning |
|---|---|
| `recoverable` | Agent can fix this by providing the missing/corrected data |
| `requires_buyer_input` | Requires human intervention (e.g., custom fields, 3DS) |
| `requires_buyer_review` | Buyer should review before proceeding |

---

## 4. UCP Card (Checkout Response Object)

The UCP Card is the standardized response object returned by all checkout
endpoints. Required fields per the specification:

```json
{
  "ucp": {
    "version": "2026-01-23",
    "capabilities": ["dev.ucp.shopping.checkout"],
    "extensions": ["dev.ucp.shopping.fulfillment", "dev.ucp.shopping.discount"]
  },
  "id": "chk_abc123",
  "status": "incomplete",
  "currency": "USD",
  "buyer": {
    "email": "jane@example.com",
    "first_name": "Jane",
    "last_name": "Doe",
    "shipping_address": {
      "address_1": "123 Main St",
      "city": "Anytown",
      "state": "CA",
      "postcode": "90210",
      "country": "US"
    }
  },
  "line_items": [
    {
      "id": "li_001",
      "item": {
        "id": "456",
        "title": "Product Name",
        "price": { "amount": "29.99", "currency": "USD" },
        "image_url": "https://...",
        "sku": "SKU-001"
      },
      "quantity": 2,
      "totals": { "subtotal": "59.98", "total": "59.98", "tax": "4.80" }
    }
  ],
  "fulfillment": {
    "methods": [
      {
        "id": "shipping_1",
        "line_item_ids": ["li_001"],
        "groups": [
          {
            "id": "package_1",
            "options": [
              { "id": "flat_rate:1", "label": "Flat Rate", "amount": "5.00" },
              { "id": "free_shipping:2", "label": "Free Shipping", "amount": "0.00" }
            ],
            "selected_option_id": "flat_rate:1"
          }
        ]
      }
    ]
  },
  "totals": {
    "subtotal": "59.98",
    "shipping": "5.00",
    "tax": "4.80",
    "discount": "0.00",
    "total": "69.78"
  },
  "messages": [
    {
      "type": "validation",
      "code": "missing_field",
      "path": "buyer.shipping_address",
      "content": "Shipping address is required",
      "severity": "recoverable"
    }
  ],
  "links": {
    "self": "https://example.com/wp-json/ucp/v1/checkout-sessions/chk_abc123",
    "continue_url": "https://example.com/checkout/?ucp_session=chk_abc123"
  }
}
```

### Required Fields

| Field | Type | Description |
|---|---|---|
| `ucp` | object | Protocol metadata (version, capabilities, extensions) |
| `id` | string | Unique session identifier |
| `status` | string | Current status (see state machine) |
| `currency` | string | ISO 4217 currency code |
| `line_items` | array | Items in the checkout |
| `totals` | object | Order totals (subtotal, shipping, tax, discount, total) |
| `links` | object | Hypermedia links (self, continue_url) |

### Address & Fulfillment Defaults

To keep agent UX simple, the plugin layers a few automatic behaviors on top of
the base specification:

- **Billing → Shipping mirroring:** When the buyer only provides billing
  details, WooCommerce still requires a shipping destination for physical
  products. The controller copies the billing name/company/address into the
  shipping fields as soon as postage is needed, ensuring fulfillment validation
  can succeed without extra prompts.
- **Default shipping selection:** After an address is known and at least one
  rate is available, the first WooCommerce rate is attached to the order. This
  keeps `fulfillment.methods[].groups[].selected_option_id` populated and lets
  the session graduate to `ready_for_complete` even if the agent never sends a
  manual selection.
- **Headless shipping calculations:** REST/MCP calls run outside of a browser
  session, so WooCommerce's native session handler may be absent. Before any
  shipping calculation, the plugin boots a lightweight runtime session shim and
  restores the previous `WC()->session` afterwards. This prevents `Call to a
  member function get() on null` fatals while remaining stateless.

---

## 5. MCP Binding (Model Context Protocol)

The MCP binding allows LLMs to call UCP tools directly via JSON-RPC 2.0.

### Endpoint

`POST {merchant_base_url}/mcp`

### Protocol

JSON-RPC 2.0. Every request must include a `meta` object with `ucp-agent`
profile URI for capability negotiation.

### Available Tools

| Tool | Maps To | Description |
|---|---|---|
| `create_checkout` | POST /checkout-sessions | Create new session |
| `update_checkout` | PUT /checkout-sessions/{id} | Update session |
| `get_checkout` | GET /checkout-sessions/{id} | Get session state |
| `complete_checkout` | POST /checkout-sessions/{id}/complete | Complete order |
| `cancel_checkout` | POST /checkout-sessions/{id}/cancel | Cancel session |

### Request Example

```json
{
  "jsonrpc": "2.0",
  "method": "create_checkout",
  "params": {
    "meta": {
      "ucp-agent": {
        "profile": "https://platform.example/profiles/v2026-01/shopping-agent.json"
      }
    },
    "checkout": {
      "buyer": { "email": "jane@example.com" },
      "line_items": [{ "item": { "id": "123" }, "quantity": 1 }],
      "currency": "USD"
    }
  },
  "id": 1
}
```

### Key Behaviors

- `update_checkout` requires complete checkout state on every request (full replacement)
- MCP tools separate resource identification (`id` param) from payload data (`checkout` object)
- Responses include `checkout.id` as part of the full resource state

---

## 6. Capability Negotiation

Both merchants and agents publish profiles declaring what they support.

1. Agent passes its profile URL in `meta.ucp-agent.profile`
2. Merchant fetches the agent's profile (cached for 1 hour)
3. Merchant computes the intersection of capabilities and extensions
4. Response includes only the negotiated capabilities

This negotiation happens per-transaction. Change the cart, buyer, or location,
and available capabilities may shift.

---

## 7. Authentication

### REST Transport Authentication Methods

| Method | Header | Use Case |
|---|---|---|
| API Key | `X-API-Key: {key}` | Agent integrations |
| OAuth 2.0 | `Authorization: Bearer {token}` | Identity-linked sessions |

### OAuth 2.0 Identity Linking (RFC 6749)

Enables linking a user's store account with their AI assistant.

- Authorization Code flow with PKCE (RFC 7636)
- Auth codes expire in 5 minutes
- Access tokens expire in 1 hour
- Token rotation on refresh (old refresh token invalidated)
- Redirect URI validation against registered URIs
- Token revocation per RFC 7009

### Endpoints

| Endpoint | Method | Description |
|---|---|---|
| `/oauth/authorize` | GET | Authorization (consent screen) |
| `/oauth/token` | POST | Token exchange (code → tokens) |
| `/oauth/revoke` | POST | Token revocation |

---

## 8. AP2 (Agent Payments Protocol)

AP2 is a secure pre-authorization layer on top of existing payment gateways.
Developed by Google under Apache 2.0.

### Mandate Types (Verifiable Digital Credentials)

| Mandate | Description |
|---|---|
| **Intent Mandate** | Conditions under which an agent can purchase (human-not-present) |
| **Cart Mandate** | User's explicit authorization for a specific cart (human-present) |
| **Payment Mandate** | Shared with payment network/issuer for transaction authorization |

### Roles

| Role | Description |
|---|---|
| User | Originator of purchase intent |
| Agent | Executor of tasks on behalf of the user |
| Credential Provider | Secures payment methods, manages authentication |
| Merchant Endpoint | Receives mandates and executes settlement |
| Issuer/Network | Authorizes transactions using standard risk models |

### Mandate Structure

```json
{
  "type": "cart",
  "payment_method": "stripe",
  "amount": "69.78",
  "currency": "USD",
  "expires_at": "2026-03-27T22:00:00Z",
  "credential": {
    "token": "pm_...",
    "payment_intent_id": "pi_..."
  },
  "signature": {
    "algorithm": "ES256",
    "value": "base64-encoded-signature"
  }
}
```

### PSP Integration

AP2 works with existing PSPs. This plugin bridges mandates to:
- **Stripe** — via WooCommerce Stripe Gateway
- **PayPal** — via WooCommerce PayPal Payments
- **WooCommerce Payments** — via WCPay

---

## 9. Extensions

### Fulfillment (dev.ucp.shopping.fulfillment)

Extends the checkout capability with shipping method calculation and selection.

- Shipping options returned as fulfillment groups with selectable options
- Rate calculation uses WC Shipping Zones and Methods
- Agent selects an option via `selected_option_id` in update requests

### Discount (dev.ucp.shopping.discount)

Extends the checkout capability with coupon code support.

- Agents can pass `discount_codes` in create/update requests
- Validated and applied via WooCommerce coupon system
- Discount totals reflected in the UCP Card `totals.discount`

### Order (dev.ucp.shopping.order)

Order lifecycle management via webhook-driven events.

- Order status changes trigger webhook notifications
- Events: shipped, delivered, returned, refunded

---

## 10. References

- [UCP Specification](https://ucp.dev/specification/overview/)
- [UCP REST Binding](https://ucp.dev/specification/checkout-rest/)
- [UCP MCP Binding](https://ucp.dev/specification/checkout-mcp/)
- [UCP Embedded Checkout](https://ucp.dev/specification/embedded-checkout/)
- [UCP Reference](https://ucp.dev/specification/reference/)
- [UCP GitHub](https://github.com/Universal-Commerce-Protocol/ucp)
- [AP2 Protocol](https://ap2-protocol.org/)
- [AP2 Specification](https://ap2-protocol.org/specification/)
- [AP2 GitHub](https://github.com/google-agentic-commerce/AP2)
- [Google UCP Guide](https://developers.google.com/merchant/ucp)
- [Shopify UCP Engineering](https://shopify.engineering/ucp)
