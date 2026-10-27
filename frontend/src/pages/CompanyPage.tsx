import { useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors } from '../api'
import { PictureField, appendPicture, choosePicture, emptyPicture, imageError, replacePicture, type Picture } from '../components/PictureField'
import { useOrganization } from '../organization'

const nameLimit = 255

export function CompanyPage() {
  const queryClient = useQueryClient()
  const organization = useOrganization()
  const [name, setName] = useState('')
  const [filled, setFilled] = useState(false)
  const [logo, setLogo] = useState<Picture>(emptyPicture)
  const [icon, setIcon] = useState<Picture>(emptyPicture)
  const [banner, setBanner] = useState<Picture>(emptyPicture)
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
      if (logo.previewUrl) {
        URL.revokeObjectURL(logo.previewUrl)
      }
      if (icon.previewUrl) {
        URL.revokeObjectURL(icon.previewUrl)
      }
      if (banner.previewUrl) {
        URL.revokeObjectURL(banner.previewUrl)
      }
    }
  }, [logo.previewUrl, icon.previewUrl, banner.previewUrl])

  function clearPictures() {
    setLogo((current) => replacePicture(current, null))
    setIcon((current) => replacePicture(current, null))
    setBanner((current) => replacePicture(current, null))
  }

  async function submit(event: FormEvent) {
    event.preventDefault()
    const nextErrors: Record<string, string> = {}

    if (!name.trim()) {
      nextErrors.name = 'Enter a company name.'
    } else if (name.trim().length > nameLimit) {
      nextErrors.name = 'The company name must be 255 characters or fewer.'
    }

    const logoError = imageError(logo.file, 'logo')
    const iconError = imageError(icon.file, 'icon')
    const bannerError = imageError(banner.file, 'banner')
    if (logoError) {
      nextErrors.logo = logoError
    }
    if (iconError) {
      nextErrors.icon = iconError
    }
    if (bannerError) {
      nextErrors.banner = bannerError
    }

    setErrors(nextErrors)
    if (Object.keys(nextErrors).length > 0) {
      return
    }

    setPending(true)

    try {
      const body = new FormData()
      body.append('name', name.trim())
      appendPicture(body, 'logo', logo)
      appendPicture(body, 'icon', icon)
      appendPicture(body, 'banner', banner)
      await api.post('/api/v1/organization', body)
      clearPictures()
      await queryClient.invalidateQueries({ queryKey: ['organization'] })
    } catch (caught) {
      setErrors(fieldErrors(caught))
    } finally {
      setPending(false)
    }
  }

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <p className="eyebrow">Settings</p>
          <h1>Company</h1>
          <p className="hint">
            The name and logo appear in the sidebar, on the login page, and on reports. The icon is the browser tab.
            The banner is the wide image on the login page.
          </p>
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
          <PictureField
            label="Logo"
            noun="logo"
            hint="Shown in the sidebar, on the login page, and on reports. JPG, PNG, or WebP. 2 MB or smaller."
            error={errors.logo}
            savedUrl={organization.data?.logo_url ?? null}
            picture={logo}
            onChoose={(file) => choosePicture(setLogo, file)}
            onRemove={(remove) => setLogo((current) => ({ ...current, remove }))}
            previewClass="logo-preview"
          />
          <PictureField
            label="Icon"
            noun="icon"
            hint="Optional. Used as the browser-tab icon. Leave this empty to keep the default icon. JPG, PNG, or WebP. 2 MB or smaller."
            error={errors.icon}
            savedUrl={organization.data?.icon_url ?? null}
            picture={icon}
            onChoose={(file) => choosePicture(setIcon, file)}
            onRemove={(remove) => setIcon((current) => ({ ...current, remove }))}
            previewClass="logo-preview"
          />
          <PictureField
            label="Banner"
            noun="banner"
            hint="Optional. Shown above the company name on the login page. JPG, PNG, or WebP. 2 MB or smaller."
            error={errors.banner}
            savedUrl={organization.data?.banner_url ?? null}
            picture={banner}
            onChoose={(file) => choosePicture(setBanner, file)}
            onRemove={(remove) => setBanner((current) => ({ ...current, remove }))}
            previewClass="banner-preview"
          />
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
