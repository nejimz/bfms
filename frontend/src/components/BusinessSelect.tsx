import { useState } from 'react'
import { errorMessage } from '../api'
import { useAuth } from '../auth'

export function BusinessSelect({
  disabled = false,
  onSwitched,
}: {
  disabled?: boolean
  onSwitched?: () => void
}) {
  const { user, selectCompany } = useAuth()
  const [error, setError] = useState<string | null>(null)

  if (!user) {
    return null
  }

  async function change(companyId: string) {
    if (!user || disabled || companyId === user.company.id) {
      return
    }

    setError(null)

    try {
      await selectCompany(companyId)
      onSwitched?.()
    } catch (caught) {
      setError(errorMessage(caught))
    }
  }

  return (
    <label className="business-select">
      Business
      <select
        value={user.company.id}
        disabled={disabled}
        onChange={(event) => void change(event.target.value)}
      >
        {user.companies.map((company) => (
          <option key={company.id} value={company.id}>
            {company.name}
          </option>
        ))}
      </select>
      {error && <span className="form-error">{error}</span>}
    </label>
  )
}
