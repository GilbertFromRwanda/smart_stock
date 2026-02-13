# Smart Stock - Inventory Management System

A comprehensive inventory and stock management system built for **UO & GN Boutique** (Rwanda). It tracks product inventory, manages purchases and sales (bulk & retail), calculates profit/revenue, and provides role-based user access control.

## Tech Stack

| Component      | Technology              |
| -------------- | ----------------------- |
| Backend        | PHP 8.x                |
| Database       | MariaDB / MySQL (MySQLi) |
| Frontend       | HTML5, CSS3, JavaScript |
| Charts         | Chart.js                |
| Local Server   | XAMPP (Apache)          |

## Features

- **Dual Inventory Tracking** - Warehouse stock (packages) and retail stock (individual pieces) with movement tracking between them
- **Product Management** - CRUD operations with categories, unit measures, reorder levels, and search
- **Purchase Management** - Record supplier purchases with cost/package/retail pricing and automatic stock updates
- **Sales (Bulk & Retail)** - Package-level wholesale sales and piece-level retail sales with customer tracking
- **Profit Analysis** - Daily/weekly/monthly revenue reports, profit margins, and cost-based analysis with Chart.js visualizations
- **Supplier Management** - Vendor contact info and purchase history
- **User Management** - Role-based access control (admin / manager / user) with bcrypt password hashing
- **Low Stock Alerts** - Dashboard warnings for products below reorder level
- **Database Admin Panel** - View and manage database records (admin only)

## Project Structure

```
smart_stock/
├── config.php              # Database connection & helpers
├── index.php               # Entry point (redirects to login/dashboard)
├── login.php               # Authentication page
├── logout.php              # Session termination
├── sidebar.php             # Navigation sidebar
├── dashboard.php           # Main dashboard with KPIs & charts
├── products.php            # Product/item management
├── purchases.php           # Purchase recording
├── sales.php               # Bulk & retail sales
├── stock.php               # Stock management & movement
├── revenue.php             # Profit analysis & reports
├── suppliers.php           # Supplier management
├── users.php               # User management
├── database.php            # Database admin panel
├── script.js               # Frontend JavaScript
├── chart.js                # Chart.js library
├── config/
│   └── database.php        # Alternative PDO config
├── css/
│   ├── style.css           # Global styles
│   ├── dashboard.css       # Dashboard styles
│   ├── revenue.css         # Revenue page styles
│   ├── sales.css           # Sales page styles
│   └── user.css            # User management styles
└── db/
    └── db.sql              # Database schema
```

## Setup

1. **Install XAMPP** with Apache and MySQL/MariaDB

2. **Clone the repository** into your XAMPP htdocs directory:
   ```bash
   git clone <repo-url> /path/to/xampp/htdocs/smart_stock
   ```

3. **Create the database** by importing the schema:
   ```bash
   mysql -u root -p < db/db.sql
   ```
   Or import `db/db.sql` via phpMyAdmin.

4. **Configure database credentials** in [config.php](config.php):
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'root');
   define('DB_PASS', 'your_password');
   define('DB_NAME', 'olive2_db');
   ```

5. **Start Apache and MySQL** from the XAMPP control panel

6. **Open in browser**: `http://localhost/smart_stock`

## Default Credentials

| Username | Password   | Role  |
| -------- | ---------- | ----- |
| admin    | admin123   | Admin |

## User Roles

| Role      | Access                                                  |
| --------- | ------------------------------------------------------- |
| **Admin** | Full access (users, database admin, profit analysis)    |
| **Manager** | Profit analysis, user management, all operations      |
| **User**  | Products, sales, purchases, stock management            |

## Database Schema

The system uses 9 core tables:

- **products** - Product master data (name, category, unit measure, reorder level)
- **stock** - Warehouse inventory (packages with piece counts)
- **retail_stock** - Retail shop inventory (individual pieces)
- **purchases** - Purchase records with supplier and pricing info
- **sales_bulk** - Wholesale/package-level sales
- **sales_retail** - Piece-level retail sales
- **stock_movements** - Audit trail for stock transfers (warehouse to retail)
- **suppliers** - Vendor contact information
- **users** - System users with roles and status
- **weekly_revenue** - Aggregated weekly financial data
