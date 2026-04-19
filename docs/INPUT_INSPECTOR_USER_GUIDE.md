# Input Inspector - User Guide

## What Problem Does This Solve?

### The Confusion

When editing a jq parser step, you've probably experienced this:

1. You see variables like `{query_products.output.data.products.edges}`
2. You write a jq expression: `. | .edges[]`
3. Pipeline fails: **"Cannot index array with string 'edges'"**
4. You're confused - the variable clearly shows `.edges` exists!

### The Truth

**You were looking at the wrong thing!**

- **Exported Variables** (`{query_products.output.data.products.edges}`) are for config substitution
- **STDIN** (what jq actually receives) is the FULL output: `{"data": {"products": {"edges": [...]}}}`

The Input Inspector shows you **what jq actually sees**.

## Real-World Examples

### Example 1: Shopify Product Parser

#### Scenario
You have a Shopify GraphQL query that fetches products, and you want to transform them into a simple array.

#### Step 1: Query Products (Shopify GraphQL)
```graphql
query getProducts($first: Int!) {
  products(first: $first) {
    edges {
      node {
        id
        title
        handle
        priceRange {
          minVariantPrice {
            amount
            currencyCode
          }
        }
      }
    }
  }
}
```

**Exported Variables:**
- Path: `data.products.edges` → Name: `products_list`

#### Step 2: Transform Products (JQ Parser)

**WITHOUT Input Inspector (The Old Way):**

❌ User sees variable: `{query_products.output.data.products.edges}`
❌ User writes jq: `. | .edges[] | .node`
❌ Pipeline fails: `Cannot index array with string "edges"`

**WITH Input Inspector (The New Way):**

✅ User opens Input Inspector
✅ User sees actual STDIN:
```json
{
  "data": {
    "products": {
      "edges": [
        {
          "node": {
            "id": "gid://shopify/Product/123",
            "title": "Premium Widget",
            "handle": "premium-widget",
            "priceRange": {
              "minVariantPrice": {
                "amount": "29.99",
                "currencyCode": "USD"
              }
            }
          }
        }
      ]
    }
  }
}
```

✅ User understands: jq receives the FULL output, not just `.edges`
✅ User writes correct jq expression in Test Playground:
```jq
. | .data.products.edges[] | .node | {
  id,
  title,
  handle,
  price: .priceRange.minVariantPrice.amount
}
```

✅ User clicks "Test Expression", sees output:
```json
[
  {
    "id": "gid://shopify/Product/123",
    "title": "Premium Widget",
    "handle": "premium-widget",
    "price": "29.99"
  }
]
```

✅ Expression works! User saves step confidently.

---

### Example 2: Conditional Filtering

#### Scenario
You want to filter products that are out of stock and extract only certain fields.

#### Step 1: Query Products (same as above)

#### Step 2: Filter Out of Stock (JQ Parser)

**Using Input Inspector:**

1. **Open Data Preview tab** - See full STDIN structure
2. **Switch to Test Playground tab**
3. **Write expression:**
```jq
.data.products.edges[]
| .node
| select(.totalInventory == 0)
| {
    id,
    title,
    handle,
    outOfStock: true
  }
```

4. **Click "Test Expression"**
5. **See output immediately:**
```json
[
  {
    "id": "gid://shopify/Product/456",
    "title": "Sold Out Widget",
    "handle": "sold-out-widget",
    "outOfStock": true
  }
]
```

6. **Save step** - No trial and error needed!

---

### Example 3: Understanding Input Sources

#### Scenario
You have a multi-step pipeline and want to use data from a SPECIFIC step, not just the previous one.

#### Pipeline Structure:
```
Row 1, Col 1: fetch_orders (Shopify)
Row 1, Col 2: extract_customer_emails (JQ)
Row 1, Col 3: send_notifications (Email)
Row 2, Col 1: fetch_products (Shopify)
Row 2, Col 2: enrich_with_order_data (JQ) ← Uses BOTH fetch_orders AND fetch_products
```

#### Step: enrich_with_order_data

**Input Source Configuration:**
- Input Source: `Get from specific step`
- Get From Step: `fetch_products`

