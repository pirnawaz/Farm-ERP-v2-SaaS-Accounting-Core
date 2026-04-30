import { Routes, Route, Navigate, useLocation } from 'react-router-dom';
import { ProtectedRoute } from './components/ProtectedRoute';
import { ModuleProtectedRoute } from './components/ModuleProtectedRoute';
import { AppLayout } from './components/AppLayout';
import { AppLanding } from './components/AppLanding';
import LoginPage from './pages/LoginPage';
import AcceptInvitePage from './pages/AcceptInvitePage';
import DashboardPage from './pages/DashboardPage';
import FarmPulsePage from './pages/FarmPulsePage';
import CashDrilldownPage from './pages/farmPulse/CashDrilldownPage';
import BankDrilldownPage from './pages/farmPulse/BankDrilldownPage';
import LabourOwedPage from './pages/farmPulse/LabourOwedPage';
import PayablesPage from './pages/farmPulse/PayablesPage';
import TodayPage from './pages/TodayPage';
import AlertsPage from './pages/AlertsPage';
import OverdueCustomersAlertPage from './pages/alerts/OverdueCustomersAlertPage';
import NegativeMarginFieldsAlertPage from './pages/alerts/NegativeMarginFieldsAlertPage';
import UnpaidLabourAlertPage from './pages/alerts/UnpaidLabourAlertPage';
import AlertSettingsPage from './pages/alerts/AlertSettingsPage';
import GovernanceHubPage from './pages/GovernanceHubPage';
import LandParcelsPage from './pages/LandParcelsPage';
import LandParcelDetailPage from './pages/LandParcelDetailPage';
import LandLeasesPage from './pages/LandLease/LandLeasesPage';
import LandLeaseDetailPage from './pages/LandLease/LandLeaseDetailPage';
import LandlordStatementPage from './pages/LandLease/LandlordStatementPage';
import CropCyclesPage from './pages/CropCyclesPage';
import CropCycleDetailPage from './pages/CropCycleDetailPage';
import SeasonSetupWizardPage from './pages/cropCycles/SeasonSetupWizardPage';
import ProductionUnitsPage from './pages/ProductionUnitsPage';
import OrchardsPage from './pages/orchards/OrchardsPage';
import OrchardDetailPage from './pages/orchards/OrchardDetailPage';
import LivestockPage from './pages/livestock/LivestockPage';
import LivestockDetailPage from './pages/livestock/LivestockDetailPage';
import PartiesPage from './pages/PartiesPage';
import PartyDetailPage from './pages/PartyDetailPage';
import LandAllocationsPage from './pages/LandAllocationsPage';
import ProjectsPage from './pages/ProjectsPage';
import ProjectDetailPage from './pages/ProjectDetailPage';
import FieldCycleSetupPage from './pages/projects/FieldCycleSetupPage';
import ProjectPlanningPage from './pages/planning/ProjectPlanningPage';
import ProjectRulesPage from './pages/ProjectRulesPage';
import TransactionsPage from './pages/TransactionsPage';
import TransactionDetailPage from './pages/TransactionDetailPage';
import TransactionFormPage from './pages/TransactionFormPage';
import SettlementPage from './pages/SettlementPage';
import SettlementPacksPage from './pages/SettlementPacksPage';
import SettlementPackDetailPage from './pages/SettlementPackDetailPage';
import LoansPage from './pages/LoansPage';
import LoanAgreementDetailPage from './pages/LoanAgreementDetailPage';
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
import FieldJobsPage from './pages/cropOps/fieldJobs/FieldJobsPage';
import FieldJobFormPage from './pages/cropOps/fieldJobs/FieldJobFormPage';
import FieldJobDetailPage from './pages/cropOps/fieldJobs/FieldJobDetailPage';
import AgreementsPage from './pages/cropOps/agreements/AgreementsPage';
import AgreementFormPage from './pages/cropOps/agreements/AgreementFormPage';
import HarvestsPage from './pages/harvests/HarvestsPage';
import HarvestFormPage from './pages/harvests/HarvestFormPage';
import HarvestDetailPage from './pages/harvests/HarvestDetailPage';
import FarmActivityTimelinePage from './pages/FarmActivityTimelinePage';
import MachinesPage from './pages/machinery/MachinesPage';
import MaintenanceTypesPage from './pages/machinery/MaintenanceTypesPage';
import RateCardsPage from './pages/machinery/RateCardsPage';
import ChargesPage from './pages/machinery/ChargesPage';
import ChargeDetailPage from './pages/machinery/ChargeDetailPage';
import MaintenanceJobsPage from './pages/machinery/MaintenanceJobsPage';
import MaintenanceJobFormPage from './pages/machinery/MaintenanceJobFormPage';
import MaintenanceJobDetailPage from './pages/machinery/MaintenanceJobDetailPage';
import MachineryOverviewPage from './pages/machinery/MachineryOverviewPage';
import MachineryWorkLogsPage from './pages/machinery/WorkLogsPage';
import MachineryWorkLogFormPage from './pages/machinery/WorkLogFormPage';
import MachineryWorkLogDetailPage from './pages/machinery/WorkLogDetailPage';
import MachineryProfitabilityPage from './pages/machinery/MachineryProfitabilityPage';
import MachineryExternalIncomePage from './pages/machinery/MachineryExternalIncomePage';
import MachineryServicesPage from './pages/machinery/MachineryServicesPage';
import MachineryServiceFormPage from './pages/machinery/MachineryServiceFormPage';
import MachineryServiceDetailPage from './pages/machinery/MachineryServiceDetailPage';
import ReportsPage from './pages/ReportsPage';
import TrialBalancePage from './pages/TrialBalancePage';
import GeneralLedgerPage from './pages/GeneralLedgerPage';
import ProfitLossPage from './pages/reports/ProfitLossPage';
import BalanceSheetPage from './pages/reports/BalanceSheetPage';
import ProjectPLPage from './pages/ProjectPLPage';
import CropCyclePLPage from './pages/CropCyclePLPage';
import CropProfitabilityReportPage from './pages/reports/CropProfitabilityReportPage';
import CropProfitabilityTrendPage from './pages/reports/CropProfitabilityTrendPage';
import ProjectProfitabilityPage from './pages/reports/ProjectProfitabilityPage';
import FarmPnLSummaryPage from './pages/reports/FarmPnLSummaryPage';
import OverheadsReportPage from './pages/reports/OverheadsReportPage';
import ProjectForecastDashboardPage from './pages/reports/ProjectForecastDashboardPage';
import ProjectBudgetVsActualReportPage from './pages/reports/ProjectBudgetVsActualReportPage';
import CropCycleBudgetVsActualReportPage from './pages/reports/CropCycleBudgetVsActualReportPage';
import SettlementPackProjectReportPage from './pages/reports/SettlementPackProjectReportPage';
import SettlementPackCropCycleReportPage from './pages/reports/SettlementPackCropCycleReportPage';
import ProjectResponsibilityReportPage from './pages/reports/ProjectResponsibilityReportPage';
import ProjectPartyEconomicsPage from './pages/reports/ProjectPartyEconomicsPage';
import MachineProfitabilityReportPage from './pages/reports/MachineProfitabilityReportPage';
import ProductionUnitsProfitabilityReportPage from './pages/reports/ProductionUnitsProfitabilityReportPage';
import AccountBalancesPage from './pages/AccountBalancesPage';
import CashbookPage from './pages/CashbookPage';
import ARAgeingPage from './pages/ARAgeingPage';
import APAgeingPage from './pages/APAgeingPage';
import APSupplierOutstandingPage from './pages/APSupplierOutstandingPage';
import SupplierPaymentsReportPage from './pages/SupplierPaymentsReportPage';
import TreasurySupplierOutflowsPage from './pages/TreasurySupplierOutflowsPage';
import SupplierCreditNoteNewPage from './pages/SupplierCreditNoteNewPage';
import SupplierInvoicesPage from './pages/SupplierInvoicesPage';
import SupplierInvoiceDetailPage from './pages/SupplierInvoiceDetailPage';
import BillsPage from './pages/accounting/BillsPage';
import { BillFormEditRoute, BillFormNewRoute } from './pages/accounting/BillFormRoute';
import CostCentersPage from './pages/accounting/CostCentersPage';
import SalesMarginPage from './pages/SalesMarginPage';
import PartyLedgerPage from './pages/PartyLedgerPage';
import PartySummaryPage from './pages/PartySummaryPage';
import RoleAgeingPage from './pages/RoleAgeingPage';
import ReconciliationDashboardPage from './pages/ReconciliationDashboardPage';
import BankReconciliationsPage from './pages/BankReconciliationsPage';
import BankReconciliationDetailPage from './pages/BankReconciliationDetailPage';
import JournalsPage from './pages/accounting/JournalsPage';
import JournalFormPage from './pages/accounting/JournalFormPage';
import JournalDetailPage from './pages/accounting/JournalDetailPage';
import AccountingPeriodsPage from './pages/accounting/AccountingPeriodsPage';
import AccountingAllocationToolsPage from './pages/accounting/AccountingAllocationToolsPage';
import FixedAssetsPage from './pages/accounting/fixed-assets/FixedAssetsPage';
import FixedAssetFormPage from './pages/accounting/fixed-assets/FixedAssetFormPage';
import FixedAssetDetailPage from './pages/accounting/fixed-assets/FixedAssetDetailPage';
import FixedAssetDepreciationRunsPage from './pages/accounting/fixed-assets/FixedAssetDepreciationRunsPage';
import FixedAssetDepreciationRunDetailPage from './pages/accounting/fixed-assets/FixedAssetDepreciationRunDetailPage';
import ExchangeRatesPage from './pages/accounting/multi-currency/ExchangeRatesPage';
import FXRevaluationRunsPage from './pages/accounting/multi-currency/FXRevaluationRunsPage';
import FXRevaluationRunDetailPage from './pages/accounting/multi-currency/FXRevaluationRunDetailPage';
import LocalisationSettingsPage from './pages/LocalisationSettingsPage';
import ModuleTogglePage from './pages/ModuleTogglePage';
import AdminFarmProfilePage from './pages/AdminFarmProfilePage';
import AdminUsersPage from './pages/AdminUsersPage';
import AdminRolesPage from './pages/AdminRolesPage';
import TenantAuditLogsPage from './pages/TenantAuditLogsPage';
import FarmIntegrityPage from './pages/internal/FarmIntegrityPage';
import ReviewQueuePage from './pages/ReviewQueuePage';
import PlatformTenantsPage from './pages/PlatformTenantsPage';
import PlatformTenantDetailPage from './pages/platform/PlatformTenantDetailPage';
import PlatformAuditLogsPage from './pages/platform/PlatformAuditLogsPage';
import SetPasswordPage from './pages/SetPasswordPage';
import PostingGroupDetailPage from './pages/PostingGroupDetailPage';
import SuppliersPage from './pages/procurement/SuppliersPage';
import SupplierFormPage from './pages/procurement/SupplierFormPage';
import SupplierBillsPage from './pages/procurement/SupplierBillsPage';
import SupplierBillFormPage from './pages/procurement/SupplierBillFormPage';
import SupplierBillDetailPage from './pages/procurement/SupplierBillDetailPage';
import PurchaseOrdersPage from './pages/procurement/PurchaseOrdersPage';
import PurchaseOrderFormPage from './pages/procurement/PurchaseOrderFormPage';
import PurchaseOrderDetailPage from './pages/procurement/PurchaseOrderDetailPage';
import { ApReportsIndexPage } from './pages/procurement/reports/ApReportsIndexPage';
import { SupplierLedgerReportPage } from './pages/procurement/reports/SupplierLedgerReportPage';
import { UnpaidBillsReportPage } from './pages/procurement/reports/UnpaidBillsReportPage';
import { ApAgingReportPage } from './pages/procurement/reports/ApAgingReportPage';
import { CreditPremiumByProjectReportPage } from './pages/procurement/reports/CreditPremiumByProjectReportPage';
import { ModulesProvider } from './contexts/ModulesContext';
import { PlatformLayout } from './components/PlatformLayout';
import { PlatformAdminRoute } from './components/PlatformAdminRoute';
import { TenantAreaRoute } from './components/TenantAreaRoute';
import { ImpersonationBanner } from './components/ImpersonationBanner';

