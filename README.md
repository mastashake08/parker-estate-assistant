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
    - `web_search`: searches the web and returns titles, URLs and snippets. It uses DuckDuckGo in headless Chrome by default, or SearXNG, Brave Search or Tavily (see [Web search engines](#web-search-engines)).
    - `web_fetch`: opens a page in headless Chrome (so JavaScript-rendered content works) and returns its text. It only reaches public addresses.
    - `current_date_time`: the current date and time in any timezone.
- **Document analysis**: attach PDF, Word (.docx), text, Markdown or CSV files to a message (up to 5, 20 MB each). Their text is given to the assistant for the rest of the conversation, and long documents can be read in full with the `read_document` tool.
- **Email and text messages that need your approval**: `send_email` (Laravel Mail) and `send_sms` (Twilio, through Laravel Notifications) never send on their own. The reply pauses and shows exactly what will be sent, with **Approve** and **Deny** buttons, and continues once you decide.
- **Read aloud**: press **Listen** under any reply, or turn on the speaker button next to Send to hear each new reply. It uses the browser's built-in speech synthesis, so it works offline and nothing is sent anywhere. Choose the voice and speed under **Settings → Appearance**; they're saved per device.
- **Routines**: schedule the assistant to do something on its own, such as a morning brief at 8 AM on weekdays. Each routine has its own instructions, schedule and timezone, model, and the tools it may use. Every run is saved as a chat, and the result can also be emailed or texted to you. See [Routines](#routines).
- **Skills**: named instructions the assistant loads on demand when a task matches the skill's description.
- **Audio studio**: a page for making audio with local models, and playing it in the browser. See [Audio studio](#audio-studio).
    - **Speech** from Gradium's [Phonon](https://huggingface.co/gradium/phonon-31m-en) text-to-speech models. Run one server per model and choose between them.
    - **Music** from Meta's [MusicGen](https://huggingface.co/facebook/musicgen-small). Load any of its models from the page.
    - **MIDI stems** written by Llama 3: one track per instrument, played through any Web MIDI device or a built-in synth, with mute, solo and per-stem downloads.
    - **Sung vocals**: Llama 3 writes lyrics and a melody, the app writes them as a [DiffSinger](https://github.com/openvpi/DiffSinger) score (`.ds` file), and DiffSinger sings them.

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
| `WEB_SEARCH_ENGINE`             | `duckduckgo`                        | `duckduckgo`, `searxng`, `brave` or `tavily`                 |
| `WEB_SEARCH_URL`                | DuckDuckGo HTML                     | DuckDuckGo search URL; `%s` is replaced with the query       |
| `SEARXNG_URL`                   | (none)                              | Your SearXNG instance, such as `http://searxng:8080`         |
| `BRAVE_SEARCH_API_KEY`          | (none)                              | Brave Search API key                                         |
| `TAVILY_API_KEY`                | (none)                              | Tavily API key                                               |
| `WEB_SEARCH_RESULTS`            | `5`                                 | Default number of search results (max 10)                    |
| `WEB_FETCH_MAX_CHARACTERS`      | `20000`                             | Page text is truncated after this many characters            |

> Don't set `CHROME_PATH` in `.env`. The Chrome library reads it directly, even when it's empty. Use `BROWSER_CHROME_BINARY` instead.

### Headless browser

Under Sail, the `chrome` service in [`compose.yaml`](compose.yaml) runs [browserless/chromium](https://github.com/browserless/browserless) and the app connects to it at `ws://chrome:3000/`. Outside Docker (for example with Herd), set `BROWSER_ENDPOINT=` and the Chrome installed on the machine is launched instead.

### Web search engines

`web_search` goes through the engine named by `WEB_SEARCH_ENGINE`:

- **`duckduckgo`** (default): renders DuckDuckGo's HTML results page in the headless browser. No account is needed, but it isn't an official API, may show CAPTCHAs under heavy use, and isn't meant for heavy automated use.
- **`searxng`**: a [SearXNG](https://docs.searxng.org/) metasearch instance you host. Set `SEARXNG_URL`, and allow the JSON format in its `settings.yml` (`search: formats: [html, json]`), or it answers with HTTP 403.
- **`brave`**: the [Brave Search API](https://brave.com/search/api/). Set `BRAVE_SEARCH_API_KEY`.
- **`tavily`**: the [Tavily](https://tavily.com) search API, built for AI agents. Set `TAVILY_API_KEY`.

Every engine returns the same Markdown links, so the Sources list works with all of them. When an engine is unreachable, is missing its key, or answers with an error, the assistant is told why instead of the reply failing.

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

### Routines

A routine is a prompt the assistant runs on a schedule: every day, on weekdays, weekly, every few hours, or a custom cron expression, in the routine's timezone (so 8:00 AM stays 8:00 AM across daylight saving changes). Manage them under **Routines** in the sidebar, where **Run now** runs one immediately and each routine lists its recent runs with links to their chats.

- **Tools**: a routine only gets the tools ticked on it, whether or not they're switched on for the chat.
- **Approvals**: `send_email` and `send_sms` still need approval, so the run pauses in its chat until you decide. Tick **Send emails and texts without asking me** on a routine to approve them automatically; the assistant can then message anyone, including someone it gets wrong.
- **Delivery**: every run is a new chat titled with the routine's name and time. The reply can also be emailed to your account's address and texted to a number on the routine (Twilio, shortened to fit). If a run is waiting for approval, you're told that instead.

Routines need the **scheduler** running alongside the queue worker. `composer run dev` starts `schedule:work`; in production, run `php artisan schedule:run` every minute from cron. The scheduler queues due routines with `routines:run-due`, and the queue worker runs them.

The text is read when you upload: PDFs with `smalot/pdfparser`, Word files from their XML, and everything else as plain text. Scanned PDFs (pictures of text) have no text to read and are rejected. Files are kept on the private `local` disk and deleted with their conversation.

The text goes into the assistant's instructions for that conversation, shared between documents up to `ASSISTANT_DOCUMENT_CHARACTERS`, and the model is told to treat it as information rather than instructions. Ollama silently drops anything beyond its context window, so the app raises it to `ASSISTANT_CONTEXT_TOKENS` (16k by default; larger uses more memory). Small models such as `llama3.2:1b` often fail to use documents or tools well, so a 7B+ model is recommended.

### Email and SMS

`send_email` uses the app's mailer, so set `MAIL_MAILER` and its settings in `.env` (with the default `log` mailer, emails are only written to `storage/logs/laravel.log`). `send_sms` needs `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN` and a `TWILIO_FROM` number, and numbers must be in international format (`+15025550123`). Twilio errors, including unverified numbers on trial accounts, are reported back to the assistant instead of being ignored.

### Audio studio

**Audio studio** in the sidebar makes four kinds of audio. Each runs on the queue and appears in the library when it's ready, which the page hears about over Reverb. Files are kept on the private `local` disk under `audio/`.

| Kind   | Engine                                       | Result                                                    |
| ------ | -------------------------------------------- | --------------------------------------------------------- |
| Speech | Phonon, through `ptts-openai-server`         | WAV                                                       |
| Music  | MusicGen, through `services/musicgen`        | WAV                                                       |
| MIDI   | Llama 3 (`AUDIO_MIDI_MODEL`) through Ollama  | A multi-track `.mid`, and each stem on its own            |
| Vocals | Llama 3 for lyrics, DiffSinger for the voice | WAV, the DiffSinger score (`.ds`), and the melody as MIDI |

WAV files play through the **Web Audio API**: a waveform you can click to seek, a level meter, volume and looping. MIDI stems play through the **Web MIDI API**. Press **Use MIDI devices**, allow access, and choose an output such as a hardware synth, a DAW or a virtual port (IAC on macOS, loopMIDI on Windows). Until you do, or in browsers without Web MIDI, a small built-in Web Audio synth plays them. Each stem has mute and solo, and you can change the tempo and loop.

`COMPOSE_PROFILES=audio` (in `.env.example`) starts the three engines with Sail. MusicGen and DiffSinger are built from `services/`, CPU-only by default; pass `--build-arg TORCH_INDEX=https://download.pytorch.org/whl/cu124` for an NVIDIA GPU.

| Variable                  | Default                      | Purpose                                                             |
| ------------------------- | ---------------------------- | ------------------------------------------------------------------- |
| `PHONON_URLS`             | `http://phonon:8880`         | Phonon servers, comma-separated; each serves one model              |
| `PTTS_LANG`, `PTTS_MODEL` | `en`, Phonon 31m English     | The `phonon` service's language and model                           |
| `MUSICGEN_URL`            | `http://musicgen:8000`       | The MusicGen service                                                |
| `MUSICGEN_MODEL`          | `facebook/musicgen-small`    | The model loaded at start and used by default                       |
| `MUSICGEN_MODELS`         | small, medium, large, stereo | Models the studio may load                                          |
| `MUSICGEN_MAX_SECONDS`    | `30`                         | Longest piece                                                       |
| `AUDIO_MIDI_MODEL`        | `llama3`                     | The Ollama model that writes MIDI and lyrics (`ollama pull llama3`) |
| `DIFFSINGER_URL`          | `http://diffsinger:8000`     | The DiffSinger service                                              |
| `DIFFSINGER_VOICE`        | (first installed)            | The singing voice chosen first                                      |
| `DB_QUEUE_RETRY_AFTER`    | `90` (`1300` in the example) | Must be longer than the longest job (MusicGen allows 21 minutes)    |

**Speech models.** A Phonon server loads one model (`PTTS_MODEL`). To offer more, run another server, as in the commented `phonon-fr` service in `compose.yaml`, and add its URL to `PHONON_URLS`. The studio lists each server by the model it has loaded, with its voices. The app asks Phonon for raw PCM and adds the WAV header itself, because Phonon's streamed WAV has no length in its header and browsers may refuse to decode it.

**MusicGen models** download from Hugging Face the first time they load, into the `sail-huggingface` volume. Choose one under **Engines** to load it now, or pick one when generating and it loads first. On a CPU, `musicgen-small` takes a few minutes for 30 seconds of music, and the larger models much longer.

**MIDI.** The model writes each stem's General MIDI instrument and notes as structured output (Ollama's JSON schema `format`). The app clamps them to MIDI's ranges and the length you asked for, puts drums on channel 10, and writes a Standard MIDI File.

**Vocals and DiffSinger.** DiffSinger has no pretrained voice built in. Add your own voices (acoustic models, optional variance models and a vocoder) to `storage/diffsinger`, as [its README](storage/diffsinger/README.md) describes. To sing:

1. The language model writes the lyrics and melody, one word (or, for Chinese and Japanese voices, one syllable) per note, with `-` to hold a word over several notes.
2. The app spells each word in the voice's phonemes using its dictionary, which the service reads from the voice's config. It then writes the `.ds` score: one segment per line, with `ph_seq`, `ph_num` (each word's group starts at its vowel, on the note), `note_seq`/`note_dur`/`note_slur`, and estimated `ph_dur` and `f0_seq` so a voice without a variance model can still sing it.
3. The service runs DiffSinger's `scripts/infer.py`: the variance model, if the voice has one, predicts natural timing and pitch, then the acoustic model sings.

Words the dictionary doesn't know are sung on an open vowel and listed under the result. The score is kept even when singing fails, so you can download it and open it in another DiffSinger editor.

## Security notes

- **Local MCP servers run commands on the host.** Anyone who can sign in can add one. If anyone but you can reach the app, set `ASSISTANT_MCP_STDIO=false`.
- **`web_fetch` refuses non-public addresses.** That covers `localhost`, private ranges, cloud metadata and Docker service names, so the model can't be steered into reading internal services. If a public page redirects somewhere private, the content is withheld. The browser still makes that redirected request.
- **Conversations, MCP servers, skills, tool settings and audio are per user.** Other users' records return 404.
- **The audio engines have no authentication.** Keep `phonon`, `musicgen` and `diffsinger` on the Docker network, as `compose.yaml` does, rather than publishing their ports. The studio only sends speech to the servers listed in `PHONON_URLS`.

## Architecture

Controllers are thin. They validate with form requests, authorize with policies, and hand everything else to a service.

```
app/
├── Ai/
│   ├── Agents/EstateAssistant.php   # The agent: instructions, tools, skills, conversation memory
│   ├── Agents/MidiComposer.php      # Writes MIDI stems as structured output
│   ├── Agents/LyricsComposer.php    # Writes lyrics and their melody as structured output
│   └── Tools/                       # Built-in tools (CurrentDateTime, WebSearch, WebFetch)
├── Events/AssistantReplyStreamed.php  # Broadcasts each streamed event to the user's channel
├── Jobs/StreamAssistantReply.php      # Runs one reply on the queue
├── Jobs/GenerateAudio.php             # Makes one speech, music, MIDI or vocals generation
├── Http/Controllers/Assistant/      # Chat, MCP servers, tools, skills
├── Http/Controllers/Audio/          # The audio studio, its files and MusicGen models
├── Models/                          # McpServer, Skill, DisabledTool
└── Services/
    ├── AssistantService.php         # Queues a reply; builds the agent and streams the reply
    ├── ConversationService.php      # Lists, shows and deletes a user's conversations
    ├── McpServerService.php         # MCP server CRUD and tool discovery
    ├── SkillService.php             # Skill CRUD; converts skills for the agent
    ├── ToolService.php              # Tool catalog, on/off switches, enabled tools for a user
    ├── WebFetchService.php          # Public-only page fetching
    ├── WebSearchService.php         # Formats search results for the model
    ├── AudioService.php             # Queues and makes audio; normalizes what the models write
    ├── Audio/                       # Phonon, MusicGen and DiffSinger clients; MIDI, WAV and .ds writers
    ├── Search/                      # SearchEngine contract + DuckDuckGo, SearXNG, Brave and Tavily
    ├── Browser/                     # Browser contract + headless Chrome implementation
    └── Mcp/                         # MCP connector contract + laravel/mcp client implementation
services/
├── musicgen/                        # FastAPI service that runs MusicGen
└── diffsinger/                      # FastAPI service that sings .ds scores with DiffSinger
resources/js/
├── pages/audio/Studio.vue           # The audio studio
├── components/audio/                # Web Audio WAV player, Web MIDI stem player, library cards
└── lib/audio/                       # Lookahead sequencer, Web MIDI and synth outputs
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

The audio engines are faked too. The two Python services have their own tests, which stand in for the models:

```bash
cd services/musicgen && pip install -r requirements-dev.txt && pytest
cd services/diffsinger && pip install -r requirements-dev.txt && pytest
```
