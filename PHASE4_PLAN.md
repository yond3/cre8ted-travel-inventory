# Phase 4 proposal: inventory categories and filtering

Status: implementation and 287 automated API/regression checks passed; user accepted isolated manual checks. Fresh native backup/rehearsal passed and the authorized normal migration was applied and verified on 2026-10-25 at 16:37 Asia/Manila (PHASE4_APPLICATION_ROLLOUT.md). Existing 1,329 records and 54 receipts were preserved. Final normal-browser confirmation and separate commit approval remain; Phase 5 has not been implemented. No commit or push has been performed.

## Current flow and integration points

Managers/Super Admin create or edit an inventory record in `php/index.html` through `php/api/inventory.php`. Its `items.item_key` is the shared catalog identity. `item_type` controls consumable thresholds/usage versus equipment deployment/returns. `equipment_group` groups equipment variants in the catalog and display labels. Locations and department deployments express physical placement; they are not product categories.

Staff and department stock requests select existing items through `stock_requests.php?requestable=1`, backed by `fetch_requestable_stock_items()` in `config.php`. That helper currently filters to active items with positive storage stock and valid equipment placement. Fulfillment uses the same item identity and Phase 2 stock mechanism.

Purchase requests can link to a catalog item; equipment purchase requests can initially be descriptive and are linked before PO creation. POs obtain their item through the purchase request. Receiving changes the linked item's balance. Existing vendor quotation submissions select active catalog items through `vendor_applications.php?quotable=1` and approval copies their prices into `supplier_prices`. Inventory exports and forecasting also use the original item identity.

There is no general inventory category table or category relationship in the reviewed schema. The PO `expense_category` is an existing Finance integration classification, not an inventory category. It must not be repurposed.

## Problem and proposed flow

Current type/group filters do not consistently classify stationery, furniture, cleaning supplies and electronics. Replacing `item_type` or `equipment_group` with categories would change stock behavior or lose equipment variant organization.

Proposed: keep each item and its existing behavior, add one optional category assignment, and reuse it in catalog searches, request/quote pickers and inventory reports. Selecting a parent category includes items directly assigned to it and its subcategories. Changing a category never issues, receives, reserves or adjusts stock.

Recommend two levels for the first release: a root category and optional subcategory. This meets the requested examples and keeps cycle prevention, administration and filters understandable. No arbitrary-depth tree or multiple-category tagging is needed initially. This depth recommendation is part of the plan for approval, not a change already made.

## Proposed database changes

- New `inventory_categories`: stable ID, display name, normalized name for uniqueness, optional `parent_id`, active flag, revision and creation/update timestamps.
- Self-reference for `parent_id`; restrict deletion. Enforce unique normalized sibling names, including roots (a nullable-parent unique index alone is insufficient). Index parent/active lookup.
- Add nullable, indexed `items.category_id` referencing the category table; restrict deletion of referenced categories. Existing item identifiers, quantities and relationships stay unchanged.
- Use the established required audit mechanism for category creation, rename, move, assignment and deactivation. No second audit system or duplicate category copies on requests/POs.
- Enforce depth, valid parent, no self-parent/cycle, duplicate-name rules and active assignment server-side. Lock category mutations in a consistent order and use revisions so concurrent administration cannot bypass validation.

Exact DDL must be checked against the actual MySQL version/collations and rehearsed on a restored copy. No migration is created by this plan.

## Migration and existing records

Leave existing item assignments null initially and display them as **Uncategorized**. This is an honest missing assignment, not a new inventory item or another workflow. Existing items remain selectable and usable. Do not infer categories from item names, item type or equipment groups without review.

Seed an initial taxonomy without modifying item quantities: Electronic Equipment (Computers, Networking Equipment, Peripherals, Accessories); Office Supplies / Stationery (Paper Products, Writing Materials, Filing Supplies); Furniture; Cleaning Supplies (Chemicals, Cleaning Tools, Sanitation Supplies); Other. These names can be reviewed before implementation. "Other" is an explicit classification; "Uncategorized" means an item has not been classified.

Managers/Super Admin classify existing items through the existing item edit form. Category is optional during migration so old records and API clients remain valid. Explicit null clears an assignment; omitting the field preserves it. Unrelated edits to an item with an archived category remain possible; new assignments to archived categories are rejected. Category changes are audited.

Archive rather than delete used categories. Recommend archiving a parent only after its active children are archived or moved, with no hidden cascade. Existing assigned items stay available under their saved category and the All filter. Archived categories remain readable for history, but cannot receive new assignments.

## Roles and UI

