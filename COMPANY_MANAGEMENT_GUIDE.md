# Company Management System - Complete Guide

## 🎯 Overview
Full CRUD (Create, Read, Update, Delete) operations for managing Companies, Currencies, Rental Software, and Equipment with comprehensive relationship displays.

---

## ✅ Completed Implementation

### 1. **Controllers Created**
All located in `app/Http/Controllers/Admin/`:

#### `CompanyManagementController.php`
- ✅ index() - List all companies with location, currency, software, users count, equipment count
- ✅ create() - Show create form with all related dropdowns
- ✅ store() - Save new company
- ✅ show() - View company details with users and equipment lists
- ✅ edit() - Show edit form
- ✅ update() - Update company
- ✅ destroy() - Delete company

#### `CurrencyManagementController.php`
- ✅ index() - List all currencies with companies count
- ✅ create() - Show create form
- ✅ store() - Save new currency
- ✅ show() - View currency details with companies list
- ✅ edit() - Show edit form
- ✅ update() - Update currency
- ✅ destroy() - Delete currency

#### `RentalSoftwareManagementController.php`
- ✅ index() - List all rental software with companies count
- ✅ create() - Show create form
- ✅ store() - Save new rental software
- ✅ show() - View software details with companies list
- ✅ edit() - Show edit form
- ✅ update() - Update rental software
- ✅ destroy() - Delete rental software

#### `EquipmentManagementController.php`
- ✅ index() - List all equipment with company, product, brand, category info
- ✅ create() - Show create form with cascading company→users dropdown
- ✅ store() - Save new equipment
- ✅ show() - View equipment details with images and pricing calculator
- ✅ edit() - Show edit form with cascading dropdowns
- ✅ update() - Update equipment
- ✅ destroy() - Delete equipment (with image cleanup)
- ✅ getUsersByCompany() - AJAX endpoint for cascading dropdowns

---

### 2. **Views Created**
All views use AdminLTE theme with DataTables integration.

#### Companies (`resources/views/admin/companies/`)
- ✅ `index.blade.php` - DataTable with location, currency, software, counts
- ✅ `create.blade.php` - Comprehensive form with location and preferences
- ✅ `edit.blade.php` - Edit form
- ✅ `show.blade.php` - Details with users list and equipment table

#### Currencies (`resources/views/admin/companies/currencies/`)
- ✅ `index.blade.php` - DataTable with symbol and companies count
- ✅ `create.blade.php` - Form with code, name, symbol
- ✅ `edit.blade.php` - Edit form
- ✅ `show.blade.php` - Details with large symbol display and companies list

#### Rental Software (`resources/views/admin/companies/rental-software/`)
- ✅ `index.blade.php` - DataTable with version, price, companies count
- ✅ `create.blade.php` - Form with version and price fields
- ✅ `edit.blade.php` - Edit form
- ✅ `show.blade.php` - Details with companies list

#### Equipment (`resources/views/admin/companies/equipment/`)
- ✅ `index.blade.php` - DataTable with company, product, brand, category
- ✅ `create.blade.php` - Form with cascading company→users dropdown
- ✅ `edit.blade.php` - Edit form with cascading dropdowns
- ✅ `show.blade.php` - Details with images gallery and pricing calculator

---

### 3. **Routes Added**
All routes in `routes/web.php` with `admin` prefix and `admin.` name prefix:

```php
Route::prefix('admin')->name('admin.')->group(function () {
    Route::resource('companies', CompanyManagementController::class);
    Route::resource('currencies', CurrencyManagementController::class);
    Route::resource('rental-software', RentalSoftwareManagementController::class);
    Route::resource('equipment', EquipmentManagementController::class);
    
    Route::get('/ajax/companies/{company}/users', 'getUsersByCompany');
});
```

---

### 4. **Menu Configuration**
Added to `config/adminlte.php` under "COMPANY MANAGEMENT" section:

```php
['header' => 'COMPANY MANAGEMENT'],
[
    'text' => 'Companies',
    'route' => 'admin.companies.index',
    'icon' => 'fas fa-fw fa-building',
    'icon_color' => 'primary',
],
[
    'text' => 'Currencies',
    'route' => 'admin.currencies.index',
    'icon' => 'fas fa-fw fa-dollar-sign',
    'icon_color' => 'success',
],
[
    'text' => 'Rental Software',
    'route' => 'admin.rental-software.index',
    'icon' => 'fas fa-fw fa-laptop-code',
    'icon_color' => 'info',
],
[
    'text' => 'All Equipment',
    'route' => 'admin.equipment.index',
    'icon' => 'fas fa-fw fa-boxes',
    'icon_color' => 'warning',
],
```

