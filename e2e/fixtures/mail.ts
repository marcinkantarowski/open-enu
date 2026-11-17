import { expect } from '@playwright/test'

/**
 * The newest link in the newest mail to an address, read from Mailpit's API.
 *
 * Mail leaves through the worker, so it arrives a moment after the request
 * that caused it: polled, never slept for.
 */
export async function linkMailedTo(address: string, path: string): Promise<string> {
  const base = process.env.E2E_MAIL_URL ?? 'http://mailpit:8025'
  let link = ''

  await expect.poll(async () => {
    const search = await fetch(`${base}/api/v1/search?query=${encodeURIComponent(`to:"${address}"`)}&limit=1`)
    const id = (await search.json()).messages?.[0]?.ID
    if (!id) return ''

    const message = await (await fetch(`${base}/api/v1/message/${id}`)).json()
    const found = String(message.HTML ?? message.Text ?? '').replaceAll('&amp;', '&').match(new RegExp(`https?://[^"'\\s<>]*${path}[^"'\\s<>]*`))
    link = found?.[0] ?? ''
    return link
  }, { message: `a mail to ${address} with a ${path} link`, timeout: 20_000 }).not.toBe('')

  return link
}
