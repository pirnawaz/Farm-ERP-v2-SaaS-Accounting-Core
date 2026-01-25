# Farm ERP v2 - React Web Application

## Overview

This is the React UI for Farm ERP v2 Phase 1. It provides a complete interface for managing land parcels, crop cycles, allocations, projects, transactions, settlements, and reports.

## Prerequisites

- Node.js 18+
- npm or yarn

## Setup

1. Install dependencies:
```bash
npm install
```

2. Build the shared package (if not already built):
```bash
cd ../../packages/shared
npm install
npm run build
cd ../../apps/web
```

## Configuration

### Environment Variables

Create a `.env` file in the `apps/web` directory (or set environment variables):

```env
VITE_API_URL=http://localhost:8000
```

**Note**: The default API URL is `http://localhost:8000` if `VITE_API_URL` is not set.

### Tenant ID Configuration

The Tenant ID can be set in two ways:

1. **Via UI Header**: After logging in, you can edit the Tenant ID directly in the header of the application.

2. **Via Browser localStorage**: The Tenant ID is stored in localStorage with the key `farm_erp_tenant_id`. You can set it manually:
   ```javascript
   localStorage.setItem('farm_erp_tenant_id', 'your-tenant-uuid-here');
   ```

### User Role Configuration

User roles are stored in localStorage with the key `farm_erp_user_role`. Available roles:
- `tenant_admin` - Full access
- `accountant` - Can create/edit drafts, POST transactions, run settlements
- `operator` - Can create/edit drafts, view data; NO posting or settlement

You can set it manually:
```javascript
localStorage.setItem('farm_erp_user_role', 'tenant_admin');
```

Or use the login page at `/login` to select a role.

## Running the Application

```bash
npm run dev
```

The application will be available at `http://localhost:3000`

## Features

### Phase 1 Screens

- **Dashboard** (`/app/dashboard`) - Overview with stat cards and quick links
- **Land Parcels** (`/app/land`) - Manage land parcels, documents, and allocations
- **Crop Cycles** (`/app/crop-cycles`) - Manage crop cycles, open/close cycles
- **Land Allocations** (`/app/allocations`) - Allocate land to parties by crop cycle
- **Projects** (`/app/projects`) - Manage projects created from allocations
- **Project Rules** (`/app/projects/:id/rules`) - Configure profit splits and kamdari
- **Transactions** (`/app/transactions`) - Create, edit, and post operational transactions
- **Settlement** (`/app/settlement`) - Preview and post project settlements
- **Reports** (`/app/reports`) - Trial Balance, General Ledger, Project Statement

### Role-Based Access Control

- **Tenant Admin**: Full access to all features
- **Accountant**: Can create/edit drafts, POST transactions, run settlements
- **Operator**: Can create/edit drafts, view data; NO posting or settlement

## Development

### Tech Stack

- React 18
- TypeScript
- Vite
- React Router
- TanStack React Query
- Tailwind CSS
- react-hot-toast

### Project Structure

```
src/
├── api/              # API client wrappers
├── components/        # Reusable UI components
├── hooks/            # Custom React hooks
├── pages/            # Page components
├── types/            # TypeScript interfaces
└── utils/            # Utility functions
```

## Building for Production

```bash
npm run build
```

The built files will be in the `dist` directory.

## Notes

- All API calls automatically include the `X-Tenant-Id` header from localStorage
- The application uses React Query for data fetching and caching
- Toast notifications are used for success/error feedback
- All forms include validation and error handling
