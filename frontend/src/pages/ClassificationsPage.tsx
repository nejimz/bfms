import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { ActivityLogs } from '../components/ActivityLogs'
import { useAuth } from '../auth'
import { readResource, type Classification } from '../types'

function businessLabel(count: number): string {
  if (count === 0) {
    return 'No businesses'
  }

  return `${count} ${count === 1 ? 'business' : 'businesses'}`
}

function initial(name: string): string {
  return name.trim().charAt(0).toUpperCase() || '·'
}

function ClassificationRow({ classification, editable }: { classification: Classification; editable: boolean }) {
  const body = (
    <>
      {classification.logo_url ? (
        <img className="business-logo" src={classification.logo_url} alt="" />
      ) : (
        <span className="category-mark" aria-hidden="true">
          {initial(classification.name)}
        </span>
      )}
      <h2>{classification.name}</h2>
      <p className="category-count">
        {classification.category_name && (
          <>
            {classification.category_name}
            <span aria-hidden="true"> · </span>
          </>
        )}
        {businessLabel(classification.businesses_count)}
      </p>
      {editable && <span className="category-edit">Edit</span>}
    </>
  )

  if (editable) {
    return (
      <Link className="category-row" to={`/classifications/${classification.id}/edit`}>
        {body}
      </Link>
    )
  }

  return <article className="category-row">{body}</article>
}

export function ClassificationsPage() {
  const { user } = useAuth()
  const classifications = useQuery({
    queryKey: ['classifications'],
    enabled: Boolean(user),
    queryFn: async () => {
      const response = await api.get('/api/v1/classifications')
      return (response.data.data as Array<{ id: string; attributes: Omit<Classification, 'id'> }>).map((resource) =>
        readResource(resource),
      )
    },
  })

  const rows = classifications.data ?? []
  const editable = user?.role === 'admin'
  const businessTotal = rows.reduce((total, classification) => total + classification.businesses_count, 0)

  return (
    <div className="page category-page">
      <header className="page-header">
        <div>
          <p className="eyebrow">Directory</p>
          <h1>Classifications</h1>
          <p className="hint">Group businesses, such as retail or wholesale.</p>
          {classifications.isSuccess && rows.length > 0 && (
            <p className="category-tally">
              <strong>{rows.length}</strong> {rows.length === 1 ? 'classification' : 'classifications'}
              <span aria-hidden="true"> · </span>
              <strong>{businessTotal}</strong> {businessTotal === 1 ? 'business' : 'businesses'}
            </p>
          )}
        </div>
        {editable && (
          <Link className="primary button-link" to="/classifications/new">
            Add classification
          </Link>
        )}
      </header>

      {classifications.isLoading && (
        <div className="category-sheet" role="status" aria-label="Loading classifications">
          {Array.from({ length: 6 }, (_, index) => (
            <div key={index} className="category-row category-row-skeleton" />
          ))}
        </div>
      )}

      {classifications.isError && (
        <div className="category-empty panel">
          <strong>The classifications could not be opened.</strong>
          <p>Refresh the page and try again.</p>
        </div>
      )}

      {classifications.isSuccess && rows.length === 0 && (
        <div className="category-empty panel">
          <strong>No classifications yet</strong>
          <p>Add a classification when you want to group businesses, such as retail or wholesale.</p>
          {editable && (
            <Link className="primary button-link" to="/classifications/new">
              Add classification
            </Link>
          )}
        </div>
      )}

      {rows.length > 0 && (
        <div className="category-sheet">
          {rows.map((classification) => (
            <ClassificationRow key={classification.id} classification={classification} editable={editable} />
          ))}
        </div>
      )}
      <ActivityLogs module="classification" />
    </div>
  )
}