- Staff: view/filter categories and continue current request/issuance permissions.
- Department accounts: see categories only through already-authorized requestable catalog data; no full inventory or category administration access.
- Manager/Super Admin: manage categories and item assignments with audit history and revision checks. Inventory item activation remains under existing permissions.
- Vendors: optional category filtering on the existing public quotable catalog only; no additional inventory quantities, internal records or permissions exposed.

Extend the current catalog filters and item modal, add a compact category administration view within the existing management UI, and preserve equipment groups/type tabs. Filter equipment variants before calculating visible group totals. Combine category, type, search and inactive filters predictably, including clear/reset and Uncategorized options.

## Actual files/routes likely affected

| File/module | Planned change |
|---|---|
| New `sql/migration_inventory_categories.sql` | Add taxonomy and optional item relationship; preserve existing records |
| `sql/schema.sql` | Fresh-install equivalent, never a normal-database upgrade |
| New `php/api/inventory_categories.php` | Authenticated taxonomy reads and authorized audited administration |
| New `php/api/category_service.php` | Shared hierarchy, validation, revision and filter rules |
| `php/api/config.php` | Load helper; extend requestable-item metadata |
| `php/api/inventory.php` | Validate/save assignments; expose category metadata; optional category query filter |
| `php/api/stock_requests.php` | Reuse category metadata/filtering in its existing requestable GET; request POST/fulfillment stays the same |
| `php/api/items.php` | Add optional category metadata for the existing forecast selector without changing eligibility or usage |
| `php/api/vendor_applications.php` | Category metadata on existing quotable items without exposing private taxonomy administration |
| `php/api/reports.php` | Inventory category columns/filter, shared with UI classification |
| `php/index.html` | Category assignment/management, catalog and item-picker filters, existing shared filter helpers |
| `php/department-api.html` | Filter the existing requestable list by category |
| `php/vendor-apply.html` | Filter the existing quotable list by category |
| Tests and documentation | Category validation/permissions/migration/filters and connected regression checks |

Stock issues, requests, purchase requests, POs, documents, supplier prices and the forecasting service continue referencing the same items. Their mutation/status/stock mechanisms need no category-specific alternative. Where existing central tables resolve metadata from `state.inventory`, extend that shared resolution instead of adding redundant category columns to every API/table. Check each rendering call before deciding whether a particular read projection also needs metadata.

## Reporting and future modules

Inventory category reports reflect current catalog classification. Existing historical rows do not contain historical category snapshots; do not imply a report shows classification at transaction time. Historical categories or classifications required by Finance would be a separate explicit design decision.

Bidding and supplier agreements in later phases will reference these same inventory items/categories. Phase 4 does not introduce bidding, supplier eligibility, reservations, multiple-item requests, new PO statuses, MFA or forecast thresholds. Finance expense categories keep their existing mapping from item type.

## Risks and safeguards

- Wrong classification: keep null for unreviewed items and require deliberate assignments.
- Broken request pickers: category filters narrow the existing eligible list; do not change stock/type/placement eligibility.
- Lost history: preserve stable category IDs and audit renames/moves; archive referenced categories.
- Confusing equipment behavior: categories never determine whether an item is consumable or equipment.
- Concurrent edits: consistent locks, hierarchy validation under locks, revisions and transactional audit; category-only item changes must not overwrite stale stock quantities.
- Duplicate roots: enforce actual database uniqueness, not only frontend checks.
- Public data exposure: existing vendor/department surfaces receive only relevant category labels/IDs through their current authorized catalog lists.
- UI slowdown: use joined/batched category reads and parent/item indexes rather than a query for every row.

## Testing and completion criteria

Test create/rename/archive/move, root/sibling duplicates, invalid parents, cycles/depth, inactive assignments, stale revisions, concurrent edits, audit failures and unauthorized actions. Verify parent/subcategory/Uncategorized filters compose with type, search and inactive selection, without changing equipment group rollups or stock quantities.

Migration rehearsal must preserve every original column/row, quantity, reference and receipt, with null assignments initially. Fresh-install and upgrade paths must agree. Verify old API payloads and existing records still work.

Regression-test login/roles, item creation/editing, equipment groups/locations/deployments, department requestability, stock requests and fulfillment/cancellation, issuance/reversal, purchase request approval, catalog linking, POs/proof/receiving, vendor quotation submission/approval, inventory exports, monthly closing and stock ledger reconciliation. Confirm source monthly usage and forecast eligibility are unchanged by classification.

Provide a disposable browser preview and a role-by-role manual checklist. Completion requires automated/regression passes, preserved records, user manual acceptance and an approved/rehearsed rollout. Commit and push remain separate explicit approvals.

Recommendation: approve this additive category phase after reviewing the proposed two-level structure, optional migration assignments, management permissions and archive behavior. Implementation is in progress; see PHASE4_REVIEW.md for verification status.
