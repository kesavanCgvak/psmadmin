# Frontend Import Sessions Fix - Solutions & Implementation Guide

## 🔴 CRITICAL ISSUE IDENTIFIED

**Root Cause:** The frontend does NOT refresh the sessions list after:
1. File upload completes
2. New session is created

This explains why sessions "disappear" - they exist in the database, but the frontend UI is showing stale data.

---

## Solution 1: Fix Missing Refresh After Upload (HIGH PRIORITY)

### Problem
When a file is uploaded, the `uploaded` event is emitted but the sessions list is never refreshed. The user must close the wizard to see the updated session.

### Implementation

**File:** `src/pages/ImportProducts.vue`

**Option A: Add event handler in parent component**

```typescript
// Add new handler function
function handleUploadComplete() {
  // Refresh sessions list immediately after upload
  void loadActiveSessions();
}

// Update ImportWizard component usage (around line 200-210)
<ImportWizard
  :session-id="currentSessionId ?? undefined"
  @complete="handleImportComplete"
  @cancel="handleCancelWizard"
  @session-created="handleSessionCreated"
  @uploaded="handleUploadComplete"  // ✅ ADD THIS LINE
/>
```

**Option B: Emit event from ImportWizard to trigger refresh**

**File:** `src/components/import/ImportWizard.vue`

Update the `handleUploaded` function:

```typescript
// Current code (line 140-144)
function handleUploaded(data: UploadResponse['data']) {
  uploadData.value = data;
  currentStep.value = 2;
  isNewSession.value = false;
  // ✅ ADD THIS: Emit event to parent to refresh sessions
  emit('sessions-changed');
}
```

Then in `ImportProducts.vue`:

```typescript
// Add handler
function handleSessionsChanged() {
  void loadActiveSessions();
}

// Update component
<ImportWizard
  @sessions-changed="handleSessionsChanged"  // ✅ ADD THIS
  // ... other props
/>
```

**Recommended:** Use Option A (simpler, more direct)

---

## Solution 2: Fix Missing Refresh After Session Creation (HIGH PRIORITY)

### Problem
When a new session is created, the sessions list is not updated, so the new session doesn't appear until the page is refreshed.

### Implementation

**File:** `src/pages/ImportProducts.vue`

**Update `handleSessionCreated` function (around line 138):**

```typescript
// Current code
function handleSessionCreated(sessionId: number) {
  currentSessionId.value = sessionId;
  showNewWizard.value = false;
}

// ✅ FIXED CODE
function handleSessionCreated(sessionId: number) {
  currentSessionId.value = sessionId;
  showNewWizard.value = false;
  // ✅ ADD THIS: Refresh sessions list to show new session
  void loadActiveSessions();
}
```

---

## Solution 3: Add Cache Busting (MEDIUM PRIORITY)

### Problem
Browser or HTTP caching might serve stale responses, especially in production environments.

### Implementation

**File:** `src/services/importService.ts`

**Update `getActiveSessions` function (around line 176):**

```typescript
// Current code
export const getActiveSessions = async (): Promise<ImportSession[]> => {
  const response = await api.get<SessionResponse>('/api/import/sessions');
  if (response.data.success && Array.isArray(response.data.data)) {
    return response.data.data;
  }
  return [];
};

// ✅ FIXED CODE with cache busting
export const getActiveSessions = async (): Promise<ImportSession[]> => {
  const response = await api.get<SessionResponse>('/api/import/sessions', {
    params: {
      _t: Date.now(), // Cache busting parameter
    },
    headers: {
      'Cache-Control': 'no-cache, no-store, must-revalidate',
      'Pragma': 'no-cache',
      'Expires': '0',
    },
  });
  if (response.data.success && Array.isArray(response.data.data)) {
    return response.data.data;
  }
  return [];
};
```

**Alternative (simpler):** Just add cache-busting query parameter:

```typescript
export const getActiveSessions = async (): Promise<ImportSession[]> => {
  const response = await api.get<SessionResponse>('/api/import/sessions', {
    params: {
      _t: Date.now(), // Simple cache busting
    },
  });
  // ... rest of code
};
```

---

## Solution 4: Add Optimistic UI Updates (OPTIONAL - NICE TO HAVE)

### Problem
UI doesn't update immediately, user has to wait for API response.

