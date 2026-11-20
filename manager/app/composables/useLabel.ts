/**
 * The reader's word for a value the API sends as a key - a status, a role.
 *
 *     const label = useLabel()
 *     label('manager.tenantStatus', 'suspended')   // "Zawieszony"
 *
 * Unknown values are shown as their key rather than hidden: a status added on
 * the server before its translation must still be visible to an operator.
 */
export function useLabel() {
  const { t, te } = useI18n()

  return (prefix: string, value: string): string => {
    const key = `${prefix}.${value}`
    return te(key) ? t(key) : value
  }
}
