import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider, useAuth } from './auth'
import { Layout } from './components/Layout'
import { AccountsPage } from './pages/AccountsPage'
import { AccountColumnReportFormPage } from './pages/AccountColumnReportFormPage'
import { AccountColumnReportsPage } from './pages/AccountColumnReportsPage'
import { ChartAccountColumnsPage } from './pages/ChartAccountColumnsPage'
import { chartAccountModuleList } from './chartAccountModules'
import { ChartAccountEntriesPage } from './pages/ChartAccountEntriesPage'
import { ChartAccountEntryFormPage } from './pages/ChartAccountEntryFormPage'
import { BusinessFormPage } from './pages/BusinessFormPage'
import { BusinessesPage } from './pages/BusinessesPage'
import { CategoriesPage } from './pages/CategoriesPage'
import { CategoryFormPage } from './pages/CategoryFormPage'
import { JournalFormPage } from './pages/JournalFormPage'
import { JournalsPage } from './pages/JournalsPage'
import { LoginPage } from './pages/LoginPage'
import { TrialBalancePage } from './pages/TrialBalancePage'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchOnWindowFocus: false,
      retry: false,
    },
  },
})

function RequireAuth() {
  const { user, isLoading } = useAuth()

  if (isLoading) {
    return <p className="boot">Opening the books…</p>
  }

  if (!user) {
    return <Navigate to="/login" replace />
  }

  return <Layout />
}

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <BrowserRouter>
          <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route element={<RequireAuth />}>
              <Route index element={<Navigate to="/businesses" replace />} />
              <Route path="/businesses" element={<BusinessesPage />} />
              <Route path="/businesses/new" element={<BusinessFormPage />} />
              <Route path="/businesses/:id/edit" element={<BusinessFormPage />} />
              <Route path="/categories" element={<CategoriesPage />} />
              <Route path="/categories/new" element={<CategoryFormPage />} />
              <Route path="/categories/:id/edit" element={<CategoryFormPage />} />
              <Route path="/accounts" element={<AccountsPage />} />
              <Route path="/chart-account-entries" element={<ChartAccountEntriesPage />} />
              <Route path="/chart-account-columns" element={<AccountColumnReportsPage />} />
              <Route path="/chart-account-columns/new" element={<AccountColumnReportFormPage />} />
              <Route path="/chart-account-columns/:id/edit" element={<AccountColumnReportFormPage />} />
              <Route path="/chart-account-columns/:id" element={<ChartAccountColumnsPage />} />
              <Route path="/chart-account-entries/new" element={<ChartAccountEntryFormPage />} />
              <Route path="/chart-account-entries/:id" element={<ChartAccountEntryFormPage />} />
              {chartAccountModuleList.map((module) => (
                <Route key={`${module.key}-list`} path={module.path} element={<ChartAccountEntriesPage moduleKey={module.key} />} />
              ))}
              {chartAccountModuleList.map((module) => (
                <Route
                  key={`${module.key}-new`}
                  path={`${module.path}/new`}
                  element={<ChartAccountEntryFormPage moduleKey={module.key} />}
                />
              ))}
              {chartAccountModuleList.map((module) => (
                <Route
                  key={`${module.key}-edit`}
                  path={`${module.path}/:id`}
                  element={<ChartAccountEntryFormPage moduleKey={module.key} />}
                />
              ))}
              <Route path="/journals" element={<JournalsPage />} />
              <Route path="/journals/new" element={<JournalFormPage />} />
              <Route path="/journals/:id" element={<JournalFormPage />} />
              <Route path="/trial-balance" element={<TrialBalancePage />} />
            </Route>
          </Routes>
        </BrowserRouter>
      </AuthProvider>
    </QueryClientProvider>
  )
}
