import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { chartAccountModule, type ChartAccountModuleKey } from '../chartAccountModules'
import { BusinessSelect } from '../components/BusinessSelect'
import { formatDecimal } from '../money'
import type { ChartAccountEntry } from '../types'
import { readChartAccountEntries } from '../types'

function formatEntryDate(iso: string): string {
  const [year, month, day] = iso.split('-').map(Number)
  if (!year || !month || !day) {
    return iso
  }

  return new Date(year, month - 1, day).toLocaleDateString('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}

export function ChartAccountEntriesPage({ moduleKey }: { moduleKey?: ChartAccountModuleKey }) {
  const moduleConfig = moduleKey ? chartAccountModule(moduleKey) : null
  const listPath = moduleConfig?.path ?? '/chart-account-entries'
  const partyLabel = moduleConfig?.partyLabel ?? 'Particulars'
  const { user } = useAuth()
  const companyId = user?.company.id
  const entries = useQuery({
    queryKey: ['chart-account-entries', companyId],
    enabled: Boolean(companyId),
    queryFn: async () => {
      const response = await api.get('/api/v1/chart-account-entries')
      return readChartAccountEntries(response.data)
    },
  })

  const rows = (entries.data ?? []).filter((entry) =>
    moduleConfig ? entry.account?.code === moduleConfig.accountCode : true,
  )
  const drafts = rows.filter((entry) => entry.status === 'draft').length

  return (
    <div className="page entry-page">
      <header className="entry-hero">
        <div>
          <p className="eyebrow">{user?.company.currency}</p>
          <h1>{moduleConfig?.title ?? 'Chart account entry'}</h1>
          <p className="entry-lede">A private register. Posted lines stay off the general ledger.</p>
        </div>
        <div className="entry-hero-actions">
          {rows.length > 0 && (
            <p className="entry-count">
              {rows.length} {rows.length === 1 ? 'entry' : 'entries'}
              {drafts > 0 ? ` · ${drafts} draft${drafts === 1 ? '' : 's'}` : ''}
            </p>
          )}
          <BusinessSelect />
          {moduleConfig && (
            <Link className="button-link" to={`${listPath}/items`}>
              Item report
            </Link>
          )}
          <Link className="primary button-link" to={`${listPath}/new`}>
            New entry
          </Link>
        </div>
      </header>

      <section className="entry-sheet" aria-label={moduleConfig?.title ?? 'Chart account entries'}>
        {entries.isLoading && <p className="entry-empty">Opening entries…</p>}
        {entries.isError && <p className="entry-empty">The register could not be opened.</p>}
        {entries.isSuccess && rows.length === 0 && (
          <div className="entry-empty">
            <strong>No entries yet</strong>
            <span>
              {moduleConfig
                ? `Record a date, a ${partyLabel.toLowerCase()}, and how much was cash or credit.`
                : 'Record a date, an account, particulars, and how much was cash or credit.'}
            </span>
          </div>
        )}
        {rows.length > 0 && (
          <>
            <div className="entry-sheet-head" aria-hidden="true">
              <span>Date</span>
              <span>Account</span>
              <span>{partyLabel}</span>
              <span>Reference</span>
              <span>Total</span>
              <span>Cash</span>
              <span>Credit</span>
              <span>Status</span>
            </div>
            {rows.map((entry) => (
              <EntryRow key={entry.id} entry={entry} listPath={listPath} />
            ))}
          </>
        )}
      </section>
    </div>
  )
}

function EntryRow({ entry, listPath }: { entry: ChartAccountEntry; listPath: string }) {
  const code = entry.account?.code
  const name = entry.account?.name ?? entry.account_id

  return (
    <Link className="entry-row" to={`${listPath}/${entry.id}`}>
      <time dateTime={entry.date}>{formatEntryDate(entry.date)}</time>
      <span className="entry-account">
        {code && <span className="entry-code">{code}</span>}
        <span className="entry-account-name">{name}</span>
      </span>
      <span className="entry-narrative">
        <span>{entry.particulars}</span>
        {entry.lines.length > 0 && (
          <span className="entry-items-summary">{entry.lines.map((line) => line.name).join(', ')}</span>
        )}
      </span>
      <span className="entry-reference">{entry.reference}</span>
      <span className="entry-amount">{formatDecimal(entry.amount)}</span>
      <span className="entry-cash">
        <span className="entry-money-label">Cash</span>
        {formatDecimal(entry.cash)}
      </span>
      <span className="entry-credit">
        <span className="entry-money-label">Credit</span>
        {formatDecimal(entry.credit)}
      </span>
      <span className={`status ${entry.status}`}>{entry.status}</span>
    </Link>
  )
}
