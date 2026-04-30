import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { suppliersApi, type CreateSupplierPayload, type Supplier, type SupplierStatus } from '../../lib/api/procurement/suppliers';

export default function SupplierFormPage() {
  const { id } = useParams();
  const isNew = useMemo(() => !id || id === 'new', [id]);
  const navigate = useNavigate();

  const [loading, setLoading] = useState(!isNew);
  const [saving, setSaving] = useState(false);
  const [supplier, setSupplier] = useState<Supplier | null>(null);

  const [name, setName] = useState('');
  const [status, setStatus] = useState<SupplierStatus>('ACTIVE');
  const [phone, setPhone] = useState('');
  const [email, setEmail] = useState('');
  const [address, setAddress] = useState('');
  const [notes, setNotes] = useState('');

  useEffect(() => {
    if (isNew) return;
    let active = true;
    setLoading(true);
    suppliersApi
      .get(id!)
      .then((r) => {
        if (!active) return;
        setSupplier(r);
        setName(r.name ?? '');
        setStatus(r.status ?? 'ACTIVE');
        setPhone(r.phone ?? '');
        setEmail(r.email ?? '');
        setAddress(r.address ?? '');
        setNotes(r.notes ?? '');
      })
      .catch((e) => {
        toast.error(e?.message ?? 'Failed to load supplier');
      })
      .finally(() => {
        if (!active) return;
        setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [id, isNew]);

  const onSave = async () => {
    const payload: CreateSupplierPayload = {
      name,
      status,
      phone: phone || null,
      email: email || null,
      address: address || null,
      notes: notes || null,
    };

    setSaving(true);
    try {
      if (isNew) {
        const r = await suppliersApi.create(payload);
        toast.success('Supplier created');
        navigate(`/app/procurement/suppliers/${r.id}`);
      } else {
        const r = await suppliersApi.update(id!, payload);
        setSupplier(r);
        toast.success('Supplier updated');
      }
    } catch (e: any) {
      toast.error(e?.message ?? 'Save failed');
    } finally {
      setSaving(false);
    }
  };

  const title = isNew ? 'New supplier' : `Supplier: ${supplier?.name ?? ''}`;

  return (
    <div className="p-6 max-w-3xl">
      <div className="flex items-center justify-between">
        <h2 className="text-xl font-semibold">{title}</h2>
        <button
          className="px-3 py-2 rounded bg-[#1F6F5C] text-white text-sm disabled:opacity-50"
          onClick={onSave}
          disabled={saving || loading || !name.trim()}
        >
          {saving ? 'Saving…' : 'Save'}
        </button>
      </div>

      {loading ? <div className="mt-4 text-sm text-gray-600">Loading…</div> : null}

      <div className="mt-4 bg-white rounded border p-4 space-y-4">
        <div>
          <label className="block text-sm text-gray-600">Name</label>
          <input className="mt-1 w-full border rounded px-3 py-2" value={name} onChange={(e) => setName(e.target.value)} />
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="block text-sm text-gray-600">Status</label>
            <select className="mt-1 w-full border rounded px-3 py-2" value={status} onChange={(e) => setStatus(e.target.value as SupplierStatus)}>
              <option value="ACTIVE">ACTIVE</option>
              <option value="INACTIVE">INACTIVE</option>
            </select>
          </div>
          <div>
            <label className="block text-sm text-gray-600">Phone</label>
            <input className="mt-1 w-full border rounded px-3 py-2" value={phone} onChange={(e) => setPhone(e.target.value)} />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="block text-sm text-gray-600">Email</label>
            <input className="mt-1 w-full border rounded px-3 py-2" value={email} onChange={(e) => setEmail(e.target.value)} />
          </div>
          <div />
        </div>

        <div>
          <label className="block text-sm text-gray-600">Address</label>
          <textarea className="mt-1 w-full border rounded px-3 py-2" rows={3} value={address} onChange={(e) => setAddress(e.target.value)} />
        </div>

        <div>
          <label className="block text-sm text-gray-600">Notes</label>
          <textarea className="mt-1 w-full border rounded px-3 py-2" rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>
      </div>
    </div>
  );
}

