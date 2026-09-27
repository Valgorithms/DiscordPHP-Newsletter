# DiscordPHP-Newsletter agent guide

- CLI-only, long-running ReactPHP process. All I/O is Promise-based; blocking helpers belong in tests only.
- Every network edge (Ollama, GitHub, Steam) goes through an injectable transport (`OllamaClient`, `JsonClient`).
  Tests fake them and settle promises synchronously (`Tests\TestCase::settle()`, `fakeLlm()`, `fakeHttp()`).
- Sources return `SourceReport`s of plain fact lines. They never throw for partial failures: they put the reason in `errors`.
- The LLM only writes from notes and facts. Keep the notes → compose → fact-check → revise chain sequential (one GPU).
- Never post without an explicit owner approval. An approval the model merely inferred must be confirmed.
- Hosted LLMs are out of scope by design: the owner wants everything written by their local Ollama model.
- Careful with arrow functions (`fn`): they capture by value, so use a plain closure with `use (&$x)` for shared state.
- Before pushing: `composer unit`, `vendor/bin/php-cs-fixer fix --dry-run --diff`.
