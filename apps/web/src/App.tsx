import { Routes, Route, Navigate } from 'react-router-dom';
import { ProtectedRoute } from './components/ProtectedRoute';
import { ModuleProtectedRoute } from './components/ModuleProtectedRoute';
import { AppLayout } from './components/AppLayout';
import LoginPage from './pages/LoginPage';
import DashboardPage from './pages/DashboardPage';
import LandParcelsPage from './pages/LandParcelsPage';
import LandParcelDetailPage from './pages/LandParcelDetailPage';
import CropCyclesPage from './pages/CropCyclesPage';
import CropCycleDetailPage from './pages/CropCycleDetailPage';
import PartiesPage from './pages/PartiesPage';
import PartyDetailPage from './pages/PartyDetailPage';
import LandAllocationsPage from './pages/LandAllocationsPage';
import ProjectsPage from './pages/ProjectsPage';
import ProjectDetailPage from './pages/ProjectDetailPage';
import ProjectRulesPage from './pages/ProjectRulesPage';
import TransactionsPage from './pages/TransactionsPage';
import TransactionDetailPage from './pages/TransactionDetailPage';
import TransactionFormPage from './pages/TransactionFormPage';
import SettlementPage from './pages/SettlementPage';
import SettlementPackPage from './pages/SettlementPackPage';
import PaymentsPage from './pages/PaymentsPage';
import PaymentFormPage from './pages/PaymentFormPage';
import PaymentDetailPage from './pages/PaymentDetailPage';
import AdvancesPage from './pages/AdvancesPage';
import AdvanceFormPage from './pages/AdvanceFormPage';
import AdvanceDetailPage from './pages/AdvanceDetailPage';
import SalesPage from './pages/SalesPage';
import SaleFormPage from './pages/SaleFormPage';
import SaleDetailPage from './pages/SaleDetailPage';
import InventoryDashboardPage from './pages/inventory/InventoryDashboardPage';
import InvItemsPage from './pages/inventory/InvItemsPage';
import InvStoresPage from './pages/inventory/InvStoresPage';
import InvCategoriesPage from './pages/inventory/InvCategoriesPage';
import InvUomsPage from './pages/inventory/InvUomsPage';
import InvGrnsPage from './pages/inventory/InvGrnsPage';
import InvGrnFormPage from './pages/inventory/InvGrnFormPage';
import InvGrnDetailPage from './pages/inventory/InvGrnDetailPage';
import InvIssuesPage from './pages/inventory/InvIssuesPage';
import InvIssueFormPage from './pages/inventory/InvIssueFormPage';
import InvIssueDetailPage from './pages/inventory/InvIssueDetailPage';
import InvTransfersPage from './pages/inventory/InvTransfersPage';
import InvTransferFormPage from './pages/inventory/InvTransferFormPage';
import InvTransferDetailPage from './pages/inventory/InvTransferDetailPage';
import InvAdjustmentsPage from './pages/inventory/InvAdjustmentsPage';
import InvAdjustmentFormPage from './pages/inventory/InvAdjustmentFormPage';
import InvAdjustmentDetailPage from './pages/inventory/InvAdjustmentDetailPage';
import StockOnHandPage from './pages/inventory/StockOnHandPage';
import StockMovementsPage from './pages/inventory/StockMovementsPage';
import LabourDashboardPage from './pages/labour/LabourDashboardPage';
import WorkersPage from './pages/labour/WorkersPage';
import WorkLogsPage from './pages/labour/WorkLogsPage';
import WorkLogFormPage from './pages/labour/WorkLogFormPage';
import WorkLogDetailPage from './pages/labour/WorkLogDetailPage';
import PayablesOutstandingPage from './pages/labour/PayablesOutstandingPage';
import CropOpsDashboardPage from './pages/cropOps/CropOpsDashboardPage';
import ActivityTypesPage from './pages/cropOps/ActivityTypesPage';
import ActivitiesPage from './pages/cropOps/ActivitiesPage';
import ActivityFormPage from './pages/cropOps/ActivityFormPage';
import ActivityDetailPage from './pages/cropOps/ActivityDetailPage';
import HarvestsPage from './pages/harvests/HarvestsPage';
import HarvestFormPage from './pages/harvests/HarvestFormPage';
import HarvestDetailPage from './pages/harvests/HarvestDetailPage';
import MachinesPage from './pages/machinery/MachinesPage';
import MaintenanceTypesPage from './pages/machinery/MaintenanceTypesPage';
import RateCardsPage from './pages/machinery/RateCardsPage';
import ChargesPage from './pages/machinery/ChargesPage';
import ChargeDetailPage from './pages/machinery/ChargeDetailPage';
import MaintenanceJobsPage from './pages/machinery/MaintenanceJobsPage';
import MaintenanceJobFormPage from './pages/machinery/MaintenanceJobFormPage';
import MaintenanceJobDetailPage from './pages/machinery/MaintenanceJobDetailPage';
import MachineryWorkLogsPage from './pages/machinery/WorkLogsPage';
import MachineryWorkLogFormPage from './pages/machinery/WorkLogFormPage';
import MachineryWorkLogDetailPage from './pages/machinery/WorkLogDetailPage';
import MachineryProfitabilityPage from './pages/machinery/MachineryProfitabilityPage';
import MachineryServicesPage from './pages/machinery/MachineryServicesPage';
import MachineryServiceFormPage from './pages/machinery/MachineryServiceFormPage';
import MachineryServiceDetailPage from './pages/machinery/MachineryServiceDetailPage';
import ReportsPage from './pages/ReportsPage';
import TrialBalancePage from './pages/TrialBalancePage';
import GeneralLedgerPage from './pages/GeneralLedgerPage';
import ProjectPLPage from './pages/ProjectPLPage';
import CropCyclePLPage from './pages/CropCyclePLPage';
import AccountBalancesPage from './pages/AccountBalancesPage';
import CashbookPage from './pages/CashbookPage';
import ARAgeingPage from './pages/ARAgeingPage';
import SalesMarginPage from './pages/SalesMarginPage';
import PartyLedgerPage from './pages/PartyLedgerPage';
import PartySummaryPage from './pages/PartySummaryPage';
import RoleAgeingPage from './pages/RoleAgeingPage';
import ReconciliationDashboardPage from './pages/ReconciliationDashboardPage';
import LocalisationSettingsPage from './pages/LocalisationSettingsPage';
import ModuleTogglePage from './pages/ModuleTogglePage';
import AdminFarmProfilePage from './pages/AdminFarmProfilePage';
import AdminUsersPage from './pages/AdminUsersPage';
import AdminRolesPage from './pages/AdminRolesPage';
import PlatformTenantsPage from './pages/PlatformTenantsPage';
import PlatformTenantDetailPage from './pages/platform/PlatformTenantDetailPage';
import PostingGroupDetailPage from './pages/PostingGroupDetailPage';
import { ModulesProvider } from './contexts/ModulesContext';
import { PlatformLayout } from './components/PlatformLayout';
import { PlatformAdminRoute } from './components/PlatformAdminRoute';

