import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { readResource, type Category } from '../types'

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

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <p className="eyebrow">Directory</p>
          <h1>Categories</h1>
          <p className="hint">Group businesses, such as retail or wholesale.</p>
        </div>
        {user?.role === 'admin' && (
          <Link className="primary button-link" to="/categories/new">
            Add category
          </Link>
        )}
      </header>

      {categories.isLoading && <p className="hint">Loading categories…</p>}
      {categories.isError && <p className="hint">The categories could not be opened.</p>}
      {categories.isSuccess && rows.length === 0 && <p className="hint">No categories yet.</p>}

      {rows.length > 0 && (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Businesses</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {rows.map((category) => (
                <tr key={category.id}>
                  <td>{category.name}</td>
                  <td className="num">{category.businesses_count}</td>
                  <td>
                    {user?.role === 'admin' && (
                      <div className="actions">
                        <Link className="button-link" to={`/categories/${category.id}/edit`}>
                          Edit
                        </Link>
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
