# Phase 0 Implementation Notes

## What Was Built

This phase implements the foundational operational layer for Farm ERP v2 Accounting Core:

1. **Monorepo Structure**: Laravel API, React web app, and shared TypeScript package
2. **Database Schema**: Operational tables only (tenants, projects, daily_book_entries)
3. **API Endpoints**: Full CRUD for DailyBookEntries with tenant scoping
4. **React UI**: Complete interface for managing DailyBookEntries
5. **Tenant Isolation**: Middleware-based tenant scoping via X-Tenant-Id header

## Key Constraints Respected

✅ **DRAFT State Only**: All entries are created and remain in DRAFT state
✅ **No Accounting Artifacts**: No PostingGroups, AllocationRows, or LedgerEntries
✅ **No POST Action**: Posting functionality is reserved for Phase 1
✅ **Informational Event Dates**: Event dates are stored but not used for validation
✅ **Tenant Scoping**: All operations are tenant-scoped

## Database Tables

### tenants
- Stores tenant information
- UUID primary key with auto-generation

### projects
- Minimal project structure (no crop cycle in Phase 0)
- Tenant-scoped

### daily_book_entries
- Operational entries in DRAFT state
- Supports EXPENSE and INCOME types
- Status field supports DRAFT, POSTED, VOID (only DRAFT used in Phase 0)
- Indexed for tenant and project queries

## API Design

### Authentication
- Temporary: X-Tenant-Id header
- Future: Replace with proper JWT/OAuth authentication

### Endpoints
- Health check endpoint for monitoring
- Projects endpoint for tenant-scoped project listing
- Full CRUD for DailyBookEntries with filtering

### Validation
- Tenant isolation enforced at middleware and query level
- Status must remain DRAFT (enforced in update/delete)
- Type validation (EXPENSE/INCOME)
- Amount validation (>= 0)
- Project must belong to tenant

## Frontend Design

### Tenant Selection
- Tenant ID stored in localStorage
- Dropdown selector in header
- Automatically included in all API requests

### Pages
- Health check page (calls /api/health)
- DailyBookEntries list with filters
- Create/Edit forms for entries

### Shared Package
- TypeScript types for all entities
- Typed API client with automatic tenant header injection

## Testing

### Laravel Tests
- Tenant isolation tests (cannot access other tenant data)
- CRUD operation tests
- Validation failure tests

## Next Phase Handoff

### Phase 1 Will Add:
1. **Accounting Schema**
   - PostingGroups table
   - AllocationRows table
   - LedgerEntries table
   - Relationships between operational and accounting layers

2. **POST Action**
   - Rule resolution engine
   - Posting date validation
   - Entry locking mechanism
   - Creation of accounting artifacts

3. **Enhanced Operational Layer**
   - Crop cycle integration
   - More project fields
   - Entry status transitions (DRAFT → POSTED)

### Migration Strategy
- Keep operational tables clean and separate
- Accounting tables will reference operational entries
- Use foreign keys to maintain referential integrity
- Implement soft deletes for posted entries (or prevent deletion)

## File Locations

- **Migrations**: `apps/api/database/migrations/` (Laravel) and `docs/migrations.sql` (Supabase)
- **API Controllers**: `apps/api/app/Http/Controllers/`
- **Models**: `apps/api/app/Models/`
- **Middleware**: `apps/api/app/Http/Middleware/`
- **React Pages**: `apps/web/src/pages/`
- **Shared Types**: `packages/shared/src/types.ts`
- **API Client**: `packages/shared/src/api-client.ts`

## Configuration

### Supabase Connection
Use these environment variables in `apps/api/.env`:
```
SUPABASE_DB_HOST=db.xxxxx.supabase.co
SUPABASE_DB_PORT=5432
SUPABASE_DB_DATABASE=postgres
SUPABASE_DB_USERNAME=postgres
SUPABASE_DB_PASSWORD=your_password
```

### Seed Data
The seed tenant ID is: `00000000-0000-0000-0000-000000000001`
This is used by default in the React app's tenant selector.

## Known Limitations

1. **No Real Authentication**: X-Tenant-Id header is temporary
2. **No Multi-tenancy UI**: Only one tenant selector (hardcoded to seed tenant)
3. **No Validation for Posted Entries**: Status validation only prevents editing posted entries, but doesn't exist yet
4. **No Audit Trail**: No tracking of who created/modified entries
5. **No Soft Deletes**: Deleted entries are permanently removed

These will be addressed in future phases.
