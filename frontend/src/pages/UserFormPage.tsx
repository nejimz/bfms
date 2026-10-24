import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import { Link, Navigate, useNavigate, useParams } from 'react-router-dom'
import { api, errorMessage, fieldErrors } from '../api'
import { useAuth } from '../auth'
import { readResource, type Business, type DirectoryUser } from '../types'

const roles = [
  { value: 'admin', label: 'Admin' },
  { value: 'manager', label: 'Manager' },
  { value: 'staff', label: 'Staff' },
] as const

const nameLimit = 255

function clientErrors(
  name: string,
  email: string,
  password: string,
  role: string,
  companyIds: string[],
  editing: boolean,
): Record<string, string> {
  const errors: Record<string, string> = {}

  if (!name.trim()) {
    errors.name = 'Enter a name.'
  } else if (name.trim().length > nameLimit) {
    errors.name = 'The name must be 255 characters or fewer.'
  }

  if (!email.trim()) {
    errors.email = 'Enter an email address.'
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) {
    errors.email = 'Enter a valid email address.'
  }

  if (!editing && password.length < 8) {
    errors.password = 'The password must be at least 8 characters.'
  } else if (editing && password !== '' && password.length < 8) {
    errors.password = 'The password must be at least 8 characters.'
  }

  if (!roles.some((item) => item.value === role)) {
    errors.role = 'Choose a role.'
  }

  if (role === 'staff' && companyIds.length === 0) {
    errors.company_ids = 'Choose at least one business.'
  }

  return errors
}