function App() {
  const location = useLocation();
  const showImpersonationBanner = location.pathname.startsWith('/app');

  return (
    <>
      <ImpersonationBanner enabled={showImpersonationBanner} />
      <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/accept-invite" element={<AcceptInvitePage />} />
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
        <Route path="audit-logs" element={<PlatformAuditLogsPage />} />
      </Route>
      <Route
        path="/app"
        element={
          <ProtectedRoute>
            <TenantAreaRoute>
              <ModulesProvider>
                <AppLayout />
              </ModulesProvider>
            </TenantAreaRoute>
          </ProtectedRoute>
        }
      >
        <Route index element={<AppLanding />} />
        <Route path="set-password" element={<SetPasswordPage />} />
        <Route path="dashboard" element={<DashboardPage />} />
        <Route path="farm-activity" element={<FarmActivityTimelinePage />} />
        <Route path="farm-pulse" element={<FarmPulsePage />} />
        <Route path="farm-pulse/cash" element={<CashDrilldownPage />} />
        <Route path="farm-pulse/bank" element={<BankDrilldownPage />} />
        <Route path="farm-pulse/labour-owed" element={<LabourOwedPage />} />
        <Route path="farm-pulse/payables" element={<PayablesPage />} />
        <Route path="today" element={<TodayPage />} />
        <Route path="alerts" element={<AlertsPage />} />
        <Route path="alerts/overdue-customers" element={<OverdueCustomersAlertPage />} />
        <Route path="alerts/negative-margin" element={<NegativeMarginFieldsAlertPage />} />
        <Route path="alerts/unpaid-labour" element={<UnpaidLabourAlertPage />} />
        <Route path="alerts/settings" element={<AlertSettingsPage />} />
        <Route path="governance" element={<GovernanceHubPage />} />
        <Route path="land" element={<ModuleProtectedRoute requiredModule="land"><LandParcelsPage /></ModuleProtectedRoute>} />
        <Route path="land/:id" element={<ModuleProtectedRoute requiredModule="land"><LandParcelDetailPage /></ModuleProtectedRoute>} />
        <Route path="land-leases" element={<ModuleProtectedRoute requiredModule="land_leases"><LandLeasesPage /></ModuleProtectedRoute>} />
        <Route path="land-leases/:id" element={<ModuleProtectedRoute requiredModule="land_leases"><LandLeaseDetailPage /></ModuleProtectedRoute>} />
        <Route path="crop-cycles" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><CropCyclesPage /></ModuleProtectedRoute>} />
        <Route path="crop-cycles/season-setup" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><SeasonSetupWizardPage /></ModuleProtectedRoute>} />
        <Route path="crop-cycles/:id" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><CropCycleDetailPage /></ModuleProtectedRoute>} />
        <Route path="production-units" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><ProductionUnitsPage /></ModuleProtectedRoute>} />
        <Route path="orchards" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><OrchardsPage /></ModuleProtectedRoute>} />
        <Route path="orchards/:id" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><OrchardDetailPage /></ModuleProtectedRoute>} />
        <Route path="livestock" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><LivestockPage /></ModuleProtectedRoute>} />
        <Route path="livestock/:id" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><LivestockDetailPage /></ModuleProtectedRoute>} />
        <Route path="parties" element={<PartiesPage />} />
        <Route path="parties/:id" element={<PartyDetailPage />} />
        <Route path="allocations" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><LandAllocationsPage /></ModuleProtectedRoute>} />
        <Route path="projects" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><ProjectsPage /></ModuleProtectedRoute>} />
        <Route path="projects/setup" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><FieldCycleSetupPage /></ModuleProtectedRoute>} />
        <Route path="projects/:id" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><ProjectDetailPage /></ModuleProtectedRoute>} />
        <Route path="projects/:id/rules" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><ProjectRulesPage /></ModuleProtectedRoute>} />
        <Route
          path="planning"
          element={
            <ModuleProtectedRoute requiredModule="projects_crop_cycles">
              <ModuleProtectedRoute requiredModule="reports">
                <ProjectPlanningPage />
              </ModuleProtectedRoute>
            </ModuleProtectedRoute>
          }
        />
        <Route path="transactions" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><TransactionsPage /></ModuleProtectedRoute>} />
        <Route path="transactions/new" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><TransactionFormPage /></ModuleProtectedRoute>} />
        <Route path="transactions/:id" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><TransactionDetailPage /></ModuleProtectedRoute>} />
        <Route path="transactions/:id/edit" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><TransactionFormPage /></ModuleProtectedRoute>} />
        <Route path="settlement" element={<ModuleProtectedRoute requiredModule="settlements"><SettlementPage /></ModuleProtectedRoute>} />
        <Route path="settlement-packs" element={<ModuleProtectedRoute requiredModule="settlements"><SettlementPacksPage /></ModuleProtectedRoute>} />
        <Route path="settlement-packs/:id" element={<ModuleProtectedRoute requiredModule="settlements"><SettlementPackDetailPage /></ModuleProtectedRoute>} />
        <Route path="loans" element={<ModuleProtectedRoute requiredModule="loans"><LoansPage /></ModuleProtectedRoute>} />
        <Route path="loans/:id" element={<ModuleProtectedRoute requiredModule="loans"><LoanAgreementDetailPage /></ModuleProtectedRoute>} />
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
        <Route path="crop-ops/field-jobs" element={<ModuleProtectedRoute requiredModule="crop_ops"><FieldJobsPage /></ModuleProtectedRoute>} />
        <Route path="crop-ops/field-jobs/new" element={<ModuleProtectedRoute requiredModule="crop_ops"><FieldJobFormPage /></ModuleProtectedRoute>} />
        <Route path="crop-ops/field-jobs/:id" element={<ModuleProtectedRoute requiredModule="crop_ops"><FieldJobDetailPage /></ModuleProtectedRoute>} />
        <Route path="crop-ops/agreements" element={<ModuleProtectedRoute requiredModule="crop_ops"><AgreementsPage /></ModuleProtectedRoute>} />
        <Route path="crop-ops/agreements/new" element={<ModuleProtectedRoute requiredModule="crop_ops"><AgreementFormPage /></ModuleProtectedRoute>} />
        <Route path="crop-ops/agreements/:id" element={<ModuleProtectedRoute requiredModule="crop_ops"><AgreementFormPage /></ModuleProtectedRoute>} />
        <Route path="harvests" element={<ModuleProtectedRoute requiredModule="crop_ops"><HarvestsPage /></ModuleProtectedRoute>} />
        <Route path="harvests/new" element={<ModuleProtectedRoute requiredModule="crop_ops"><HarvestFormPage /></ModuleProtectedRoute>} />
        <Route path="harvests/:id" element={<ModuleProtectedRoute requiredModule="crop_ops"><HarvestDetailPage /></ModuleProtectedRoute>} />
        <Route path="machinery" element={<ModuleProtectedRoute requiredModule="machinery"><MachineryOverviewPage /></ModuleProtectedRoute>} />
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
        <Route path="machinery/external-income" element={<ModuleProtectedRoute requiredModule="machinery"><MachineryExternalIncomePage /></ModuleProtectedRoute>} />
        <Route path="reports" element={<ModuleProtectedRoute requiredModule="reports"><ReportsPage /></ModuleProtectedRoute>} />
        <Route path="reports/trial-balance" element={<ModuleProtectedRoute requiredModule="reports"><TrialBalancePage /></ModuleProtectedRoute>} />
        <Route path="reports/profit-loss" element={<ModuleProtectedRoute requiredModule="reports"><ProfitLossPage /></ModuleProtectedRoute>} />
        <Route path="reports/balance-sheet" element={<ModuleProtectedRoute requiredModule="reports"><BalanceSheetPage /></ModuleProtectedRoute>} />
        <Route path="reports/general-ledger" element={<ModuleProtectedRoute requiredModule="reports"><GeneralLedgerPage /></ModuleProtectedRoute>} />
        <Route path="reports/project-pl" element={<ModuleProtectedRoute requiredModule="reports"><ProjectPLPage /></ModuleProtectedRoute>} />
        <Route path="reports/overheads" element={<ModuleProtectedRoute requiredModule="reports"><OverheadsReportPage /></ModuleProtectedRoute>} />
        <Route path="reports/farm-pnl" element={<ModuleProtectedRoute requiredModule="reports"><FarmPnLSummaryPage /></ModuleProtectedRoute>} />
        <Route path="reports/crop-cycle-pl" element={<ModuleProtectedRoute requiredModule="reports"><CropCyclePLPage /></ModuleProtectedRoute>} />
        <Route path="reports/crop-profitability" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><CropProfitabilityReportPage /></ModuleProtectedRoute>} />
        <Route path="reports/crop-profitability-trend" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><CropProfitabilityTrendPage /></ModuleProtectedRoute>} />
        <Route
          path="reports/project-profitability"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <ModuleProtectedRoute requiredModule="projects_crop_cycles">
                <ProjectProfitabilityPage />
              </ModuleProtectedRoute>
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="reports/project-forecast"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <ModuleProtectedRoute requiredModule="projects_crop_cycles">
                <ProjectForecastDashboardPage />
              </ModuleProtectedRoute>
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="reports/budget-vs-actual/project"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <ModuleProtectedRoute requiredModule="projects_crop_cycles">
                <ProjectBudgetVsActualReportPage />
              </ModuleProtectedRoute>
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="reports/budget-vs-actual/crop-cycle"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <ModuleProtectedRoute requiredModule="projects_crop_cycles">
                <CropCycleBudgetVsActualReportPage />
              </ModuleProtectedRoute>
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="reports/settlement-pack/project"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <ModuleProtectedRoute requiredModule="projects_crop_cycles">
                <SettlementPackProjectReportPage />
              </ModuleProtectedRoute>
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="reports/settlement-pack/crop-cycle"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <ModuleProtectedRoute requiredModule="projects_crop_cycles">
                <SettlementPackCropCycleReportPage />
              </ModuleProtectedRoute>
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="reports/project-responsibility"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <ModuleProtectedRoute requiredModule="projects_crop_cycles">
                <ProjectResponsibilityReportPage />
              </ModuleProtectedRoute>
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="reports/project-party-economics"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <ModuleProtectedRoute requiredModule="projects_crop_cycles">
                <ProjectPartyEconomicsPage />
              </ModuleProtectedRoute>
            </ModuleProtectedRoute>
          }
        />
        <Route path="reports/machine-profitability" element={<ModuleProtectedRoute requiredModule="reports"><MachineProfitabilityReportPage /></ModuleProtectedRoute>} />
        <Route path="reports/production-units-profitability" element={<ModuleProtectedRoute requiredModule="projects_crop_cycles"><ProductionUnitsProfitabilityReportPage /></ModuleProtectedRoute>} />
        <Route path="reports/account-balances" element={<ModuleProtectedRoute requiredModule="reports"><AccountBalancesPage /></ModuleProtectedRoute>} />
        <Route path="reports/cashbook" element={<ModuleProtectedRoute requiredModule="reports"><CashbookPage /></ModuleProtectedRoute>} />
        <Route path="reports/ar-ageing" element={<ModuleProtectedRoute requiredModule="ar_sales"><ARAgeingPage /></ModuleProtectedRoute>} />
        <Route path="reports/ap-ageing" element={<ModuleProtectedRoute requiredModule="reports"><APAgeingPage /></ModuleProtectedRoute>} />
        <Route
          path="reports/ap-supplier-outstanding"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <APSupplierOutstandingPage />
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="reports/supplier-payments"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <SupplierPaymentsReportPage />
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="reports/treasury-supplier-outflows"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <TreasurySupplierOutflowsPage />
            </ModuleProtectedRoute>
          }
        />
        <Route path="reports/sales-margin" element={<ModuleProtectedRoute requiredModule="reports"><SalesMarginPage /></ModuleProtectedRoute>} />
        <Route path="reports/party-ledger" element={<ModuleProtectedRoute requiredModule="reports"><PartyLedgerPage /></ModuleProtectedRoute>} />
        <Route path="reports/landlord-statement" element={<ModuleProtectedRoute requiredModule="land_leases"><LandlordStatementPage /></ModuleProtectedRoute>} />
        <Route path="reports/party-summary" element={<ModuleProtectedRoute requiredModule="reports"><PartySummaryPage /></ModuleProtectedRoute>} />
        <Route path="reports/role-ageing" element={<ModuleProtectedRoute requiredModule="reports"><RoleAgeingPage /></ModuleProtectedRoute>} />
        <Route path="reports/reconciliation-dashboard" element={<ModuleProtectedRoute requiredModule="reports"><ReconciliationDashboardPage /></ModuleProtectedRoute>} />
        <Route path="reports/bank-reconciliation" element={<ModuleProtectedRoute requiredModule="reports"><BankReconciliationsPage /></ModuleProtectedRoute>} />
        <Route path="reports/bank-reconciliation/:id" element={<ModuleProtectedRoute requiredModule="reports"><BankReconciliationDetailPage /></ModuleProtectedRoute>} />
        <Route path="accounting/journals" element={<ModuleProtectedRoute requiredModule="reports"><JournalsPage /></ModuleProtectedRoute>} />
        <Route path="accounting/journals/new" element={<ModuleProtectedRoute requiredModule="reports"><JournalFormPage /></ModuleProtectedRoute>} />
        <Route path="accounting/journals/:id" element={<ModuleProtectedRoute requiredModule="reports"><JournalDetailPage /></ModuleProtectedRoute>} />
        <Route path="accounting/journals/:id/edit" element={<ModuleProtectedRoute requiredModule="reports"><JournalFormPage /></ModuleProtectedRoute>} />
        <Route
          path="accounting/exchange-rates"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <ExchangeRatesPage />
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="accounting/fx-revaluation-runs"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <FXRevaluationRunsPage />
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="accounting/fx-revaluation-runs/:id"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <FXRevaluationRunDetailPage />
            </ModuleProtectedRoute>
          }
        />
        <Route path="accounting/periods" element={<ModuleProtectedRoute requiredModule="reports"><AccountingPeriodsPage /></ModuleProtectedRoute>} />
        <Route
          path="accounting/allocation-tools"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <AccountingAllocationToolsPage />
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="accounting/fixed-assets/depreciation-runs/:id"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <FixedAssetDepreciationRunDetailPage />
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="accounting/fixed-assets/depreciation-runs"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <FixedAssetDepreciationRunsPage />
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="accounting/fixed-assets/new"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <FixedAssetFormPage />
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="accounting/fixed-assets/:id"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <FixedAssetDetailPage />
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="accounting/fixed-assets"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <FixedAssetsPage />
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="accounting/bills/new"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <BillFormNewRoute mode="overhead" />
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="accounting/bills/:id/edit"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <BillFormEditRoute mode="overhead" />
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="accounting/bills"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <BillsPage />
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="accounting/cost-centers"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <CostCentersPage />
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="accounting/supplier-invoices"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <SupplierInvoicesPage />
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="accounting/supplier-invoices/new"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <BillFormNewRoute mode="supplier" />
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="accounting/supplier-invoices/:id/edit"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <BillFormEditRoute mode="supplier" />
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="accounting/supplier-invoices/:id"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <SupplierInvoiceDetailPage />
            </ModuleProtectedRoute>
          }
        />
        <Route
          path="accounting/supplier-credit-notes/new"
          element={
            <ModuleProtectedRoute requiredModule="reports">
              <SupplierCreditNoteNewPage />
            </ModuleProtectedRoute>
          }
        />
        <Route path="posting-groups/:id" element={<PostingGroupDetailPage />} />
        <Route path="procurement/suppliers" element={<SuppliersPage />} />
        <Route path="procurement/suppliers/new" element={<SupplierFormPage />} />
        <Route path="procurement/suppliers/:id" element={<SupplierFormPage />} />
        <Route path="procurement/supplier-bills" element={<SupplierBillsPage />} />
        <Route path="procurement/supplier-bills/new" element={<SupplierBillFormPage />} />
        <Route path="procurement/supplier-bills/:id" element={<SupplierBillDetailPage />} />
        <Route path="procurement/supplier-bills/:id/edit" element={<SupplierBillFormPage />} />
        <Route path="procurement/purchase-orders" element={<PurchaseOrdersPage />} />
        <Route path="procurement/purchase-orders/new" element={<PurchaseOrderFormPage />} />
        <Route path="procurement/purchase-orders/:id" element={<PurchaseOrderDetailPage />} />
        <Route path="procurement/purchase-orders/:id/edit" element={<PurchaseOrderFormPage />} />
        <Route path="procurement/reports" element={<ApReportsIndexPage />} />
        <Route path="procurement/reports/supplier-ledger" element={<SupplierLedgerReportPage />} />
        <Route path="procurement/reports/unpaid-bills" element={<UnpaidBillsReportPage />} />
        <Route path="procurement/reports/ap-aging" element={<ApAgingReportPage />} />
        <Route path="procurement/reports/credit-premium" element={<CreditPremiumByProjectReportPage />} />
        <Route path="settings/localisation" element={<LocalisationSettingsPage />} />
        <Route path="admin/farm" element={<AdminFarmProfilePage />} />
        <Route path="admin/users" element={<AdminUsersPage />} />
        <Route path="admin/roles" element={<AdminRolesPage />} />
        <Route path="admin/audit-logs" element={<TenantAuditLogsPage />} />
        <Route path="admin/modules" element={<ModuleTogglePage />} />
        <Route path="internal/farm-integrity" element={<FarmIntegrityPage />} />
        <Route path="review-queue" element={<ReviewQueuePage />} />
      </Route>
      <Route path="/" element={<Navigate to="/login" replace />} />
    </Routes>
    </>
  );
}

export default App;
