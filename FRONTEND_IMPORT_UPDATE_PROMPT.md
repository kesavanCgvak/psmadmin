# Frontend Update Prompt - Import Workflow Enhancements

## 🎯 New Features to Implement

The backend has been updated with two critical features that need frontend integration:

### 1. Quantity Addition (Not Replacement)
### 2. Show Only Remaining Items When Resuming

---

## 📋 Feature 1: Quantity Addition

### What Changed:
When a user imports a product that **already exists** in their inventory, quantities are now **added together** instead of being replaced.

**Example:**
- User has: "CHAUVET ROGUE R2 BEAM FIXTURE" with 10 units
- User imports: Same product with 25 units
- **Result**: 35 total units (10 + 25), not 25 units

### Backend Changes:
- `ImportConfirmationService` now checks for existing equipment
- If exists: Adds quantities (`existing_quantity + import_quantity`)
- If doesn't exist: Creates new equipment

### Frontend Implementation Required:

#### 1. Show Existing Equipment Info in Matches

**API Response Update:**
Each match now includes `existing_equipment` object:

```json
{
  "matches": [
    {
      "product_id": 123,
      "confidence": 0.95,
      "product": { ... },
      "existing_equipment": {
        "id": 456,
        "current_quantity": 10,
        "software_code": "STC-8655",
        "note": "You already have this product. Quantities will be added."
      }
    }
  ]
}
```

**UI Requirements:**
- **Display existing equipment badge** on matches that user already has
- **Show quantity preview**: "Current: 10 units, Importing: 25 units, Total: 35 units"
- **Warning icon/tooltip**: "Quantities will be added, not replaced"
- **Visual indicator**: Highlight matches with existing equipment (e.g., yellow border or badge)

**Example UI:**
```
┌─────────────────────────────────────────┐
│ Match: CHAUVET ROGUE R2 BEAM FIXTURE    │
│ Confidence: 95% | Exact Match           │
│                                          │
│ ⚠️ Already in Inventory                 │
│ Current: 10 units                       │
│ Importing: 25 units                     │
│ Total will be: 35 units                 │
│                                          │
│ [Select This Match]                     │
└─────────────────────────────────────────┘
```

#### 2. Show Quantity Preview Before Confirming

**When user selects a product they already have:**
- Show calculation: `current_quantity + import_quantity = total_quantity`
- Display warning: "This will add to your existing inventory"
- Show preview before final confirmation

**Example:**
```
Selected Product: CHAUVET ROGUE R2 BEAM FIXTURE
Current Inventory: 10 units
Importing: 25 units
─────────────────────────
Total After Import: 35 units

⚠️ Quantities will be added, not replaced
```

#### 3. Success Message After Import

**After successful import:**
- Show message: "Quantities were added to existing inventory items"
- List which products had quantities added
- Show before/after quantities if applicable

---

## 📋 Feature 2: Show Only Remaining Items When Resuming

### What Changed:
When a user resumes an import session, **only pending items are shown** by default. Confirmed/imported items are filtered out.

**Example:**
- User uploads 50 products
- User imports 30 products
- User logs out
- User logs back in → **Only sees 20 remaining items** (30 confirmed items are hidden)

### Backend Changes:
- `GET /api/import/sessions/{session}` now filters out confirmed items by default
- Response includes `confirmed_items` and `pending_items` counts
- Query parameter `?show_all=true` to show all items

### Frontend Implementation Required:

#### 1. Update Session Response Handling

**API Response Update:**
```json
{
  "data": {
    "id": 1,
    "confirmed_items": 30,
    "pending_items": 20,
    "items": [
      // Only pending/analyzed items (confirmed filtered out)
    ]
  }
}
```

**UI Requirements:**
- **Display progress**: "30 items imported, 20 items remaining"
- **Show only remaining items** in the preview grid
- **Hide confirmed items** by default
- **Optional toggle**: "Show all items" to view confirmed items if needed

#### 2. Update Resume Flow

**When user resumes a session:**
1. Load session: `GET /api/import/sessions/{session}`
2. Check `confirmed_items` and `pending_items` counts
3. Display progress banner: "X items already imported, Y items remaining"
4. Show only pending items in grid
5. Allow user to continue with remaining items

**Example UI:**
```
┌─────────────────────────────────────────┐
│ Import Session Resume                   │
│                                         │
│ ✅ 30 items imported                    │
│ ⏳ 20 items remaining                   │
│                                         │
│ [Continue with remaining items]         │
│                                         │
│ [Show all items] (toggle)              │
└─────────────────────────────────────────┘
```