**Input Inspector shows:**

Badge: `STDIN: fetch_products`

Description:
```
Input Source: Get From Step
This step receives STDIN from: fetch_products → this_step
```

**STDIN Preview:**
```json
{
  "data": {
    "products": {
      "edges": [...]
    }
  }
}
```

**Variable Browser shows:**
```
{fetch_orders.output.data.orders.edges}
{fetch_products.output.data.products.edges}
```

**Now user understands:**
- **STDIN** = full output from `fetch_products` (what jq operates on)
- **Variables** = can reference BOTH steps in config fields

**JQ Expression:**
```jq
# STDIN is from fetch_products
.data.products.edges[] | .node | {
  product_id: .id,
  product_title: .title,
  # But we can't access orders here - that's what variables are for!
}
```

**Config field (uses variables):**
```
email_subject: "Product {context.product_title} matches order {fetch_orders.output.data.orders.edges[0].node.name}"
```

---

### Example 4: STDIN vs Variables Comparison

#### Scenario
You're confused about when to use STDIN vs variables.

#### Open "STDIN vs Variables" Tab

**Left Side: STDIN (What jq receives)**
```json
{
  "data": {
    "products": {
      "edges": [
        {"node": {"id": "123", "title": "Widget A"}},
        {"node": {"id": "456", "title": "Widget B"}}
      ],
      "pageInfo": {
        "hasNextPage": false
      }
    }
  },
  "extensions": {
    "cost": {
      "requestedQueryCost": 5
    }
  }
}
```
Caption: ↑ Full output from query_products

**Right Side: Exported Variables (For substitution)**
```
{query_products.output.data.products.edges}
→ [array of product nodes]

{query_products.output.extensions.cost}
→ cost tracking object
```
Caption: ↑ Specific paths available for {variable} use

**Key Differences (shown below):**
- ✅ **STDIN**: Used by jq/bash/parser steps as their input data
- ✅ **Variables**: Used in config fields with `{stepname.path}` syntax
- ✅ jq expressions operate on STDIN, not variables
- ✅ Variables are created by exporting paths from step outputs

**Now user understands:**
- Use jq to TRANSFORM stdin: `. | .data.products.edges[]`
- Use variables to INSERT into config: `url: "shop.com/{context.shop_id}"`

---

## Common Use Cases

### Use Case 1: "I need to extract nested data"

**Problem:** Data is deeply nested, and you need specific fields.

**Solution:**
1. Open Data Preview tab
2. See the full structure
3. Write jq path: `.data.level1.level2.level3.targetField`
4. Test in playground
5. Verify output

**Example:**
```jq
# STDIN
{
  "response": {
    "data": {
      "user": {
        "profile": {
          "email": "user@example.com"
        }
      }
    }
  }
}

# JQ Expression
.response.data.user.profile.email

# Output
"user@example.com"
```

---

### Use Case 2: "I need to flatten an array of objects"

**Problem:** API returns nested edges/nodes structure, you want flat array.

**Solution:**
1. Open playground
2. Test expression: `. | .data.items.edges[] | .node`
3. See flattened result
4. Save

**Example:**
```jq
# STDIN
{
  "data": {
    "items": {
      "edges": [
        {"node": {"id": 1, "name": "A"}},
        {"node": {"id": 2, "name": "B"}}
      ]
    }
  }
}

# JQ Expression
.data.items.edges[] | .node

# Output
[
  {"id": 1, "name": "A"},
  {"id": 2, "name": "B"}
]
```

---

### Use Case 3: "I need to combine fields from multiple levels"

**Problem:** You want to create new objects with fields from different nesting levels.

**Solution:**
1. Inspect STDIN structure
2. Write jq expression with object construction
3. Test and verify

**Example:**
```jq
# STDIN
{
  "order": {
    "id": "12345",
    "customer": {
      "name": "John Doe",
      "contact": {
        "email": "john@example.com"
      }
    },
    "items": [
      {"sku": "WIDGET-001", "qty": 2}
    ]
  }
}

# JQ Expression
{
  order_id: .order.id,
  customer_name: .order.customer.name,
  customer_email: .order.customer.contact.email,
  first_item: .order.items[0].sku
}

# Output
{
  "order_id": "12345",
  "customer_name": "John Doe",
  "customer_email": "john@example.com",
  "first_item": "WIDGET-001"
}
```

