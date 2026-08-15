=== AI Builder Connector ===
Contributors: syedsaudahsan
Tags: elementor, ai, mcp, page builder, drafts
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 2.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let an AI build Elementor pages on your site as drafts only. You review and publish. The AI can never publish or touch live pages.

== Description ==

AI Builder Connector turns your WordPress site into an MCP server that AI coding tools — Claude Code, Cursor, and other MCP clients — can connect to. The AI writes a page plan, the plugin validates it against the widgets you allow, and the result becomes an Elementor draft waiting in a review queue.

Most AI page-builder integrations give the AI the run of the site: edit live pages, publish, rewrite global styles, inject code. This plugin inverts that. The AI can only create and revise drafts it made itself. There is no publish tool for it to call. The worst outcome is a draft you reject.

**How access is controlled**

* Connections use bearer tokens you create, with an optional expiry, individual revoke, and one switch that turns the whole endpoint off.
* Tokens are stored hashed. Every tool call is written to an audit log.
* You choose which widget sources the AI may build with (Elementor Core, Pro, and any third-party addons detected on your install), and optionally which individual widgets.
* Blocked widgets are reported back to the AI at plan time, not dropped silently.

**What the AI can do**

* Read your site context, allowed widgets, page templates, saved templates and design system.
* Create a page plan, validate it, and build an Elementor draft from it.
* Revise a draft it created, with the previous version snapshotted first.
* Search Openverse for openly licensed images and import them into your media library.
* Set the SEO meta title and description on its drafts, which also feed Yoast SEO when installed.
* Attach sanitized, page-scoped CSS. No JavaScript and no PHP, by design.
* Save a draft's plan as a reusable template.

**What it cannot do**

Publish, edit pages it did not create, delete published pages, run JavaScript, run PHP, run shell or database commands, or call any external AI API. The plugin itself never talks to an AI provider; your own tool connects to it.

**Review workflow**

Every draft moves through: generated, needs review, then approved or rejected. Approving lets an administrator publish it. A published page can be unpublished and rolled back. Approval never carries across an AI revision — a revised draft always returns to needs review.

**Elementor 4 atomic pages (experimental)**

Drafts can be built from Elementor 4 atomic elements instead of legacy widgets, which produces much lighter markup. This depends on Elementor 4's atomic-elements experiment, which is alpha software. Read the beta notice below before turning it on.

**Beta notice**

This plugin has been runtime-tested on one site only: WordPress 7.0, PHP 8, Elementor 4.2. It has not been tested against Elementor 3.x, multisite, or other themes and PHP versions. There is no automated test suite and no external security review. Uninstalling removes all plugin data. Please install it on staging before a production site.

== Installation ==

1. Upload the plugin zip through Plugins → Add New → Upload Plugin, then activate it.
2. Go to AI Builder → Setup Wizard.
3. The wizard checks Elementor, creates your first connection token, lets you pick which addons the AI may use, and sets default colours and fonts.
4. Paste the connection command the wizard shows into your AI tool.
5. Ask your AI to build a page, then review it under AI Builder → Drafts.

== Frequently Asked Questions ==

= Does this send my content to an AI company? =

No. The plugin has no outbound AI calls. It exposes an endpoint on your own site; your AI tool connects to it. The only outbound request the plugin makes is to Openverse, and only when you ask it to search or import a stock image.

= Can the AI publish a page? =

No. No publish tool exists in the MCP surface. Publishing is an administrator action in the dashboard, protected by a capability check and a nonce, and only available for drafts that are approved and have passed validation.

= Can the AI edit my existing pages? =

No. Every tool that writes checks that the page was created by this plugin. Human-created pages are out of reach.

= Does it work with third-party Elementor addons? =

Yes. The plugin reads the widgets registered on your install and derives safe content fields from their Elementor controls, so addons like Essential Addons and Premium Addons work without a hand-written definition per widget. You still have to allow them.

= What happens if I revoke a token? =

That connection stops working immediately. Drafts it created stay where they are.

== Changelog ==

= 2.0.1 =
* Redesigned the admin screens around the review workflow: a drawing-sheet title block, a plain statement of what is waiting for you, and rubber-stamp status marks.
* Review queue rows now show how long a draft has been waiting.

= 1.9.5 =
* Elementor 4 atomic engine: build drafts from atomic elements with `engine: "atomic"`.
* Added `get_atomic_status` and a dashboard button to enable Elementor's atomic experiment.
* Fixed atomic style output so backgrounds, padding, colours, radius and typography render.

= 1.8.2 =
* Added a flyout submenu under AI Builder for every dashboard tab.

= 1.8.1 =
* Existing full-scope connections now gain tools added in later plugin versions instead of getting a permission error.

= 1.8.0 =
* Openverse stock image search and import.
* SEO meta title and description on drafts, with Yoast support.
* Sanitized page-scoped custom CSS.
* Save a draft's plan as a reusable template.
* Ten brand kits with one-click apply and restore.

= 1.6.0 =
* Bulk reject and delete for created drafts.

= 1.5.0 =
* Top-level AI Builder menu.
* Page plans now chain directly into draft creation.
* Ready-made connection commands for common MCP clients.

Earlier releases are listed in the repository history.

== Upgrade Notice ==

= 2.0.1 =
Admin redesign plus the Elementor 4 atomic engine. Still a beta: test on staging first.
