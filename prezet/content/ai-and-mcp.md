---
title: AI & MCP Setup
excerpt: Add AI provider keys with failover and connect PayPal's MCP server, from the dashboard or from .env.
date: 2026-10-15
category: Configuration
---

The switch's AI features call a language model of your choice, and use PayPal's
tools over the [Model Context Protocol](https://modelcontextprotocol.io) (MCP).
Both are **optional**: payments, routing and every provider driver work without
any AI key. You only need this page if you want the AI features.

## Two places to configure

| Where | Who it's for | Scope |
| --- | --- | --- |
| **AI & Agents** in the dashboard sidebar | Each account, no server access needed | That account only |
| **`.env`** on the server | Whoever runs the server | Every account, as the fallback |

The rule is simple: **what an account sets in the dashboard wins, and anything
it leaves blank falls back to `.env`.** An account can, for example, add OpenAI
without a key, and the server's `OPENAI_API_KEY` is used.

## Option A: the dashboard

Open **AI & Agents** in the sidebar. An account can add **several AI
providers**, and its agents **fail over** between them in order: they use the
first one and move to the next when a provider is rate-limited, overloaded,
unreachable or out of credit.

1. **Add AI provider.** Pick a provider, an optional **model** (blank means the
   provider's default) and an optional **API key** (blank means the provider's
   key from `.env`). Each provider can be added once.
2. **Order.** Use the up and down arrows to set the failover order. The top
   provider is tried first.
3. **Test.** Each provider has a **Test** button that sends a tiny prompt
   through that provider alone and tells you whether its key works.
4. **Edit** a provider to change its model or key, or **remove** it.

Each provider shows where its key comes from:

| Badge | Meaning |
| --- | --- |
| **Key ••••1234** | A key saved in the dashboard |
| **Key from .env** | No saved key; the provider's `.env` key is used |
| **No key, skipped** | No key anywhere; agents skip this provider |

If an account has no providers, or none of them has a key, its agents use the
server default (`AI_PROVIDER` in `.env`).

How keys are handled:

- Keys are **encrypted at rest** and never shown again. The page only shows the
  last four characters.
- **Remove saved key and use .env** (in the edit dialog) returns a provider to
  its `.env` key.
- Each account's keys are used only for that account's agents. They never
  replace the server's `.env` keys for anyone else.

The same page shows how **PayPal MCP** is connected (see below).

## Option B: `.env`

Claude (Anthropic) is the default. Create a key in the
[Anthropic Console](https://console.anthropic.com/) and add it:

```ini
AI_PROVIDER=anthropic
ANTHROPIC_API_KEY=sk-ant-...
```

To use another provider, set `AI_PROVIDER` to its name and add that provider's
key instead:

| `AI_PROVIDER` | Key variable |
| --- | --- |
| `anthropic` | `ANTHROPIC_API_KEY` |
| `openai` | `OPENAI_API_KEY` |
| `gemini` | `GEMINI_API_KEY` |
| `mistral` | `MISTRAL_API_KEY` |
| `groq` | `GROQ_API_KEY` |
| `xai` | `XAI_API_KEY` |
| `deepseek` | `DEEPSEEK_API_KEY` |
| `openrouter` | `OPENROUTER_API_KEY` |

The full list of providers and their settings is in `config/ai.php`. If you
cache configuration, run `php artisan config:cache` after editing `.env`.

## Create the conversation tables

The AI features store conversations in two tables (`agent_conversations` and
`agent_conversation_messages`), and each account's providers in `ai_credentials`. They're
part of the normal migrations:

```bash
php artisan migrate
```

## Connect PayPal's MCP server

PayPal runs an MCP server that exposes PayPal actions (invoices, orders,
disputes and more) as tools an AI agent can call.

### From the dashboard (automatic)

If the account has an **active PayPal provider** on the
[Providers](/docs/providers) page, nothing else is needed. Its agents connect to
PayPal's MCP server with that provider's credentials:

- The server matches the provider's environment: sandbox
  (`https://mcp.sandbox.paypal.com/http`) or live (`https://mcp.paypal.com/http`).
- Access tokens are fetched and refreshed automatically, so they never expire
  mid-use.
- With several PayPal providers, the oldest active one is used.

The **AI & Agents** page shows which PayPal provider and environment are in use.

### From `.env` (fallback)

Accounts without a PayPal provider use the server's client, configured with:

```ini
MCP_PAYPAL_URL=https://mcp.sandbox.paypal.com/http
MCP_PAYPAL_TOKEN=
```

| Environment | `MCP_PAYPAL_URL` |
| --- | --- |
| Sandbox | `https://mcp.sandbox.paypal.com/http` (the default) |
| Live | `https://mcp.paypal.com/http` |

`MCP_PAYPAL_TOKEN` is a PayPal **access token** for the same environment. Get
one with your PayPal app's client ID and secret:

```bash
curl -s https://api-m.sandbox.paypal.com/v1/oauth2/token \
  -u "CLIENT_ID:CLIENT_SECRET" \
  -d "grant_type=client_credentials"
```

Copy `access_token` from the response. For live, use
`https://api-m.paypal.com/v1/oauth2/token` with your live credentials.

> Tokens set in `.env` **expire after a few hours** and must be replaced by
> hand. Keep sandbox tokens with the sandbox URL and live tokens with the live
> URL: mixing them fails authentication. The dashboard route above avoids both
> problems.

## Production checklist

- Keys live in the dashboard or in the server's `.env` (or your host's secret
  manager), never in the repository.
- `APP_KEY` is set and stable: dashboard keys are encrypted with it, and
  changing it makes saved keys unreadable.
- **Test** succeeds for each AI provider an account relies on.
- PayPal providers, `MCP_PAYPAL_URL` and `MCP_PAYPAL_TOKEN` use the same
  environment (`sandbox` or `live`).
- A spending limit is set on each AI provider account.
- `php artisan migrate` has run, and `php artisan config:cache` after any
  `.env` change.
