# AI Builder Connector

**An MCP server for WordPress that lets an AI build Elementor pages — as drafts only.**

Your AI coding tool (Claude Code, Cursor, or any MCP client) connects to your site, writes a page
plan, and the plugin turns it into an Elementor draft. The draft waits in a review queue until you
approve it. There is no publish tool for the AI to call.

> **Beta.** Read [Project status](#project-status) before installing this on a site that matters.

---

## Why this exists

Most AI-plus-page-builder integrations hand the AI the keys to the site: edit live pages, publish,
rewrite global styles, inject JavaScript and PHP. That is fine on a playground and a bad idea on a
client site. This plugin inverts the trust model.

| | Typical AI page-builder bridge | AI Builder Connector |
|---|---|---|
| Live pages | AI can edit them | Never. The AI only touches drafts it created |
| Publishing | AI can publish | No publish tool exists. Administrator only |
| Custom code | JavaScript and PHP injection | CSS only, sanitized, scoped to one page |
| Auth | Runs as a WordPress user, with that user's capabilities | Scoped bearer tokens: expiry, revoke, emergency off switch, audit log |
| Widget support | Hand-coded, one tool per widget | Read from your own install, so third-party addons work automatically |
| Review | None | Every page: needs review → approve or reject → publish → rollback |

The worst case here is a bad draft you reject. That is the whole point.

## Requirements

- WordPress 6.0 or newer
- PHP 8.1 or newer
- Elementor (free). Elementor Pro and third-party addons are supported but not required.

## Install

1. Download the plugin zip from [Releases](../../releases).
2. WordPress admin → Plugins → Add New → Upload Plugin → activate.
3. Go to **AI Builder → Setup Wizard**.

The wizard checks Elementor, creates a connection token, lets you choose which addons the AI may
use, and sets the colours and fonts it should build with.

## Connect an AI

Step 2 of the wizard shows a ready-made command for your tool. For Claude Code it looks like this:

```bash
claude mcp add --transport http aibc \
  "https://your-site.com/wp-json/ai-builder-connector/v1/mcp" \
  --header "Authorization: Bearer YOUR_TOKEN"
```

The token is shown once. Then ask your AI something like:

> Build a landing page for my WordPress care plans: hero, three plan cards, FAQ, contact CTA.

It creates a draft on your site. You review it in **AI Builder → Drafts**, then approve or reject.

## What the AI can do

33 tools, all scoped to the connection's permissions:

- **Look around** — site context, builder status, allowed addons and widgets, widget definitions,
  page templates, saved templates, brand kits, design system, Elementor 4 atomic support.
- **Plan and build** — create a page plan, validate it, build an Elementor draft from it, revise a
  draft, validate a draft, get a preview link.
- **Content** — search Openverse for openly licensed images and import them, set SEO meta title and
  description (feeds Yoast when installed), attach sanitized page-scoped CSS.
- **Reuse** — save a draft's plan as a named template and build new pages from it.
- **Review trail** — list drafts and actions, read action details, approve, reject, roll back, delete.

The AI cannot publish, cannot edit pages it did not create, and cannot run JavaScript or PHP.

## Permissions

Two layers, both under your control in **Permissions & Design**:

1. **Addon allowlist** — which widget sources the AI may use (Elementor Core, Pro, Essential Addons,
   Premium Addons, and so on, detected from your install).
2. **Widget allowlist** — optional per-widget control inside those addons.

Anything not allowed is blocked at plan time and reported back to the AI, not silently dropped.

## Elementor 4 atomic engine (experimental)

Pass `engine: "atomic"` to `create_elementor_draft` and pages are built from Elementor 4 atomic
elements (`e-flexbox`, `e-heading`, `e-paragraph`, `e-button`, `e-image`, `e-divider`) instead of
legacy widgets. The markup is much lighter, which helps Core Web Vitals.

This depends on Elementor 4's atomic-elements experiment, which is **alpha software**. The dashboard
has a button that turns it on. Please read the warning in [Project status](#project-status) first.

## Project status

This is a beta. Being specific about what that means:

- **Runtime-tested on exactly one site.** WordPress 7.0, PHP 8.x, Elementor 4.2, Neve theme, with
  Essential Addons and Premium Addons installed. It has **not** been tested against Elementor 3.x
  (what most sites run), other themes, multisite, or other PHP minor versions. If you try it
  somewhere else, an issue report is genuinely useful.
- **No automated test suite.** There are two ad-hoc harness scripts under `tests/`, not a real suite,
  and no CI. Around 11,500 lines of PHP.
- **No external security review.** The design is careful — capability checks, nonces, hashed tokens,
  content sanitizing, no publish path — but nobody outside the project has audited it.
- **The atomic button writes Elementor's own experiment options.** Those keys belong to an alpha
  feature and Elementor may rename them at any time, at which point the button quietly stops working.
  Do not enable it on a production site you cannot afford to break.
- **Uninstalling deletes everything the plugin stored** — connections, permissions, saved templates,
  brand kits, logs. There is no "keep my data" option yet.

Install it on staging first. That is the honest recommendation.

## Known limitations

- Atomic drafts cannot be revised in place yet; delete and rebuild instead.
- The atomic engine covers five element types and stacks them in a single column. No multi-column
  rows yet.
- Imported stock images have not been verified end to end into an image widget.
- No email notification when a draft needs review.
- Drafts created before a permission change are not re-validated automatically.

## Roadmap

Roughly in order:

1. Warn at review time when a widget still contains Elementor's demo placeholder content.
2. A setting to keep plugin data on uninstall.
3. Verify and fix the stock-image path into image widgets.
4. Revise-in-place for atomic drafts, more atomic elements, real multi-column rows.
5. Email notification for the review queue.
6. AI widget builder: describe a widget, get a sandboxed custom Elementor widget.

## Security

The endpoint is `POST /wp-json/ai-builder-connector/v1/mcp`, authenticated with a bearer token.
Tokens are stored hashed, can expire, can be revoked individually, and there is a single switch that
disables the whole endpoint. Every tool call is written to an audit log with the connection that made
it.

If you find a security problem, please open a GitHub issue marked **security** rather than posting
details publicly, and I will follow up.

## Credits

Built by [Syed Saud Ahsan](https://www.linkedin.com/in/syedsaudahsan/).

Licensed GPL-2.0-or-later — see [LICENSE](LICENSE). This is the same licence WordPress uses, so you
are free to use, modify and redistribute it under those terms.

The `docs/` folder holds the internal implementation plans the plugin was built from. They are kept
in the repository for transparency and are not part of the shipped plugin.
