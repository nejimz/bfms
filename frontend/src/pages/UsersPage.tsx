import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { readResource, type DirectoryUser } from '../types'

export function UsersPage() {
  const { user } = useAuth()
  const users = useQuery({
    queryKey: ['users'],
    enabled: Boolean(user),
    queryFn: async () => {
      const response = await api.get('/api/v1/users')
      return (response.data.data as Array<{ id: string; attributes: Omit<DirectoryUser, 'id'> }>).map((resource) =>
        readResource(resource),
      )
    },
  })

  const rows = users.data ?? []

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <p className="eyebrow">Directory</p>
          <h1>Users</h1>
          <p className="hint">Admins and managers open every business. Staff open only the businesses you select.</p>
        </div>
        <Link className="primary button-link" to="/users/new">
          Add user
        </Link>
      </header>

      {users.isLoading && <p className="hint">Loading users…</p>}
      {users.isError && <p className="hint">The users could not be opened.</p>}
      {users.isSuccess && rows.length === 0 && <p className="hint">No users yet.</p>}

      {rows.length > 0 && (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Businesses</th>
                <th>Status</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {rows.map((person) => (
                <tr key={person.id}>
                  <td>{person.name}</td>
                  <td>{person.email}</td>
                  <td className="type">{person.role}</td>
                  <td>{person.role === 'staff' ? person.companies.map((company) => company.name).join(', ') : 'All'}</td>
                  <td>
                    <span className={person.is_active ? 'status posted' : 'status'}>
                      {person.is_active ? 'Active' : 'Inactive'}
                    </span>
                  </td>
                  <td>
                    <div className="actions">
                      <Link className="button-link" to={`/users/${person.id}`}>
                        Edit
                      </Link>
                    </div>
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
