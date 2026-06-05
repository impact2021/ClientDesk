# ClientDesk Repository — Agent Instructions

Read this file at the start of every session.

---

## Repository Overview

This repo contains **two WordPress plugins** and **one WordPress theme**. After each update, a zipped file is needed for each updated item so it can be uploaded to WordPress.

---

## Plugin 1 — ClientDesk (`clientdesk-v*.zip`)

**Folder inside zip:** `clientdesk/`  
**Main file:** `clientdesk.php` (+ `stream.php`)

**What it does:**  
ClientDesk is the client-facing WordPress plugin installed on each client site. It provides:

- A plain-English AI chat interface in the WP admin so non-technical clients can edit their website by describing changes in plain English.
- Page management: create, edit, rollback pages. Content (header/body/footer HTML) is stored in custom post meta fields and rendered by the theme.
- SEO tools: edit meta title, meta description, canonical URL, Open Graph tags, and JSON-LD schema (global and per-page).
- Global header, footer, and script injection managed from the WP admin.
- Google Fonts selection (heading font + body font) applied site-wide.
- Image swapping tool.
- AI streaming via a direct endpoint (`stream.php`) that fetches a short-lived token from MasterDesk, then streams responses directly from Anthropic using find/replace patching — never sends full HTML back over the wire.
- Licence key validation against MasterDesk.
- Debug logging mode.

**Depends on:** MasterDesk (for AI tokens, licence validation, and usage tracking).

---

## Plugin 2 — MasterDesk (`masterdesk-v*.zip`)

**Folder inside zip:** `masterdesk/`  
**Main file:** `clientdesk-server.php`

**What it does:**  
MasterDesk is the central hub plugin, installed on the Impact Websites owner site (not on client sites). It provides:

- Licence key management: issue, activate, and revoke licence keys for client sites.
- Site registry: tracks all registered client sites (domain, licence key, label, status, monthly budget).
- Usage tracking: logs every AI action (chat, SEO analysis, etc.) per site, including input/output tokens and cost in cents.
- REST API endpoint (`/wp-json/clientdesk/v1/chat`) that ClientDesk calls to proxy AI requests to Anthropic, enforcing monthly token budgets.
- Short-lived token issuance for direct streaming sessions.
- WP admin dashboard showing all sites, usage logs, and budget status.
- Settings for the Anthropic API key and model selection.

**Does not depend on** ClientDesk being installed on the same site.

---

## Theme — Impact Websites (`impact-websites-theme.zip`)

**Folder inside zip:** `impact-websites-theme/`  
**Files:** `style.css`, `functions.php`, `index.php` (+ WooCommerce/post templates added below)

**What it is:**  
An ultra-lightweight custom WordPress theme built specifically for Impact Websites client sites. It is intentionally minimal — it does not render any content itself. Instead it calls three render functions provided by the ClientDesk plugin (`iw_render_header()`, `iw_render_body()`, `iw_render_footer()`) which output the HTML stored in post meta.

**Supports:**
- WordPress pages (primary use case — all content via ClientDesk)
- WordPress posts (blog)
- WooCommerce: cart, checkout, shop (product archive), product category pages, single product pages
- Standard WordPress template hierarchy for all the above

---

## Zipping for Upload

When any of the three items above has been updated, zip the relevant folder:

| Item | Zip the folder | Output filename convention |
|------|---------------|---------------------------|
| ClientDesk | `clientdesk/` | `clientdesk-vX_Y_Z.zip` |
| MasterDesk | `masterdesk/` | `masterdesk-vX_Y_Z.zip` |
| Theme | `impact-websites-theme/` | `impact-websites-theme.zip` |

The zip must contain the folder itself (e.g. unzipping `clientdesk-v2_8_0.zip` produces a `clientdesk/` folder), which is the standard WordPress plugin/theme upload format.

---

## Key Conventions

- All PHP files begin with `if ( ! defined( 'ABSPATH' ) ) exit;`
- ClientDesk stores page content in post meta: `_iw_header`, `_iw_body`, `_iw_footer`, `_iw_scripts`
- Global header/footer/scripts stored in WP options: `iw_global_header`, `iw_global_footer`, `iw_global_scripts`
- SEO meta keys: `_impact_websites_meta_title`, `_impact_websites_meta_desc`
- The theme's `index.php` is the single entry point; template-specific files (added for WooCommerce and posts) delegate rendering appropriately
