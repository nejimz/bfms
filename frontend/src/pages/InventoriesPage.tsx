import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { ActivityLogs } from '../components/ActivityLogs'
import { useAuth } from '../auth'
import { readResource, type Inventory } from '../types'

function initial(name: string): string {
  return name.trim().charAt(0).toUpperCase() || '·'
}

function formatOn(value: string | null): string | null {
  if (!value) {
    return null
  }

  const [year, month, day] = value.split('-').map(Number)

  if (!year || !month || !day) {
    return value
  }

  return new Date(year, month - 1, day).toLocaleDateString('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}

function detail(row: Inventory): string | null {
  const date = formatOn(row.date)
  const parts = [date, `Starting ${row.quantity}`, row.note].filter((part) => part)

  return parts.length > 0 ? parts.join(' · ') : null
}

function InventoryRow({ row, editable }: { row: Inventory; editable: boolean }) {
  const meta = detail(row)
  const body = (
    <>
      <span className="category-mark" aria-hidden="true">
        {initial(row.item_name)}
      </span>
      <div className="item-copy">
        <h2>{row.item_name}</h2>
        {meta && <p className="item-meta">{meta}</p>}
      </div>
      <p className="category-count">
        {row.current_quantity} {row.unit}
      </p>
      {editable && <span className="category-edit">Edit</span>}
    </>
  )

  if (editable) {
    return (
      <Link className="category-row" to={`/inventories/${row.id}/edit`}>
        {body}
      </Link>
    )
  }

  return <article className="category-row">{body}</article>
}

export function InventoriesPage() {
  const { user } = useAuth()
  const inventories = useQuery({
    queryKey: ['inventories'],
    enabled: Boolean(user),
    queryFn: async () => {
      const response = await api.get('/api/v1/inventories')
      return (response.data.data as Array<{ id: string; attributes: Omit<Inventory, 'id'> }>).map((resource) =>
        readResource(resource),
      )
    },
  })

  const rows = inventories.data ?? []
  const editable = user?.role === 'admin'

  return (
    <div className="page category-page">
      <header className="page-header">
        <div>
          <p className="eyebrow">Directory</p>
          <h1>Inventory</h1>
          <p className="hint">
            On hand after posted purchase expenses, sales, and operation expenses. The date is the starting quantity.
          </p>
          {inventories.isSuccess && rows.length > 0 && (
            <p className="category-tally">
              <strong>{rows.length}</strong> {rows.length === 1 ? 'item' : 'items'}
            </p>
          )}
        </div>
        <div className="entry-hero-actions">
          <Link className="button-link" to="/inventories/stock-card">
            Stock card
          </Link>
          {editable && (
            <Link className="primary button-link" to="/inventories/new">
              Add inventory
            </Link>
          )}
        </div>
      </header>

      {inventories.isLoading && (
        <div className="category-sheet" role="status" aria-label="Loading inventory">
          {Array.from({ length: 6 }, (_, index) => (
            <div key={index} className="category-row category-row-skeleton" />
          ))}
        </div>
      )}

      {inventories.isError && (
        <div className="category-empty panel">
          <strong>The inventory could not be opened.</strong>
          <p>Refresh the page and try again.</p>
        </div>
      )}

      {inventories.isSuccess && rows.length === 0 && (
        <div className="category-empty panel">
          <strong>No inventory yet</strong>
          <p>Record how many of an item are on hand.</p>
          {editable && (
            <Link className="primary button-link" to="/inventories/new">
              Add inventory
            </Link>
          )}
        </div>
      )}

      {rows.length > 0 && (
        <div className="category-sheet">
          {rows.map((row) => (
            <InventoryRow key={row.id} row={row} editable={editable} />
          ))}
        </div>
      )}
      <ActivityLogs module="inventory" />
    </div>
  )
}
