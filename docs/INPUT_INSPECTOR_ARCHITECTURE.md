# Input Inspector Architecture

## Data Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                         PIPELINE EXECUTION                          │
└─────────────────────────────────────────────────────────────────────┘
                                   │
                                   │
        ┌──────────────────────────┼──────────────────────────┐
        │                          │                          │
        │                          ▼                          │
        │              ┌───────────────────────┐              │
        │              │  Step 1: GraphQL      │              │
        │              │  query_products       │              │
        │              └───────────────────────┘              │
        │                          │                          │
        │                          │ Full Output             │
        │                          ▼                          │
        │              ┌───────────────────────────────────┐  │
        │              │ OUTPUT JSON:                      │  │
        │              │ {                                 │  │
        │              │   "data": {                       │  │
        │              │     "products": {                 │  │
        │              │       "edges": [...],             │  │
        │              │       "pageInfo": {...}           │  │
        │              │     }                             │  │
        │              │   },                              │  │
        │              │   "extensions": {...}             │  │
        │              │ }                                 │  │
        │              └───────────────────────────────────┘  │
        │                          │                          │
        │                          │                          │
        │              ┌───────────┴───────────┐              │
        │              │                       │              │
        │              ▼                       ▼              │
        │    ┌─────────────────┐    ┌──────────────────┐     │
        │    │ EXPORTED VARS   │    │ STDIN (piped)    │     │
        │    │                 │    │                  │     │
        │    │ User chooses    │    │ FULL output goes │     │
        │    │ specific paths: │    │ to next step:    │     │
        │    │                 │    │                  │     │
        │    │ .data.products  │    │ {entire JSON}    │     │
        │    │ .edges          │    │                  │     │
        │    │                 │    │                  │     │
        │    │ Available as:   │    │                  │     │
        │    │ {query_products │    │                  │     │
        │    │  .output        │    │                  │     │
        │    │  .data          │    │                  │     │
        │    │  .products      │    │                  │     │
        │    │  .edges}        │    │                  │     │
        │    └─────────────────┘    └──────────────────┘     │
        │              │                       │              │
        │              │                       │              │
        │              ▼                       ▼              │
        │    ┌──────────────────────────────────────────┐    │
        │    │  Step 2: JQ Parser                       │    │
        │    │  store_original_state                    │    │
        │    │                                          │    │
        │    │  Config fields can use:                  │    │
        │    │  • {query_products.output.data.products} │    │
        │    │  • {context.pipeline_name}               │    │
        │    │                                          │    │
        │    │  JQ expression operates on STDIN:        │    │
        │    │  . | .data.products.edges[] | .node      │    │
        │    │      ↑                                   │    │
        │    │      └─ Receives FULL output as STDIN    │    │
        │    └──────────────────────────────────────────┘    │
        │                          │                          │
        └──────────────────────────┼──────────────────────────┘
                                   │
                                   ▼
                           [Next step...]
```

## The Critical Difference

### STDIN vs Exported Variables

| Aspect | STDIN | Exported Variables |
|--------|-------|-------------------|
| **Purpose** | Data to process | Data to reference |
| **Scope** | Input for jq/bash/parser | Config field substitution |
| **Content** | Full output from source step | Specific paths user chose |
| **Format** | Raw JSON | `{stepname.output.path}` syntax |
| **Usage** | Piped directly to step | Replaced in config strings |
| **Example** | `{"data": {...}, "extensions": {...}}` | `{query_products.output.data.products}` |

### Visual Example

```
Step 1 Output (query_products):
┌────────────────────────────────────────┐
│ {                                      │
│   "data": {                            │
│     "products": {                      │
│       "edges": [                       │  ← User exports: "data.products.edges"
│         {"node": {...}},               │
│         {"node": {...}}                │
│       ],                               │
│       "pageInfo": {...}                │
│     }                                  │
│   },                                   │
│   "extensions": {...}                  │  ← User exports: "extensions.cost"
│ }                                      │
└────────────────────────────────────────┘
            │                    │
            │                    │
   ENTIRE   │                    │  SPECIFIC
   OUTPUT   │                    │  PATHS ONLY
            │                    │
            ▼                    ▼
   ┌────────────────┐   ┌──────────────────────────┐
   │ STDIN          │   │ EXPORTED VARIABLES       │
   │                │   │                          │
   │ (jq receives   │   │ {query_products.output   │
   │  this)         │   │  .data.products.edges}   │
   │                │   │                          │
   │ Full JSON →    │   │ {query_products.output   │
   │                │   │  .extensions.cost}       │
   └────────────────┘   └──────────────────────────┘
            │                           │
            │                           │
            ▼                           ▼
   ┌────────────────────┐    ┌──────────────────────┐
   │ JQ EXPRESSION      │    │ CONFIG SUBSTITUTION  │
   │                    │    │                      │
   │ . | .data          │    │ url: "shop.com/     │
   │   .products        │    │   {context.param}"   │
   │   .edges[]         │    │                      │
   │   | .node          │    │ Replaced before      │
   │                    │    │ execution            │
   └────────────────────┘    └──────────────────────┘
