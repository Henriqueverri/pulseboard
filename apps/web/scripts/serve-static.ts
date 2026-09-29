/**
 * Serves the `nuxt generate` output like Cloudflare Pages does in SPA mode: existing files
 * (and `dir/index.html`) as-is, every other path gets the root `index.html` shell.
 *
 *   bun scripts/serve-static.ts [dir] [port]
 */
import { resolve, sep } from 'node:path'

const root = resolve(process.argv[2] ?? '.output/public')
const port = Number(process.argv[3] ?? process.env.PORT ?? 3000)

async function existingFile(path: string) {
  const file = Bun.file(path)

  return (await file.exists()) ? file : null
}

Bun.serve({
  port,
  async fetch(request) {
    const pathname = decodeURIComponent(new URL(request.url).pathname)
    const target = resolve(root, `.${pathname}`)

    if (target !== root && !target.startsWith(root + sep)) {
      return new Response('Not found', { status: 404 })
    }

    const file = await existingFile(target) ?? await existingFile(resolve(target, 'index.html'))

    return new Response(file ?? Bun.file(resolve(root, 'index.html')))
  },
})

console.log(`Serving ${root} on http://localhost:${port}`)
