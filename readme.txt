=== ABC B2B Template Designer (Phase 1) ===
Contributors: abcprinting
Tags: woocommerce, product designer, b2b, templates, web-to-print
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.2.2

= 0.1.8 =
- Add: Optional bypass capability (abc_b2b_designer_bypass) in addition to admin manage_options.
- Fix: Template title display when titles contain HTML entities (e.g., &#8211;).

A Phase 1 B2B template-based designer for WooCommerce. Customers select a brand template and fill editable fields. The saved proof and production PNGs attach to orders.

== Install ==
1) Upload the zip via Plugins > Add New, or upload the folder to /wp-content/plugins/
2) Activate: "ABC B2B Template Designer (Phase 1)"
3) Create an Organization: Organizations > Add New
4) Assign users: Users > Edit User > ABC B2B Organization
5) Create a template: Designer Templates > Add New
   - Set Organization (or All)
   - Add WooCommerce Product IDs (comma-separated)
   - Set surfaces sizes/bleed/dpi and background image
   - Add editable fields (key/label/bounds)
6) Visit the product page as a user in that Organization, select template, fill fields, Save Proof, add to cart.

== Output Files ==
Uploads folder:
  /wp-content/uploads/abc-b2b-designer/tmp/{token}/
Checkout moves to:
  /wp-content/uploads/abc-b2b-designer/orders/{order_id}/{token}/

== Notes ==
- Phase 1 saves only the currently selected surface (Front OR Back). Multi-surface saving is next.
- Fabric.js is loaded from CDN (can be bundled later).


== Changelog ==

= 0.1.1 =
- Fix: CPT menus (Organizations/Designer Templates) now appear by bootstrapping before init.

= 0.1.2 =
- UI: "Designer Templates" is now under the "Organizations" menu to make it easier to find.

= 0.1.3 =
- Fix: "Designer Templates" post type now registers reliably (no more "Invalid post type").

= 0.1.4 =
- Fix: Post type key was too long (WordPress max is 20 chars). Renamed to abc_designer_tpl so Templates register correctly.

= 0.1.5 =
- Add: Settings page to load external font stylesheet (Adobe Fonts/Typekit).
- Fix: Designer waits for fonts to load before rendering/exporting PNG proofs.

= 0.1.6 =
- Fix: Custom font loading JS bug.
- Add: Visual Builder (drag/resize fields in admin, no measuring).
- Add: Field formatting (align/bold/italic).
- Add: Vector SVG export saved alongside PNG proofs.

= 0.1.7 =
- Fix: Admin builder assets enqueue URLs.

= 0.1.9 =
- Add: Organization approval gating (pending message includes organizer first name).
- Add: Organization Admin front-end portal shortcode [abc_b2b_org_portal] for approvals + org order list.
- UX: Hide template selector when no templates are available.

= 0.1.10 =
- UX: Make front-end designer responsive and full-width; prevent field panel overflow.
- UX: Render designer block after add-to-cart form for better product/price flow.

= 0.2.1 =
- Team workflow: Organization Drafts (any org member creates; employees customize; org admin checks out).
- Dual approvals: Employee Ready + Org Admin Ready required; Org Admin can override.
- Quantity editable during proofing; changes reset approvals.
- Performance: product page loads lightweight draft launcher; editor loads on dedicated page.

= 0.2.2 =
- Fix: product page draft launcher now visible for admins/bypass even without org assignment.
- Fix: template AJAX no longer returns 403 for pending approval; returns pending message cleanly.
- UX: Draft editor page shows list of drafts when none selected, and accepts draft query param aliases.
