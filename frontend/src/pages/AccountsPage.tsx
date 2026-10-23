import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { api, errorMessage } from '../api'
import { useAuth } from '../auth'
import type { Account, AccountType } from '../types'
import { readResource } from '../types'

const types: AccountType[] = ['asset', 'liability', 'equity', 'income', 'expense']

export function AccountsPage() {
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const companyId = user?.company.id
  const accounts = useQuery({
    queryKey: ['accounts', companyId],
    enabled: Boolean(companyId),
    queryFn: async () => {
      const response = await api.get('/api/v1/accounts')
      return (response.data.data as Array<{ id: string; attributes: Omit<Account, 'id'> }>).map(
        (resource) => readResource(resource),
      )
    },
  })
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [type, setType] = useState<AccountType>('expense')
  const [error, setError] = useState<string | null>(null)

  const create = useMutation({
    mutationFn: async () => {
      await api.post('/api/v1/accounts', { code, name, type })
    },
    onSuccess: async () => {
      setCode('')
      setName('')
      setError(null)
      await queryClient.invalidateQueries({ queryKey: ['accounts'] })
    },
    onError: (caught) => setError(errorMessage(caught)),
  })

  const toggle = useMutation({
    mutationFn: async (account: Account) => {
      await api.patch(`/api/v1/accounts/${account.id}`, {
        name: account.name,
        is_active: !account.is_active,
      })
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['accounts'] })
    },
  })

  function submit(event: FormEvent) {
    event.preventDefault()
    create.mutate()
  }

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <p className="eyebrow">
            {user?.company.name} · {user?.company.currency}
          </p>
          <h1>Chart of accounts</h1>
        </div>
      </header>

      <form className="panel inline-form" onSubmit={submit}>
        <label>
          Code
          <input value={code} onChange={(event) => setCode(event.target.value)} required maxLength={20} />
        </label>
        <label className="grow">
          Name
          <input value={name} onChange={(event) => setName(event.target.value)} required />
        </label>
        <label>
          Type
          <select value={type} onChange={(event) => setType(event.target.value as AccountType)}>
            {types.map((item) => (
              <option key={item} value={item}>
                {item}
              </option>
            ))}
          </select>
        </label>
        <button type="submit" className="primary" disabled={create.isPending}>
          Add account
        </button>
      </form>
      {error && <p className="form-error">{error}</p>}

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Code</th>
              <th>Name</th>
              <th>Type</th>
              <th>Status</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {accounts.data?.map((account) => (
              <tr key={account.id}>
                <td className="num">{account.code}</td>
                <td>{account.name}</td>
                <td className="type">{account.type}</td>
                <td>{account.is_active ? 'Active' : 'Inactive'}</td>
                <td>
                  <button type="button" onClick={() => toggle.mutate(account)}>
                    {account.is_active ? 'Deactivate' : 'Activate'}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {accounts.isLoading && <p className="hint">Loading accounts…</p>}
      </div>
    </div>
  )
}
