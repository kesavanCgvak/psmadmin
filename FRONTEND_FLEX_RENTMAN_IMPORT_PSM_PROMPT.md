# Frontend Prompt: Display PSM Fields on Flex / Rentman Product Import Screen

## Context

The backend **import-check** APIs for Flex and Rentman now return a `psm` object whenever a PSM catalog or company-inventory match is found. This gives the product import screen a side-by-side view: **integration data** (`flex` or `rentman`) vs **what PSM already has** (`psm`).

Update the existing **Flex** and **Rentman** product import / link flows (search → import check → confirm) to surface these fields. Do **not** change import POST payloads unless already required; this task is **read-only display** after the check call.

---

## APIs (JWT required)

### Flex import check
- **GET** `/api/flex/import/check?flex_id={id}`
- Integration payload key: **`flex`**

### Rentman import check
- **GET** `/api/rentman/equipment/import/check?rentman_id={id}`
- Integration payload key: **`rentman`**

### When `psm` is present

| `status` | Meaning | `psm` included? |
|----------|---------|-----------------|
| `already_in_inventory` | Already linked to company inventory | Yes |
| `inventory_exists` | Matched catalog product; company already has inventory | Yes |
| `product_exists` | Matched catalog product only (no company inventory row yet) | Yes (inventory-only fields null) |
| `new_product` | No PSM match | **No** — omit PSM section |

---

## `psm` object shape (use as source of truth)

```json
{
  "code": "SM58-001",
  "current_quantity": 8,
  "subrental_costs": 15.00,
  "height": 16.0,
  "width": 5.0,
  "length": 5.0,
  "weight": 0.29,
  "country_of_origin": "China",
  "hsn_code": "85181000",
  "dimensions_display": "16 x 5 x 5 cm",
  "weight_display": "0.29 kg"
}
```

### Field mapping (labels for UI)

| UI label | JSON key | Notes |
|----------|----------|--------|
| Code | `code` | From `company_inventory.software_code`. `null` when `product_exists` only. |
| Current quantity | `current_quantity` | From `company_inventory.quantity`. `null` when no inventory row. |
| Subrental costs | `subrental_costs` | From `company_inventory.rental_price`. Format as currency. |
| Dimensions | `dimensions_display` (preferred) or `height` / `width` / `length` | Prefer formatted string when non-null. |
| Weight | `weight_display` (preferred) or `weight` | Prefer formatted string when non-null. |
| Country of origin | `country_of_origin` | |
| HSN code | `hsn_code` | |

### `product_exists` behavior

When status is `product_exists`, expect:

```json
"psm": {
  "code": null,
  "current_quantity": null,
  "subrental_costs": null,
  "height": 16.0,
  "width": 5.0,
  "length": 5.0,
  "weight": 0.29,
  "country_of_origin": "China",
  "hsn_code": "85181000",
  "dimensions_display": "16 x 5 x 5 cm",
  "weight_display": "0.29 kg"
}
```

Show catalog specs; use em dash or “Not in your inventory yet” for code / quantity / subrental costs.

---

## Example full responses

### Flex — `inventory_exists`

```json
{
  "status": "inventory_exists",
  "inventory_id": 42,
  "brand_name": "Shure",
  "model": "SM58",
  "flex": {
    "name": "Shure SM58",
    "sku": "SM58-001",
    "part_number": null,
    "rental_qty_on_hand": 10,
    "rental_qty_allocated": 2,
    "height": 16.2,
    "width": 5.1,
    "modelLength": 5.1,
    "weight": 0.3,
    "linear_unit": "cm",
    "weight_unit": "kg"
  },
  "psm": {
    "code": "SM58-001",
    "current_quantity": 8,
    "subrental_costs": 15.00,
    "height": 16.0,
    "width": 5.0,
    "length": 5.0,
    "weight": 0.29,
    "country_of_origin": "China",
    "hsn_code": "85181000",
    "dimensions_display": "16 x 5 x 5 cm",
    "weight_display": "0.29 kg"
  }
}
```

### Rentman — `product_exists`

```json
{
  "status": "product_exists",
  "product_id": 1234,
  "day_rate": null,
  "brand_name": "Shure",
  "model": "SM58",
  "rentman": {
    "rentman_id": "456",
    "code": "RM-001",
    "subrental_costs": 25.00,
    "current_quantity": 10,
    "height": 16.2,
    "width": 5.1,
    "length": 5.1,
    "weight": 0.3,
    "country_of_origin": "US"
  },
  "psm": {
    "code": null,
    "current_quantity": null,
    "subrental_costs": null,
    "height": 16.0,
    "width": 5.0,
    "length": 5.0,
    "weight": 0.29,
    "country_of_origin": "China",
    "hsn_code": "85181000",
    "dimensions_display": "16 x 5 x 5 cm",
    "weight_display": "0.29 kg"
  }
}
```

---

## UI requirements

### 1. Comparison panel on import check result

