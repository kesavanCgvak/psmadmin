# Job Management - Quick Reference Guide

## 🚀 Quick Access

### URLs
- **Rental Jobs List:** `/admin/rental-jobs`
- **Supply Jobs List:** `/admin/supply-jobs`

### Menu Location
```
PSM Admin Panel Sidebar
  └─ JOB MANAGEMENT
      ├─ Rental Jobs (Briefcase icon)
      └─ Supply Jobs (Truck icon)
```

---

## 📊 Rental Jobs

### What You'll See in the List
| Column | Description | Example |
|--------|-------------|---------|
| ID | Unique job identifier | 123 |
| Job Name | Name of the rental request | "Summer Festival Equipment" |
| Created By | Username and email | john_doe<br>john@example.com |
| Company | Company name badge | PSM Rentals |
| Date Range | From → To dates | Oct 18, 2025 to Oct 25, 2025 |
| Delivery Address | Truncated address | 123 Main Street... |
| Products | Count of requested items | 5 |
| Supply Jobs | Count of offers received | 3 |
| Status | Current job status | Active, Pending, etc. |
| Created At | When job was created | Oct 18, 2025 |
| Actions | View button | 👁️ |

### What You'll See in Details
**Main Information:**
- Job ID and Name
- Who created it (with contact info)
- Which company they're from
- Rental period (with duration)
- Where to deliver
- Special requirements
- Global message
- Status

**Statistics:**
- Number of products requested
- Number of supply offers
- Number of comments

**Requested Products Table:**
- Product model and code
- Brand
- Category and sub-category
- Quantity needed
- Which company is assigned

**Supply Jobs Summary:**
- Each offer from providers
- Quote prices
- Important dates
- Link to view full offer

**Comments (if any):**
- Who said what to whom
- When it was said

---

## 🚚 Supply Jobs

### What You'll See in the List
| Column | Description | Example |
|--------|-------------|---------|
| ID | Unique supply job identifier | 456 |
| Rental Job | Related rental request | "Summer Festival Equipment"<br>Job #123 |
| Provider Company | Who's offering | Equipment Co.<br>Los Angeles, USA |
| Client | Who's requesting | john_doe<br>PSM Rentals |
| Quote Price | Total offer price | $1,250.00 |
| Products | Count of offered items | 4 |
| Dates | Key dates with icons | 📦 Oct 17<br>🚚 Oct 18<br>↩️ Oct 26 |
| Status | Current offer status | Accepted, Pending, etc. |
| Created At | When offer was made | Oct 18, 2025 |
| Actions | View button | 👁️ |

### What You'll See in Details
**Main Information:**
- Supply Job ID
- Link to related rental job
- Provider company details (location, currency)
- Client information
- Quote price (big and bold)
- Status
- Notes
- All dates:
  - 📦 Packing Date
  - 🚚 Delivery Date
  - ↩️ Return Date
  - 📭 Unpacking Date

**Statistics:**
- Number of products offered
- Number of comments
- Average price per product

**Timeline (if dates exist):**
- Visual timeline showing packing → delivery → return → unpacking

**Offered Products Table:**
- Product model and code
- Brand
- Category and sub-category
- Quantity offered
- Price per unit
- Total price (auto-calculated)
- **Grand Total** at the bottom

**Related Rental Job Context:**
- Original rental job details
- Rental period
- Delivery address
- Number of products requested

**Comments (if any):**
- Who said what to whom
- When it was said

---

## 🎨 Status Colors

### Rental Jobs
- 🟡 **Pending** - Waiting for offers
- 🔵 **Active** - Currently ongoing
- 🟢 **Completed** - Finished successfully
- 🔴 **Cancelled** - Job cancelled

### Supply Jobs
- 🟡 **Pending** - Waiting for response
- 🔵 **Negotiating** - In discussion
- 🟢 **Accepted** - Offer accepted
- 🔴 **Cancelled** - Offer cancelled

---

## 🔗 Quick Navigation

### From Rental Job Details
- Click **View** next to a Supply Job → Go to that Supply Job's details
- Click **Company badge** → Go to Company details
- Click **Back to List** → Return to Rental Jobs list

### From Supply Job Details
- Click **View Rental Job** button → Go to related Rental Job details
- Click **Rental Job badge** (in header) → Go to related Rental Job details
- Click **Company badge** → Go to Company details
- Click **Back to List** → Return to Supply Jobs list

---

## 📱 Mobile/Responsive View

The tables automatically adjust for smaller screens:
- Less important columns are hidden
- Click the **+** button on a row to expand and see all details
- Priority columns (name, actions) always visible

---

## 🔍 Search & Filter

### Using DataTables Search
1. Type in the search box above the table
2. Results filter automatically
3. Search works across all visible columns

### Sorting
- Click any column header to sort
- Click again to reverse sort order
- Default: Newest jobs first (by ID)

---

## 💡 Pro Tips

1. **Finding Related Jobs:**
   - Start with a Rental Job
   - View its Supply Jobs
   - Click to see each offer in detail

2. **Understanding Pricing:**
   - Rental Jobs show # of supply offers
   - Supply Jobs show the quote price
   - Product table shows per-unit and total prices

3. **Tracking Timeline:**
   - Rental Job shows the overall period
   - Supply Job shows specific dates (pack, deliver, return)
   - Visual timeline makes it easy to see

4. **Checking Details:**
   - All contact info is shown
   - Click company badges to see full company details
   - Comments provide conversation history

5. **No Accidental Changes:**
   - Everything is read-only
   - No delete buttons
   - No edit forms
   - Just view and understand

---

## 📋 Common Tasks

### "I need to see all rental requests"
1. Click **Rental Jobs** in sidebar
2. Browse the list or search
3. Click eye icon to see details

### "I need to see all offers from a specific company"
1. Click **Supply Jobs** in sidebar
2. Type company name in search box
3. Results will filter automatically

### "I need to see the history of a specific rental"
1. Click **Rental Jobs** in sidebar
2. Find and click eye icon on the job
3. Scroll to see all products, offers, and comments

### "I need to check the pricing for a supply offer"
1. Click **Supply Jobs** in sidebar
2. Find and click eye icon on the offer
3. See quote price in header
4. Scroll to products table for breakdown
5. See grand total at bottom

### "I need to see what products were requested"
1. Go to Rental Job details
2. Scroll to "Requested Products" section
3. See full list with quantities

### "I need to see what products were offered"
1. Go to Supply Job details
2. Scroll to "Offered Products" section
3. See full list with prices

---

## ⚠️ Remember

- ✅ **View-Only:** You can look but not edit
- ✅ **All Data Shown:** Every relationship is displayed
- ✅ **Clear Layout:** Information is organized logically
- ✅ **Responsive:** Works on all devices
- ✅ **Fast Search:** DataTables search is instant
- ✅ **Easy Navigation:** Links between related items

---

## 🎯 At a Glance

```
Rental Jobs = Requests for equipment
  ├─ Created by users
  ├─ From specific companies
  ├─ For specific dates
  ├─ Requesting specific products
  └─ Receiving supply offers

Supply Jobs = Offers to provide equipment
  ├─ From provider companies
  ├─ For specific rental jobs
  ├─ With quote prices
  ├─ Offering specific products
  └─ With delivery/return dates
```

---

**That's it! You're ready to manage jobs effectively.** 🎉

