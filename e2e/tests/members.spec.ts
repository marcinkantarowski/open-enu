import { expect, test } from '@playwright/test'
import { linkMailedTo } from '../fixtures/mail'
import { signIn } from '../fixtures/session'

/**
 * Someone joins the way a person does (ADR-0025): invited from the People
 * screen, the link read from the inbox, a password chosen, then signed in -
 * and the owner sees them change from invited to active.
 */
test('an owner invites someone, who joins by the e-mailed link and signs in', async ({ page, browser }) => {
  const email = `invitee-${Date.now()}@example.test`

  await signIn(page)
  await page.goto('/settings/members')

  await page.getByTestId('member-invite').click()
  await page.getByTestId('member-form-email').fill(email)
  await page.getByTestId('member-form-save').click()

  const row = page.getByRole('row').filter({ hasText: email })
  await expect(row).toBeVisible()
  await expect(row).toContainText(/invited|zaproszona/i)

  const link = await linkMailedTo(email, '/accept-invitation')

  // A separate browser: the invitee is nobody the owner's session knows.
  const invitee = await browser.newPage()
  await invitee.goto(link)
  await expect(invitee.getByText(email)).toBeVisible()
  await invitee.getByTestId('invitation-password').fill('a-long-enough-password')
  await invitee.getByTestId('invitation-accept').click()
  await expect(invitee.getByTestId('invitation-done')).toBeVisible()

  await invitee.getByRole('link').filter({ hasText: /sign in|zaloguj/i }).click()
  await expect(invitee.locator('input[type=email]')).toHaveValue(email)
  await invitee.locator('input[type=password]').fill('a-long-enough-password')
  await invitee.locator('button[type=submit]').click()
  await expect(invitee).not.toHaveURL(/login/)

  await page.reload()
  await expect(page.getByRole('row').filter({ hasText: email })).toContainText(/active|aktywna/i)
})
