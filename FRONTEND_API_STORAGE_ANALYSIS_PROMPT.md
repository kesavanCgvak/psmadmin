# Frontend API Storage Analysis Prompt

## Objective
Analyze the frontend codebase to determine how API calls and responses for the import sessions feature are being handled, stored, and cached. This will help identify if the issue where sessions disappear after upload is caused by frontend storage/caching mechanisms.

## Analysis Tasks

### 1. Locate Import Sessions API Calls
Search for all instances where the import sessions API endpoints are called:

**Search for:**
- `GET /api/import/sessions` or `/import/sessions`
- `POST /api/import/sessions` or `/import/sessions`
- `POST /api/import/sessions/{id}/upload` or `/import/sessions/{id}/upload`
- `POST /api/import/sessions/{id}/cancel` or `/import/sessions/{id}/cancel`
- Any axios/fetch calls related to "import" and "sessions"
- Service files or API client files that handle import endpoints

**Questions to answer:**
- Where is the GET sessions API called? (component, hook, service)
- When is it called? (on mount, after upload, on modal close, etc.)
- Is it called immediately after upload completes?
- Is there any debouncing or throttling?

### 2. Check Response Storage Mechanisms

**Search for:**
- `localStorage.setItem` or `localStorage.getItem` with "import" or "session"
- `sessionStorage.setItem` or `sessionStorage.getItem` with "import" or "session"
- State management stores (Redux, Zustand, Pinia, Vuex) that store import sessions
- React Context or Vue provide/inject that stores import sessions
- Any caching libraries (React Query, SWR, Apollo Client) used for import sessions

**Questions to answer:**
- Are API responses stored in localStorage?
- Are API responses stored in sessionStorage?
- Are API responses stored in state management (Redux/Zustand/etc.)?
- Are API responses cached by a library (React Query, SWR)?
- What keys are used for storage? (e.g., "importSessions", "sessions", etc.)

### 3. Check Caching Configuration

**Search for:**
- React Query configuration (`useQuery`, `queryClient`, `staleTime`, `cacheTime`)
- SWR configuration (`useSWR`, `revalidate`, `dedupingInterval`)
- Axios interceptors that cache responses
- Custom caching logic or middleware

**Questions to answer:**
- Is React Query or SWR used for import sessions API calls?
- What is the cache/stale time configuration?
- Is there any response caching middleware?
- Are responses being cached and not invalidated after upload?

### 4. Check State Management After Upload

**Search for:**
- Code that runs after successful file upload
- Modal close handlers
- State updates after upload API call
- Cache invalidation after upload

**Questions to answer:**
- After upload completes, is the sessions list refreshed?
- Is cache invalidated after upload?
- Is state updated after upload?
- What happens when the upload modal is closed?

### 5. Check API Call Timing

**Search for:**
- Event handlers for modal close
- useEffect hooks that trigger API calls
- Lifecycle methods that fetch sessions
- Any async/await or promise chains

**Questions to answer:**
- Is GET sessions called BEFORE upload completes?
- Is GET sessions called AFTER upload response is received?
- Is there a race condition between upload and GET sessions?
- Is there any delay or setTimeout before calling GET sessions?

### 6. Check Error Handling and Retry Logic

**Search for:**
- Error handlers for import API calls
- Retry logic or exponential backoff
- Error state management

**Questions to answer:**
- Are errors from GET sessions being silently ignored?
- Is there retry logic that might be using stale data?
- Are failed requests being cached?

## Specific Code Patterns to Look For

### React/Next.js Patterns:
```javascript
// Look for these patterns:
useQuery('/api/import/sessions', ...)
useSWR('/api/import/sessions', ...)
localStorage.setItem('importSessions', ...)
sessionStorage.setItem('importSessions', ...)
useState([...sessions])
useContext(ImportContext)
```

### Vue/Nuxt Patterns:
```javascript
// Look for these patterns:
useQuery('/api/import/sessions', ...)
useSWR('/api/import/sessions', ...)
localStorage.setItem('importSessions', ...)
sessionStorage.setItem('importSessions', ...)
ref([...sessions])
provide/inject('importSessions')
```

### Vanilla JavaScript:
```javascript
// Look for these patterns:
fetch('/api/import/sessions')
axios.get('/api/import/sessions')
localStorage.setItem(...)
sessionStorage.setItem(...)
```

## Expected Findings Report

After analysis, provide:

1. **API Call Locations**: List all files/components where import sessions API is called
2. **Storage Mechanisms**: Identify if and where responses are stored (localStorage, sessionStorage, state, cache)
3. **Caching Configuration**: Document any caching libraries and their configuration
4. **Timing Issues**: Identify if API calls are made at the wrong time
5. **State Management**: Document how state is managed and updated
6. **Potential Issues**: List any findings that could cause sessions to disappear

## Key Questions to Answer

1. ✅ **Is the GET sessions API called immediately after upload completes?**
2. ✅ **Are API responses cached? If yes, is the cache invalidated after upload?**
3. ✅ **Is there any localStorage/sessionStorage that stores sessions?**
4. ✅ **Is there a race condition where GET sessions is called before upload completes?**
5. ✅ **Does the frontend wait for upload response before calling GET sessions?**
6. ✅ **Is there any state management that might be showing stale data?**

## Files to Examine

Priority files to check:
- API service/client files (e.g., `api.js`, `services/import.js`, `api/import.ts`)
- Components that use import sessions (e.g., `ImportModal.vue`, `ImportSessions.tsx`)
- Hooks or composables (e.g., `useImportSessions.js`, `useImport.ts`)
- State management stores (e.g., `store/import.js`, `stores/import.ts`)
- Configuration files for caching libraries

## Output Format

Provide findings in this format:

```markdown
## Findings

### 1. API Call Locations
- File: `src/services/importService.js`
  - Line 45: GET /api/import/sessions called in `fetchSessions()`
  - Called from: `ImportModal.vue` on mount

### 2. Storage Mechanisms
- localStorage: ❌ Not used
- sessionStorage: ✅ Used at `src/utils/storage.js:12`
  - Key: `import_sessions_cache`
  - Set after: GET sessions response
  - Not cleared after: Upload

### 3. Caching Configuration
- React Query: ✅ Used
  - staleTime: 5 minutes
  - cacheTime: 10 minutes
  - Issue: Cache not invalidated after upload

### 4. Timing Issues
- GET sessions called: On modal open
- Upload completes: After modal closes
- Issue: GET sessions called before upload completes

### 5. Recommendations
- Invalidate React Query cache after upload
- Clear sessionStorage after upload
- Call GET sessions after upload response received
```

---

**Use this prompt with your codebase analysis tool (like Cursor, GitHub Copilot, or manual code review) to identify frontend storage and caching issues.**