### Implementation

**File:** `src/pages/ImportProducts.vue`

**Update `handleSessionCreated` with optimistic update:**

```typescript
function handleSessionCreated(sessionId: number) {
  // ✅ Optimistically add session to list (if we have session data)
  // This provides instant feedback to user
  const optimisticSession: ImportSession = {
    id: sessionId,
    status: 'active',
    total_rows: 0,
    valid_rows: 0,
    rejected_rows: 0,
    pending_items: 0,
    stage: 1,
    stage_name: 'start',
    stage_description: 'Ready to upload file',
    created_at: new Date().toISOString(),
    updated_at: new Date().toISOString(),
  };
  
  // Add to list immediately
  activeSessions.value.unshift(optimisticSession);
  
  // Then refresh from server to get accurate data
  void loadActiveSessions();
  
  currentSessionId.value = sessionId;
  showNewWizard.value = false;
}
```

---

## Solution 5: Create Pinia Store for Shared State (OPTIONAL - LONG TERM)

### Problem
Sessions state is component-scoped, not shared across components. This makes state management harder.

### Implementation

**New File:** `src/stores/useImportSessionsStore.ts`

```typescript
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { getActiveSessions, type ImportSession } from '../services/importService';

export const useImportSessionsStore = defineStore('importSessions', () => {
  const sessions = ref<ImportSession[]>([]);
  const isLoading = ref(false);
  const lastFetched = ref<Date | null>(null);

  // Computed
  const hasSessions = computed(() => sessions.value.length > 0);
  const activeSessionCount = computed(() => 
    sessions.value.filter(s => s.status === 'active').length
  );

  // Actions
  async function fetchSessions(force = false) {
    // Don't fetch if recently fetched (within 2 seconds) unless forced
    if (!force && lastFetched.value) {
      const timeSinceLastFetch = Date.now() - lastFetched.value.getTime();
      if (timeSinceLastFetch < 2000) {
        return; // Skip if fetched recently
      }
    }

    isLoading.value = true;
    try {
      sessions.value = await getActiveSessions();
      lastFetched.value = new Date();
    } catch (error) {
      console.error('Failed to fetch import sessions:', error);
      throw error;
    } finally {
      isLoading.value = false;
    }
  }

  function addSession(session: ImportSession) {
    // Check if session already exists
    const exists = sessions.value.some(s => s.id === session.id);
    if (!exists) {
      sessions.value.unshift(session);
    }
  }

  function removeSession(sessionId: number) {
    sessions.value = sessions.value.filter(s => s.id !== sessionId);
  }

  function updateSession(sessionId: number, updates: Partial<ImportSession>) {
    const index = sessions.value.findIndex(s => s.id === sessionId);
    if (index !== -1) {
      sessions.value[index] = { ...sessions.value[index], ...updates };
    }
  }

  function getSession(sessionId: number): ImportSession | undefined {
    return sessions.value.find(s => s.id === sessionId);
  }

  function clearSessions() {
    sessions.value = [];
    lastFetched.value = null;
  }

  return {
    // State
    sessions,
    isLoading,
    lastFetched,
    // Computed
    hasSessions,
    activeSessionCount,
    // Actions
    fetchSessions,
    addSession,
    removeSession,
    updateSession,
    getSession,
    clearSessions,
  };
});
```

**Update `src/pages/ImportProducts.vue` to use store:**

```typescript
import { useImportSessionsStore } from '@/stores/useImportSessionsStore';

// In setup()
const importSessionsStore = useImportSessionsStore();

// Replace activeSessions ref with store
// const activeSessions = ref<ImportSession[]>([]); // ❌ Remove this
const activeSessions = computed(() => importSessionsStore.sessions); // ✅ Use store

// Update loadActiveSessions function
async function loadActiveSessions() {
  await importSessionsStore.fetchSessions();
}

// Update handleSessionCreated
function handleSessionCreated(sessionId: number) {
  currentSessionId.value = sessionId;
  showNewWizard.value = false;
  // Store will handle refresh
  void importSessionsStore.fetchSessions(true); // Force refresh
}

// Update handleUploadComplete
function handleUploadComplete() {
  void importSessionsStore.fetchSessions(true); // Force refresh
}
```

---

