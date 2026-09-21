/**
 * llms.txt (llmstxt.org): the site described for a language model - its name,
 * one paragraph, and every page with what it is about. Built from the same
 * titles and descriptions a search engine reads, so writing those well is the
 * whole of the work.
 */
export default defineEventHandler(async (event) => {
  const { name } = siteConfig(event)
  const pages = await sitePages(event)
  setHeader(event, 'Content-Type', 'text/plain; charset=utf-8')

  const list = (code: string) => pages
    .map(page => page.versions.find(v => v.code === code))
    .filter(v => v !== undefined)
    .map(v => `- [${v.title}](${v.url}): ${v.description}`)

  const home = pages.find(p => p.path === '/')?.versions.find(v => v.code === 'en')

  return [
    `# ${name}`,
    '',
    ...(home ? [`> ${home.description}`, ''] : []),
    '## Pages',
    '',
    ...list('en'),
    '',
    '## Polski',
    '',
    ...list('pl'),
    '',
  ].join('\n')
})
