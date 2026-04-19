# Shopify App Builder - Complete UI Tutorial

**How to Create and Deploy a Shopify App Using Only the MyCTOBot UI**

This tutorial walks through creating a complete Shopify app with pipeline integration, using only the web UI - no command line required.

---

## Overview

We'll build a **Product Badges** app that:
- Displays dynamic badges on product pages
- Fetches badge data from a MyCTOBot pipeline
- Includes an announcement bar with session persistence
- Deploys to Shopify as a theme app extension

**Time to Complete**: ~30 minutes
**Prerequisites**:
- MyCTOBot account on dev.myctobot.ai
- Shopify Partner account
- Dev store (e.g., myctobot-dev.myshopify.com)

---

## Part 1: Create the Shopify App (5 min)

### Step 1: Navigate to Apps

1. Log in to https://dev.myctobot.ai
2. From the sidebar, click **"Shopify Apps"** (or navigate to `/apps?target=shopify`)

**Screenshot**: 01-apps-list.png

### Step 2: Create New App

1. Click the **"New App"** button (top right)
2. Fill in the form:
   - **Name**: Product Badges
   - **Slug**: test-landing-page
   - **Description**: Dynamic product badges with pipeline integration
   - **Deployment Target**: Shopify
3. Click **"Save"**

**What You'll See**: The app appears in the list with ID #1 and status "pending"

---

## Part 2: Create the Pipeline Backend (10 min)

### Step 3: Navigate to Pipelines

From your new app's row, you'll see several buttons:
- 📋 Screens
- 🔗 Pipelines  ← Click this one
- 💎 Shopify
- 🔨 Build

**Screenshot**: 06-pipelines-breadcrumb-missing-step.png

### Step 4: Create Product Badges Pipeline

1. Click **"New Pipeline"** button
2. Fill in the form:
   - **Name**: Product Badges API
   - **Slug**: product-badges
   - **Description**: Returns dynamic badge data for products
   - **Webhook Type**: Incoming (default)
3. Click **"Save"**

**What You'll See**: Pipeline created with ID #45

**Screenshot**: product-badges-pipeline.png

### Step 5: Add Pipeline Step

1. From the pipeline list, click **"Steps"** for your new pipeline
2. Click **"New Step"** button
3. Fill in the step form:
   - **Name**: generate_badges
   - **Step Type**: parser (from dropdown)
   - **Order**: 1
   - **Parser Type**: php
4. In the **PHP Expression** field, paste:

```php
return [
    "badges" => [
        [
            "id" => "trending",
            "label" => "🔥 Trending",
            "color" => "#ff6b6b"
        ],
        [
            "id" => "low-stock",
            "label" => "⚡ Low Stock",
            "color" => "#f39c12"
        ],
        [
            "id" => "new-arrival",
            "label" => "✨ New Arrival",
            "color" => "#3498db"
        ]
    ]
];
```

5. Click **"Save"**

### Step 6: Test the Pipeline

1. From the pipeline list, click **"Run"** next to your pipeline
2. Leave the context empty (or add test data if desired)
3. Click **"Execute"**

