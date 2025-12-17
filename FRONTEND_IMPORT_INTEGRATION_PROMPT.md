# Frontend Integration Prompt for Cursor AI

## Context

We have implemented a new secure product import workflow with persistent draft state on the backend. The backend is complete and tested. Now we need to build the frontend UI to integrate with this workflow.

## Backend API Overview

The backend provides the following endpoints (all require JWT authentication):

### Base URL: `/api/import`

1. **`GET /api/import/sessions`** - List user's active import sessions
2. **`POST /api/import/sessions`** - Start a new import session
3. **`GET /api/import/sessions/{session}`** - Get session with all items and matches (preview grid)
4. **`POST /api/import/sessions/{session}/upload`** - Upload Excel file
5. **`POST /api/import/sessions/{session}/analyze`** - Run product matching
6. **`PUT /api/import/sessions/{session}/selections`** - Save draft selections
7. **`POST /api/import/sessions/{session}/confirm`** - Confirm and import selected items
8. **`POST /api/import/sessions/{session}/cancel`** - Cancel import session

## Complete Workflow

### Step 1: Start Import Session
**Endpoint:** `POST /api/import/sessions`

**Request:**
```json
{}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "status": "active",
    "created_at": "2025-12-11T12:00:00.000000Z"
  }
}
```

### Step 2: Upload Excel File
**Endpoint:** `POST /api/import/sessions/{session}/upload`

**Request:** `multipart/form-data`
- `file`: Excel file (xlsx, xls, csv)

**Response:**
```json
{
  "success": true,
  "message": "File uploaded and staged successfully",
  "data": {
    "total_rows": 5,
    "valid_rows": 4,
    "rejected_rows": 1
  }
}
```

**Excel Format:**
- Column A: Quantity
- Column B: Product Description
- Column C: Software Code (optional)

**Validation:**
- Maximum 100 rows (rejected if exceeded)
- Gibberish detection (rejects meaningless descriptions)
- Model number pattern required

### Step 3: Analyze (Run Matching)
**Endpoint:** `POST /api/import/sessions/{session}/analyze`

**Response:**
```json
{
  "success": true,
  "summary": {
    "total_rows": 4,
    "analyzed": 4,
    "rejected": 0,
    "matched": 3,
    "items": [
      {
        "id": 1,
        "excel_row_number": 2,
        "original_description": "KLARK TEKNIK DN410 Professional Parametric Equalizer",
        "detected_model": "DN410",
        "quantity": 2,
        "software_code": "RENT-001",
        "status": "analyzed",
        "matches": [
          {
            "id": 1,
            "product_id": 123,
            "psm_code": "PSM00123",
            "confidence": 0.95,
            "match_type": "exact_model",
            "product": {
              "id": 123,
              "model": "DN410 Professional Parametric Equalizer",
              "psm_code": "PSM00123",
              "brand": {
                "id": 5,
                "name": "Klark-Teknik"
              },
              "category": {
                "id": 2,
                "name": "Audio Equipment"
              },
              "sub_category": null
            }
          }
        ]
      },
      {
        "id": 2,
        "excel_row_number": 3,
        "original_description": "DN360",
        "detected_model": "DN360",
        "quantity": 1,
        "software_code": null,
        "status": "analyzed",
        "matches": [
          {
            "id": 2,
            "product_id": 124,
            "psm_code": "PSM00124",
            "confidence": 0.90,
            "match_type": "partial_model",
            "product": {
              "id": 124,
              "model": "DN-360 Graphic Equalizer",
              "psm_code": "PSM00124",
              "brand": {
                "id": 5,
                "name": "Klark-Teknik"
              },
              "category": {
                "id": 2,
                "name": "Audio Equipment"
              }
            }
          }
        ]
      },
      {
        "id": 3,
        "excel_row_number": 4,
        "original_description": "New Product XYZ-123",
        "detected_model": "XYZ-123",
        "quantity": 1,
        "software_code": null,
        "status": "analyzed",
        "matches": []
      }
    ]
  }
}
```

### Step 4: Preview & Select (Save Draft - Optional)
**Endpoint:** `PUT /api/import/sessions/{session}/selections`

**Request:**
```json
{
  "items": [
    {
      "id": 1,
      "action": "attach",
      "product_id": 123
    },
    {
      "id": 2,
      "action": "attach",
      "product_id": 124
    },
    {
      "id": 3,
      "action": "create"
    }
  ]
}
```

**Actions:**
- `attach` - Use existing product (requires `product_id`)
- `create` - Create new product (will infer types from matches)