After a successful import-check call (any status except `new_product` when `psm` is absent), show **two columns** (stack on mobile):

| | Integration (Flex / Rentman) | PSM database |
|--|------------------------------|--------------|
| Header | “From Flex” or “From Rentman” | “In PSM” |
| Code | `flex.sku` / `flex.part_number` or Rentman `rentman.code` | `psm.code` |
| Current quantity | Flex: `rental_qty_on_hand` (label “Qty on hand”). Rentman: `rentman.current_quantity` | `psm.current_quantity` |
| Subrental costs | Flex: map from day rate UI if shown separately; Rentman: `rentman.subrental_costs` | `psm.subrental_costs` |
| Dimensions | Build from integration fields (Flex: `height`×`width`×`modelLength`; Rentman: `height`×`width`×`length`) | `psm.dimensions_display` or raw L×W×H |
| Weight | Integration `weight` + unit if available | `psm.weight_display` or `psm.weight` |
| Country of origin | If present on integration object | `psm.country_of_origin` |
| HSN code | Usually N/A on integration side | `psm.hsn_code` |

### 2. Highlight differences (optional but recommended)

- When a field exists on **both** sides and values differ (normalize numbers/strings before compare), apply a subtle warning style (e.g. amber background or “Differs” badge).
- When PSM value is `null` and integration has a value, show “—” on PSM side without treating as an error.
- When only PSM has a value (e.g. HSN), show integration as “—”.

### 3. Status-specific copy

- **`already_in_inventory`**: Banner — “Already imported and linked.” Still show comparison so user can verify specs.
- **`inventory_exists`**: Banner — “Product exists in PSM catalog and your inventory.” Show `inventory_id` if you already do.
- **`product_exists`**: Banner — “Product exists in PSM catalog. Not yet in your company inventory.” Explain null code/qty/cost on PSM column.
- **`new_product`**: No `psm` block; only integration preview + existing “create new” flow.

### 4. Null / empty display

- Use `—` or “Not set” for `null`, `""`, or missing keys.
- Do not render `undefined` or raw JSON keys to users.

### 5. TypeScript types (suggested)

```typescript
export type ImportCheckPsm = {
  code: string | null;
  current_quantity: number | null;
  subrental_costs: number | null;
  height: number | null;
  width: number | null;
  length: number | null;
  weight: number | null;
  country_of_origin: string | null;
  hsn_code: string | null;
  dimensions_display: string | null;
  weight_display: string | null;
};

export type FlexImportCheckResponse = {
  status: 'already_in_inventory' | 'inventory_exists' | 'product_exists' | 'new_product';
  brand_name?: string | null;
  model?: string | null;
  inventory_id?: number;
  product_id?: number;
  day_rate?: number | null;
  message?: string;
  flex?: Record<string, unknown>;
  psm?: ImportCheckPsm | null;
};

export type RentmanImportCheckResponse = {
  status: 'already_in_inventory' | 'inventory_exists' | 'product_exists' | 'new_product';
  brand_name?: string | null;
  model?: string | null;
  inventory_id?: number;
  product_id?: number;
  day_rate?: number | null;
  message?: string;
  rentman?: Record<string, unknown>;
  psm?: ImportCheckPsm | null;
};
```

### 6. Shared component

Extract a reusable **`ImportCheckComparisonTable`** (or similar) used by both Flex and Rentman import screens:

- Props: `integrationLabel`, `integrationFields`, `psm: ImportCheckPsm | null | undefined`, `status`
- Single place for labels, null handling, diff highlighting, and currency formatting

### 7. Do not break existing behavior

- Keep existing status handling (`already_in_inventory`, link vs import buttons, etc.).
- Pre-fill form fields from integration data as today; use `psm` for **display and user awareness**, not as the default import payload unless product owner says otherwise.
- If `psm` is missing (older API or `new_product`), hide the PSM column gracefully.

---

## Implementation checklist

- [ ] Extend Flex import-check API client/types with optional `psm`
- [ ] Extend Rentman import-check API client/types with optional `psm`
- [ ] Add comparison UI on product import confirmation step
- [ ] Map integration-side fields per provider (Flex vs Rentman column names differ)
- [ ] Handle `product_exists` null inventory fields with clear copy
- [ ] Optional: diff highlighting between integration and PSM
- [ ] Mobile-responsive stacked layout
- [ ] Empty states and loading/error states unchanged

---

## Success criteria

- User sees **Code, Current quantity, Subrental costs, Dimensions, Weight, Country of origin, HSN code** from **PSM** whenever import-check returns `psm`.
- User can compare the same concepts from **Flex or Rentman** on the same screen before confirming import/link.
- `new_product` flow unchanged (no empty PSM panel).
- Flex and Rentman screens stay visually consistent.

---

**Implement this in the frontend product import screen(s) for Flex and Rentman. Follow the existing design system, component patterns, and API service layer in the frontend repo. Do not modify backend code.**
