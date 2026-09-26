/** Internal notes of the applications, kept in the browser: a stand-in for a resource of the brick's API. */
export function useApplicationNotes() {
  return useState<Record<string, string>>('playground_notes', () => {
    try {
      return JSON.parse(localStorage.getItem('playground_notes') ?? '{}') as Record<string, string>
    }
    catch {
      return {}
    }
  })
}

export function saveApplicationNote(id: string, note: string) {
  const notes = useApplicationNotes()
  notes.value = { ...notes.value, [id]: note }
  localStorage.setItem('playground_notes', JSON.stringify(notes.value))
}
