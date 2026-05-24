# Mini ERP — Project Overview

## Tech Stack
- Backend: Laravel 12 (PHP 8.2)
- Frontend: Vue 3 + Inertia.js (monolith — NOT a separate SPA)
- Database: PostgreSQL (localhost:5432, db: `mini_erp_db`)
- UI: Tailwind CSS v3, primary color `primary-*`
- Auth/RBAC: Laravel session + Spatie laravel-permission
- PDF: barryvdh/laravel-dompdf (DejaVu Sans font for Vietnamese)
- Excel: maatwebsite/excel (bulk import/export)
- Audit log: spatie/laravel-activitylog

## Directory Structure
```
web_erp/
├── app/
│   ├── Enums/          # PHP 8.1 Backed Enums (status fields)
│   ├── Http/Controllers/{Module}/  # Grouped by module
│   ├── Models/         # Eloquent models
│   └── Services/       # Business logic (FSM, transactions)
├── database/
│   ├── migrations/     # Timestamp: 2026_05_21_{phase}{seq}
│   └── seeders/        # RolePermissionSeeder, demo data
├── resources/js/
│   ├── Components/     # Shared components (Layout, StatusBadge, Pagination)
│   ├── composables/    # usePermission, useFlash, useTabs
│   └── Pages/{Module}/ # Inertia pages (Index/Form/Show pattern)
└── routes/web.php      # All routes with can: middleware
```

## Commands
```powershell
# Working directory: C:\Mini_erp\web_erp
php artisan serve --host=0.0.0.0    # http://192.168.1.13:8000
npm run dev                          # Vite HMR (hmr.host: 192.168.1.13)
npm run build                        # Production build
php artisan migrate                  # Run migrations
php artisan db:seed                  # Seed demo data
php artisan view:clear && php artisan route:clear  # Clear caches
```

## Demo Accounts
| Email | Password | Role |
|---|---|---|
| admin@minierp.local | Admin@123 | admin |
| director@minierp.local | Demo@123 | director |
| sales@minierp.local | Demo@123 | sales |
| kho@minierp.local | Demo@123 | warehouse |
| kt@minierp.local | Demo@123 | technical |
| ketoan@minierp.local | Demo@123 | accounting |
| cskh@minierp.local | Demo@123 | cskh |