export function UserFormPage() {
  const { id } = useParams()
  const editing = Boolean(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const users = useQuery({
    queryKey: ['users'],
    enabled: editing && Boolean(user),
    queryFn: async () => {
      const response = await api.get('/api/v1/users')
      return (response.data.data as Array<{ id: string; attributes: Omit<DirectoryUser, 'id'> }>).map((resource) =>
        readResource(resource),
      )
    },
  })
  const businesses = useQuery({
    queryKey: ['companies', user?.id],
    enabled: Boolean(user),
    queryFn: async () => {
      const response = await api.get('/api/v1/companies')
      return (response.data.data as Array<{ id: string; attributes: Omit<Business, 'id'> }>).map((resource) =>
        readResource(resource),
      )
    },
  })
  const person = users.data?.find((item) => item.id === id)
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [role, setRole] = useState<(typeof roles)[number]['value']>('staff')
  const [active, setActive] = useState(true)
  const [companyIds, setCompanyIds] = useState<string[]>([])
  const [filled, setFilled] = useState(!editing)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [pending, setPending] = useState(false)

  useEffect(() => {
    if (!person || filled) {
      return
    }

    setName(person.name)
    setEmail(person.email)
    setRole(person.role)
    setActive(person.is_active)
    setCompanyIds(person.companies.map((company) => company.id))
    setFilled(true)
  }, [person, filled])

  if (editing && users.isSuccess && !person) {
    return <Navigate to="/users" replace />
  }

  function toggleCompany(companyId: string) {
    setCompanyIds((current) =>
      current.includes(companyId) ? current.filter((item) => item !== companyId) : [...current, companyId],
    )
  }

  async function submit(event: FormEvent) {
    event.preventDefault()
    const nextErrors = clientErrors(name, email, password, role, companyIds, editing)
    setErrors(nextErrors)
    setFormError(null)
    if (Object.keys(nextErrors).length > 0) {
      return
    }

    setPending(true)

    try {
      const payload = {
        name: name.trim(),
        email: email.trim(),
        password,
        role,
        is_active: active,
        company_ids: role === 'staff' ? companyIds.map((companyId) => Number(companyId)) : [],
      }
      if (editing) {
        await api.patch(`/api/v1/users/${id}`, payload)
      } else {
        await api.post('/api/v1/users', payload)
      }
      await queryClient.invalidateQueries({ queryKey: ['users'] })
      navigate('/users')
    } catch (caught) {
      const fields = fieldErrors(caught)
      setErrors(fields)
      if (Object.keys(fields).length === 0) {
        setFormError(errorMessage(caught))
      }
    } finally {
      setPending(false)
    }
  }

  async function deactivate() {
    if (!id || !window.confirm(`Deactivate ${name.trim() || 'this user'}?`)) {
      return
    }

    setPending(true)
    setFormError(null)

    try {
      await api.delete(`/api/v1/users/${id}`)
      await queryClient.invalidateQueries({ queryKey: ['users'] })
      navigate('/users')
    } catch (caught) {
      const fields = fieldErrors(caught)
      setErrors(fields)
      setFormError(errorMessage(caught))
      setPending(false)
    }
  }

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <p className="eyebrow">Directory</p>
          <h1>{editing ? 'Edit user' : 'Add user'}</h1>
          <p className="hint">Staff need at least one business. Admins and managers can open every business.</p>
        </div>
      </header>

      {editing && users.isLoading ? (
        <p className="hint">Loading user…</p>
      ) : (
        <form className="panel stack-form" noValidate onSubmit={(event) => void submit(event)}>
          <label>
            Name
            <input
              value={name}
              onChange={(event) => setName(event.target.value)}
              required
              maxLength={nameLimit}
              aria-invalid={Boolean(errors.name)}
            />
            {errors.name && <span className="form-error">{errors.name}</span>}
          </label>
          <label>
            Email
            <input
              type="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              required
              autoComplete="off"
              aria-invalid={Boolean(errors.email)}
            />
            {errors.email && <span className="form-error">{errors.email}</span>}
          </label>
          <label>
            {editing ? 'New password' : 'Password'}
            <input
              type="password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              autoComplete="new-password"
              aria-invalid={Boolean(errors.password)}
            />
            {editing && <span className="hint">Leave blank to keep the current password.</span>}
            {errors.password && <span className="form-error">{errors.password}</span>}
          </label>
          <label>
            Role
            <select value={role} onChange={(event) => setRole(event.target.value as (typeof roles)[number]['value'])}>
              {roles.map((item) => (
                <option key={item.value} value={item.value}>
                  {item.label}
                </option>
              ))}
            </select>
            {errors.role && <span className="form-error">{errors.role}</span>}
          </label>
          {role === 'staff' && (
            <fieldset className="account-checklist">
              <legend>Businesses</legend>
              {businesses.isLoading && <p className="hint">Loading businesses…</p>}
              {businesses.data?.map((business) => (
                <label key={business.id}>
                  <input
                    type="checkbox"
                    checked={companyIds.includes(business.id)}
                    onChange={() => toggleCompany(business.id)}
                  />
                  {business.name}
                </label>
              ))}
              {businesses.isSuccess && (businesses.data?.length ?? 0) === 0 && (
                <p className="hint">Add a business before assigning staff.</p>
              )}
              {(errors.company_ids || errors['company_ids.0']) && (
                <span className="form-error">{errors.company_ids || errors['company_ids.0']}</span>
              )}
            </fieldset>
          )}
          {editing && (
            <fieldset className="account-checklist">
              <label>
                <input type="checkbox" checked={active} onChange={(event) => setActive(event.target.checked)} />
                Active
              </label>
              {errors.is_active && <span className="form-error">{errors.is_active}</span>}
            </fieldset>
          )}
          {formError && <p className="form-error">{formError}</p>}
          <div className="actions">
            <button type="submit" className="primary" disabled={pending || (editing && !filled)}>
              {pending ? 'Saving…' : editing ? 'Save' : 'Add user'}
            </button>
            {editing && person?.is_active && person.id !== user?.id && (
              <button type="button" disabled={pending} onClick={() => void deactivate()}>
                Deactivate
              </button>
            )}
            <Link className="button-link" to="/users">
              Cancel
            </Link>
          </div>
        </form>
      )}
    </div>
  )
}
