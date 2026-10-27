import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { ActivityLogs } from '../components/ActivityLogs'
import { useAuth } from '../auth'
import { readResource, type Inventory } from '../types'

function initial(name: string): string {
  return name.trim().charAt(0).toUpperCase() || '·'
}

function InventoryRow({ row, editable }: { row: Inventory; editable: boolean }) {
  const body = (
    <>
      <span className="category-mark" aria-hidden="true">
        {initial(row.item_name)}
      </span>
      <div className="item-copy">
        <h2>{row.item_name}</h2>
        {row.note && <p className="item-meta">{row.note}</p>}
      </div>
      <p className="category-count">
        {row.quantity} {row.unit}
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
          <p className="hint">Record how much of each item is on hand. The quantity uses that item's unit.</p>
          {inventories.isSuccess && rows.length > 0 && (
            <p className="category-tally">
              <strong>{rows.length}</strong> {rows.length === 1 ? 'item' : 'items'}
            </p>
          )}
        </div>
        {editable && (
          <Link className="primary button-link" to="/inventories/new">
            Add inventory
          </Link>
        )}
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