#### 3. Update Active Sessions List

**In the sessions list:**
- Show progress: "30/50 imported" or "30 imported, 20 remaining"
- Display status badge based on progress
- Show stage information

**Example:**
```
Active Sessions:
┌─────────────────────────────────────────┐
│ Session #1 - Dec 11, 2025               │
│ 30/50 items imported                     │
│ 20 items remaining                       │
│ Stage: Review Matches                    │
│ [Continue]                               │
└─────────────────────────────────────────┘
```

#### 4. Add "Show All Items" Toggle

**Optional feature:**
- Add toggle button: "Show all items" / "Show remaining only"
- When enabled, call API with `?show_all=true`
- Display all items including confirmed ones
- Useful for reviewing what was already imported

---

## 🔧 Implementation Checklist

### Quantity Addition Feature:
- [ ] Display `existing_equipment` info in match cards
- [ ] Show quantity calculation preview (current + importing = total)
- [ ] Add warning badge/icon for existing inventory items
- [ ] Show quantity preview before confirming selection
- [ ] Update success message to mention quantity addition
- [ ] Test with products user already has in inventory

### Resume with Filtered Items:
- [ ] Update session loading to handle `confirmed_items` and `pending_items`
- [ ] Display progress banner showing import status
- [ ] Filter out confirmed items from preview grid by default
- [ ] Add "Show all items" toggle (optional)
- [ ] Update active sessions list to show progress
- [ ] Test resume flow with partial imports

### UI/UX Enhancements:
- [ ] Add visual indicators for existing inventory items
- [ ] Show quantity calculation clearly
- [ ] Add tooltips explaining quantity addition
- [ ] Update progress indicators
- [ ] Improve resume flow messaging

---

## 📝 API Endpoints Reference

### Get Session (with filtering)
```
GET /api/import/sessions/{session}
GET /api/import/sessions/{session}?show_all=true
```

**Response includes:**
- `confirmed_items`: Count of imported items
- `pending_items`: Count of remaining items
- `items`: Only pending items (unless `show_all=true`)
- Each match includes `existing_equipment` if applicable

### Analyze Matches
```
POST /api/import/sessions/{session}/analyze
```

**Response includes:**
- Each match may include `existing_equipment` info
- Shows if user already has the product in inventory

---

## 🎨 UI Component Suggestions

### 1. Existing Inventory Badge Component
```jsx
<ExistingInventoryBadge 
  currentQuantity={10}
  importingQuantity={25}
  showCalculation={true}
/>
```

### 2. Progress Banner Component
```jsx
<ImportProgressBanner 
  confirmed={30}
  pending={20}
  total={50}
/>
```

### 3. Quantity Preview Component
```jsx
<QuantityPreview 
  current={10}
  importing={25}
  total={35}
  showWarning={true}
/>
```

---

## ⚠️ Important Notes

1. **Quantity Addition is Automatic**: Backend handles it, but UI should inform users
2. **Filtering is Default**: Confirmed items are hidden by default when resuming
3. **User Experience**: Make it clear what will happen before user confirms
4. **Visual Feedback**: Use badges, icons, and colors to indicate existing inventory
5. **Progress Tracking**: Always show how many items are done vs remaining

---

## 🧪 Testing Scenarios

### Test 1: Import Product Already in Inventory
1. User has "Product A" with 10 units
2. User imports "Product A" with 25 units
3. Verify: UI shows existing quantity (10)
4. Verify: UI shows calculation (10 + 25 = 35)
5. Verify: After import, equipment has 35 units

### Test 2: Partial Import Resume
1. User uploads 50 products
2. User imports 30 products
3. User logs out
4. User logs back in
5. Verify: Only 20 items shown in grid
6. Verify: Progress shows "30 imported, 20 remaining"
7. Verify: User can continue with remaining 20 items

### Test 3: Show All Items Toggle
1. Resume session with partial import
2. Toggle "Show all items"
3. Verify: All 50 items shown (30 confirmed + 20 pending)
4. Verify: Confirmed items marked as "Imported"

---

## ✅ Success Criteria

- [ ] Users can see if they already have a product before selecting it
- [ ] Users understand quantities will be added, not replaced
- [ ] Users only see remaining items when resuming import
- [ ] Progress is clearly displayed (X imported, Y remaining)
- [ ] UI is intuitive and prevents confusion
- [ ] All edge cases are handled gracefully

---

**Please implement these features following the existing design system and patterns. Ensure proper error handling, loading states, and user feedback throughout the flow.**

