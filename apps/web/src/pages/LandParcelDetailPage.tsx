import { useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useLandParcel, useAddLandParcelDocument } from '../hooks/useLandParcels';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { useRole } from '../hooks/useRole';
import toast from 'react-hot-toast';
import type { CreateLandDocumentPayload } from '../types';

export default function LandParcelDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: parcel, isLoading } = useLandParcel(id || '');
  const addDocumentMutation = useAddLandParcelDocument();
  const { hasRole } = useRole();
  const [showAddDocumentModal, setShowAddDocumentModal] = useState(false);
  const [documentData, setDocumentData] = useState<CreateLandDocumentPayload>({
    file_path: '',
    description: '',
  });

  const canEdit = hasRole(['tenant_admin', 'accountant']);

  const handleAddDocument = async () => {
    if (!id) return;
    try {
      await addDocumentMutation.mutateAsync({ id, payload: documentData });
      toast.success('Document added successfully');
      setShowAddDocumentModal(false);
      setDocumentData({ file_path: '', description: '' });
    } catch (error: any) {
      toast.error(error.message || 'Failed to add document');
    }
  };

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (!parcel) {
    return <div>Land parcel not found</div>;
  }

  // Calculate remaining acres
  const totalAllocated = parcel.allocations?.reduce(
    (sum, alloc) => sum + parseFloat(alloc.allocated_acres || '0'),
    0
  ) || 0;
  const remainingAcres = parseFloat(parcel.total_acres) - totalAllocated;

  return (
    <div>
      <div className="mb-6">
        <Link to="/app/land" className="text-[#1F6F5C] hover:text-[#1a5a4a] mb-2 inline-block">
          ← Back to Land Parcels
        </Link>
        <h1 className="text-2xl font-bold text-gray-900 mt-2">{parcel.name}</h1>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Basic Info */}
        <div className="bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">Basic Information</h2>
          <dl className="space-y-2">
            <div>
              <dt className="text-sm font-medium text-gray-500">Total Acres</dt>
              <dd className="text-sm text-gray-900">{parcel.total_acres}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Remaining Acres</dt>
              <dd className="text-sm text-gray-900">{remainingAcres.toFixed(2)}</dd>
            </div>
            {parcel.notes && (
              <div>
                <dt className="text-sm font-medium text-gray-500">Notes</dt>
                <dd className="text-sm text-gray-900">{parcel.notes}</dd>
              </div>
            )}
          </dl>
        </div>

        {/* Documents */}
        <div className="bg-white rounded-lg shadow p-6">
          <div className="flex justify-between items-center mb-4">
            <h2 className="text-lg font-medium text-gray-900">Documents</h2>
            {canEdit && (
              <button
                onClick={() => setShowAddDocumentModal(true)}
                className="text-sm text-[#1F6F5C] hover:text-[#1a5a4a]"
              >
                + Add Document
              </button>
            )}
          </div>
          {parcel.documents && parcel.documents.length > 0 ? (
            <ul className="space-y-2">
              {parcel.documents.map((doc) => (
                <li key={doc.id} className="flex justify-between items-center p-2 border rounded">
                  <div>
                    <p className="text-sm font-medium text-gray-900">{doc.file_path}</p>
                    {doc.description && (
                      <p className="text-xs text-gray-500">{doc.description}</p>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-gray-500">No documents</p>
          )}
        </div>
      </div>

      {/* Allocations by Crop Cycle */}
      <div className="mt-6 bg-white rounded-lg shadow p-6">
        <h2 className="text-lg font-medium text-gray-900 mb-4">Allocations by Crop Cycle</h2>
        {parcel.allocations_by_cycle && parcel.allocations_by_cycle.length > 0 ? (
          <div className="space-y-4">
            {parcel.allocations_by_cycle.map((group, idx) => (
              <div key={idx} className="border rounded p-4">
                <h3 className="font-medium text-gray-900 mb-2">
                  {group.crop_cycle.name} ({group.crop_cycle.crop_type || 'N/A'})
                </h3>
                <p className="text-sm text-gray-600 mb-2">
                  Total Allocated: {group.total_allocated_acres} acres
                </p>
                <ul className="space-y-1">
                  {group.allocations.map((alloc) => (
                    <li key={alloc.id} className="text-sm text-gray-700">
                      {alloc.allocated_acres} acres to {alloc.party?.name || 'Unknown'} 
                      {alloc.project && (
                        <Link
                          to={`/app/projects/${alloc.project.id}`}
                          className="ml-2 text-[#1F6F5C] hover:text-[#1a5a4a]"
                        >
                          (Project: {alloc.project.name})
                        </Link>
                      )}
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        ) : (
          <p className="text-sm text-gray-500">No allocations</p>
        )}
      </div>

      <Modal
        isOpen={showAddDocumentModal}
        onClose={() => setShowAddDocumentModal(false)}
        title="Add Document"
      >
        <div className="space-y-4">
          <FormField label="File Path" required>
            <input
              type="text"
              value={documentData.file_path}
              onChange={(e) => setDocumentData({ ...documentData, file_path: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
              placeholder="/path/to/document.pdf"
            />
          </FormField>
          <FormField label="Description">
            <textarea
              value={documentData.description}
              onChange={(e) => setDocumentData({ ...documentData, description: e.target.value })}
              rows={3}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <div className="flex justify-end space-x-3">
            <button
              onClick={() => setShowAddDocumentModal(false)}
              className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              onClick={handleAddDocument}
              disabled={addDocumentMutation.isPending || !documentData.file_path}
              className="px-4 py-2 text-sm font-medium text-white bg-[#1F6F5C] rounded-md hover:bg-[#1a5a4a] disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {addDocumentMutation.isPending ? 'Adding...' : 'Add'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
