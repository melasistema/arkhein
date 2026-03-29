# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.0.4] - 2026-03-28

### Added
- **Hierarchical Knowledge Architecture:** Implemented Silo (Folder) -> Vessel (Document) -> Fragment (Chunk) hierarchy for deep contextual RAG.
- **Vessel Summarization:** Automatic high-level document summarization during indexing to provide assistants with parent context.
- **Sovereign Arbiter (Help Module):** Multi-stage reasoning pipeline (Dispatcher-Researcher-Synthesizer) for accurate intent routing.
- **System Heartbeat:** Persistent global status indicator in the header for real-time background process monitoring.
- **Compute Profiles:** "Efficient" (Mistral/768d) and "Elite" (Qwen3/1056d) presets with hardware-aware guidance.
- **Hot-Swap Reconciliation:** Unbreakable background re-indexing using shadow partitions and atomic swaps.
- **SSE Streaming:** Real-time, line-by-line response rendering across all intelligence modules.
- **Workspace Map:** Injected authorized silo metadata into the Archivist for better environmental awareness.
- **Silo Purging:** Automatic cleanup of binary Vektor indices upon folder de-authorization.

### Changed
- Renamed "System Help" to **Sovereign Archivist** to align with system persona.
- Renamed "Vantage" to **Vantage Hub** for architectural consistency.
- Set **Dark Mode** as the absolute system default for the "Sovereign" aesthetic.
- Promoted Archivist to primary sidebar navigation.
- Optimized RAG recall thresholds and increased result limits for better non-English retrieval.
- Updated README to reflect raw sovereign philosophy and correct NativePHP commands.

### Fixed
- **CSRF Mismatch:** Resolved 419 errors during long-running SSE fetch requests.
- **Duplicate ID Errors:** Fixed binary index collisions during large file indexing via bulk mode logic.
- **Path Resolution:** Fixed stale storage path issues in background workers using absolute NativePHP path resolution.

### Known Problems (To Be Fixed)
- **Deep Creation Content Failure:** The `/create` magic command may fail to populate documents with the expected RAG-driven content when using the "Efficient" profile (Mistral). This is likely due to the small LLM's inability to handle complex multi-stage placeholder filling. Requires investigation into more robust extraction prompts.

[0.0.4]: https://github.com/melasistema/arkhein/compare/v0.0.3...v0.0.4