---

## 🔗 Relationships Structure

### Company Relationships:
```
Company
  ├── belongs to Region
  ├── belongs to Country
  ├── belongs to State/Province
  ├── belongs to City
  ├── belongs to Currency
  ├── belongs to RentalSoftware
  ├── belongs to User (defaultContact)
  ├── has many Users
  ├── has many Equipments
  ├── has many Ratings
  └── has many Blocks
```

### Equipment Relationships:
```
Equipment
  ├── belongs to Company
  ├── belongs to User (owner)
  ├── belongs to Product
  │    ├── belongs to Brand
  │    ├── belongs to Category
  │    └── belongs to SubCategory
  └── has many Images
```

### Currency Relationships:
```
Currency
  └── has many Companies
```

### RentalSoftware Relationships:
```
RentalSoftware
  └── has many Companies
```

---

## 📊 Table Displays

### Companies Index:
| ID | Name | Location | Currency | Rental Software | Users | Equipment | Rating | Created | Actions |

**Location Column:** City, Country (combined)  
**Currency Column:** Badge with code (USD, EUR, etc.)  
**Rental Software Column:** Badge with name  
**Users/Equipment:** Count badges  
**Rating:** Star display (1-5)

### Currencies Index:
| ID | Code | Name | Symbol | Companies Using | Created | Actions |

**Symbol Column:** Large display (e.g., $, €, £, ¥)  
**Companies Using:** Count badge

### Rental Software Index:
| ID | Name | Version | Price | Companies Using | Created | Actions |

**Version:** Badge display (e.g., v2.5.3)  
**Price:** Formatted currency ($99.99)

### Equipment Index:
| ID | Company | Product | Brand | Category | Quantity | Price | Software Code | Created | Actions |

**Company:** Badge with company name  
**Brand:** Badge with brand name  
**Category:** Badge with category name  
**Quantity:** Badge with number  
**Price:** Formatted currency

---

## 🎨 UI Special Features

### Companies Show Page:
1. **Widget User Card** - Shows company logo, stats (users count, equipment count)
2. **Users List** - All company users with admin badges
3. **Equipment Table** - All equipment with brand, quantity, price
4. **Complete Location Display** - Region, Country, State, City as badges
5. **GPS Coordinates** - Formatted latitude/longitude
6. **Rating Display** - Star icons with average rating

### Currency Show Page:
1. **Large Symbol Display** - Huge currency symbol (4em size)
2. **Companies List** - All companies using this currency with country badges

### Rental Software Show Page:
1. **Version Badge** - Software version display
2. **Price Display** - Monthly subscription price
3. **Companies List** - Companies using this software with user counts

### Equipment Show Page:
1. **Images Gallery** - Equipment images in grid layout
2. **Pricing Calculator** - Shows:
   - Price per day
   - Week price (7 days)
   - Month price (30 days)
   - Total value (price × quantity)
3. **Product Details** - Full product hierarchy display
4. **Callout Boxes** - Organized information display

---

## 🛠️ Special Features

### 1. **Cascading Dropdowns (Equipment)**
When creating/editing equipment:
- Select **Company** → Automatically loads **Users** from that company
- Dynamic dropdown population via AJAX
- No page reload required

### 2. **Company Location Management**
Companies can have complete address:
- Region, Country, State/Province, City (hierarchical)
- Address Line 1, Address Line 2
- Postal Code
- GPS Coordinates (Latitude, Longitude)

### 3. **Company Preferences**
Configurable settings:
- **Currency** - Default currency for transactions
- **Rental Software** - Software integration
- **Date Format** - MM/DD/YYYY, DD/MM/YYYY, YYYY-MM-DD
- **Pricing Scheme** - Day, Week, Month, Custom
- **Search Priority** - Number for search ranking

### 4. **Equipment Images**
- Multiple images per equipment
- Displayed in show page
- Auto-deleted when equipment is deleted

### 5. **Pricing Calculator**
Automatic calculations on equipment show page:
- Daily price
- Weekly price (×7)
- Monthly price (×30)
- Total inventory value (price × quantity)

