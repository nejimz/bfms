import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, errorMessage } from '../api'
import { useAuth } from '../auth'
import { chartAccountModuleList } from '../chartAccountModules'
import { formatDecimal, toMinor } from '../money'
import type { BusinessSummary, BusinessSummaryReport } from '../types'

const months = [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
]

function registerPath(code: string): string {
  return chartAccountModuleList.find((module) => module.accountCode === code)?.path ?? '/chart-account-entries'
}

function clampYear(year: number): number {
  return Math.min(9999, Math.max(1900, year))
}

function periodLabel(period: 'month' | 'year', year: number, month: number): string {
  if (period === 'year') {
    return String(year)
  }

  return new Date(year, month - 1, 1).toLocaleDateString('en-GB', {
    month: 'long',
    year: 'numeric',
  })
}

function shareWidth(amount: string, maxMinor: number): number {
  if (maxMinor <= 0) {
    return 0
  }

  const minor = Math.abs(toMinor(amount) ?? 0)

  return Math.min(100, (minor / maxMinor) * 100)
}

function groupBusinesses(rows: BusinessSummary[]): Array<{ category: string; businesses: BusinessSummary[] }> {
  const groups: Array<{ category: string; businesses: BusinessSummary[] }> = []

  for (const business of rows) {
    const category = business.category_name ?? 'No category'
    const current = groups[groups.length - 1]

    if (current?.category === category) {
      current.businesses.push(business)
    } else {
      groups.push({ category, businesses: [business] })
    }
  }

  return groups
}

export function DashboardPage() {
  const { user, selectCompany } = useAuth()
  const navigate = useNavigate()
  const today = new Date()
  const [period, setPeriod] = useState<'month' | 'year'>('month')
  const [year, setYear] = useState(today.getFullYear())
  const [month, setMonth] = useState(today.getMonth() + 1)
  const [switchError, setSwitchError] = useState<string | null>(null)

  const report = useQuery({
    queryKey: ['business-summary', period, year, month],
    placeholderData: keepPreviousData,
    queryFn: async () => {
      const response = await api.get<BusinessSummaryReport>('/api/v1/reports/business-summary', {
        params: period === 'month' ? { period, year, month } : { period, year },
      })

      return response.data
    },
  })

  const rows = report.data?.data ?? []
  const groups = groupBusinesses(rows)
  const years = Array.from(new Set([year, ...(report.data?.meta.years ?? [])])).sort((left, right) => left - right)
  const atStart = period === 'year' ? year <= 1900 : year <= 1900 && month === 1
  const atEnd = period === 'year' ? year >= 9999 : year >= 9999 && month === 12
  const totals = report.data?.meta.totals

  function shift(direction: -1 | 1) {
    if (period === 'year') {
      setYear((current) => clampYear(current + direction))
      return
    }

    const next = new Date(year, month - 1 + direction, 1)
    setYear(clampYear(next.getFullYear()))
    setMonth(next.getMonth() + 1)
  }

  async function openRegister(companyId: string, code: string) {
    setSwitchError(null)

    try {
      if (user && companyId !== user.company.id) {
        await selectCompany(companyId)
      }

      navigate(registerPath(code))
    } catch (caught) {
      setSwitchError(errorMessage(caught))
    }
  }

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <p className="eyebrow">{periodLabel(period, year, month)}</p>
          <h1>Dashboard</h1>
          <p className="hint">Posted chart account totals for every business. Drafts stay out of the money.</p>
        </div>
      </header>

      <div className="panel dashboard-filters">
        <label>
          Period
          <select
            value={period}
            onChange={(event) => setPeriod(event.target.value === 'year' ? 'year' : 'month')}
          >
            <option value="month">Month</option>
            <option value="year">Year</option>
          </select>
        </label>
        {period === 'month' && (
          <label>
            Month
            <select value={month} onChange={(event) => setMonth(Number(event.target.value))}>
              {months.map((name, index) => (
                <option key={name} value={index + 1}>
                  {name}
                </option>
              ))}
            </select>
          </label>
        )}
        <label>
          Year
          <select value={year} onChange={(event) => setYear(clampYear(Number(event.target.value)))}>
            {years.map((option) => (
              <option key={option} value={option}>
                {option}
              </option>
            ))}
          </select>
        </label>
        <div className="period-actions">
          <button type="button" onClick={() => shift(-1)} disabled={atStart}>
            Previous
          </button>
          <button type="button" onClick={() => shift(1)} disabled={atEnd}>
            Next
          </button>
        </div>
      </div>

      {switchError && <p className="form-error">{switchError}</p>}
      {report.isLoading && <p className="hint">Opening the dashboard…</p>}
      {report.isError && <p className="hint">The dashboard could not be opened.</p>}
      {report.isSuccess && rows.length === 0 && <p className="hint">No businesses yet.</p>}

      {groups.length > 0 && (
        <div className="dashboard-groups">
          {groups.map((group) => (
            <section key={group.category} className="dashboard-group" aria-label={group.category}>
              <h2 className="dashboard-category">{group.category}</h2>
              {group.businesses.map((business) => (
                <BusinessBlock
                  key={business.id}
                  business={business}
                  onOpen={(code) => void openRegister(business.id, code)}
                />
              ))}
            </section>
          ))}
        </div>
      )}

      {totals && (
        <section className="panel dashboard-totals">
          <h2>All businesses · {totals.currency}</h2>
          <p>
            Income {formatDecimal(totals.income)}
            <span> · Expenses {formatDecimal(totals.expense)}</span>
            <strong className={totals.result.startsWith('-') ? 'unbalanced' : 'balanced'}>
              {' '}
              · {formatDecimal(totals.result)}
            </strong>
          </p>
        </section>
      )}
    </div>
  )
}

