import { useState, useEffect } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import { useTenant } from '../hooks/useTenant';
import { acceptInvite } from '../api/auth';
import { BrandLogo } from '../components/BrandLogo';
import toast from 'react-hot-toast';

export default function AcceptInvitePage() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const { setDevIdentity } = useAuth();
  const { setTenantId } = useTenant();

  const tokenFromUrl = searchParams.get('token') ?? '';
  const [token, setToken] = useState(tokenFromUrl);
  const [name, setName] = useState('');
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    setToken(tokenFromUrl);
  }, [tokenFromUrl]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!token.trim() || !name.trim() || !password) {
      toast.error('Token, name and password are required');
      return;
    }
    if (password.length < 8) {
      toast.error('Password must be at least 8 characters');
      return;
    }
    setSubmitting(true);
    try {
      const res = await acceptInvite(token.trim(), name.trim(), password);
      setDevIdentity({
        role: res.user.role as import('../types').UserRole,
        userId: res.user.id,
        tenantId: res.tenant?.id ?? null,
      });
      setTenantId(res.tenant?.id ?? '');
      toast.success('Account activated. Welcome!');
      navigate('/app/dashboard', { replace: true });
    } catch (err: unknown) {
      toast.error((err as Error)?.message ?? 'Failed to accept invitation');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-md w-full space-y-8">
        <div>
          <div className="flex justify-center mb-4">
            <BrandLogo size="lg" />
          </div>
          <h2 className="mt-6 text-center text-2xl font-bold text-gray-900">
            Set up your account
          </h2>
          <p className="mt-2 text-center text-sm text-gray-600">
            Enter your name and choose a password to activate your account.
          </p>
        </div>

        <form onSubmit={handleSubmit} className="mt-8 space-y-4 bg-white shadow rounded-lg p-6">
          <div>
            <label htmlFor="token" className="block text-sm font-medium text-gray-700 mb-1">
              Invitation token
            </label>
            <input
              id="token"
              type="text"
              value={token}
              onChange={(e) => setToken(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#1F6F5C] focus:border-[#1F6F5C] font-mono text-sm"
              placeholder="Paste the token from your invite link"
              autoComplete="off"
            />
          </div>
          <div>
            <label htmlFor="name" className="block text-sm font-medium text-gray-700 mb-1">
              Your name
            </label>
            <input
              id="name"
              type="text"
              value={name}
              onChange={(e) => setName(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
              required
              autoComplete="name"
            />
          </div>
          <div>
            <label htmlFor="password" className="block text-sm font-medium text-gray-700 mb-1">
              Password
            </label>
            <input
              id="password"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
              required
              minLength={8}
              autoComplete="new-password"
            />
            <p className="mt-1 text-xs text-gray-500">At least 8 characters</p>
          </div>
          <button
            type="submit"
            disabled={submitting || !token.trim() || !name.trim() || password.length < 8}
            className="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-[#1F6F5C] hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C] disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {submitting ? 'Activating...' : 'Activate account'}
          </button>
        </form>
      </div>
    </div>
  );
}