function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route
        path="/app/platform"
        element={
          <ProtectedRoute>
            <PlatformAdminRoute>
              <PlatformLayout />
            </PlatformAdminRoute>
          </ProtectedRoute>
        }
      >
        <Route index element={<Navigate to="/app/platform/tenants" replace />} />
        <Route path="tenants" element={<PlatformTenantsPage />} />
        <Route path="tenants/:id" element={<PlatformTenantDetailPage />} />
      </Route>
      <Route
        path="/app"
        element={
          <ProtectedRoute>
            <ModulesProvider>
              <AppLayout />
            </ModulesProvider>
          </ProtectedRoute>
        }
      >
        <Route index element={<Navigate to="/app/dashboard" replace />} />
        <Route path="dashboard" element={<DashboardPage />} />
        <Route path="land" element={<ModuleProtectedRoute requiredModule="land"><LandParcelsPage /></ModuleProtectedRoute>} />
        <Route path="land/:id" element={<ModuleProtectedRoute requiredModule="land"><LandParcelDetailPage /></ModuleProtectedRoute>} />
        <Route path="crop-cycles" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><CropCyclesPage /></ModuleProtectedRoute>} />
        <Route path="crop-cycles/:id" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><CropCycleDetailPage /></ModuleProtectedRoute>} />
        <Route path="parties" element={<PartiesPage />} />
        <Route path="parties/:id" element={<PartyDetailPage />} />
        <Route path="allocations" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><LandAllocationsPage /></ModuleProtectedRoute>} />
        <Route path="projects" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><ProjectsPage /></ModuleProtectedRoute>} />
        <Route path="projects/:id" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><ProjectDetailPage /></ModuleProtectedRoute>} />
        <Route path="projects/:id/rules" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><ProjectRulesPage /></ModuleProtectedRoute>} />
        <Route path="transactions" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><TransactionsPage /></ModuleProtectedRoute>} />
        <Route path="transactions/new" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><TransactionFormPage /></ModuleProtectedRoute>} />
        <Route path="transactions/:id" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><TransactionDetailPage /></ModuleProtectedRoute>} />
        <Route path="transactions/:id/edit" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><TransactionFormPage /></ModuleProtectedRoute>} />
        <Route path="settlement" element={<ModuleProtectedRoute requiredModule="settlements"><SettlementPage /></ModuleProtectedRoute>} />
        <Route path="settlement-packs/:id" element={<ModuleProtectedRoute requiredModule="settlements"><SettlementPackPage /></ModuleProtectedRoute>} />
        <Route path="payments" element={<ModuleProtectedRoute requiredModule="treasury_payments"><PaymentsPage /></ModuleProtectedRoute>} />
        <Route path="payments/new" element={<ModuleProtectedRoute requiredModule="treasury_payments"><PaymentFormPage /></ModuleProtectedRoute>} />
        <Route path="payments/:id" element={<ModuleProtectedRoute requiredModule="treasury_payments"><PaymentDetailPage /></ModuleProtectedRoute>} />
        <Route path="payments/:id/edit" element={<ModuleProtectedRoute requiredModule="treasury_payments"><PaymentFormPage /></ModuleProtectedRoute>} />
        <Route path="advances" element={<ModuleProtectedRoute requiredModule="treasury_advances"><AdvancesPage /></ModuleProtectedRoute>} />
        <Route path="advances/new" element={<ModuleProtectedRoute requiredModule="treasury_advances"><AdvanceFormPage /></ModuleProtectedRoute>} />
        <Route path="advances/:id" element={<ModuleProtectedRoute requiredModule="treasury_advances"><AdvanceDetailPage /></ModuleProtectedRoute>} />
        <Route path="advances/:id/edit" element={<ModuleProtectedRoute requiredModule="treasury_advances"><AdvanceFormPage /></ModuleProtectedRoute>} />
        <Route path="sales" element={<ModuleProtectedRoute requiredModule="ar_sales"><SalesPage /></ModuleProtectedRoute>} />
        <Route path="sales/new" element={<ModuleProtectedRoute requiredModule="ar_sales"><SaleFormPage /></ModuleProtectedRoute>} />
        <Route path="sales/:id" element={<ModuleProtectedRoute requiredModule="ar_sales"><SaleDetailPage /></ModuleProtectedRoute>} />
        <Route path="sales/:id/edit" element={<ModuleProtectedRoute requiredModule="ar_sales"><SaleFormPage /></ModuleProtectedRoute>} />
        <Route path="inventory" element={<ModuleProtectedRoute requiredModule="inventory"><InventoryDashboardPage /></ModuleProtectedRoute>} />
        <Route path="inventory/items" element={<ModuleProtectedRoute requiredModule="inventory"><InvItemsPage /></ModuleProtectedRoute>} />
        <Route path="inventory/stores" element={<ModuleProtectedRoute requiredModule="inventory"><InvStoresPage /></ModuleProtectedRoute>} />
        <Route path="inventory/categories" element={<ModuleProtectedRoute requiredModule="inventory"><InvCategoriesPage /></ModuleProtectedRoute>} />
        <Route path="inventory/uoms" element={<ModuleProtectedRoute requiredModule="inventory"><InvUomsPage /></ModuleProtectedRoute>} />
        <Route path="inventory/grns" element={<ModuleProtectedRoute requiredModule="inventory"><InvGrnsPage /></ModuleProtectedRoute>} />
        <Route path="inventory/grns/new" element={<ModuleProtectedRoute requiredModule="inventory"><InvGrnFormPage /></ModuleProtectedRoute>} />
        <Route path="inventory/grns/:id" element={<ModuleProtectedRoute requiredModule="inventory"><InvGrnDetailPage /></ModuleProtectedRoute>} />
        <Route path="inventory/issues" element={<ModuleProtectedRoute requiredModule="inventory"><InvIssuesPage /></ModuleProtectedRoute>} />
        <Route path="inventory/issues/new" element={<ModuleProtectedRoute requiredModule="inventory"><InvIssueFormPage /></ModuleProtectedRoute>} />
        <Route path="inventory/issues/:id" element={<ModuleProtectedRoute requiredModule="inventory"><InvIssueDetailPage /></ModuleProtectedRoute>} />
        <Route path="inventory/transfers" element={<ModuleProtectedRoute requiredModule="inventory"><InvTransfersPage /></ModuleProtectedRoute>} />
        <Route path="inventory/transfers/new" element={<ModuleProtectedRoute requiredModule="inventory"><InvTransferFormPage /></ModuleProtectedRoute>} />
        <Route path="inventory/transfers/:id" element={<ModuleProtectedRoute requiredModule="inventory"><InvTransferDetailPage /></ModuleProtectedRoute>} />
        <Route path="inventory/adjustments" element={<ModuleProtectedRoute requiredModule="inventory"><InvAdjustmentsPage /></ModuleProtectedRoute>} />
        <Route path="inventory/adjustments/new" element={<ModuleProtectedRoute requiredModule="inventory"><InvAdjustmentFormPage /></ModuleProtectedRoute>} />
        <Route path="inventory/adjustments/:id" element={<ModuleProtectedRoute requiredModule="inventory"><InvAdjustmentDetailPage /></ModuleProtectedRoute>} />
        <Route path="inventory/stock-on-hand" element={<ModuleProtectedRoute requiredModule="inventory"><StockOnHandPage /></ModuleProtectedRoute>} />
        <Route path="inventory/stock-movements" element={<ModuleProtectedRoute requiredModule="inventory"><StockMovementsPage /></ModuleProtectedRoute>} />
        <Route path="labour" element={<ModuleProtectedRoute requiredModule="labour"><LabourDashboardPage /></ModuleProtectedRoute>} />
        <Route path="labour/workers" element={<ModuleProtectedRoute requiredModule="labour"><WorkersPage /></ModuleProtectedRoute>} />
        <Route path="labour/work-logs" element={<ModuleProtectedRoute requiredModule="labour"><WorkLogsPage /></ModuleProtectedRoute>} />
        <Route path="labour/work-logs/new" element={<ModuleProtectedRoute requiredModule="labour"><WorkLogFormPage /></ModuleProtectedRoute>} />
        <Route path="labour/work-logs/:id" element={<ModuleProtectedRoute requiredModule="labour"><WorkLogDetailPage /></ModuleProtectedRoute>} />
        <Route path="labour/payables" element={<ModuleProtectedRoute requiredModule="labour"><PayablesOutstandingPage /></ModuleProtectedRoute>} />
        <Route path="crop-ops" element={<ModuleProtectedRoute requiredModule="crop_ops"><CropOpsDashboardPage /></ModuleProtectedRoute>} />
        <Route path="crop-ops/activity-types" element={<ModuleProtectedRoute requiredModule="crop_ops"><ActivityTypesPage /></ModuleProtectedRoute>} />
        <Route path="crop-ops/activities" element={<ModuleProtectedRoute requiredModule="crop_ops"><ActivitiesPage /></ModuleProtectedRoute>} />
        <Route path="crop-ops/activities/new" element={<ModuleProtectedRoute requiredModule="crop_ops"><ActivityFormPage /></ModuleProtectedRoute>} />
        <Route path="crop-ops/activities/:id" element={<ModuleProtectedRoute requiredModule="crop_ops"><ActivityDetailPage /></ModuleProtectedRoute>} />
        <Route path="harvests" element={<ModuleProtectedRoute requiredModule="crop_ops"><HarvestsPage /></ModuleProtectedRoute>} />
        <Route path="harvests/new" element={<ModuleProtectedRoute requiredModule="crop_ops"><HarvestFormPage /></ModuleProtectedRoute>} />
        <Route path="harvests/:id" element={<ModuleProtectedRoute requiredModule="crop_ops"><HarvestDetailPage /></ModuleProtectedRoute>} />
        <Route path="machinery" element={<Navigate to="/app/machinery/work-logs" replace />} />
        <Route path="machinery/work-logs" element={<ModuleProtectedRoute requiredModule="machinery"><MachineryWorkLogsPage /></ModuleProtectedRoute>} />
        <Route path="machinery/work-logs/new" element={<ModuleProtectedRoute requiredModule="machinery"><MachineryWorkLogFormPage /></ModuleProtectedRoute>} />
        <Route path="machinery/work-logs/:id" element={<ModuleProtectedRoute requiredModule="machinery"><MachineryWorkLogDetailPage /></ModuleProtectedRoute>} />
        <Route path="machinery/work-logs/:id/edit" element={<ModuleProtectedRoute requiredModule="machinery"><MachineryWorkLogFormPage /></ModuleProtectedRoute>} />
        <Route path="machinery/machines" element={<ModuleProtectedRoute requiredModule="machinery"><MachinesPage /></ModuleProtectedRoute>} />
        <Route path="machinery/maintenance-types" element={<ModuleProtectedRoute requiredModule="machinery"><MaintenanceTypesPage /></ModuleProtectedRoute>} />
        <Route path="machinery/rate-cards" element={<ModuleProtectedRoute requiredModule="machinery"><RateCardsPage /></ModuleProtectedRoute>} />
        <Route path="machinery/services" element={<ModuleProtectedRoute requiredModule="machinery"><MachineryServicesPage /></ModuleProtectedRoute>} />
        <Route path="machinery/services/new" element={<ModuleProtectedRoute requiredModule="machinery"><MachineryServiceFormPage /></ModuleProtectedRoute>} />
        <Route path="machinery/services/:id" element={<ModuleProtectedRoute requiredModule="machinery"><MachineryServiceDetailPage /></ModuleProtectedRoute>} />
        <Route path="machinery/services/:id/edit" element={<ModuleProtectedRoute requiredModule="machinery"><MachineryServiceFormPage /></ModuleProtectedRoute>} />
        <Route path="machinery/charges" element={<ModuleProtectedRoute requiredModule="machinery"><ChargesPage /></ModuleProtectedRoute>} />
        <Route path="machinery/charges/:id" element={<ModuleProtectedRoute requiredModule="machinery"><ChargeDetailPage /></ModuleProtectedRoute>} />
        <Route path="machinery/maintenance-jobs" element={<ModuleProtectedRoute requiredModule="machinery"><MaintenanceJobsPage /></ModuleProtectedRoute>} />
        <Route path="machinery/maintenance-jobs/new" element={<ModuleProtectedRoute requiredModule="machinery"><MaintenanceJobFormPage /></ModuleProtectedRoute>} />
        <Route path="machinery/maintenance-jobs/:id" element={<ModuleProtectedRoute requiredModule="machinery"><MaintenanceJobDetailPage /></ModuleProtectedRoute>} />
        <Route path="machinery/maintenance-jobs/:id/edit" element={<ModuleProtectedRoute requiredModule="machinery"><MaintenanceJobFormPage /></ModuleProtectedRoute>} />
        <Route path="machinery/reports/profitability" element={<ModuleProtectedRoute requiredModule="machinery"><MachineryProfitabilityPage /></ModuleProtectedRoute>} />
        <Route path="reports" element={<ModuleProtectedRoute requiredModule="reports"><ReportsPage /></ModuleProtectedRoute>} />
        <Route path="reports/trial-balance" element={<ModuleProtectedRoute requiredModule="reports"><TrialBalancePage /></ModuleProtectedRoute>} />
        <Route path="reports/general-ledger" element={<ModuleProtectedRoute requiredModule="reports"><GeneralLedgerPage /></ModuleProtectedRoute>} />
        <Route path="reports/project-pl" element={<ModuleProtectedRoute requiredModule="reports"><ProjectPLPage /></ModuleProtectedRoute>} />
        <Route path="reports/crop-cycle-pl" element={<ModuleProtectedRoute requiredModule="reports"><CropCyclePLPage /></ModuleProtectedRoute>} />
        <Route path="reports/account-balances" element={<ModuleProtectedRoute requiredModule="reports"><AccountBalancesPage /></ModuleProtectedRoute>} />
        <Route path="reports/cashbook" element={<ModuleProtectedRoute requiredModule="reports"><CashbookPage /></ModuleProtectedRoute>} />
        <Route path="reports/ar-ageing" element={<ModuleProtectedRoute requiredModule="ar_sales"><ARAgeingPage /></ModuleProtectedRoute>} />
        <Route path="reports/sales-margin" element={<ModuleProtectedRoute requiredModule="reports"><SalesMarginPage /></ModuleProtectedRoute>} />
        <Route path="reports/party-ledger" element={<ModuleProtectedRoute requiredModule="reports"><PartyLedgerPage /></ModuleProtectedRoute>} />
        <Route path="reports/party-summary" element={<ModuleProtectedRoute requiredModule="reports"><PartySummaryPage /></ModuleProtectedRoute>} />
        <Route path="reports/role-ageing" element={<ModuleProtectedRoute requiredModule="reports"><RoleAgeingPage /></ModuleProtectedRoute>} />
        <Route path="reports/reconciliation-dashboard" element={<ModuleProtectedRoute requiredModule="reports"><ReconciliationDashboardPage /></ModuleProtectedRoute>} />
        <Route path="posting-groups/:id" element={<PostingGroupDetailPage />} />
        <Route path="settings/localisation" element={<LocalisationSettingsPage />} />
        <Route path="admin/farm" element={<AdminFarmProfilePage />} />
        <Route path="admin/users" element={<AdminUsersPage />} />
        <Route path="admin/roles" element={<AdminRolesPage />} />
        <Route path="admin/modules" element={<ModuleTogglePage />} />
      </Route>
      <Route path="/" element={<Navigate to="/login" replace />} />
    </Routes>
  );
}

export default App;