```

## Input Inspector Component Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    INPUT INSPECTOR PANEL                     │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  [📊 Input Inspector]  [STDIN: Previous Step ▼]             │
│                                                              │
├─────────────────────────────────────────────────────────────┤
│  Tabs: [Data Preview] [Test Playground] [STDIN vs Vars]    │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  TAB 1: DATA PREVIEW                                        │
│  ┌──────────────────────────────────────────────────────┐   │
│  │ ⚠ What is STDIN?                                     │   │
│  │ STDIN is the full output that gets piped to this     │   │
│  │ step. Different from Exported Variables.             │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                              │
│  ┌──────────────────────────────────────────────────────┐   │
│  │ 🔀 Input Source: Previous Step                       │   │
│  │ query_products → this_step                           │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                              │
│  ┌──────────────────────────────────────────────────────┐   │
│  │ {                                  [2.4KB] [Copy] [⬇]│   │
│  │   "data": {                                          │   │
│  │     "products": {                                    │   │
│  │       "edges": [...]                                 │   │
│  │     }                                                │   │
│  │   }                                                  │   │
│  │ }                                                    │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                              │
│  TAB 2: TEST PLAYGROUND                                     │
│  ┌──────────────────────────────────────────────────────┐   │
│  │ 💡 Test Your Expression                              │   │
│  │ Write and test jq against actual STDIN               │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                              │
│  ┌──────────────────────────────────────────────────────┐   │
│  │ JQ Expression:                                       │   │
│  │ ┌────────────────────────────────────────────────┐   │   │
│  │ │ . | .data.products.edges[] | .node             │   │   │
│  │ └────────────────────────────────────────────────┘   │   │
│  │                                                      │   │
│  │ [▶ Test Expression]                                  │   │
│  │                                                      │   │
│  │ Output:                                              │   │
│  │ ┌────────────────────────────────────────────────┐   │   │
│  │ │ [{                                             │   │   │
│  │ │   "id": "...",                                 │   │   │
│  │ │   "title": "Premium Widget"                    │   │   │
│  │ │ }]                                             │   │   │
│  │ └────────────────────────────────────────────────┘   │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                              │
│  TAB 3: STDIN VS VARIABLES                                  │
│  ┌──────────────────────────────────────────────────────┐   │
│  │ Understanding the Difference                         │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                              │
│  ┌───────────────────────┐  ┌────────────────────────────┐  │
│  │ STDIN                 │  │ EXPORTED VARIABLES         │  │
│  │ (What jq receives)    │  │ (For substitution)         │  │
│  ├───────────────────────┤  ├────────────────────────────┤  │
│  │ {                     │  │ {query_products.output     │  │
│  │   "data": {...},      │  │  .data.products.edges}     │  │
│  │   "extensions": {...} │  │                            │  │
│  │ }                     │  │ {query_products.output     │  │
│  │                       │  │  .extensions.cost}         │  │
│  └───────────────────────┘  └────────────────────────────┘  │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

## API Endpoints

### 1. GET `/pipelines/getstdin/{pipelineId}`

**Purpose:** Fetch STDIN data that a step will receive

**Query Parameters:**
- `row` - Step row position
- `col` - Step column position
- `step_id` - Current step ID (0 for new)
- `input_source` - Where input comes from (`previous`, `getfrom`, `context`)
- `getfrom_step` - Step name if `input_source=getfrom`

**Response:**
```json
{
  "success": true,
  "data": {
    "stdin": {
      "data": {
        "products": {
          "edges": [...]
        }
      }
    },
    "variables": {
      "query_products.output.data.products.edges": [...],
      "query_products.output.extensions.cost": {...}
    },
    "source_step": {
      "name": "query_products",
      "label": "Query Products"
    }
  },
  "message": "STDIN data loaded"
}
```

**Error Cases:**
- No previous runs → "No pipeline runs yet. Run the pipeline to generate preview data."
- Source step not executed → "Source step has not been executed yet"
- Context only → Returns `stdin: null`

### 2. POST `/pipelines/testjq`

**Purpose:** Test a jq expression against stdin data

**Request Body:**
```json
{
  "csrf_token": "...",
  "expression": ". | .data.products.edges[] | .node",
  "stdin": {
    "data": {
      "products": {
        "edges": [...]
      }
    }
  }
}
```

**Success Response:**
```json
{
  "success": true,
  "data": {
    "output": [
      {"id": "...", "title": "Premium Widget"},
      {"id": "...", "title": "Standard Widget"}
    ]
  },
  "message": "Expression executed successfully"
}
```

**Error Response:**
```json
{
  "success": false,
  "message": "jq: error (at <stdin>:1): Cannot index array with string \"edges\""
}
```

## State Management

### Component State

```javascript
{
  currentStdinData: null,           // Full STDIN data object
  inputSource: 'previous',          // 'previous', 'getfrom', 'context'
  sourceStepName: 'query_products', // Where STDIN comes from
  isLoading: false,                 // Loading spinner state
  activeTab: 'preview',             // 'preview', 'playground', 'comparison'
  jqExpression: '',                 // Current jq expression being tested
  jqOutput: null,                   // Result of jq test
  jqError: null                     // Error from jq test
}
```

### Event Flow

```
User opens step modal
    ↓
