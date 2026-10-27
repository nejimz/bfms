import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { readResource, type Location } from '../types'

function businessLabel(count: number): string {
  if (count === 0) {
    return 'No businesses'
  }

  return `${count} ${count === 1 ? 'business' : 'businesses'}`
}

function initial(name: string): string {
  return name.trim().charAt(0).toUpperCase() || '·'
}

function LocationRow({ location, editable }: { location: Location; editable: boolean }) {
  const body = (
    <>
      <span className="category-mark" aria-hidden="true">
        {initial(location.name)}
      </span>
      <h2>{location.name}</h2>
      <p className="category-count">{businessLabel(location.businesses_count)}</p>
      {editable && <span className="category-edit">Edit</span>}
    </>
  )

  if (editable) {
    return (
      <Link className="category-row" to={`/locations/${location.id}/edit`}>
        {body}
      </Link>
    )
  }

  return <article className="category-row">{body}</article>
}

export function LocationsPage() {
  const { user } = useAuth()
  const locations = useQuery({
    queryKey: ['locations'],
    enabled: Boolean(user),
    queryFn: async () => {
      const response = await api.get('/api/v1/locations')
      return (response.data.data as Array<{ id: string; attributes: Omit<Location, 'id'> }>).map((resource) =>
        readResource(resource),
      )
    },
  })

  const rows = locations.data ?? []
  const editable = user?.role === 'admin'
  const businessTotal = rows.reduce((total, location) => total + location.businesses_count, 0)

  return (
    <div className="page category-page">
      <header className="page-header">
        <div>
          <p className="eyebrow">Directory</p>
          <h1>Locations</h1>
          <p className="hint">Name the places a business can belong to.</p>
          {locations.isSuccess && rows.length > 0 && (
            <p className="category-tally">
              <strong>{rows.length}</strong> {rows.length === 1 ? 'location' : 'locations'}
              <span aria-hidden="true"> · </span>
              <strong>{businessTotal}</strong> {businessTotal === 1 ? 'business' : 'businesses'}
            </p>
          )}
        </div>
        {editable && (
          <Link className="primary button-link" to="/locations/new">
            Add location
          </Link>
        )}
      </header>

      {locations.isLoading && (
        <div className="category-sheet" role="status" aria-label="Loading locations">
          {Array.from({ length: 6 }, (_, index) => (
            <div key={index} className="category-row category-row-skeleton" />
          ))}
        </div>
      )}

      {locations.isError && (
        <div className="category-empty panel">
          <strong>The locations could not be opened.</strong>
          <p>Refresh the page and try again.</p>
        </div>
      )}

      {locations.isSuccess && rows.length === 0 && (
        <div className="category-empty panel">
          <strong>No locations yet</strong>
          <p>Add a location when a business needs a place, such as a branch or a city.</p>
          {editable && (
            <Link className="primary button-link" to="/locations/new">
              Add location
            </Link>
          )}
        </div>
      )}

      {rows.length > 0 && (
        <div className="category-sheet">
          {rows.map((location) => (
            <LocationRow key={location.id} location={location} editable={editable} />
          ))}
        </div>
      )}
    </div>
  )
}
