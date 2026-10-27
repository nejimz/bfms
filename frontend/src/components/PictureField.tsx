import type { Dispatch, SetStateAction } from 'react'

const imageLimit = 2 * 1024 * 1024
const imageTypes = ['image/jpeg', 'image/png', 'image/webp']

export type Picture = {
  file: File | null
  previewUrl: string | null
  remove: boolean
}

export function emptyPicture(): Picture {
  return { file: null, previewUrl: null, remove: false }
}

export function imageError(file: File | null, noun: string): string | null {
  if (!file) {
    return null
  }

  if (!imageTypes.includes(file.type)) {
    return `Use a JPG, PNG, or WebP ${noun}.`
  }

  if (file.size > imageLimit) {
    return `The ${noun} must be 2 MB or smaller.`
  }

  return null
}

export function replacePicture(current: Picture, file: File | null): Picture {
  if (current.previewUrl) {
    URL.revokeObjectURL(current.previewUrl)
  }

  return {
    file,
    previewUrl: file ? URL.createObjectURL(file) : null,
    remove: false,
  }
}

export function PictureField({
  label,
  hint,
  noun,
  error,
  savedUrl,
  picture,
  onChoose,
  onRemove,
  previewClass,
}: {
  label: string
  hint: string
  noun: string
  error?: string
  savedUrl: string | null
  picture: Picture
  onChoose: (file: File | null) => void
  onRemove: (remove: boolean) => void
  previewClass: string
}) {
  const shown = picture.remove ? null : (picture.previewUrl ?? savedUrl)

  return (
    <>
      <label>
        {label}
        <input
          type="file"
          accept="image/jpeg,image/png,image/webp"
          onChange={(event) => onChoose(event.target.files?.[0] ?? null)}
          aria-invalid={Boolean(error)}
        />
        <span className="hint">{hint}</span>
        {error && <span className="form-error">{error}</span>}
      </label>
      {shown && <img className={previewClass} src={shown} alt="" />}
      {savedUrl && !picture.file && (
        <fieldset className="account-checklist">
          <label>
            <input type="checkbox" checked={picture.remove} onChange={(event) => onRemove(event.target.checked)} />
            Remove {noun}
          </label>
        </fieldset>
      )}
    </>
  )
}

export function appendPicture(body: FormData, field: string, picture: Picture) {
  if (picture.file) {
    body.append(field, picture.file)
  } else if (picture.remove) {
    body.append(`remove_${field}`, '1')
  }
}

export function choosePicture(setPicture: Dispatch<SetStateAction<Picture>>, file: File | null) {
  setPicture((current) => replacePicture(current, file))
}