function BusinessBlock({
  business,
  onOpen,
}: {
  business: BusinessSummary
  onOpen: (code: string) => void
}) {
  const maxMinor = business.accounts.reduce((max, account) => {
    return Math.max(max, Math.abs(toMinor(account.amount) ?? 0))
  }, 0)

  return (
    <section className="dashboard-business" aria-label={business.name}>
      <div className="account-section-head">
        <h3>
          {business.name} <span className="account-code">{business.currency}</span>
        </h3>
        {business.drafts > 0 && (
          <p className="account-section-total">
            <span className="status">
              {business.drafts} {business.drafts === 1 ? 'draft' : 'drafts'}
            </span>
          </p>
        )}
      </div>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Account</th>
              <th>Type</th>
              <th>Share</th>
              <th className="amount">Amount</th>
              <th className="amount">Cash</th>
              <th className="amount">Credit</th>
              <th className="amount">Entries</th>
            </tr>
          </thead>
          <tbody>
            {business.accounts.map((account) => (
              <tr key={account.id}>
                <td>
                  <button type="button" className="account-link" onClick={() => onOpen(account.code)}>
                    <span className="account-code">{account.code}</span> {account.name}
                  </button>
                </td>
                <td className="type">{account.type}</td>
                <td>
                  <span className="share-track" aria-hidden="true">
                    <span style={{ width: `${shareWidth(account.amount, maxMinor)}%` }} />
                  </span>
                </td>
                <td className="num">{formatDecimal(account.amount)}</td>
                <td className="num">{formatDecimal(account.cash)}</td>
                <td className="num">{formatDecimal(account.credit)}</td>
                <td className="num">{account.entries}</td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr>
              <td colSpan={4}>
                Income {formatDecimal(business.income)}
                <span> · Expenses {formatDecimal(business.expense)}</span>
              </td>
              <td colSpan={3} className="num">
                <span className={business.result.startsWith('-') ? 'unbalanced' : 'balanced'}>
                  {formatDecimal(business.result)}
                </span>
              </td>
            </tr>
          </tfoot>
        </table>
        {business.accounts.length === 0 && <p className="hint">No chart accounts in this period.</p>}
      </div>
    </section>
  )
}
