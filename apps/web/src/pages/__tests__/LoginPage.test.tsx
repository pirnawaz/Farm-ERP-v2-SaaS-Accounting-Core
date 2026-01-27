import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { BrowserRouter } from 'react-router-dom';
import LoginPage from '../LoginPage';

// Mock the devApi
vi.mock('../api/dev', () => ({
  devApi: {
    listTenants: vi.fn().mockResolvedValue({ tenants: [] }),
  },
}));

describe('LoginPage', () => {
  it('renders login form', () => {
    render(
      <BrowserRouter>
        <LoginPage />
      </BrowserRouter>
    );

    expect(screen.getByText(/Welcome back to Terrava|Terrava/i)).toBeInTheDocument();
    expect(screen.getByText('Select Role')).toBeInTheDocument();
  });

  it('has role selection dropdown', () => {
    render(
      <BrowserRouter>
        <LoginPage />
      </BrowserRouter>
    );

    const roleSelect = screen.getByLabelText('Select Role');
    expect(roleSelect).toBeInTheDocument();
  });
});
