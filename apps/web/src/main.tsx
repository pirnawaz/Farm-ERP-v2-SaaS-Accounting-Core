import React from 'react'
import ReactDOM from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import App from './App.tsx'
import { ToastProvider } from './components/ToastProvider'
import { TenantSettingsProvider } from './contexts/TenantSettingsContext'
import './index.css'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchOnWindowFocus: false,
      retry: 1,
    },
  },
})

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <TenantSettingsProvider>
          <ToastProvider />
          <App />
        </TenantSettingsProvider>
      </BrowserRouter>
    </QueryClientProvider>
  </React.StrictMode>,
)
