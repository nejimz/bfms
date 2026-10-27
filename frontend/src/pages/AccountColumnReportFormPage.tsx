import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { Link, Navigate, useNavigate, useParams } from 'react-router-dom'
import { api, errorMessage, fieldErrors } from '../api'
import { useAuth } from '../auth'
import { canWriteReport, readResource, type Account, type AccountColumnReport } from '../types'

export function AccountColumnReportFormPage() {
  const { id } = useParams()
  const editing = Boolean(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const companyId = user?.company?.id
  const accounts = useQuery({
    queryKey: ['accounts', companyId],
    enabled: Boolean(companyId),
    queryFn: async () => {
      const response = await api.get('/api/v1/accounts')
      return (response.data.data as Array<{ id: string; attributes: Omit<Account, 'id'> }>).map((resource) =>
        readResource(resource),
      )
    },
  })
  const report = useQuery({
    queryKey: ['account-column-report', companyId, id],
    enabled: editing && Boolean(companyId),
    queryFn: async () => {
      const response = await api.get(`/api/v1/account-column-reports/${id}`)
      return readResource<Omit<AccountColumnReport, 'id'>>(response.data.data)
    },
  })
  const [name, setName] = useState('')
  const [selected, setSelected] = useState<string[]>([])
  const [loadedReportId, setLoadedReportId] = useState<string | null>(editing ? null : 'new')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [pending, setPending] = useState(false)

  if (report.data && loadedReportId !== report.data.id) {
    setLoadedReportId(report.data.id)
    setName(report.data.name)
    setSelected(report.data.accounts.map((account) => account.id))
  }

  if (user && !canWriteReport(user.role)) {
    return <Navigate to="/chart-account-columns" replace />
  }

  function toggle(accountId: string) {
    setSelected((current) =>
      current.includes(accountId) ? current.filter((id) => id !== accountId) : [...current, accountId],
    )
  }

  async function submit(event: FormEvent) {
    event.preventDefault()
    const nextErrors: Record<string, string> = {}
    if (!name.trim()) {
      nextErrors.name = 'Enter a report name.'
    }
    const accountIds = (accounts.data ?? [])
      .filter((account) => selected.includes(account.id))
      .map((account) => Number(account.id))
    if (accountIds.length === 0) {
      nextErrors.account_ids = 'Choose at least one chart account.'
    }
    setErrors(nextErrors)
    setFormError(null)
    if (Object.keys(nextErrors).length > 0) {
      return
    }

    setPending(true)

    try {
      const payload = { name: name.trim(), account_ids: accountIds }
      if (editing) {
        await api.put(`/api/v1/account-column-reports/${id}`, payload)
      } else {
        await api.post('/api/v1/account-column-reports', payload)
      }
      await queryClient.invalidateQueries({ queryKey: ['account-column-reports'] })
      await queryClient.invalidateQueries({ queryKey: ['account-column-report'] })
      await queryClient.invalidateQueries({ queryKey: ['chart-account-columns'] })
      navigate('/chart-account-columns')
    } catch (caught) {
      const fields = fieldErrors(caught)
      const accountError = Object.entries(fields).find(([key]) => key === 'account_ids' || key.startsWith('account_ids.'))
      setErrors({
        ...fields,
        ...(accountError ? { account_ids: accountError[1] } : {}),
      })
      if (Object.keys(fields).length === 0) {
        setFormError(errorMessage(caught))
      }
    } finally {
      setPending(false)
    }
  }

  async function remove() {
    if (!id || !window.confirm(`Delete ${name.trim() || 'this report'}?`)) {
      return
    }

    setPending(true)
    setFormError(null)

    try {
      await api.delete(`/api/v1/account-column-reports/${id}`)
      await queryClient.invalidateQueries({ queryKey: ['account-column-reports'] })
      navigate('/chart-account-columns')
    } catch (caught) {
      setFormError(errorMessage(caught))
      setPending(false)
    }
  }

  const accountError = errors.account_ids

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <p className="eyebrow">{user?.company?.name}</p>
          <h1>{editing ? 'Edit report' : 'New report'}</h1>
          <p className="hint">Columns follow the order of the accounts below.</p>
        </div>
      </header>

      {editing && report.isLoading ? (
        <p className="hint">Loading report…</p>
      ) : (
        <form className="panel stack-form" noValidate onSubmit={(event) => void submit(event)}>
          <label>
            Report name
            <input
              value={name}
              onChange={(event) => setName(event.target.value)}
              required
              maxLength={255}
              aria-invalid={Boolean(errors.name)}
            />
            {errors.name && <span className="form-error">{errors.name}</span>}
          </label>
          <fieldset className="account-checklist">
            <legend>Chart accounts</legend>
            {accounts.isLoading && <p className="hint">Opening accounts…</p>}
            {accounts.isError && <p className="hint">The chart of accounts could not be opened.</p>}
            {accounts.data?.map((account) => (
              <label key={account.id}>
                <input
                  type="checkbox"
                  checked={selected.includes(account.id)}
                  onChange={() => toggle(account.id)}
                />
                <span>
                  {account.code} {account.name}
                </span>
              </label>
            ))}
            {accountError && <span className="form-error">{accountError}</span>}
          </fieldset>
          {formError && <p className="form-error">{formError}</p>}
          <div className="actions">
            <button type="submit" className="primary" disabled={pending || (editing && loadedReportId !== id)}>
              {pending ? 'Saving…' : editing ? 'Save' : 'Create report'}
            </button>
            {editing && (
              <button type="button" disabled={pending} onClick={() => void remove()}>
                Delete
              </button>
            )}
            <Link className="button-link" to="/chart-account-columns">
              Cancel
            </Link>
          </div>
        </form>
      )}
    </div>
  )
}
