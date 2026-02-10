import { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import {
  useCreateMachineryService,
  useUpdateMachineryService,
  useMachineryServiceQuery,
  useMachinesQuery,
  useRateCardsQuery,
} from '../../hooks/useMachinery';
import { useProjects } from '../../hooks/useProjects';
import { useInventoryItems, useInventoryStores } from '../../hooks/useInventory';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { useFormatting } from '../../hooks/useFormatting';
import type { CreateMachineryServicePayload, UpdateMachineryServicePayload, MachineryServiceAllocationScope } from '../../types';

const ALLOCATION_SCOPES: MachineryServiceAllocationScope[] = ['SHARED', 'HARI_ONLY'];

export default function MachineryServiceFormPage() {
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const isEdit = !!id && id !== 'new';
  const createM = useCreateMachineryService();
  const updateM = useUpdateMachineryService();
  const { data: service, isLoading: loadingService } = useMachineryServiceQuery(id || '');
  const { data: projects } = useProjects();
  const { data: machines } = useMachinesQuery();
  const { data: inventoryItems } = useInventoryItems(true);
  const { data: inventoryStores } = useInventoryStores(true);
  const { formatMoney } = useFormatting();

  const [project_id, setProjectId] = useState('');
  const [machine_id, setMachineId] = useState('');
  const [rate_card_id, setRateCardId] = useState('');
  const [quantity, setQuantity] = useState('');
  const [allocation_scope, setAllocationScope] = useState<MachineryServiceAllocationScope>('SHARED');
  const [payInKind, setPayInKind] = useState(false);
  const [in_kind_item_id, setInKindItemId] = useState('');
  const [in_kind_rate_per_unit, setInKindRatePerUnit] = useState('');
  const [in_kind_store_id, setInKindStoreId] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});

  const effectiveMachineId = isEdit ? service?.machine_id : machine_id;
  const { data: rateCards } = useRateCardsQuery(
    effectiveMachineId ? { machine_id: effectiveMachineId } : undefined
  );

  const cards = rateCards;

  useEffect(() => {
    if (service && isEdit) {
      setProjectId(service.project_id);
      setMachineId(service.machine_id);
      setRateCardId(service.rate_card_id);
      setQuantity(service.quantity ?? '');
      setAllocationScope((service.allocation_scope as MachineryServiceAllocationScope) || 'SHARED');
      const hasInKind = !!(service.in_kind_item_id && service.in_kind_rate_per_unit);
      setPayInKind(hasInKind);
      setInKindItemId(service.in_kind_item_id ?? '');
      setInKindRatePerUnit(service.in_kind_rate_per_unit ?? '');
      setInKindStoreId(service.in_kind_store_id ?? '');
    }
  }, [service, isEdit]);

  const selectedRateCard = cards?.find((r) => r.id === rate_card_id);
  const qtyNum = parseFloat(quantity);
  const estimatedAmount =
    selectedRateCard && !Number.isNaN(qtyNum) && qtyNum > 0
      ? parseFloat(selectedRateCard.base_rate) * qtyNum
      : null;

  const validate = (): boolean => {
    const e: Record<string, string> = {};
    if (!project_id) e.project_id = 'Project is required';
    if (!machine_id) e.machine_id = 'Machine is required';
    if (!rate_card_id) e.rate_card_id = 'Rate card is required';
    if (!quantity || parseFloat(quantity) <= 0) e.quantity = 'Quantity must be greater than 0';
    if (payInKind) {
      if (!in_kind_item_id) e.in_kind_item_id = 'Inventory item is required for in-kind payment';
      if (in_kind_rate_per_unit === '' || parseFloat(in_kind_rate_per_unit) < 0) e.in_kind_rate_per_unit = 'Rate per unit is required and must be ≥ 0';
      if (!in_kind_store_id) e.in_kind_store_id = 'Store is required for in-kind payment';
    }
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const handleSubmit = async () => {
    if (!validate()) return;
    const qty = parseFloat(quantity);
    if (isEdit && id) {
      const payload: UpdateMachineryServicePayload = {
        rate_card_id,
        quantity: qty,
        allocation_scope,
        ...(payInKind ? { in_kind_item_id: in_kind_item_id || null, in_kind_rate_per_unit: in_kind_rate_per_unit ? parseFloat(in_kind_rate_per_unit) : null, in_kind_store_id: in_kind_store_id || null } : { in_kind_item_id: null, in_kind_rate_per_unit: null, in_kind_store_id: null }),
      };
      await updateM.mutateAsync({ id, payload });
      navigate(`/app/machinery/services/${id}`);
    } else {
      const payload: CreateMachineryServicePayload = {
        project_id,
        machine_id,
        rate_card_id,
        quantity: qty,
        allocation_scope,
        ...(payInKind ? { in_kind_item_id: in_kind_item_id || undefined, in_kind_rate_per_unit: in_kind_rate_per_unit ? parseFloat(in_kind_rate_per_unit) : undefined, in_kind_store_id: in_kind_store_id || undefined } : {}),
      };
      const created = await createM.mutateAsync(payload);
      navigate(`/app/machinery/services/${created.id}`);
    }
  };

  if (isEdit && loadingService) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (isEdit && service?.status !== 'DRAFT') {
    return (
      <div className="p-6">
        <p className="text-red-600">Only draft services can be edited.</p>
        <button
          onClick={() => navigate(`/app/machinery/services/${id}`)}
          className="mt-4 text-[#1F6F5C] hover:underline"
        >
          Back to detail
        </button>
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title={isEdit ? 'Edit Service' : 'New Service'}
        backTo="/app/machinery/services"
        breadcrumbs={[
          { label: 'Machinery', to: '/app/machinery' },
          { label: 'Services', to: '/app/machinery/services' },
          { label: isEdit ? 'Edit' : 'New' },
        ]}
      />

      <div className="bg-white rounded-lg shadow p-6 space-y-4">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormField label="Project" required error={errors.project_id}>
            <select
              value={project_id}
              onChange={(e) => setProjectId(e.target.value)}
              disabled={isEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            >
              <option value="">Select project</option>
              {projects?.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Machine" required error={errors.machine_id}>
            <select
              value={machine_id}
              onChange={(e) => {
                setMachineId(e.target.value);
                if (!isEdit) setRateCardId('');
              }}
              disabled={isEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            >
              <option value="">Select machine</option>
              {machines?.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.code} – {m.name}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Rate card" required error={errors.rate_card_id}>
            <select
              value={rate_card_id}
              onChange={(e) => setRateCardId(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">Select rate card</option>
              {cards?.map((r) => (
                <option key={r.id} value={r.id}>
                  {r.effective_from} – {r.rate_unit} @ {formatMoney(r.base_rate)}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Quantity" required error={errors.quantity}>
            <input
              type="number"
              min="0"
              step="any"
              value={quantity}
              onChange={(e) => setQuantity(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
              placeholder="e.g. 10"
            />
          </FormField>
          <FormField label="Allocation scope" required>
            <select
              value={allocation_scope}
              onChange={(e) => setAllocationScope(e.target.value as MachineryServiceAllocationScope)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              {ALLOCATION_SCOPES.map((s) => (
                <option key={s} value={s}>
                  {s}
                </option>
              ))}
            </select>
          </FormField>
        </div>

        {isEdit && service?.status !== 'DRAFT' ? null : (
          <div className="border-t pt-4 mt-4 space-y-4">
            <div className="flex items-center gap-2">
              <input
                type="checkbox"
                id="pay-in-kind"
                checked={payInKind}
                onChange={(e) => {
                  setPayInKind(e.target.checked);
                  if (!e.target.checked) {
                    setInKindItemId('');
                    setInKindRatePerUnit('');
                    setInKindStoreId('');
                  }
                }}
                className="h-4 w-4 text-[#1F6F5C] focus:ring-[#1F6F5C] border-gray-300 rounded"
              />
              <label htmlFor="pay-in-kind" className="text-sm font-medium text-gray-700">Pay in kind</label>
            </div>
            {payInKind && (
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4 bg-gray-50 rounded-lg p-4">
                <FormField label="Inventory item" required error={errors.in_kind_item_id}>
                  <select
                    value={in_kind_item_id}
                    onChange={(e) => setInKindItemId(e.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
                  >
                    <option value="">Select item (e.g. Wheat)</option>
                    {inventoryItems?.map((item) => (
                      <option key={item.id} value={item.id}>
                        {item.name}
                      </option>
                    ))}
                  </select>
                </FormField>
                <FormField label="Store" required error={errors.in_kind_store_id}>
                  <select
                    value={in_kind_store_id}
                    onChange={(e) => setInKindStoreId(e.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
                  >
                    <option value="">Select store</option>
                    {inventoryStores?.map((store) => (
                      <option key={store.id} value={store.id}>
                        {store.name}
                      </option>
                    ))}
                  </select>
                </FormField>
                <FormField label="Rate per unit (e.g. kg per hour)" required error={errors.in_kind_rate_per_unit}>
                  <input
                    type="number"
                    min="0"
                    step="any"
                    value={in_kind_rate_per_unit}
                    onChange={(e) => setInKindRatePerUnit(e.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
                    placeholder="e.g. 10"
                  />
                </FormField>
                <FormField label="Total in-kind quantity (read-only)">
                  <input
                    type="text"
                    readOnly
                    value={
                      qtyNum > 0 && in_kind_rate_per_unit !== '' && !Number.isNaN(parseFloat(in_kind_rate_per_unit))
                        ? (qtyNum * parseFloat(in_kind_rate_per_unit)).toFixed(4)
                        : '—'
                    }
                    className="w-full px-3 py-2 border border-gray-200 rounded-md bg-gray-100 text-gray-700"
                  />
                </FormField>
              </div>
            )}
          </div>
        )}

        {estimatedAmount != null && (
          <p className="text-sm text-gray-600">
            Estimated amount: <span className="font-medium tabular-nums">{formatMoney(String(estimatedAmount))}</span>
            {' (amount will be calculated on posting if rate differs)'}
          </p>
        )}
        {!selectedRateCard && (project_id && machine_id && rate_card_id && qtyNum > 0) && (
          <p className="text-sm text-gray-500">Amount will be calculated on posting.</p>
        )}

        <div className="flex gap-2 pt-4">
          <button
            onClick={handleSubmit}
            disabled={createM.isPending || updateM.isPending}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] disabled:opacity-50"
          >
            {isEdit ? (updateM.isPending ? 'Saving...' : 'Save') : createM.isPending ? 'Creating...' : 'Create'}
          </button>
          <button
            onClick={() => navigate(isEdit && id ? `/app/machinery/services/${id}` : '/app/machinery/services')}
            className="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50"
          >
            Cancel
          </button>
        </div>
      </div>
    </div>
  );
}
