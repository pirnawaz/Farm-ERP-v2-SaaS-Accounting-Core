import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { completeFirstLoginPassword } from '../api/auth';
import { useAuth } from '../hooks/useAuth';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { BrandLogo } from '../components/BrandLogo';
import toast from 'react-hot-toast';

export default function SetPasswordPage() {
  const navigate = useNavigate();
  const { setMustChangePassword } = useAuth();
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (password !== confirm) {
      toast.error('Passwords do not match');
      return;
    }
    if (password.length < 10) {
      toast.error('Password must be at least 10 characters');
      return;
    }
    setSubmitting(true);
    try {
      await completeFirstLoginPassword(password);
      setMustChangePassword(false);
      toast.success('Password set. You can now use the app.');
      navigate('/app/dashboard', { replace: true });
    } catch (err: unknown) {
      toast.error((err as Error)?.message ?? 'Failed to set password');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4">
      <div className="max-w-md w-full space-y-6">
        <div className="text-center">
          <BrandLogo size="lg" />
          <h1 className="mt-4 text-xl font-semibold text-gray-900">Set your password</h1>
          <p className="mt-2 text-sm text-gray-600">
            You must set a new password before you can use the app.
          </p>
        </div>
        <form onSubmit={handleSubmit} className="bg-white shadow rounded-lg p-6 space-y-4">
          <div>
            <label htmlFor="new-password" className="block text-sm font-medium text-gray-700 mb-1">
              New password
            </label>
            <input
              id="new-password"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md"
              placeholder="At least 10 characters"
              minLength={10}
              required
              autoComplete="new-password"
            />
          </div>
          <div>
            <label htmlFor="confirm-password" className="block text-sm font-medium text-gray-700 mb-1">
              Confirm password
            </label>
            <input
              id="confirm-password"
              type="password"
              value={confirm}
              onChange={(e) => setConfirm(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md"
              placeholder="Confirm new password"
              minLength={10}
              required
              autoComplete="new-password"
            />
          </div>
          <button
            type="submit"
            disabled={submitting || !password || password !== confirm}
            className="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-[#1F6F5C] hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C] disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {submitting ? <LoadingSpinner size="sm" /> : 'Set password'}
          </button>
        </form>
      </div>
    </div>
  );
}
