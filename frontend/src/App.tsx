import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter, Navigate, Outlet, Route, Routes } from 'react-router-dom'
import { AuthProvider, useAuth } from './auth'
import { Layout } from './components/Layout'
import { OrganizationEffects } from './organization'
import { AccountsPage } from './pages/AccountsPage'
import { AccountColumnReportFormPage } from './pages/AccountColumnReportFormPage'
import { AccountColumnReportsPage } from './pages/AccountColumnReportsPage'
import { ChartAccountColumnsPage } from './pages/ChartAccountColumnsPage'
import { chartAccountModuleList } from './chartAccountModules'
import { ChartAccountEntriesPage } from './pages/ChartAccountEntriesPage'
import { ChartAccountEntryFormPage } from './pages/ChartAccountEntryFormPage'
import { BusinessFormPage } from './pages/BusinessFormPage'
import { CompanyPage } from './pages/CompanyPage'
import { BusinessesPage } from './pages/BusinessesPage'
import { DashboardPage } from './pages/DashboardPage'
import { IncomeExpensePage } from './pages/IncomeExpensePage'
import { YearlySummaryPage } from './pages/YearlySummaryPage'
import { ItemActivityPage } from './pages/ItemActivityPage'
import { CategoriesPage } from './pages/CategoriesPage'
import { CategoryFormPage } from './pages/CategoryFormPage'
import { ClassificationsPage } from './pages/ClassificationsPage'
import { ClassificationFormPage } from './pages/ClassificationFormPage'
import { ItemFormPage } from './pages/ItemFormPage'
import { ItemsPage } from './pages/ItemsPage'
import { LocationFormPage } from './pages/LocationFormPage'
import { LocationsPage } from './pages/LocationsPage'
import { JournalFormPage } from './pages/JournalFormPage'
import { JournalsPage } from './pages/JournalsPage'
import { LoginPage } from './pages/LoginPage'
import { PasswordPage } from './pages/PasswordPage'
import { TrialBalancePage } from './pages/TrialBalancePage'
import { UserFormPage } from './pages/UserFormPage'
import { UsersPage } from './pages/UsersPage'
import { canViewDashboard, homePath } from './types'

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

function HomeRedirect() {
  const { user } = useAuth()

  return <Navigate to={homePath(user?.role, user?.company)} replace />
}

function RequireAdmin() {
  const { user } = useAuth()

  if (user && user.role !== 'admin') {
    return <Navigate to={homePath(user.role)} replace />
  }

  return <Outlet />
}

function RequireDashboard() {
  const { user } = useAuth()

  if (user && !canViewDashboard(user.role)) {
    return <Navigate to={homePath(user.role)} replace />
  }

  return <Outlet />
}

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <OrganizationEffects />
        <BrowserRouter>
          <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route element={<RequireAuth />}>
              <Route index element={<HomeRedirect />} />
              <Route path="/password" element={<PasswordPage />} />
              <Route element={<RequireDashboard />}>
                <Route path="/dashboard" element={<DashboardPage />} />
                <Route path="/income-expense" element={<IncomeExpensePage />} />
                <Route path="/yearly-summary" element={<YearlySummaryPage />} />
              </Route>
              <Route element={<RequireAdmin />}>
                <Route path="/company" element={<CompanyPage />} />
                <Route path="/businesses" element={<BusinessesPage />} />
                <Route path="/businesses/new" element={<BusinessFormPage />} />
                <Route path="/businesses/:id/edit" element={<BusinessFormPage />} />
                <Route path="/classifications" element={<ClassificationsPage />} />
                <Route path="/classifications/new" element={<ClassificationFormPage />} />
                <Route path="/classifications/:id/edit" element={<ClassificationFormPage />} />
                <Route path="/categories" element={<CategoriesPage />} />
                <Route path="/categories/new" element={<CategoryFormPage />} />
                <Route path="/categories/:id/edit" element={<CategoryFormPage />} />
                <Route path="/locations" element={<LocationsPage />} />
                <Route path="/locations/new" element={<LocationFormPage />} />
                <Route path="/locations/:id/edit" element={<LocationFormPage />} />
                <Route path="/items" element={<ItemsPage />} />
                <Route path="/items/new" element={<ItemFormPage />} />
                <Route path="/items/:id/edit" element={<ItemFormPage />} />
                <Route path="/users" element={<UsersPage />} />
                <Route path="/users/new" element={<UserFormPage />} />
                <Route path="/users/:id" element={<UserFormPage />} />
                <Route path="/accounts" element={<AccountsPage />} />
                <Route path="/chart-account-columns" element={<AccountColumnReportsPage />} />
                <Route path="/chart-account-columns/new" element={<AccountColumnReportFormPage />} />
                <Route path="/chart-account-columns/:id/edit" element={<AccountColumnReportFormPage />} />
                <Route path="/chart-account-columns/:id" element={<ChartAccountColumnsPage />} />
                <Route path="/journals" element={<JournalsPage />} />
                <Route path="/journals/new" element={<JournalFormPage />} />
                <Route path="/journals/:id" element={<JournalFormPage />} />
                <Route path="/trial-balance" element={<TrialBalancePage />} />
              </Route>
              <Route path="/chart-account-entries" element={<ChartAccountEntriesPage />} />
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
                  key={`${module.key}-items`}
                  path={`${module.path}/items`}
                  element={<ItemActivityPage moduleKey={module.key} />}
                />
              ))}
              {chartAccountModuleList.map((module) => (
                <Route
                  key={`${module.key}-edit`}
                  path={`${module.path}/:id`}
                  element={<ChartAccountEntryFormPage moduleKey={module.key} />}
                />
              ))}
            </Route>
          </Routes>
        </BrowserRouter>
      </AuthProvider>
    </QueryClientProvider>
  )
}
