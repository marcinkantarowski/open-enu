import { expect, test } from '@playwright/test'
import { linkMailedTo } from '../fixtures/mail'
import { signIn } from '../fixtures/session'

/**
 * Someone whose role reads one thing (ADR-0025) still has a way into Settings -
 * their profile - and a page their role does not include says so, instead of
 * "something went wrong, try again", which no retry ever fixes.
 */
test('a narrow role opens Settings on its first tab and is told when a page is not theirs', async ({ page, browser }) => {
  const stamp = Date.now()
  const roleName = `Reader ${stamp}`
  const email = `reader-${stamp}@example.test`

  await signIn(page)
  await page.goto('/settings/roles')
  await page.getByTestId('role-new').click()
  await page.getByTestId('role-form-name').fill(roleName)
  await page.getByTestId('role-permission-example.view').getByRole('checkbox').click()
  await page.getByTestId('role-form-save').click()
  await expect(page.getByRole('row').filter({ hasText: roleName })).toBeVisible()

  await page.goto('/settings/members')
  await page.getByTestId('member-invite').click()
  await page.getByTestId('member-form-email').fill(email)
  await page.getByTestId('member-form-role').click()
  await page.getByRole('option', { name: roleName }).click()
  await page.getByTestId('member-form-save').click()
  await expect(page.getByRole('row').filter({ hasText: email })).toBeVisible()

  const reader = await browser.newPage()
  await reader.goto(await linkMailedTo(email, '/accept-invitation'))
  await reader.getByTestId('invitation-password').fill('a-long-enough-password')
  await reader.getByTestId('invitation-accept').click()
  await reader.getByRole('link').filter({ hasText: /sign in|zaloguj/i }).click()
  await reader.locator('input[type=password]').fill('a-long-enough-password')
  await reader.locator('button[type=submit]').click()
  await expect(reader).not.toHaveURL(/login/)

  // The menu offers Settings to everyone; without settings.view it opens on
  // the first tab this role may see, not on a 403.
  await reader.getByRole('navigation').getByRole('link', { name: /^(settings|ustawienia)$/i }).click()
  await expect(reader).toHaveURL(/\/settings\/profile$/)

  await reader.goto('/audit')
  await expect(reader.getByTestId('error-page')).toContainText(/no access|brak dostępu/i)
  await expect(reader.getByTestId('error-page')).not.toContainText(/try again|spróbuj ponownie/i)
})
