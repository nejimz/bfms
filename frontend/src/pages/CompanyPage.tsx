import { useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors } from '../api'
import { useOrganization } from '../organization'

const nameLimit = 255
const logoLimit = 2 * 1024 * 1024
const logoTypes = ['image/jpeg', 'image/png', 'image/webp']

function clientErrors(name: string, logo: File | null): Record<string, string> {
  const errors: Record<string, string> = {}

  if (!name.trim()) {
    errors.name = 'Enter a company name.'
  } else if (name.trim().length > nameLimit) {
    errors.name = 'The company name must be 255 characters or fewer.'
  }

  if (logo && !logoTypes.includes(logo.type)) {
    errors.logo = 'Use a JPG, PNG, or WebP logo.'
  } else if (logo && logo.size > logoLimit) {
    errors.logo = 'The logo must be 2 MB or smaller.'
  }

  return errors
}

export function CompanyPage() {
  const queryClient = useQueryClient()
  const organization = useOrganization()
  const [name, setName] = useState('')
  const [filled, setFilled] = useState(false)
  const [logoFile, setLogoFile] = useState<File | null>(null)
  const [previewUrl, setPreviewUrl] = useState<string | null>(null)
  const [removeLogo, setRemoveLogo] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [pending, setPending] = useState(false)

  useEffect(() => {
    if (!organization.data || filled) {
      return
    }

    setName(organization.data.name)
    setFilled(true)
  }, [organization.data, filled])

  useEffect(() => {
    return () => {
      if (previewUrl) {
        URL.revokeObjectURL(previewUrl)
      }
    }
  }, [previewUrl])

  function chooseLogo(file: File | null) {
    setLogoFile(file)
    setRemoveLogo(false)
    setPreviewUrl((current) => {
      if (current) {
        URL.revokeObjectURL(current)
      }

      return file ? URL.createObjectURL(file) : null
    })
  }

  async function submit(event: FormEvent) {
    event.preventDefault()
    const nextErrors = clientErrors(name, logoFile)
    setErrors(nextErrors)
    if (Object.keys(nextErrors).length > 0) {
      return
    }

    setPending(true)

    try {
      const body = new FormData()
      body.append('name', name.trim())
      if (logoFile) {
        body.append('logo', logoFile)
      } else if (removeLogo) {
        body.append('remove_logo', '1')
      }
      await api.post('/api/v1/organization', body)
      chooseLogo(null)
      setRemoveLogo(false)
      await queryClient.invalidateQueries({ queryKey: ['organization'] })
    } catch (caught) {
      setErrors(fieldErrors(caught))
    } finally {
      setPending(false)
    }
  }

  const savedLogo = organization.data?.logo_url
  const shownLogo = removeLogo ? null : (previewUrl ?? savedLogo)

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <p className="eyebrow">Settings</p>
          <h1>Company</h1>
          <p className="hint">This name and logo appear in the sidebar, on the login page, in the browser tab, and on reports.</p>
        </div>
      </header>

      {organization.isLoading ? (
        <p className="hint">Loading company…</p>
      ) : organization.isError ? (
        <p className="form-error">The company profile could not be opened.</p>
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
            Logo
            <input
              type="file"
              accept="image/jpeg,image/png,image/webp"
              onChange={(event) => chooseLogo(event.target.files?.[0] ?? null)}
              aria-invalid={Boolean(errors.logo)}
            />
            <span className="hint">JPG, PNG, or WebP. 2 MB or smaller.</span>
            {errors.logo && <span className="form-error">{errors.logo}</span>}
          </label>
          {shownLogo && <img className="logo-preview" src={shownLogo} alt="" />}
          {savedLogo && !logoFile && (
            <fieldset className="account-checklist">
              <label>
                <input
                  type="checkbox"
                  checked={removeLogo}
                  onChange={(event) => setRemoveLogo(event.target.checked)}
                />
                Remove logo
              </label>
            </fieldset>
          )}
          <div className="actions">
            <button type="submit" className="primary" disabled={pending || !filled}>
              {pending ? 'Saving…' : 'Save'}
            </button>
          </div>
        </form>
      )}
    </div>
  )
}
