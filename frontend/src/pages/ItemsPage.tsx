import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { ActivityLogs } from '../components/ActivityLogs'
import { useAuth } from '../auth'
import { readResource, type Item } from '../types'

function entryLabel(count: number): string {
  if (count === 0) {
    return 'Not used'
  }

  return `${count} ${count === 1 ? 'entry' : 'entries'}`
}

function initial(name: string): string {
  return name.trim().charAt(0).toUpperCase() || '·'
}

function detail(item: Item): string {
  if (!item.description) {
    return item.unit
  }

  return `${item.unit} · ${item.description}`
}

function ItemRow({ item, editable }: { item: Item; editable: boolean }) {
  const body = (
    <>
      <span className="category-mark" aria-hidden="true">
        {initial(item.name)}
      </span>
      <div className="item-copy">
        <h2>{item.name}</h2>
        <p className="item-meta">{detail(item)}</p>
      </div>
      <p className="category-count">{entryLabel(item.entries_count)}</p>
      {editable && <span className="category-edit">Edit</span>}
    </>
  )

  if (editable) {
    return (
      <Link className="category-row" to={`/items/${item.id}/edit`}>
        {body}
      </Link>
    )
  }

  return <article className="category-row">{body}</article>
}

export function ItemsPage() {
  const { user } = useAuth()
  const items = useQuery({
    queryKey: ['items'],
    enabled: Boolean(user),
    queryFn: async () => {
      const response = await api.get('/api/v1/items')
      return (response.data.data as Array<{ id: string; attributes: Omit<Item, 'id'> }>).map((resource) =>
        readResource(resource),
      )
    },
  })

  const rows = items.data ?? []
  const editable = user?.role === 'admin'

  return (
    <div className="page category-page">
      <header className="page-header">
        <div>
          <p className="eyebrow">Directory</p>
          <h1>Items</h1>
          <p className="hint">Name what is sold or bought, with the unit it is counted in.</p>
          {items.isSuccess && rows.length > 0 && (
            <p className="category-tally">
              <strong>{rows.length}</strong> {rows.length === 1 ? 'item' : 'items'}
            </p>
          )}
        </div>
        {editable && (
          <Link className="primary button-link" to="/items/new">
            Add item
          </Link>
        )}
      </header>

      {items.isLoading && (
        <div className="category-sheet" role="status" aria-label="Loading items">
          {Array.from({ length: 6 }, (_, index) => (
            <div key={index} className="category-row category-row-skeleton" />
          ))}
        </div>
      )}

      {items.isError && (
        <div className="category-empty panel">
          <strong>The items could not be opened.</strong>
          <p>Refresh the page and try again.</p>
        </div>
      )}

      {items.isSuccess && rows.length === 0 && (
        <div className="category-empty panel">
          <strong>No items yet</strong>
          <p>Add an item such as rice, in kilograms, before it can be chosen on a sale or expense.</p>
          {editable && (
            <Link className="primary button-link" to="/items/new">
              Add item
            </Link>
          )}
        </div>
      )}

      {rows.length > 0 && (
        <div className="category-sheet">
          {rows.map((item) => (
            <ItemRow key={item.id} item={item} editable={editable} />
          ))}
        </div>
      )}
      <ActivityLogs module="item" />
    </div>
  )
}
