import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '../api'
import { formatDecimal } from '../money'
import type { YearlySummaryBusiness, YearlySummaryCategory, YearlySummaryReport } from '../types'

function clampYear(year: number): number {
  return Math.min(9999, Math.max(1900, year))
}

function businessLabel(count: number): string {
  if (count === 0) {
    return 'No businesses'
  }

  return count === 1 ? '1 business' : `${count} businesses`
}

const figures = [
  { key: 'sales', label: 'Sales' },
  { key: 'operation_expense', label: 'Operation Expenses' },
  { key: 'purchase_expense', label: 'Purchase Expenses' },
] as const

const monthNames = [
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

export function YearlySummaryPage() {
  const today = new Date()
  const [year, setYear] = useState(today.getFullYear())

  const report = useQuery({
    queryKey: ['yearly-summary', year],
    placeholderData: keepPreviousData,
    queryFn: async () => {
      const response = await api.get<YearlySummaryReport>('/api/v1/reports/yearly-summary', {
        params: { year },
      })

      return response.data
    },
  })

  const groups = report.data?.data ?? []
  const years = Array.from(new Set([year, ...(report.data?.meta.years ?? [])])).sort((left, right) => left - right)

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <p className="eyebrow">{year}</p>
          <h1>Yearly Summary</h1>
          <p className="hint">
            Posted sales, operation expenses, and purchase expenses for each business. Open a business to see each
            month. Drafts stay out of the money.
          </p>
        </div>
      </header>

      <div className="panel dashboard-filters">
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
          <button type="button" onClick={() => setYear((current) => clampYear(current - 1))} disabled={year <= 1900}>
            Previous
          </button>
          <button type="button" onClick={() => setYear((current) => clampYear(current + 1))} disabled={year >= 9999}>
            Next
          </button>
        </div>
      </div>

      {report.isLoading && <p className="hint">Opening the yearly summary…</p>}
      {report.isError && <p className="hint">The yearly summary could not be opened.</p>}
      {report.isSuccess && groups.length === 0 && <p className="hint">No categories yet.</p>}

      {groups.length > 0 && (
        <div className="year-categories">
          {groups.map((group) => (
            <CategoryAccordion key={group.id ?? 'none'} category={group} />
          ))}
        </div>
      )}
    </div>
  )
}

function CategoryAccordion({ category }: { category: YearlySummaryCategory }) {
  return (
    <details className="year-category">
      <summary>
        <span className="year-category-name">{category.name}</span>
        <span className="year-summary-aside">
          <span>{businessLabel(category.businesses.length)}</span>
        </span>
      </summary>
      {category.businesses.length === 0 ? (
        <p className="hint year-empty">No businesses in this category.</p>
      ) : (
        <div className="year-businesses">
          {category.businesses.map((business) => (
            <BusinessAccordion key={business.id} business={business} />
          ))}
        </div>
      )}
    </details>
  )
}

function BusinessAccordion({ business }: { business: YearlySummaryBusiness }) {
  return (
    <details className="year-business">
      <summary>
        <span className="year-business-name">{business.name}</span>
        <span className="year-summary-aside">
          <span className="account-code">{business.currency}</span>
        </span>
      </summary>
      <dl className="year-figures">
        {figures.map((figure) => (
          <div key={figure.key} className={`year-figure ${figure.key}`}>
            <dt>{figure.label}</dt>
            <dd>{formatDecimal(business[figure.key])}</dd>
          </div>
        ))}
      </dl>
      <div className="year-months">
        <table>
          <thead>
            <tr>
              <th>Month</th>
              {figures.map((figure) => (
                <th key={figure.key} className={`amount ${figure.key}`}>
                  {figure.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {business.months.map((month) => (
              <tr key={month.month}>
                <td>{monthNames[month.month - 1]}</td>
                {figures.map((figure) => (
                  <td key={figure.key} className="num">
                    {formatDecimal(month[figure.key])}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </details>
  )
}
