import { expect, test } from '@playwright/test'
import { signIn } from '../fixtures/session'

/**
 * The search box in the top bar, driven the way someone uses it: "/" to reach
 * it from anywhere, a few letters of each word in any order, the arrow and
 * Enter to open the hit - which the module's `search.ts` sends to its page.
 *
 * The seeded project ("Getting started", ExampleSetup) is in every workspace.
 */
test('the search box finds a record by the start of its words and opens it', async ({ page }) => {
  await signIn(page)

  await page.locator('main').click()
  await page.keyboard.press('/')
  const box = page.getByTestId('global-search')
  await expect(box).toBeFocused()

  await box.fill('star gett')
  const hit = page.getByTestId('global-search-result').first()
  await expect(hit).toContainText('Getting started')
  // The matched words are marked, as text - the excerpt is user data.
  await expect(hit.locator('strong').first()).toHaveText(/getting|started/i)

  await box.press('Enter')
  await expect(page).toHaveURL(/\/example$/)
  await expect(box).toHaveValue('')
})
