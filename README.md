# Parker Estate Assistant

A personal AI assistant that runs on local models through [Ollama](https://ollama.com). It remembers your conversations, can use tools from any [Model Context Protocol](https://modelcontextprotocol.io) (MCP) server you connect, follows reusable skills you write, and can search and read the web through a headless browser.

Built with Laravel 13, Inertia + Vue, and the [Laravel AI SDK](https://github.com/laravel/ai).

## Features

- **Chat with memory**: every conversation is stored, and the model is sent the earlier messages each time you continue one.
- **Model per conversation**: pick any installed Ollama model next to the Send button. The choice is saved with the conversation; new chats start on `ASSISTANT_MODEL` when it's installed.
- **Live replies**: replies are generated on a queue and streamed to the browser token by token over [Laravel Reverb](https://reverb.laravel.com) WebSockets, including which tools the assistant used.
- **Linked sources**: `web_search` and `web_fetch` return Markdown links, and every reply that used them ends with a numbered **Sources** list of the pages, so you can always click through.
- **Tool transcript**: each reply lists the tools the assistant used. Expand one to see what it was called with and what it returned.
- **MCP servers**: connect local (stdio) or remote (HTTP) MCP servers. Their tools become available to the assistant automatically. Each server's tool list is cached, so a local server only starts when one of its tools is used; **Refresh tools** on the Tools page lists them again. Environment variables and headers are encrypted at rest.
- **MCP sign-in (OAuth)**: remote servers that require signing in get a **Sign in** button. The app finds the server's authorization server, registers itself if needed, and signs in with PKCE. Tokens are stored encrypted and refreshed automatically before they expire.
- **Tools**: switch any built-in or MCP tool on or off. Built-in tools:
    - `web_search`: searches DuckDuckGo in headless Chrome and returns titles, URLs and snippets.
    - `web_fetch`: opens a page in headless Chrome (so JavaScript-rendered content works) and returns its text. It only reaches public addresses.
    - `current_date_time`: the current date and time in any timezone.
- **Document analysis**: attach PDF, Word (.docx), text, Markdown or CSV files to a message (up to 5, 20 MB each). Their text is given to the assistant for the rest of the conversation, and long documents can be read in full with the `read_document` tool.
- **Email and text messages that need your approval**: `send_email` (Laravel Mail) and `send_sms` (Twilio, through Laravel Notifications) never send on their own. The reply pauses and shows exactly what will be sent, with **Approve** and **Deny** buttons, and continues once you decide.
- **Skills**: named instructions the assistant loads on demand when a task matches the skill's description.

## Requirements

- Docker (the app runs in [Laravel Sail](https://laravel.com/docs/sail))
- [Ollama](https://ollama.com) running on the host, with at least one model pulled. Pick a model that supports tool calling, such as `llama3.1:8b` or `qwen2.5:7b`.

## Getting started

```bash
cp .env.example .env
composer install
vendor/bin/sail up -d
vendor/bin/sail artisan key:generate
vendor/bin/sail artisan migrate
vendor/bin/sail npm install
vendor/bin/sail artisan reverb:install   # generates REVERB_APP_* keys in .env
vendor/bin/sail composer run dev         # Vite, the queue worker and Reverb
```

Pull a model and point the app at it:

```bash
ollama pull llama3.1
# then in .env:
ASSISTANT_MODEL=llama3.1
```

Register an account, then open **Chat** from the sidebar.

## Configuration

Most settings live in [`config/assistant.php`](config/assistant.php) and can be set from `.env`:

| Variable                        | Default                             | Purpose                                                      |
| ------------------------------- | ----------------------------------- | ------------------------------------------------------------ |
| `AI_PROVIDER`                   | `ollama`                            | Provider from `config/ai.php`                                |
| `OLLAMA_URL`                    | `http://host.docker.internal:11434` | Where Ollama is listening (from inside Sail)                 |
| `ASSISTANT_MODEL`               | `llama3.1`                          | The default model; each chat can pick another installed one  |
| `ASSISTANT_INSTRUCTIONS`        | (built in)                          | The system prompt                                            |
| `ASSISTANT_MEMORY_MESSAGES`     | `50`                                | Earlier messages sent back with each prompt                  |
| `ASSISTANT_CONTEXT_TOKENS`      | `16384`                             | Ollama's context window (`num_ctx`)                          |
| `ASSISTANT_DOCUMENT_CHARACTERS` | `24000`                             | Document text put in the instructions                        |
| `ASSISTANT_MCP_STDIO`           | `true`                              | Allow local (stdio) MCP servers                              |
| `ASSISTANT_MCP_TIMEOUT`         | `30`                                | Seconds to wait for an MCP server                            |
| `ASSISTANT_MCP_TOOL_CACHE`      | `3600`                              | Seconds to cache each MCP server's tool list                 |
| `BROWSER_ENDPOINT`              | `ws://chrome:3000/`                 | Remote headless Chrome; leave empty to launch a local Chrome |
| `BROWSER_CHROME_BINARY`         | (auto-detected)                     | Path to Chrome when launching locally                        |
| `BROWSER_TIMEOUT`               | `30`                                | Seconds to wait for a page to load                           |
| `WEB_SEARCH_URL`                | DuckDuckGo HTML                     | Search URL; `%s` is replaced with the query                  |
| `WEB_SEARCH_RESULTS`            | `5`                                 | Default number of search results (max 10)                    |
| `WEB_FETCH_MAX_CHARACTERS`      | `20000`                             | Page text is truncated after this many characters            |

> Don't set `CHROME_PATH` in `.env`. The Chrome library reads it directly, even when it's empty. Use `BROWSER_CHROME_BINARY` instead.

### Headless browser

Under Sail, the `chrome` service in [`compose.yaml`](compose.yaml) runs [browserless/chromium](https://github.com/browserless/browserless) and the app connects to it at `ws://chrome:3000/`. Outside Docker (for example with Herd), set `BROWSER_ENDPOINT=` and the Chrome installed on the machine is launched instead.

### Live replies (Reverb)

Sending a message queues a `StreamAssistantReply` job and redirects straight back to the chat. The job streams the reply from the model and broadcasts each piece as an `assistant.stream` event on the user's private `App.Models.User.{id}` channel. It finishes with a `reply_complete` event, sent once the exchange has been saved, and the chat page then reloads the stored messages.

This needs both a **queue worker** and the **Reverb server** running. `composer run dev` starts both. Without a worker, messages are accepted but no reply ever arrives. Reverb listens on port 8080, which `compose.yaml` publishes from the app container.

### Using it from other devices on your network

The app container publishes port 80 on every interface, so other devices can open `http://<this machine's address>` (on a Mac, `ipconfig getifaddr en0` prints it). Two addresses are baked into the pages and must point at this machine instead of `localhost`:

```dotenv
VITE_REVERB_HOST=192.168.0.60   # live replies; keep REVERB_HOST="localhost" for the server side
VITE_HMR_HOST=192.168.0.60      # only needed while the Vite dev server runs
```

Rebuild the assets (`vendor/bin/sail npm run build`) or restart `composer run dev` after changing them. Anyone on the network can then register and add local MCP servers, which run commands on this machine, so set `ASSISTANT_MCP_STDIO=false` unless you trust everyone on it.

### MCP sign-in (OAuth)

Remote MCP servers that answer with `401` show **Sign in** on the MCP servers and Tools pages. Signing in uses the OAuth support in `laravel/mcp`: it reads the server's protected resource metadata, registers the app with dynamic client registration (or uses the client ID and secret entered under **OAuth sign-in** on the server's form), and sends you to the provider's sign-in page. When no scopes are entered, it asks for the scopes the server advertises. The provider returns you to `/settings/mcp-servers/{id}/oauth/callback` on the address you opened the app at. Some providers, such as WorkOS AuthKit, only accept `https` or `localhost` return addresses, so sign in from `http://localhost` when the app has no `https` address. Once signed in, the tokens work from any address. Changing a server's URL or client signs you out.

### Documents

The text is read when you upload: PDFs with `smalot/pdfparser`, Word files from their XML, and everything else as plain text. Scanned PDFs (pictures of text) have no text to read and are rejected. Files are kept on the private `local` disk and deleted with their conversation.

The text goes into the assistant's instructions for that conversation, shared between documents up to `ASSISTANT_DOCUMENT_CHARACTERS`, and the model is told to treat it as information rather than instructions. Ollama silently drops anything beyond its context window, so the app raises it to `ASSISTANT_CONTEXT_TOKENS` (16k by default; larger uses more memory). Small models such as `llama3.2:1b` often fail to use documents or tools well, so a 7B+ model is recommended.

### Email and SMS

`send_email` uses the app's mailer, so set `MAIL_MAILER` and its settings in `.env` (with the default `log` mailer, emails are only written to `storage/logs/laravel.log`). `send_sms` needs `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN` and a `TWILIO_FROM` number, and numbers must be in international format (`+15025550123`). Twilio errors, including unverified numbers on trial accounts, are reported back to the assistant instead of being ignored.

## Security notes

- **Local MCP servers run commands on the host.** Anyone who can sign in can add one. If anyone but you can reach the app, set `ASSISTANT_MCP_STDIO=false`.
- **`web_fetch` refuses non-public addresses.** That covers `localhost`, private ranges, cloud metadata and Docker service names, so the model can't be steered into reading internal services. If a public page redirects somewhere private, the content is withheld. The browser still makes that redirected request.
- **Conversations, MCP servers, skills and tool settings are per user.** Other users' records return 404.

## Architecture

Controllers are thin. They validate with form requests, authorize with policies, and hand everything else to a service.

```
app/
├── Ai/
│   ├── Agents/EstateAssistant.php   # The agent: instructions, tools, skills, conversation memory
│   └── Tools/                       # Built-in tools (CurrentDateTime, WebSearch, WebFetch)
├── Events/AssistantReplyStreamed.php  # Broadcasts each streamed event to the user's channel
├── Jobs/StreamAssistantReply.php      # Runs one reply on the queue
├── Http/Controllers/Assistant/      # Chat, MCP servers, tools, skills
├── Models/                          # McpServer, Skill, DisabledTool
└── Services/
    ├── AssistantService.php         # Queues a reply; builds the agent and streams the reply
    ├── ConversationService.php      # Lists, shows and deletes a user's conversations
    ├── McpServerService.php         # MCP server CRUD and tool discovery
    ├── SkillService.php             # Skill CRUD; converts skills for the agent
    ├── ToolService.php              # Tool catalog, on/off switches, enabled tools for a user
    ├── WebFetchService.php          # Public-only page fetching
    ├── WebSearchService.php         # Search results parsing
    ├── Browser/                     # Browser contract + headless Chrome implementation
    └── Mcp/                         # MCP connector contract + laravel/mcp client implementation
```

Tools are identified by a key: `builtin:{name}` for built-in tools and `mcp:{server id}:{tool name}` for MCP tools. Tools are enabled by default, and switching one off stores a row in `disabled_tools`.

### Adding a built-in tool

```bash
vendor/bin/sail artisan make:tool MyTool
```

Implement the tool, then add its class to the `tools` array in `config/assistant.php`. It will show up on the Tools page and be given to the agent.

## Testing

```bash
vendor/bin/sail artisan test
```

The model, MCP servers and browser are all faked in tests, so the suite needs no network, Ollama or Chrome. Outside Sail, you can run it against in-memory SQLite:

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test
```

Before pushing, `composer test` runs Pint, PHPStan and the test suite.
