---
title: AI & MCP Setup
excerpt: Add an AI provider key and connect PayPal's MCP server so the switch's AI features run, from the dashboard or from .env.
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
| **Dashboard** → Settings → **AI & Agents** | Each account, no server access needed | That account only |
| **`.env`** on the server | Whoever runs the server | Every account, as the fallback |

The rule is simple: **what an account sets in the dashboard wins, and anything
it leaves blank falls back to `.env`.** An account can, for example, pick
OpenAI in the dashboard but leave the key blank, and the server's
`OPENAI_API_KEY` is used.

## Option A: the dashboard

Open **Settings → AI & Agents**.

1. **Provider.** Pick one, or choose *Use .env defaults* to follow the server.
2. **Model** *(optional)*. Leave blank for the provider's default model.
3. **API key** *(optional)*. Paste your key, or leave it blank to use the
   provider's key from `.env`.
4. **Save**, then **Test connection**. This sends a tiny prompt and tells you
   whether the provider accepted the key.

How keys are handled:

- Keys are **encrypted at rest** and never shown again. The page only shows the
  last four characters.
- A key belongs to its provider. Switching to a different provider removes the
  saved key; add the new provider's key, or rely on `.env`.
- **Remove saved key** returns that account to the `.env` key.
- Each account's key is used only for that account's agents. It never replaces
  the server's `.env` key for anyone else.

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
`agent_conversation_messages`), and dashboard settings in `ai_settings`. They're
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

**Settings → AI & Agents** shows which provider and environment are in use.

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
- **Test connection** succeeds for each account that uses AI.
- PayPal providers, `MCP_PAYPAL_URL` and `MCP_PAYPAL_TOKEN` use the same
  environment (`sandbox` or `live`).
- A spending limit is set on each AI provider account.
- `php artisan migrate` has run, and `php artisan config:cache` after any
  `.env` change.