openStepModal(row, col, stepId)
    ↓
updateInputInspector()
    ├─ Read input_source value
    ├─ Update badge text
    └─ Call loadStdinData(row, col, stepId, inputSource)
        ↓
        Fetch /pipelines/getstdin
        ↓
        ┌─ Success
        │  ├─ Store currentStdinData
        │  ├─ Render data preview
        │  └─ Update comparison view
        │
        └─ Error
           └─ Show no-data state with message

User changes input_source dropdown
    ↓
    updateInputInspector()
    ↓
    (Re-fetch STDIN from new source)

User enters jq expression and clicks "Test"
    ↓
    testJqExpression()
    ↓
    POST /pipelines/testjq
    ↓
    ┌─ Success
    │  └─ Show output in green box
    │
    └─ Error
       └─ Show error in red box
```

## Integration Points

### 1. Step Editor Modal

The Input Inspector sits between:
- **Above:** Input Source dropdown (determines what STDIN is)
- **Below:** Variable Browser (shows exported variables)

This positioning helps users understand the relationship between:
1. Where input comes from (Input Source)
2. What the raw input looks like (Input Inspector)
3. What variables are available (Variable Browser)

### 2. Step Type Awareness

**Show playground tab only for:**
- `parser` (jq parser)
- `jq` (if standalone jq step exists)
- Future: `bash`, `python`, etc.

**Hide for:**
- `shopify_graphql` (doesn't use STDIN transformation)
- `webhook_out` (uses variables in config, not STDIN)
- `ai_agent` (prompt-based, not data transformation)

### 3. Data Source Logic

```javascript
function determineStdinSource(row, col, inputSource, getfromStep) {
  if (inputSource === 'context') {
    return { type: 'none', message: 'Context only - no STDIN' };
  }

  if (inputSource === 'getfrom' && getfromStep) {
    return {
      type: 'specific',
      stepName: getfromStep,
      message: `STDIN from step: ${getfromStep}`
    };
  }

  // Default: previous step
  const prevCol = col - 1;
  if (prevCol < 1) {
    return {
      type: 'none',
      message: 'First step - no previous STDIN'
    };
  }

  return {
    type: 'previous',
    col: prevCol,
    message: 'STDIN from previous step'
  };
}
```

## Mobile Responsiveness

```css
@media (max-width: 768px) {
  /* Stack comparison side-by-side into vertical */
  .comparison-grid {
    grid-template-columns: 1fr;
  }

  /* Make preview toolbar static (not absolute) */
  .preview-toolbar {
    position: static;
    margin-bottom: 10px;
    justify-content: flex-end;
  }

  /* Reduce padding */
  .input-tab {
    padding: 8px 12px;
    font-size: 0.8rem;
  }

  /* Smaller code font on mobile */
  .data-preview,
  .jq-expression-input {
    font-size: 0.75rem;
  }
}
```

## Performance Considerations

### 1. Lazy Loading
- Only load STDIN when panel is expanded
- Cache STDIN data per step to avoid re-fetching
- Debounce input_source changes

### 2. Large Data Handling
- Limit preview to first 100KB
- Offer "Download Full Data" button if truncated
- Use virtual scrolling for large JSON trees (future enhancement)

### 3. JQ Testing
- Server-side timeout (5 seconds max)
- Client-side debounce on "Test" button
- Show spinner during test execution

## Accessibility

- Keyboard navigation for tabs (Arrow keys)
- ARIA labels on all interactive elements
- Focus management when switching tabs
- Screen reader announcements for test results
- High contrast mode support

## Error Handling

| Error Scenario | User Message | Action |
|----------------|--------------|--------|
| No pipeline runs | "No pipeline runs yet. Run the pipeline to generate preview data." | Show empty state |
| Source step not found | "Source step not found. Check input source configuration." | Show error state |
| Source step not executed | "Source step hasn't executed yet. Run pipeline to see data." | Show empty state |
| JQ test timeout | "Expression took too long to execute (>5s). Simplify your expression." | Show error in playground |
| Network error | "Failed to load STDIN data. Check connection." | Show retry button |

## Future Enhancements

1. **Monaco Editor** - Syntax highlighting for jq
2. **AI Suggestions** - "Based on your data, try: `.data.products[]`"
3. **Expression History** - Save recently tested expressions
4. **Schema Viewer** - Tree view of JSON structure
5. **Diff View** - Before/after transformation comparison
6. **Step Preview** - Live preview as you type jq
7. **Data Sampling** - Test against subset for large datasets
8. **Export Templates** - Common patterns (flatten, map, filter)
