# File Tree Summary

```
Farm ERP v2 – SaaS Accounting Core/
├── apps/
│   ├── api/                          # Laravel API
│   │   ├── app/
│   │   │   ├── Http/
│   │   │   │   ├── Controllers/
│   │   │   │   │   ├── Controller.php
│   │   │   │   │   ├── DailyBookEntryController.php
│   │   │   │   │   ├── HealthController.php
│   │   │   │   │   └── ProjectController.php
│   │   │   │   └── Middleware/
│   │   │   │       └── TenantScopeMiddleware.php
│   │   │   └── Models/
│   │   │       ├── DailyBookEntry.php
│   │   │       ├── Project.php
│   │   │       └── Tenant.php
│   │   ├── bootstrap/
│   │   │   └── app.php
│   │   ├── config/
│   │   │   ├── app.php
│   │   │   └── database.php
│   │   ├── database/
│   │   │   └── migrations/
│   │   │       ├── 2024_01_01_000001_create_tenants_table.php
│   │   │       ├── 2024_01_01_000002_create_projects_table.php
│   │   │       └── 2024_01_01_000003_create_daily_book_entries_table.php
│   │   ├── public/
│   │   │   └── index.php
│   │   ├── routes/
│   │   │   ├── api.php
│   │   │   ├── console.php
│   │   │   └── web.php
│   │   ├── tests/
│   │   │   ├── Feature/
│   │   │   │   ├── DailyBookEntryCrudTest.php
│   │   │   │   └── TenantIsolationTest.php
│   │   │   └── TestCase.php
│   │   ├── artisan
│   │   ├── composer.json
│   │   ├── phpunit.xml
│   │   └── .env.example
│   └── web/                           # React + Vite
│       ├── src/
│       │   ├── components/
│       │   │   └── TenantSelector.tsx
│       │   ├── pages/
│       │   │   ├── DailyBookEntriesPage.tsx
│       │   │   ├── DailyBookEntryFormPage.tsx
│       │   │   └── HealthPage.tsx
│       │   ├── App.tsx
│       │   ├── index.css
│       │   └── main.tsx
│       ├── index.html
│       ├── package.json
│       ├── postcss.config.js
│       ├── tailwind.config.js
│       ├── tsconfig.json
│       ├── tsconfig.node.json
│       └── vite.config.ts
├── packages/
│   └── shared/                        # Shared TypeScript package
│       ├── src/
│       │   ├── api-client.ts
│       │   ├── types.ts
│       │   └── index.ts
│       ├── package.json
│       └── tsconfig.json
├── docs/
│   ├── FILE_TREE.md                   # This file
│   ├── migrations.sql                 # Supabase migration SQL
│   └── PHASE_0_NOTES.md               # Phase 0 implementation notes
├── .gitignore
├── package.json                        # Root package.json for monorepo
└── README.md                          # Main README with setup instructions
```

## Key Files

### API (Laravel)
- **Controllers**: Handle HTTP requests and business logic
- **Models**: Eloquent models for database entities
- **Middleware**: Tenant scoping middleware
- **Migrations**: Database schema definitions
- **Tests**: Feature tests for CRUD and tenant isolation

### Web (React)
- **Pages**: Main application pages (Health, List, Create/Edit)
- **Components**: Reusable UI components (TenantSelector)
- **App.tsx**: Main application component with routing

### Shared Package
- **types.ts**: TypeScript type definitions
- **api-client.ts**: Typed API client with tenant header injection

### Documentation
- **README.md**: Setup and usage instructions
- **migrations.sql**: Supabase-compatible SQL migrations
- **PHASE_0_NOTES.md**: Implementation details and phase handoff notes