### Step 5: Confirm & Import
**Endpoint:** `POST /api/import/sessions/{session}/confirm`

**Request:**
```json
{
  "rows": [
    {
      "row": 2,
      "action": "attach",
      "product_id": 123
    },
    {
      "row": 3,
      "action": "attach",
      "product_id": 124
    },
    {
      "row": 4,
      "action": "create"
    }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "message": "Import completed. 0 items remain pending.",
  "data": {
    "session_id": 1,
    "created_products": 1,
    "attached_products": 2,
    "total_processed": 3,
    "pending_items": 0,
    "session_remains_active": false
  }
}
```

**Partial Import Response:**
```json
{
  "success": true,
  "message": "Import completed. 2 items remain pending. You can continue later.",
  "data": {
    "session_id": 1,
    "created_products": 1,
    "attached_products": 1,
    "total_processed": 2,
    "pending_items": 2,
    "session_remains_active": true
  }
}
```

### Step 6: View Active Sessions (Resume Later)
**Endpoint:** `GET /api/import/sessions`

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "status": "active",
      "total_rows": 5,
      "valid_rows": 4,
      "rejected_rows": 1,
      "pending_items": 2,
      "created_at": "2025-12-11T12:00:00.000000Z",
      "updated_at": "2025-12-11T12:30:00.000000Z"
    }
  ]
}
```

### Step 7: Get Session Details (Resume)
**Endpoint:** `GET /api/import/sessions/{session}`

Returns the same structure as Step 3 (analyze response), but includes user's saved selections.

**Response includes `stage` information and item filtering:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "status": "active",
    "stage": 3,
    "stage_name": "review",
    "stage_description": "Matches found, review and select actions",
    "confirmed_items": 30,
    "pending_items": 20,
    "items": [
      // Only shows pending/analyzed items (confirmed items are hidden by default)
      // Use ?show_all=true to show all items including confirmed
    ]
  }
}
```

**Query Parameters:**
- `?show_all=false` (default): Only show pending/analyzed items (hide confirmed)
- `?show_all=true`: Show all items including confirmed ones

**Stage Values:**
- `stage: 1, stage_name: "start"` - No items uploaded, ready to upload file
- `stage: 2, stage_name: "upload"` - File uploaded, ready to analyze matches
- `stage: 3, stage_name: "review"` - Matches found, review and select actions
- `stage: 4, stage_name: "confirm"` - All items have actions selected, ready to import

**Resume Logic:**
When user logs back in and views an active session:
1. Check `stage` value
2. Navigate directly to that step:
   - Stage 1 → Show upload form
   - Stage 2 → Show upload summary + "Analyze" button
   - Stage 3 → Show preview grid with matches (this is where user left off)
   - Stage 4 → Show preview grid + "Confirm Import" button

## UI/UX Requirements

### 1. Import Page/Component

**Location:** Should be accessible from the main navigation (e.g., "Import Products" or "Bulk Import")

**Initial State:**
- Button: "Start New Import"
- List of active sessions (if any) with:
  - Session date/time
  - Number of pending items
  - "Continue Import" button

### 2. Import Wizard Flow

**Step 1: Upload File**
- File upload input (accept: .xlsx, .xls, .csv)
- Drag & drop support
- Show file name and size after selection
- "Upload" button
- Display validation errors if file is rejected (e.g., "Maximum 100 rows allowed")

**Step 2: Review & Match**
- Show summary: "X valid rows, Y rejected rows"
- Display rejected rows with reason (if any)
- "Analyze Matches" button