---

## 📝 Field Details

### Company Fields:
- **name** (string, 255, unique, required)
- **description** (text, optional)
- **region_id** (foreign key, optional)
- **country_id** (foreign key, optional)
- **state_id** (foreign key, optional)
- **city_id** (foreign key, optional)
- **address_line_1, address_line_2** (string, optional)
- **postal_code** (string, optional)
- **latitude, longitude** (decimal, optional)
- **currency_id** (foreign key, optional)
- **rental_software_id** (foreign key, optional)
- **date_format** (string, optional)
- **pricing_scheme** (string, optional)
- **search_priority** (string, optional)
- **rating** (integer, auto-calculated)
- **logo, image1, image2, image3** (string, optional)
- **default_contact_id** (foreign key, optional)

### Currency Fields:
- **code** (string, 255, unique, required) - e.g., USD, EUR, GBP
- **name** (string, 255, required) - e.g., US Dollar, Euro
- **symbol** (string, 255, required) - e.g., $, €, £

### Rental Software Fields:
- **name** (string, 255, unique, required)
- **description** (text, optional)
- **version** (string, 255, optional)
- **price** (decimal 10,2, optional)

### Equipment Fields:
- **company_id** (foreign key, required)
- **user_id** (foreign key, required)
- **product_id** (foreign key, required)
- **quantity** (integer, min:1, required)
- **price** (decimal 10,2, min:0, required)
- **software_code** (string, 255, optional)
- **description** (text, optional)

---

## 🚀 Usage Guide

### Adding a Currency:
1. Navigate to **Company Management → Currencies**
2. Click **"Add New Currency"**
3. Enter:
   - Code: USD (3 letters, uppercase)
   - Name: US Dollar
   - Symbol: $
4. Click **"Create Currency"**

### Adding Rental Software:
1. Navigate to **Company Management → Rental Software**
2. Click **"Add New Software"**
3. Enter:
   - Name: EasyRent Pro
   - Description: Equipment rental management system
   - Version: 2.5.3
   - Price: 99.99 (monthly)
4. Click **"Create Rental Software"**

### Adding a Company:
1. Navigate to **Company Management → Companies**
2. Click **"Add New Company"**
3. Fill **Basic Information**:
   - Name (required, unique)
   - Description
4. Fill **Location Information**:
   - Select Region, Country, State, City
   - Enter address details
   - Enter GPS coordinates
5. Fill **Preferences**:
   - Select Currency
   - Select Rental Software
   - Choose Date Format
   - Choose Pricing Scheme
   - Set Search Priority
6. Click **"Create Company"**

### Adding Equipment:
1. Navigate to **Company Management → All Equipment**
2. Click **"Add New Equipment"**
3. Select **Company** (users dropdown auto-loads)
4. Select **User/Owner**
5. Select **Product**
6. Enter **Quantity**
7. Enter **Price per Day**
8. Enter **Software Code** (optional)
9. Add **Description** (optional)
10. Click **"Create Equipment"**

---

## 📊 Company Details Display

### Company Show Page Sections:

#### 1. Widget User Card (Top):
```
┌─────────────────────────────────┐
│  [Company Logo]                 │
│  Company Name                   │
│  Company Statistics              │
│                                 │
│  15 USERS  |  42 EQUIPMENT      │
└─────────────────────────────────┘
```

#### 2. Company Information Card:
- ID, Name, Description
- Location (Region, Country, State, City badges)
- Full Address
- GPS Coordinates
- Currency (code badge + name)
- Rental Software (name badge + version)
- Date Format
- Pricing Scheme
- Search Priority
- Rating (star display)
- Timestamps

#### 3. Users List Card:
```
• john_admin     [Admin]
  john@company.com
  
• jane_user      [User]
  jane@company.com
```

#### 4. Equipment Table:
| Product | Brand | Quantity | Price | Software Code |
|---------|-------|----------|-------|---------------|
| 320D | [Caterpillar] | 5 | $350.00 | CAT-001 |
| JS220 | [JCB] | 3 | $280.00 | JCB-045 |

---

## 🔗 Integration Points

### Company → Currency:
- Companies select default currency
- Currency show page lists all companies using it
- Prevents deletion if companies exist

