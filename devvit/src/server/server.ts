import {once} from 'node:events'
import type {IncomingMessage, ServerResponse} from 'node:http'
import {context, reddit, redis} from '@devvit/web/server'
import type {
  PartialJsonValue,
  TriggerResponse,
  UiResponse,
} from '@devvit/web/shared'
import {
  Endpoint,
  EndpointMethod,
  type ErrorRsp,
  type GetCounterRsp,
  type IncCounterReq,
  type IncCounterRsp,
} from '../shared/api.ts'
import {dbGetCounter, dbIncCounter} from './db.ts'
import {fetchFeed, sync} from './newsletter.ts'

/** The scheduled task in devvit.json (scheduler.tasks.newsletter-sync). */
const NEWSLETTER_SYNC = '/internal/scheduler/newsletter-sync'

/** Small print under each post. */
const NEWSLETTER_FOOTER = 'Also on https://discordphp.org/newsletter.html'

type AnyRsp =
  | GetCounterRsp
  | IncCounterRsp
  | UiResponse
  | TriggerResponse
  | ErrorRsp

export async function onReq(
  reqMsg: IncomingMessage,
  rspMsg: ServerResponse,
): Promise<void> {
  try {
    await route(reqMsg, rspMsg)
  } catch (err) {
    const msg = `server error; ${err instanceof Error ? err.stack : err}`
    console.error(msg)
    writeJson<ErrorRsp>(500, {error: msg, status: 500}, rspMsg)
  }
}

async function route(
  reqMsg: IncomingMessage,
  rspMsg: ServerResponse,
): Promise<void> {
  // The newsletter sync, run by the scheduler every few minutes.
  if (reqMsg.url === NEWSLETTER_SYNC) {
    writeJson<PartialJsonValue>(200, {actions: await runNewsletterSync()}, rspMsg)
    return
  }

  const endpoint = reqMsg.url?.slice(1) as Endpoint
  const method = EndpointMethod[endpoint]

  let rsp: AnyRsp
  if (method !== reqMsg.method) {
    rsp = {error: 'not found', status: 404}
  } else {
    switch (endpoint) {
      case Endpoint.GetCounter:
        rsp = await routeGetCounter()
        break
      case Endpoint.IncCounter:
        rsp = await routeInc(reqMsg)
        break
      case Endpoint.OnMenuNewPost:
        rsp = await routeMenuNewPost()
        break
      case Endpoint.OnAppInstall:
        rsp = await routeAppInstall()
        break
      default:
        endpoint satisfies never
        rsp = {error: 'not found', status: 404}
        break
    }
  }

  writeJson<PartialJsonValue>('status' in rsp ? rsp.status : 200, rsp, rspMsg)
}

/** Posts the newsletter editions not yet posted in this subreddit. */
async function runNewsletterSync(): Promise<string[]> {
  return sync(
    {
      fetchFeed: () => fetchFeed(),
      redis: {
        hGet: (key, field) => redis.hGet(key, field),
        hSet: (key, fieldValues) => redis.hSet(key, fieldValues),
      },
      submitPost: opts => reddit.submitPost(opts),
      editPost: async (id, text) => {
        const post = await reddit.getPostById(id as `t3_${string}`)
        await post.edit({text})
      },
      subredditName: context.subredditName,
      log: message => console.log(message),
    },
    NEWSLETTER_FOOTER,
  )
}

async function routeGetCounter(): Promise<GetCounterRsp> {
  const t3 = context.postId
  if (!t3) throw Error('no t3')
  return {count: await dbGetCounter(t3)}
}

async function routeInc(reqMsg: IncomingMessage): Promise<IncCounterRsp> {
  const t3 = context.postId
  if (!t3) throw Error('no t3')
  const req = await readJson<IncCounterReq>(reqMsg)
  return {count: await dbIncCounter(t3, req.amount)}
}

/** The subreddit menu's "Post the newsletter now": the scheduled sync, on demand. */
async function routeMenuNewPost(): Promise<UiResponse> {
  const actions = await runNewsletterSync()
  return {
    showToast: {
      text: actions.length ? actions.join('; ') : 'No new newsletter to post.',
      appearance: actions.some(a => a.startsWith('failed')) ? 'neutral' : 'success',
    },
  }
}

/** Installing posts nothing: the scheduled sync posts the newsletter. */
async function routeAppInstall(): Promise<TriggerResponse> {
  return {}
}

async function readJson<T>(reqMsg: IncomingMessage): Promise<T> {
  const chunks: Uint8Array[] = []
  reqMsg.on('data', chunk => chunks.push(chunk))
  await once(reqMsg, 'end')
  return JSON.parse(`${Buffer.concat(chunks)}`)
}

function writeJson<T extends PartialJsonValue>(
  status: number,
  json: Readonly<T>,
  rsp: ServerResponse,
): void {
  const body = JSON.stringify(json)
  const len = Buffer.byteLength(body)
  rsp.writeHead(status, {
    'Content-Length': len,
    'Content-Type': 'application/json',
  })
  rsp.end(body)
}