**What You'll See**:
- Success message
- Output showing the badges array
- Run ID (e.g., #894)

**Screenshot**: product-badges-run-success.png

**✅ CHECKPOINT**: Your pipeline is now working and ready to be called from Shopify!

---

## Part 3: Create Liquid Files (10 min)

### Step 7: Navigate to Shopify Files

From the app list at `/apps`:
1. Find your **Product Badges** app
2. Click the **💎 Shopify** button

**What You'll See**: Liquid files list (currently empty)

**Screenshot**: 02-liquidfiles-initial.png

### Step 8: Create Product Badges Block

1. Click **"New File"** button (top right)
2. Fill in the form:
   - **File Name**: product-badges
   - **File Type**: block (from dropdown)
   - **Display Name**: Product Badges (Pipeline)
   - **Description**: Dynamic badges from MyCTOBot Pipeline
   - **Sort Order**: 1

3. In the **File Content** editor, paste:

```liquid
{% comment %}
  Product Badges - Dynamic badges from MyCTOBot Pipeline
  Fetches badge data from pipeline API and displays on product pages
{% endcomment %}

<div class="product-badges-container"
     id="product-badges-{{ block.id }}"
     data-product-id="{{ product.id }}">
  <div class="badges-loading">Loading badges...</div>
  <div class="badges-display" style="display: none;"></div>
</div>

<style>
  .product-badges-container {
    margin: {{ block.settings.margin_top }}px 0 {{ block.settings.margin_bottom }}px 0;
  }
  .badges-display {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .product-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 4px;
    font-size: {{ block.settings.font_size }}px;
    font-weight: 600;
    color: white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
  }
  .badges-loading {
    color: #999;
    font-style: italic;
  }
</style>

<script>
(function() {
  const container = document.getElementById('product-badges-{{ block.id }}');
  const loadingEl = container.querySelector('.badges-loading');
  const displayEl = container.querySelector('.badges-display');
  const productId = container.dataset.productId;

  // API endpoint for your pipeline
  const apiUrl = 'https://dev.myctobot.ai/api/pipelines/product-badges/run';

  fetch(apiUrl, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': 'Bearer {{ block.settings.api_token }}'
    },
    body: JSON.stringify({
      context: {
        product_id: productId,
        shop: '{{ shop.domain }}'
      }
    })
  })
  .then(response => response.json())
  .then(data => {
    loadingEl.style.display = 'none';

    if (data.success && data.output && data.output.badges) {
      data.output.badges.forEach(badge => {
        const badgeEl = document.createElement('span');
        badgeEl.className = 'product-badge';
        badgeEl.style.backgroundColor = badge.color;
        badgeEl.textContent = badge.label;
        displayEl.appendChild(badgeEl);
      });
      displayEl.style.display = 'flex';
    } else {
      loadingEl.textContent = 'No badges available';
      loadingEl.style.display = 'block';
    }
  })
  .catch(error => {
    console.error('Badge fetch error:', error);
    loadingEl.textContent = 'Failed to load badges';
  });
})();
</script>

{% schema %}
{
  "name": "Product Badges (Pipeline)",
  "target": "section",
  "settings": [
    {
      "type": "text",
      "id": "api_token",
      "label": "MyCTOBot API Token",
      "info": "Enter your API token for pipeline access"
    },
    {
      "type": "range",
      "id": "font_size",
      "min": 10,
      "max": 18,
      "step": 1,
      "default": 12,
      "label": "Badge Font Size"
    },
    {
      "type": "range",
      "id": "margin_top",
      "min": 0,
      "max": 40,
      "step": 4,
      "default": 16,
      "label": "Top Margin"
    },
    {
      "type": "range",
      "id": "margin_bottom",
      "min": 0,
      "max": 40,
      "step": 4,
      "default": 16,
      "label": "Bottom Margin"
    }
  ]
}
{% endschema %}
```

4. Click **"Save"**

**What You'll See**: File appears in the list with Monaco editor showing your code

**Screenshot**: liquid-editor-file-created.png

### Step 9: Create Announcement Bar Block

1. Click **"New File"** again
2. Fill in:
   - **File Name**: announcement-bar
   - **File Type**: block
   - **Display Name**: Announcement Bar
   - **Description**: Dismissible banner with session persistence
   - **Sort Order**: 2

3. Paste this content:

```liquid
{% comment %}
  Announcement Bar - Dismissible banner with session persistence
  Shows important messages at top of site
{% endcomment %}

<div id="announcement-bar-{{ block.id }}"
     class="announcement-bar"
     style="background: {{ block.settings.bg_color }};
            color: {{ block.settings.text_color }};
            padding: {{ block.settings.padding }}px 20px;
            text-align: center;
            font-size: {{ block.settings.font_size }}px;
            display: none;">
  <div class="announcement-content">
    {{ block.settings.message }}
  </div>
  <button class="announcement-close"
          onclick="dismissAnnouncement('{{ block.id }}')"
          style="background: transparent;
                 border: none;
                 color: inherit;
                 cursor: pointer;
                 font-size: 20px;
                 position: absolute;
                 right: 20px;
                 top: 50%;
                 transform: translateY(-50%);">
    ×
  </button>
</div>

<style>
  .announcement-bar {
    position: relative;
  }
  .announcement-content {
    max-width: 1200px;
    margin: 0 auto;
  }
</style>

<script>
  function dismissAnnouncement(blockId) {
    document.getElementById('announcement-bar-' + blockId).style.display = 'none';
    sessionStorage.setItem('announcement-' + blockId + '-dismissed', 'true');
  }

  // Show if not dismissed in this session
  (function() {
    const blockId = '{{ block.id }}';
    const dismissed = sessionStorage.getItem('announcement-' + blockId + '-dismissed');
    if (!dismissed) {
      document.getElementById('announcement-bar-' + blockId).style.display = 'block';
    }
  })();
</script>

{% schema %}
{
  "name": "Announcement Bar",
  "target": "header",
  "settings": [
    {
      "type": "textarea",
      "id": "message",
      "label": "Announcement Message",
      "default": "🎉 Welcome! Free shipping on orders over $50"
    },
    {
      "type": "color",
      "id": "bg_color",
      "label": "Background Color",
      "default": "#000000"
    },
    {
      "type": "color",
      "id": "text_color",
      "label": "Text Color",
      "default": "#ffffff"
    },
    {
      "type": "range",
      "id": "font_size",
      "min": 12,
      "max": 20,
      "default": 14,
      "label": "Font Size"
    },
    {
      "type": "range",
      "id": "padding",
      "min": 8,
      "max": 24,
      "default": 12,
      "label": "Padding"
    }
  ]
}
{% endschema %}
```

4. Click **"Save"**

**Screenshot**: announcement-bar-saved.png

**✅ CHECKPOINT**: You now have 2 Liquid files ready to build!

**Screenshot**: product-badges-file-tree.png

---

## Part 4: Build the Shopify App Bundle (5 min)

### Step 10: Navigate to Build Page

From the app list:
1. Find your **Product Badges** app
2. Click the **🔨 Build** dropdown
3. Select **"Shopify Build"**

**What You'll See**: Build dashboard showing:
- File count: 2 blocks, 0 embeds, 0 sections, etc.
- App configuration preview
- Build and Download buttons

**Screenshot**: build-page-initial.png

### Step 11: Build the Bundle

1. Check **"Create ZIP file"** (optional - for download)
2. Click **"Build Shopify App"** button
3. Wait for build process (5-10 seconds)

**What You'll See**:
- Success message
- Output directory path: `/storage/detachable/dev/shopify-test-landing-page/`
- File count: 2 files generated
- ZIP file created

**Screenshot**: build-success.png

**What Was Generated**:
```
shopify-test-landing-page/
├── shopify.app.toml          # App configuration
├── README.md                  # Deployment instructions
├── package.json               # Node.js dependencies
└── extensions/
    └── theme-app-extension/
        └── blocks/
            ├── product-badges.liquid
            └── announcement-bar.liquid
```

---

## Part 5: Deploy to Shopify (REQUIRES CLI)

**⚠️ IMPORTANT**: Deployment currently requires Shopify CLI on the server. This is not yet available through the UI alone.

### Manual Deployment Steps (Server Access Required)

If you have SSH access to the server:

```bash
# SSH to server
ssh user@dev.myctobot.ai

# Navigate to build output
cd /path/to/myctobot/storage/detachable/dev/shopify-test-landing-page

# Install dependencies (first time only)
npm install

# Deploy to Shopify
shopify app deploy --force
```

**Screenshot**: shopify-login-screen.png (OAuth authentication)

**What Happens**:
1. Shopify CLI authenticates with your Partner account
2. Validates your app configuration
3. Uploads Liquid files to Shopify
4. Creates a new app version
5. Returns a URL to view in Partner Dashboard

**Expected Output**:
```
✅ New version released to users.
   test-landing-page-5
   https://dev.shopify.com/dashboard/204479733/apps/319272419329/versions/868419108865
```

---

## Part 6: Install and Test in Theme Editor

### Step 12: Install App on Dev Store

1. Go to Shopify Partner Dashboard
2. Navigate to **Apps** → **dev-myctobot**
3. Click **"Test on development store"**
4. Select your dev store (myctobot-dev.myshopify.com)
5. Click **"Install app"**

### Step 13: Add Blocks in Theme Editor

1. In your Shopify admin, go to **Online Store** → **Themes**
2. Click **"Customize"** on your active theme
3. Navigate to a product page
4. Click **"Add block"** in the sidebar
5. Under **"Apps"** section, you'll see:
   - Product Badges (Pipeline)
   - Announcement Bar

### Step 14: Configure Product Badges Block

1. Add **"Product Badges (Pipeline)"** block to the product page
2. In block settings, configure:
   - **API Token**: Enter your MyCTOBot API token
   - **Font Size**: 12-14px recommended
   - **Margins**: Adjust spacing as needed
3. Click **"Save"**

### Step 15: Test Live

1. Click **"Preview"** to view the product page
2. Open browser DevTools (F12) → Console
3. Look for:
   - API call to `https://dev.myctobot.ai/api/pipelines/product-badges/run`
   - Response with badges array
   - Badges rendering on page

**Expected Behavior**:
- "Loading badges..." appears briefly
- API fetches data from your pipeline
- 3 badges display: 🔥 Trending, ⚡ Low Stock, ✨ New Arrival
- Each badge has correct color from pipeline

---

## Troubleshooting

### Problem: Badges Don't Appear

**Check**:
1. Is API token correct in block settings?
2. Open DevTools Console - any errors?
3. Network tab - does API call return 200 OK?
4. Response data - does it match expected format?

### Problem: "Pipeline Not Found" Error

**Check**:
1. Is pipeline slug correct? (`product-badges`)
2. Is pipeline status "active"?
3. Test pipeline manually at `/pipelines` → Run

### Problem: Build Button Disabled

**Check**:
1. Are there Liquid files created?
2. Check browser console for JavaScript errors
3. Try refreshing the page

### Problem: Deployment Fails

**Common Issues**:
- **Missing package.json**: Build should create this automatically
- **Invalid subpath**: Must use underscores/hyphens, no special chars
- **OAuth timeout**: Complete authentication within 5 minutes
- **Invalid API key**: Check `conf/shopify.ini` credentials

---

## Key Things to Know

### 1. Pipeline URL Format
```
https://dev.myctobot.ai/api/pipelines/{SLUG}/run
```
Replace `{SLUG}` with your pipeline slug (e.g., `product-badges`)

### 2. API Token
You need a Bearer token for pipeline authentication. Generate one at:
- Connectors → API Keys → Create New Token (or navigate to `/apikeys`)

### 3. App Proxy Configuration
The app uses Shopify's App Proxy feature to route requests to MyCTOBot:
- **Prefix**: `apps`
- **Subpath**: `test_landing_page` (matches app slug)
- **Full URL**: `https://STORE.myshopify.com/apps/test_landing_page`

### 4. File Types and Targets

| Type | Target | Where It Appears |
|------|--------|------------------|
| block | section | Theme editor → Add block |
| embed | head/body | Theme editor → App embeds |
| section | N/A | Theme editor → Add section |
| snippet | N/A | Referenced by other Liquid files |
| asset | N/A | CSS/JS files |

### 5. Liquid Schema Settings

The `{% schema %}` block defines merchant-facing settings:
- **text**: Single-line input
- **textarea**: Multi-line input
- **range**: Slider control
- **color**: Color picker
- **checkbox**: Boolean toggle
- **select**: Dropdown menu

### 6. Testing Pipelines

Always test your pipeline BEFORE deploying:
1. Go to `/pipelines`
2. Click "Run" on your pipeline
3. Enter test context data
4. Verify output format matches what Liquid expects

---

## Next Steps

### Extend Your App

1. **Add More Badge Logic**:
   - Check inventory levels
   - Display "On Sale" if discounted
   - Show "Best Seller" based on sales data

2. **Create Additional Blocks**:
   - Product recommendations
   - Trust badges
   - Size guide popup
   - Review summary

3. **Add Pipeline Steps**:
   - Fetch data from Shopify API
   - Check external inventory systems
   - Apply business rules
   - Log analytics

### Production Checklist

Before launching to production stores:

- [ ] Test all blocks on multiple product types
- [ ] Verify API authentication works correctly
- [ ] Check mobile responsive design
- [ ] Test performance (API response time)
- [ ] Add error handling for API failures
- [ ] Configure rate limiting on pipelines
- [ ] Set up monitoring/alerts
- [ ] Create merchant documentation
- [ ] Add app listing screenshots
- [ ] Test installation on fresh store

---

## Summary

**What You Built**:
- ✅ Shopify app with pipeline integration
- ✅ Backend pipeline returning dynamic data
- ✅ 2 Liquid app blocks (badges + announcement)
- ✅ Complete Shopify app bundle
- ✅ Deployed and tested on dev store

**What You Learned**:
- How to create apps in MyCTOBot UI
- How to build pipelines for dynamic data
- How to write Liquid templates with API calls
- How to configure Shopify app extensions
- How to deploy and test Shopify apps

**Time Investment**: ~30 minutes for complete setup

---

## Resources

- **MyCTOBot Docs**: `/docs/SHOPIFY_PLUGIN_BUILDER.md`
- **Shopify Liquid**: https://shopify.dev/docs/api/liquid
- **Theme App Extensions**: https://shopify.dev/docs/apps/online-store/theme-app-extensions
- **Pipeline Documentation**: `/docs/PIPELINE_SYSTEM.md`

---

*Last Updated: 2026-02-23*