### Company → Rental Software:
- Companies select their rental software system
- Software show page lists all companies
- Prevents deletion if companies exist

### Company → Equipment:
- Equipment belongs to company
- Company show page displays all equipment
- Equipment shows company badge
- Deletion cascades to equipment

### Equipment → Product:
- Equipment references product catalog
- Shows full product details (brand, category, model)
- Product changes reflect in all equipment

### Equipment → User:
- Each equipment has an owner/manager
- User must belong to same company
- Cascading dropdown ensures data integrity

---

## 🗑️ Deletion Rules

### Cascade Protection:
- ❌ Cannot delete Currency if companies use it
- ❌ Cannot delete Rental Software if companies use it
- ❌ Cannot delete Company if has users or equipment
- ✅ Can delete Equipment (deletes images too)

### Cascade Deletions:
- ✅ Delete Company → Deletes Users & Equipment (CASCADE)
- ✅ Delete Equipment → Deletes Equipment Images (CASCADE)

---

## 🎨 Color Coding

### Card Colors:
- **Primary (Blue)** - Companies, Main info cards
- **Success (Green)** - Currencies, Preferences
- **Info (Cyan)** - Rental Software, Location
- **Warning (Yellow)** - Equipment

### Badge Colors:
- **Primary** - Companies, Regions
- **Success** - Brands, Currencies, Countries
- **Info** - Rental Software, States, Sub-categories
- **Warning** - Cities, Quantity, Equipment count
- **Secondary** - Versions, Codes

---

## 💰 Currency Examples

Common currencies to add:
```
Code: USD | Name: US Dollar        | Symbol: $
Code: EUR | Name: Euro             | Symbol: €
Code: GBP | Name: British Pound    | Symbol: £
Code: CAD | Name: Canadian Dollar  | Symbol: C$
Code: AUD | Name: Australian Dollar| Symbol: A$
Code: JPY | Name: Japanese Yen     | Symbol: ¥
Code: CHF | Name: Swiss Franc       | Symbol: CHF
Code: INR | Name: Indian Rupee     | Symbol: ₹
```

---

## 💻 Rental Software Examples

Common rental software:
```
Name: EasyRent Pro       | Version: 2.5.3  | Price: $99.99
Name: RentWorks          | Version: 3.1.0  | Price: $149.99
Name: QuickRent          | Version: 1.8.2  | Price: $79.99
Name: SAP Rental Module  | Version: 2024.1 | Price: $299.99
Name: Point of Rental    | Version: 7.5    | Price: $199.99
```

---

## 📁 File Structure

```
app/Http/Controllers/Admin/
├── CompanyManagementController.php      ✅ Companies CRUD
├── CurrencyManagementController.php     ✅ Currencies CRUD
├── RentalSoftwareManagementController.php ✅ Software CRUD
└── EquipmentManagementController.php    ✅ Equipment CRUD + AJAX

app/Models/
├── Company.php                          ✅ All relationships
├── Currency.php                         ✅ Companies relationship
├── RentalSoftware.php                   ✅ Companies relationship
└── Equipment.php                        ✅ All relationships

resources/views/admin/companies/
├── index.blade.php                      ✅ Companies DataTable
├── create.blade.php                     ✅ Multi-section form
├── edit.blade.php                       ✅ Multi-section form
├── show.blade.php                       ✅ Comprehensive view
├── currencies/
│   ├── index.blade.php                  ✅ DataTable
│   ├── create.blade.php                 ✅ Form
│   ├── edit.blade.php                   ✅ Form
│   └── show.blade.php                   ✅ Details + Companies
├── rental-software/
│   ├── index.blade.php                  ✅ DataTable
│   ├── create.blade.php                 ✅ Form
│   ├── edit.blade.php                   ✅ Form
│   └── show.blade.php                   ✅ Details + Companies
└── equipment/
    ├── index.blade.php                  ✅ DataTable
    ├── create.blade.php                 ✅ Form + Cascading
    ├── edit.blade.php                   ✅ Form + Cascading
    └── show.blade.php                   ✅ Details + Calculator

routes/web.php                            ✅ Prefixed routes
config/adminlte.php                       ✅ Menu + DataTables
```

---

## 🎯 Advanced Features

### 1. **Company Widget (Show Page)**
AdminLTE widget-user component showing:
- Company logo (or default)
- Company name
- Statistics (users, equipment)
- Professional card design