---

### Use Case 4: "I need to filter data based on conditions"

**Problem:** Only want items matching certain criteria.

**Solution:**
1. Test filter expression in playground
2. See filtered results immediately
3. Adjust filter until correct

**Example:**
```jq
# STDIN
{
  "products": [
    {"id": 1, "price": 100, "status": "active"},
    {"id": 2, "price": 50, "status": "archived"},
    {"id": 3, "price": 150, "status": "active"}
  ]
}

# JQ Expression
.products[] | select(.status == "active" and .price > 75)

# Output
[
  {"id": 1, "price": 100, "status": "active"},
  {"id": 3, "price": 150, "status": "active"}
]
```

---

## Troubleshooting Guide

### Issue 1: "Input Inspector shows 'No STDIN Available'"

**Possible Causes:**
1. **Input Source is set to "Context"**
   - Solution: Change to "Previous Step" or "Get From Step"

2. **This is the first step in the pipeline**
   - Solution: First steps don't have STDIN. They only use context variables.

3. **No pipeline runs yet**
   - Solution: Run the pipeline once to generate preview data.

---

### Issue 2: "STDIN data looks wrong"

**Possible Causes:**
1. **Wrong input source selected**
   - Check: Is "Input Source" pointing to the right step?
   - Solution: Change input source or getfrom step name

2. **Previous step failed**
   - Check: Did the source step execute successfully?
   - Solution: Fix source step errors first

3. **Looking at old run data**
   - Solution: Run pipeline again to update preview

---

### Issue 3: "JQ test fails but expression looks correct"

**Common Mistakes:**

❌ **Trying to access exported variable in jq:**
```jq
# WRONG - This won't work
{query_products.output.data.products}
```

✅ **Access data from STDIN:**
```jq
# CORRECT - jq operates on STDIN
.data.products
```

❌ **Wrong nesting level:**
```jq
# WRONG - Missing .data wrapper
.products.edges[]
```

✅ **Correct path:**
```jq
# CORRECT - Include full path from STDIN root
.data.products.edges[]
```

❌ **Array indexing on object:**
```jq
# WRONG - .edges is an object, not array
.data.edges[]
```

✅ **Correct structure:**
```jq
# CORRECT - Check STDIN structure first!
.data.products.edges[]
```

---

### Issue 4: "Expression works in test but fails in pipeline"

**Possible Causes:**

1. **Input source changed after testing**
   - Solution: Verify input source matches what you tested

2. **Data structure changed between runs**
   - Solution: Re-run pipeline and re-test with fresh data

3. **Exported variables not available in jq context**
   - Remember: jq only sees STDIN, not variables
   - Variables work in CONFIG fields, not jq expressions

---

## Best Practices

### 1. Always Check STDIN First
Before writing any jq expression:
1. Open Input Inspector
2. Look at Data Preview tab
3. Understand the structure
4. Then write your expression

### 2. Test Before Saving
1. Write expression in playground
2. Click "Test Expression"
3. Verify output is correct
4. Only then save the step

### 3. Use STDIN vs Variables Tab
When confused about data flow:
1. Open "STDIN vs Variables" tab
2. See both side-by-side
3. Understand which is which
4. Use STDIN for jq, variables for config

### 4. Copy/Download for Complex Analysis
For large or complex data:
1. Click "Download" button
2. Open JSON in external editor
3. Analyze structure
4. Come back and write expression

### 5. Incremental Development
Build jq expressions step-by-step:

**Step 1:** Test basic access
```jq
.data
```

**Step 2:** Add nesting
```jq
.data.products
```

**Step 3:** Add array iteration
```jq
.data.products.edges[]
```

**Step 4:** Extract node
```jq
.data.products.edges[] | .node
```

**Step 5:** Shape output
```jq
.data.products.edges[] | .node | {id, title}
```

Test EACH step in playground!

