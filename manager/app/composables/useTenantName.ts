/**
 * The name of the tenant an id points at, once it is a whole id - so a field
 * an operator pasted into, or arrived at from a tenant's page, says "Ramus"
 * and not only a UUID. Null while the id is partial or names no tenant.
 */
export function useTenantName(id: Ref<string>) {
  const api = useApi()
  const name = ref<string | null>(null)
  const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

  watch(id, async (value) => {
    name.value = null
    if (!UUID.test(value.trim())) return
    try {
      const tenant = await api.get<{ name: string }>(`/api/manager/tenants/${value.trim()}`)
      if (id.value === value) name.value = tenant.name
    } catch {
      // An unknown id stays nameless; saving against it is refused by the server.
    }
  }, { immediate: true })

  return name
}