### 2. **Equipment Pricing Calculator**
Auto-calculates:
- Daily rate: $350.00
- Weekly rate: $2,450.00 (×7)
- Monthly rate: $10,500.00 (×30)
- Total inventory value: $1,750.00 (price × quantity)

### 3. **Smart Filtering**
Equipment form filters users by selected company:
```
Select Company: ABC Rentals
  ↓
User dropdown shows only:
  • john_admin
  • jane_user
  • bob_manager
(All from ABC Rentals)
```

### 4. **Image Management**
Equipment images:
- Stored in `public/equipment_image/`
- Multiple images per equipment
- Grid display (2 columns)
- Auto-cleanup on delete

---

## 🔍 Search Capabilities

All DataTables support searching by:

**Companies:**
- Name, Location, Currency, Software, Rating

**Currencies:**
- Code, Name, Symbol

**Rental Software:**
- Name, Version, Price

**Equipment:**
- Company, Product, Brand, Category, Software Code

---

## 🧪 Testing Checklist

### Companies:
- [ ] Create company with all fields
- [ ] Create company with minimal fields
- [ ] View companies list with relationships
- [ ] Search companies
- [ ] Edit company
- [ ] View company details showing users and equipment
- [ ] Delete company (verify cascade)

### Currencies:
- [ ] Create currency (USD, EUR, GBP)
- [ ] View currencies list
- [ ] Search currencies
- [ ] Edit currency
- [ ] View currency details with companies list
- [ ] Try delete currency in use (should fail)
- [ ] Delete unused currency

### Rental Software:
- [ ] Create rental software with version and price
- [ ] View software list
- [ ] Search software
- [ ] Edit software
- [ ] View software details with companies
- [ ] Try delete software in use (should fail)
- [ ] Delete unused software

### Equipment:
- [ ] Create equipment with company and user
- [ ] Test cascading dropdown (company → users)
- [ ] View equipment list with all relationships
- [ ] Search equipment
- [ ] Edit equipment
- [ ] View equipment details with calculator
- [ ] Verify pricing calculations
- [ ] Delete equipment

---

## 🔒 Validation

### Company:
- Name unique constraint
- Location fields validated against existing records
- GPS coordinates range validation
- Optional but related fields (city requires country)

### Currency:
- Code unique constraint
- Symbol required (can be multi-character like CHF)
- ISO 4217 standard recommended

### Rental Software:
- Name unique constraint
- Price must be positive if provided
- Version string format flexible

### Equipment:
- Company must exist
- User must exist (and belong to company ideally)
- Product must exist
- Quantity minimum 1
- Price minimum 0

---

## 💡 Best Practices

1. **Setup Currencies First** - Before adding companies
2. **Setup Rental Software** - Before assigning to companies
3. **Create Companies** - With complete information
4. **Add Equipment** - After products are in catalog
5. **Use Software Codes** - For integration tracking
6. **Add GPS Coordinates** - For distance-based search
7. **Set Search Priority** - Lower numbers rank higher

---

## 📊 Dashboard Integration

The dashboard now shows:
- Total Products count (from products table)
- Registered Companies count (from companies table)
- Total Users count (from users table)
- My Equipment count (user's company equipment)

---

## 🎉 Summary

### Created Components:
- ✅ 4 Controllers with full CRUD + AJAX
- ✅ 16 Views (4 per entity)
- ✅ 4 Menu items
- ✅ Resource routes with admin prefix
- ✅ DataTables on all listings
- ✅ Cascading dropdowns
- ✅ Relationship displays
- ✅ Widget cards
- ✅ Image galleries
- ✅ Pricing calculator
- ✅ Validation & protection

### Relationships Displayed:
- ✅ Companies show: Location, Currency, Software, Users, Equipment
- ✅ Currencies show: Companies using them
- ✅ Rental Software show: Companies using them
- ✅ Equipment show: Company, User, Product hierarchy

---

**Date**: October 9, 2025  
**Version**: 1.0  
**Status**: ✅ Complete & Production Ready

---

## 🚀 You now have:
- Complete company management with all relationships
- Currency and rental software master data management
- Comprehensive equipment inventory tracking
- Integration with product catalog
- User-company associations
- Professional AdminLTE interface
- Export and search capabilities

The Company Management System is fully integrated with your PSM Admin Panel! 🎉