---

## Quick Reference Card

### When to Use STDIN vs Variables

| Scenario | Use STDIN | Use Variables |
|----------|-----------|---------------|
| jq expression input | ✅ Yes | ❌ No |
| bash script input | ✅ Yes | ❌ No |
| Config field value | ❌ No | ✅ Yes |
| URL substitution | ❌ No | ✅ Yes |
| Condition evaluation | ❌ No | ✅ Yes |
| Data transformation | ✅ Yes | ❌ No |

### Input Inspector Tabs

| Tab | Purpose | When to Use |
|-----|---------|-------------|
| **Data Preview** | See full STDIN | Understanding what data looks like |
| **Test Playground** | Test jq expressions | Writing and debugging jq |
| **STDIN vs Variables** | Compare both | Understanding the difference |

### Common jq Patterns

```jq
# Extract nested field
.data.level1.level2.field

# Flatten array
.data.items[] | .node

# Filter array
.data.items[] | select(.status == "active")

# Map to new structure
.data.items[] | {id, name: .title}

# Get first item
.data.items[0]

# Get last item
.data.items[-1]

# Count items
.data.items | length

# Combine fields
{
  full_name: (.first_name + " " + .last_name),
  email
}

# Conditional
if .status == "active" then .id else null end
```

---

## FAQ

**Q: Why can't I see STDIN for my new step?**
A: You need to run the pipeline at least once to generate preview data. STDIN comes from the PREVIOUS execution.

**Q: Can I test jq expressions without running the whole pipeline?**
A: YES! That's exactly what the Test Playground is for. It uses STDIN from the last run.

**Q: What if I want to use a variable inside my jq expression?**
A: You can't directly. jq operates on STDIN only. Use jq to transform STDIN, then export the result as a variable for other steps.

**Q: The STDIN preview is huge. Can I download it?**
A: Yes! Click the "Download" button to save as JSON file.

**Q: Can I edit STDIN in the preview?**
A: No, it's read-only. STDIN comes from the actual step execution. To change it, modify the source step.

**Q: How do I reference data from TWO different steps?**
A: Set input_source to one step for STDIN. Use variables from the other step in config fields. jq can only process one STDIN at a time.

**Q: My jq test passed but pipeline still fails. Why?**
A: Check if:
1. Input source changed
2. Data structure changed between runs
3. You're testing with old data - try re-running pipeline

**Q: Can I use Input Inspector for non-jq steps?**
A: Yes! Any step that receives STDIN (bash, parser, etc.) benefits from seeing what input it gets.

---

## Keyboard Shortcuts

| Action | Shortcut |
|--------|----------|
| Switch tabs | `Tab` / `Shift+Tab` |
| Copy STDIN | `Ctrl+C` (when preview focused) |
| Test expression | `Ctrl+Enter` (when in playground textarea) |
| Expand/collapse panel | `Space` (when header focused) |

---

## Tips & Tricks

### Tip 1: Compare Output Between Tests
1. Test expression A, copy output
2. Test expression B
3. Paste A's output in external diff tool
4. Compare to find best approach

### Tip 2: Use Playground as Learning Tool
Experiment with jq syntax:
- Try different paths
- Test filters
- Practice transformations
- See results instantly

### Tip 3: Document Complex Expressions
When you find a working expression:
1. Copy from playground
2. Paste into step description
3. Future you will thank present you

### Tip 4: Preview Data Shape Before Export
1. Test jq transformation
2. See output shape
3. Decide what paths to export as variables
4. Configure exports based on actual output

---

## Summary

The Input Inspector solves the critical problem of **STDIN invisibility** in pipeline development.

**Before:**
- ❌ Blind guessing at data structure
- ❌ Trial-and-error debugging
- ❌ Confusion between STDIN and variables
- ❌ Wasted time on failed runs

**After:**
- ✅ See actual STDIN data
- ✅ Test transformations before running
- ✅ Understand STDIN vs variables clearly
- ✅ Build pipelines faster with confidence

**Remember:** STDIN is what jq RECEIVES. Variables are what you REFERENCE. Input Inspector shows both!