## Solution 6: Add Automatic Polling (OPTIONAL - ADVANCED)

### Problem
Sessions list doesn't update automatically if changed by another tab/window or external process.

### Implementation

**File:** `src/pages/ImportProducts.vue`

```typescript
import { onMounted, onUnmounted } from 'vue';

// Add polling interval
let pollingInterval: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
  void loadActiveSessions();
  
  // ✅ Poll every 30 seconds to keep sessions list fresh
  pollingInterval = setInterval(() => {
    void loadActiveSessions();
  }, 30000); // 30 seconds
});

onUnmounted(() => {
  // Clean up polling on unmount
  if (pollingInterval) {
    clearInterval(pollingInterval);
    pollingInterval = null;
  }
});
```

**Better approach:** Only poll when wizard is open or when there are active sessions:

```typescript
const shouldPoll = computed(() => 
  showNewWizard.value || activeSessions.value.length > 0
);

watch(shouldPoll, (should) => {
  if (should && !pollingInterval) {
    pollingInterval = setInterval(() => {
      void loadActiveSessions();
    }, 30000);
  } else if (!should && pollingInterval) {
    clearInterval(pollingInterval);
    pollingInterval = null;
  }
});
```

---

## Implementation Priority

### 🔴 IMMEDIATE (Do First - Fixes the Bug)
1. ✅ **Solution 1:** Add refresh after upload
2. ✅ **Solution 2:** Add refresh after session creation

### 🟡 SHORT TERM (Improves Reliability)
3. ✅ **Solution 3:** Add cache busting

### 🟢 LONG TERM (Nice to Have)
4. ✅ **Solution 4:** Optimistic UI updates
5. ✅ **Solution 5:** Pinia store for state management
6. ✅ **Solution 6:** Automatic polling

---

## Complete Fixed Code Example

### `src/pages/ImportProducts.vue` - Key Changes

```typescript
// Add new handler for upload complete
function handleUploadComplete() {
  // Refresh sessions list immediately after upload
  void loadActiveSessions();
}

// Update existing handler
function handleSessionCreated(sessionId: number) {
  currentSessionId.value = sessionId;
  showNewWizard.value = false;
  void loadActiveSessions(); // ✅ ADD THIS
}

// Update component template
<ImportWizard
  :session-id="currentSessionId ?? undefined"
  @complete="handleImportComplete"
  @cancel="handleCancelWizard"
  @session-created="handleSessionCreated"
  @uploaded="handleUploadComplete"  // ✅ ADD THIS
/>
```

### `src/services/importService.ts` - Cache Busting

```typescript
export const getActiveSessions = async (): Promise<ImportSession[]> => {
  const response = await api.get<SessionResponse>('/api/import/sessions', {
    params: {
      _t: Date.now(), // ✅ ADD: Cache busting
    },
  });
  if (response.data.success && Array.isArray(response.data.data)) {
    return response.data.data;
  }
  return [];
};
```

---

## Testing Checklist

After implementing fixes, test:

- [ ] Upload a file → Sessions list updates immediately
- [ ] Create new session → Sessions list shows new session
- [ ] Cancel session → Sessions list updates (already works)
- [ ] Complete import → Sessions list updates (already works)
- [ ] Close wizard → Sessions list updates (already works)
- [ ] Refresh page → Sessions list loads correctly
- [ ] Test in production → No stale data issues

---

## Expected Behavior After Fix

1. **User uploads file:**
   - Upload completes ✅
   - Sessions list refreshes automatically ✅
   - User sees updated session with file data ✅

2. **User creates new session:**
   - Session created ✅
   - Sessions list refreshes automatically ✅
   - New session appears in list ✅

3. **No more "disappearing sessions":**
   - Sessions always reflect current database state ✅
   - No stale data in UI ✅
   - Consistent behavior across environments ✅

---

## Summary

The root cause is **missing refresh calls** after upload and session creation. The backend is working correctly (sessions are saved), but the frontend UI is showing stale data because it's not refreshing the list.

**Minimum fix required:**
1. Add `void loadActiveSessions()` in `handleUploadComplete()`
2. Add `void loadActiveSessions()` in `handleSessionCreated()`
3. Add cache busting to `getActiveSessions()`

This will resolve the issue where sessions appear to "disappear" after upload.
