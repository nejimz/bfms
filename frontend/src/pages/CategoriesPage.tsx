import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { ActivityLogs } from '../components/ActivityLogs'
import { useAuth } from '../auth'
import { readResource, type Category } from '../types'

function classificationLabel(count: number): string {
  if (count === 0) {
    return 'No classifications'
  }

  return `${count} ${count === 1 ? 'classification' : 'classifications'}`
}

function initial(name: string): string {
  return name.trim().charAt(0).toUpperCase() || '·'
}

function CategoryRow({ category, editable }: { category: Category; editable: boolean }) {
  const body = (
    <>
      <span className="category-mark" aria-hidden="true">
        {initial(category.name)}
      </span>
      <h2>{category.name}</h2>
      <p className="category-count">{classificationLabel(category.classifications_count)}</p>
      {editable && <span className="category-edit">Edit</span>}
    </>
  )

  if (editable) {
    return (
      <Link className="category-row" to={`/categories/${category.id}/edit`}>
        {body}
      </Link>
    )
  }

  return <article className="category-row">{body}</article>
}

export function CategoriesPage() {
  const { user } = useAuth()
  const categories = useQuery({
    queryKey: ['categories'],
    enabled: Boolean(user),
    queryFn: async () => {
      const response = await api.get('/api/v1/categories')
      return (response.data.data as Array<{ id: string; attributes: Omit<Category, 'id'> }>).map((resource) =>
        readResource(resource),
      )
    },
  })

  const rows = categories.data ?? []
  const editable = user?.role === 'admin'
  const classificationTotal = rows.reduce((total, category) => total + category.classifications_count, 0)

  return (
    <div className="page category-page">
      <header className="page-header">
        <div>
          <p className="eyebrow">Directory</p>
          <h1>Categories</h1>
          <p className="hint">Name a shared category, such as vegetables or live stocks and fisheries.</p>
          {categories.isSuccess && rows.length > 0 && (
            <p className="category-tally">
              <strong>{rows.length}</strong> {rows.length === 1 ? 'category' : 'categories'}
              <span aria-hidden="true"> · </span>
              <strong>{classificationTotal}</strong>{' '}
              {classificationTotal === 1 ? 'classification' : 'classifications'}
            </p>
          )}
        </div>
        {editable && (
          <Link className="primary button-link" to="/categories/new">
            Add category
          </Link>
        )}
      </header>

      {categories.isLoading && (
        <div className="category-sheet" role="status" aria-label="Loading categories">
          {Array.from({ length: 6 }, (_, index) => (
            <div key={index} className="category-row category-row-skeleton" />
          ))}
        </div>
      )}

      {categories.isError && (
        <div className="category-empty panel">
          <strong>The categories could not be opened.</strong>
          <p>Refresh the page and try again.</p>
        </div>
      )}

      {categories.isSuccess && rows.length === 0 && (
        <div className="category-empty panel">
          <strong>No categories yet</strong>
          <p>Add a category such as vegetables or live stocks and fisheries. A classification picks one.</p>
          {editable && (
            <Link className="primary button-link" to="/categories/new">
              Add category
            </Link>
          )}
        </div>
      )}

      {rows.length > 0 && (
        <div className="category-sheet">
          {rows.map((category) => (
            <CategoryRow key={category.id} category={category} editable={editable} />
          ))}
        </div>
      )}
      <ActivityLogs module="category" />
    </div>
  )
}
