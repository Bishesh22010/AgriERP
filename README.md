# Inventory Management System (Agriculture ERP)

Enterprise-grade Inventory Management and Agricultural Trading ERP Software developed for managing **Bardan (Gunny Bags)**, **Farmer Transactions**, **Purchase Books**, and **Billing Operations** for agricultural trading businesses and mandi operations.

---

# 📌 Project Overview

This software is designed for agricultural traders, grain merchants, mandi businesses, and bardan suppliers to efficiently manage:

- Bardan Purchase
- Bardan Selling
- Purchase Book Entries
- Bill Management
- Farmer Records
- Supplier Records
- Inventory Tracking
- Reports & Analytics

The system provides a modern enterprise-level experience inspired by software such as:

- Microsoft Dynamics
- Oracle ERP
- SAP
- Tally Prime

---

# 🚀 Features

---

## 🔐 Authentication & Security

- Login & Logout System
- Role-Based Access Control
- Session Management
- Secure Authentication
- Audit Logs

---

# 📦 Module 1: Bardan Purchase

Manage bardan stock purchased from suppliers.

## Features

- Add Purchase Entry
- Edit/Delete Purchase
- Supplier Management
- Vehicle Tracking
- Fare & Hamali Tracking
- Purchase History
- Purchase Receipt Printing
- Automatic Stock Increase

## Fields

- Number of Bundles
- Total Nos
- Fare
- Hamali
- Vehicle Number
- Purchase Date
- Supplier Name
- Purchase Bill Number
- Notes

---

# 🧾 Module 2: Bardan Sell

Manage bardans sold/provided to farmers.

## Features

- Auto Bill Number Generation
- Yearly Bill Reset
- Farmer Management
- Signature Capture
- Farmer Photo Upload
- Invoice Printing
- Stock Deduction
- Search & Filter

## Fields

- Bill Number
- Date
- Farmer Name
- Town/City
- Mobile Number
- Number of Bardans
- Used For (Dangar, Ghau, Bajri, Mafri)
- Digital Signature
- Photo

---

# 📖 Module 3: Purchase Book

Store grain purchase records from farmers.

## Features

- Grain Purchase Entry
- Weight & Rate Calculations
- Farmer-wise Purchase History
- Linked Bardan Tracking
- Payment Tracking
- Daily Purchase Reports

---

# 🧾 Module 4: Bill Board

Centralized billing and invoice management system.

## Features

- Bill Search
- Bill Reprint
- Daily/Monthly Summaries
- Invoice Export
- PDF Generation
- Print Management

---

# 📊 Dashboard Features

The dashboard provides real-time business insights.

## Includes

- Total Bardan Stock
- Today's Purchases
- Today's Sales
- Farmer Count
- Supplier Count
- Recent Transactions
- Stock Alerts
- Monthly Analytics

---

# 📈 Reports

Generate professional reports including:

- Daily Reports
- Monthly Reports
- Farmer Reports
- Supplier Reports
- Stock Reports
- Purchase Reports
- Sales Reports

---

# 🛠 Tech Stack

| Technology | Usage |
|------------|-------|
| PHP | Backend Development |
| MySQL | Database |
| HTML5 | Structure |
| CSS3 | Styling |
| JavaScript | Client-side Logic |
| AJAX | Dynamic Requests |

---

# 🗂 Project Structure

```bash
inventory-management-system/
│
├── admin/
├── assets/
│   ├── css/
│   ├── js/
│   ├── images/
│
├── database/
├── includes/
├── modules/
│   ├── bardan_purchase/
│   ├── bardan_sell/
│   ├── purchase_book/
│   ├── bill_board/
│
├── uploads/
│   ├── signatures/
│   ├── farmer_photos/
│
├── reports/
├── auth/
├── dashboard/
└── index.php
```

---

# 🗄 Database Tables

The project includes the following main tables:

- users
- farmers
- bardan_suppliers
- bardan_purchase
- bardan_sell
- bardan_stock_ledger
- purchase_book
- bills
- system_settings
- audit_logs

---

# ⚙️ Installation Guide

## 1️⃣ Clone Repository

```bash
git clone https://github.com/yourusername/inventory-management-system.git
```

---

## 2️⃣ Move Project

Move project folder to:

### XAMPP

```bash
htdocs/
```

### WAMP

```bash
www/
```

---

## 3️⃣ Import Database

- Open phpMyAdmin
- Create a database
- Import the `.sql` file from `/database`

---

## 4️⃣ Configure Database

Update database configuration:

```php
$conn = mysqli_connect(
    "localhost",
    "root",
    "",
    "inventory_management"
);
```

---

## 5️⃣ Run Project

Open browser:

```bash
http://localhost/inventory-management-system
```

---

# 🔒 Security Features

- Prepared Statements
- SQL Injection Protection
- Session Validation
- Input Sanitization
- Authentication Middleware
- Role-based Access

---

# 🖨 Printing System

Professional print layouts included for:

- Bardan Bills
- Purchase Receipts
- Reports
- Stock Summaries

---

# 📱 UI/UX Highlights

- Enterprise Dashboard
- Sidebar Navigation
- Responsive Design
- Professional Data Tables
- Search & Filters
- Pagination
- Toast Notifications
- Modal Forms
- Light/Dark Mode

---

# 📌 Future Enhancements

- GST Billing
- SMS Notifications
- WhatsApp Invoice Sharing
- Barcode Integration
- Cloud Backup
- Multi-Branch Support
- Mobile Application
- REST API

---

# 👨‍💻 Developer Notes

This project is designed as a real-world ERP-style application for agricultural commodity traders and mandi operations.

The architecture focuses on:

- Scalability
- Maintainability
- Enterprise UI/UX
- Modular Development

---

# 📄 License

This project is licensed under the MIT License.

---

# 🤝 Contributing

Contributions, issues, and feature requests are welcome.

---

# ⭐ Support

If you like this project, give it a ⭐ on GitHub.