**Step 3: Match Results (Preview Grid)**
- Table/grid showing:
  - Row number
  - Original description
  - Quantity to import
  - Software code
  - Match suggestions (if any)
    - **Show existing equipment info** for each match (if product exists in user's inventory)
    - Display: "You already have X units. Importing Y will result in X+Y total."
  - Action selector (dropdown: "Attach to existing" or "Create new")
  - Product selector (if "Attach to existing" selected)
  - **Note**: Only shows pending items (confirmed items are hidden when resuming)

**Match Display:**
- For each match, show:
  - Product name (Brand + Model)
  - PSM Code
  - Confidence percentage (e.g., "95% match")
  - Match type badge (e.g., "Exact Match", "PSM Code Match")
  - **Existing Equipment Info** (if user already has this product):
    - Badge: "Already in inventory"
    - Current quantity
    - Quantity preview: "Current: X, Importing: Y, Total: X+Y"
    - Warning: "Quantities will be added, not replaced"
  - "Select" button

**Actions:**
- "Save Draft" button (saves selections without importing)
- "Import Selected" button (confirms and imports)
- "Cancel" button

**Step 4: Import Confirmation**
- Show success message
- Display summary:
  - X products created
  - Y products attached
  - Z items remaining (if partial import)
- "Continue Later" button (if items remain)
- "Finish" button (if all items processed)

### 3. Resume Import Flow

**Active Sessions List:**
- Show all active sessions
- For each session:
  - Date/time created
  - Number of pending items
  - Number of confirmed items (e.g., "30 imported, 20 remaining")
  - Current stage (e.g., "Review Matches", "Ready to Import")
  - "Continue" button

**Resume Session:**
- Load session data using `GET /api/import/sessions/{session}`
- Check `stage` value in response
- **Important**: Response only includes pending items by default (confirmed items filtered out)
- Navigate to appropriate step based on `stage`:
  - **Stage 1 (start)**: Show upload form
  - **Stage 2 (upload)**: Show upload summary + "Analyze Matches" button
  - **Stage 3 (review)**: Show preview grid with **only remaining items** (confirmed items hidden)
    - Display: "X items already imported, Y items remaining"
    - Show matches and saved selections for remaining items only
  - **Stage 4 (confirm)**: Show preview grid + "Confirm Import" button
- Allow user to:
  - Change selections (if in review stage)
  - Confirm remaining items
  - Cancel session
- **Optional**: Add toggle "Show all items" to view confirmed items if needed

### 4. Visual Design Requirements

**Match Confidence Indicators:**
- 90%+ (High): Green badge
- 70-89% (Medium): Yellow badge
- Below 70%: Gray badge (if shown)

**Status Badges:**
- "Matched" - Green
- "No Match" - Gray
- "Rejected" - Red
- "Pending" - Blue
- "Confirmed" - Green

**Table Columns:**
1. Row # (excel_row_number)
2. Description (original_description)
3. Quantity
4. Software Code
5. Matches (expandable/collapsible)
6. Action (dropdown)
7. Selected Product (if action = "attach")

### 5. Error Handling

**Display user-friendly messages for:**
- File too large
- Too many rows (>100)
- Invalid file format
- Gibberish detected
- Network errors
- Duplicate prevention errors

**Error Format:**
```json
{
  "success": false,
  "message": "Maximum 100 rows allowed per upload. Your file contains 150 data rows.",
  "errors": {
    "file": ["Maximum 100 rows allowed per upload. Your file contains 150 data rows."]
  }
}
```

## Technical Requirements

### 1. State Management

**Session State:**
- Current session ID
- Session status (uploading, analyzing, reviewing, importing)
- Items with matches
- User selections (action + product_id per item)
- Upload progress

**Persistent State:**
- List of active sessions (fetch on page load)
- Auto-save selections (optional - can use "Save Draft" button)

### 2. API Integration

**Base Configuration:**
- Base URL: `/api/import`
- Headers: `Authorization: Bearer {token}`
- Content-Type: `application/json` (except upload: `multipart/form-data`)

**Error Handling:**
- Handle 422 (validation errors)
- Handle 429 (rate limit)
- Handle 403 (unauthorized)
- Handle 500 (server errors)

### 3. File Upload

**Requirements:**
- Support .xlsx, .xls, .csv
- Max file size: 20MB
- Show upload progress
- Validate file before upload (client-side check)

### 4. Match Display

**For items with matches:**
- Show top match prominently
- Show "View all matches" link/button (if multiple matches)
- Display match details in expandable section or modal

**For items without matches:**
- Show "No matches found" message
- Default action: "Create new product"
- Show warning: "This will create a new product in the system"

### 5. Selection Management

**Per Item:**
- Action: "attach" or "create"
- If "attach": Show product selector (dropdown or search)
- If "create": Show confirmation message

**Bulk Actions:**
- "Select all matches" (auto-selects top match for all items)
- "Create all new" (sets all to "create")
- "Clear selections"

### 6. Confirmation Flow

**Before Import:**
- Show summary:
  - X items will be attached
  - Y items will be created
  - Z items will be skipped
- Confirmation dialog: "Are you sure you want to import these items?"

**After Import:**
- Success message
- Show results:
  - X products created
  - Y products attached
  - **Quantity addition summary**: "Quantities were added to existing inventory items"
  - Errors (if any)
- If partial import: 
  - Show "Continue" button
  - Display: "X items imported, Y items remaining"
  - Note: "When you continue, only remaining items will be shown"

## Implementation Checklist

### Phase 1: Basic Flow
- [ ] Create import page/component
- [ ] Implement file upload
- [ ] Display upload results
- [ ] Implement analyze endpoint
- [ ] Display match results in table
- [ ] Implement selection UI (action + product selector)
- [ ] Implement confirm endpoint
- [ ] Display import results

### Phase 1.5: Resume Functionality
- [ ] Check for active sessions on page load
- [ ] Display active sessions list with stage information
- [ ] Implement resume logic based on `stage` value
- [ ] Navigate to correct step when resuming session
- [ ] Load saved selections when resuming at review stage

### Phase 2: Draft State
- [ ] Implement save draft functionality
- [ ] Create active sessions list
- [ ] Implement resume session flow
- [ ] Load saved selections on resume

### Phase 3: Polish
- [ ] Add loading states
- [ ] Add error handling
- [ ] Add success/error notifications
- [ ] Add match confidence indicators
- [ ] Add status badges
- [ ] Improve match display (expandable, modal, etc.)
- [ ] Add bulk actions
- [ ] Add confirmation dialogs

### Phase 4: Edge Cases
- [ ] Handle rejected rows display
- [ ] Handle no matches scenario
- [ ] Handle partial imports
- [ ] Handle session cancellation
- [ ] Handle network errors
- [ ] Handle duplicate prevention errors

## Example Component Structure

```javascript
// ImportWizard.jsx
- ImportWizard (main component)
  - Step1Upload (file upload)
  - Step2Review (upload summary)
  - Step3Matches (preview grid)
  - Step4Confirm (import results)

// ImportSessionList.jsx
- List of active sessions
- Resume session functionality

// MatchGrid.jsx
- Table displaying items and matches
- Selection management
- Match display components

// MatchItem.jsx
- Individual match display
- Confidence indicator
- Select button
```

## API Service Functions Needed

```javascript
// importService.js

// Start new session
startImportSession()

// Upload file
uploadFile(sessionId, file)

// Analyze matches
analyzeMatches(sessionId)

// Get session details
getSession(sessionId)

// Save draft selections
saveSelections(sessionId, selections)

// Confirm import
confirmImport(sessionId, rows)

// List active sessions
getActiveSessions()

// Cancel session
cancelSession(sessionId)
```

## Notes

1. **Authentication:** All endpoints require JWT token in Authorization header
2. **Error Format:** Backend returns consistent error format with `success: false` and `message` or `errors` object
3. **Partial Imports:** Session remains active if items are pending - user can return later
4. **Type Matching:** Backend automatically infers types (category, brand, sub_category) from matches when creating products
5. **Duplicate Prevention:** Backend blocks creation if 90%+ match found - shows clear error message
6. **Rate Limiting:** Maximum 10 sessions per week per user
7. **Quantity Addition:** When importing products that already exist in inventory, quantities are **added** (not replaced)
   - Example: Existing 10 units + Importing 25 units = 35 total units
   - Show this clearly in UI before user confirms
   - Backend automatically handles addition - no need to calculate on frontend
8. **Resume Behavior:** When resuming an import session, only **pending items** are shown by default
   - Confirmed items are filtered out automatically by backend
   - Use `?show_all=true` query parameter to show all items
   - Display counts: "X items imported, Y items remaining"
   - Progress banner should show: "30 items imported, 20 items remaining"
9. **Existing Equipment Info:** Each match includes `existing_equipment` object if user already has the product
   - Display current quantity in match card
   - Show quantity addition preview: "Current: 10, Importing: 25, Total: 35"
   - Warning badge/icon: "Quantities will be added, not replaced"
   - Helps user make informed decisions before selecting matches

## Questions to Consider

1. Should we auto-save selections or require explicit "Save Draft"?
2. Should we show all matches or just top match by default?
3. Should we allow bulk selection of matches?
4. Should we show rejected rows in a separate section or inline?
5. Should we allow editing of product details before creating?
6. Should we show a preview of what will be created before confirming?

## Success Criteria

- User can upload Excel file and see matches
- User can select actions (attach/create) for each item
- User can save draft and resume later
- User can import selected items
- User can see import results
- User can handle partial imports
- Error messages are clear and helpful
- UI is intuitive and matches existing design system

---

**Please implement the frontend integration following this workflow and requirements. Use the existing design system and patterns from the codebase. Ensure proper error handling, loading states, and user feedback throughout the flow.**

