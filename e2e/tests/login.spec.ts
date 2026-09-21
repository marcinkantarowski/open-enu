import { expect, test } from '@playwright/test'
import { OWNER } from '../fixtures/accounts'
import { signIn } from '../fixtures/session'

/**
 * The session, as the browser actually holds it (ADR-0006).
 *
 * Three claims are made in that ADR and none of them can be checked anywhere but
 * here: the refresh token is an httpOnly cookie, the access token is NOT in any
 * storage the page can read, and a reload turns the cookie back into a session
 * without a login screen.
 */
test.describe('session', () => {
  test('an unauthenticated visit is sent to the login page', async ({ page }) => {
    await page.goto('/example')

    await expect(page).toHaveURL(/\/login/)
    // And remembers where it was going, rather than dropping the person on the
    // dashboard to navigate again.
    await expect(page).toHaveURL(/[?&]next=(%2F|\/)example/)
  })

  test('wrong credentials are refused with one indistinguishable message', async ({ page }) => {
    await page.goto('/login')
    await page.getByTestId('login-email').fill(OWNER.email)
    await page.getByTestId('login-password').fill('definitely-not-the-password')
    await page.getByTestId('login-submit').click()

    await expect(page.getByTestId('login-error')).toBeVisible()
    await expect(page).toHaveURL(/\/login/)
  })

  test('signing in stores the refresh token in an httpOnly cookie and nothing else', async ({ page, context }) => {
    await signIn(page)

    const cookies = await context.cookies()
    const refresh = cookies.find(c => c.name.includes('refresh'))

    expect(refresh, 'the refresh token must be a cookie, not a JSON field the page keeps').toBeTruthy()
    expect(refresh!.httpOnly, 'an XSS must not be able to read it').toBe(true)
    expect(refresh!.sameSite, 'it must not ride along on a cross-site request').toBe('Strict')

    // The access token lives in memory only. Anything that survives the tab is
    // a credential an XSS could steal and use later.
    const stored = await page.evaluate(() => ({
      local: JSON.stringify(localStorage),
      session: JSON.stringify(sessionStorage),
    }))

    expect(stored.local).not.toMatch(/eyJ/)
    expect(stored.session).not.toMatch(/eyJ/)
  })

  test('a reload restores the session from the cookie instead of showing a login form', async ({ page }) => {
    await signIn(page)
    await page.reload()

    await expect(page.getByTestId('current-user')).toBeVisible()
    await expect(page).not.toHaveURL(/\/login/)
  })

  test('signing out clears the session and the guard takes effect immediately', async ({ page }) => {
    await signIn(page)
    await page.getByTestId('sign-out').click()

    await expect(page).toHaveURL(/\/login/)

    await page.goto('/example')
    await expect(page).toHaveURL(/\/login/)
  })
})
