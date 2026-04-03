# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Cognitive Fragment Architecture:** Re-architected ingestion to anchor knowledge fragments to their parent document's context, significantly improving SLM accuracy.
- **Silo Manifest Protocol:** Implemented structural awareness in Vantage Hub, providing assistants with a complete map of all documents and summaries in a silo.
- **Anchored SSOT:** Added permanent `vector_anchor` persistence in the `knowledge` table, ensuring the SQLite database stores both content and the cognitive intent used for indexing.
- **Semantic Slicing:** Introduced `MarkdownSplitter` and `StandardSplitter` to respect document boundaries (# Headers, newlines) instead of character counts.
- **Sovereign Media Core:** Implemented a new extensible `MediaProcessorInterface` and `MediaResult` value object for robust multimodal ingestion.
- **Visual Intelligence:** Introduced `VisualProcessor` leveraging `OllamaService::generateWithImages` for image-to-text conversion using `qwen3-vl`.
- **Presence vs. Essence Ingestion:** Added `PresenceProcessor` for fast, searchable metadata-only ingestion of unknown or unauthorized file formats.
- **Controlled Vision:** Per-folder authorization for visual indexing with dedicated UI toggles in both Settings and Vantage Hub.
- **Media Promotion Logic:** Automatic "upgrade" of presence-only records to full visual analysis when sight is authorized for a silo.
- **High-Precision Intent Detection:** Refined the Help Dispatcher with a `PRECISION` intent to identify quantitative queries (totals, lists) and guide users toward deep Vantage analysis.
- **Reactive System Heartbeat:** Enhanced the global status indicator with red "System Busy" pulsation for high-load states and a detailed interactive dropdown.
- **Earthbeat Operations Monitor:** Implementation of a real-time task monitor in the heartbeat dropdown showing active/queued indexing tasks and memory sync progress.
- **Sovereign Task Protocol:** Transitioned folder synchronization to a robust `sync_status` state machine (IDLE, QUEUED, INDEXING).
- **MIME Type Tracking:** Added `mime_type` columns to `documents` and `knowledge` tables for improved media data tracking.
- **Vision Model Support:** Integrated `qwen3-vl` vision model across default settings, seeder, and UI.
- **Strategic Scope UI:** Added a 'Global Search' tip to the Archivist interface to manage search depth expectations.

### Changed
- **Operational Protocols Refactoring:** Renamed `boundaries` to `protocols` in configuration with balanced limits (`silo_recursion_limit: 20`, `inference_timeout: 600s`).
- **Archive Service Refactor:** Reworked `ArchiveService` to route content processing by MIME type and implemented throttled progress updates to reduce SQLite lock contention.
- **Ingestion Robustness:** Increased `IndexFolderJob` timeout to 1 hour and refined ignore logic to only skip junk folders at the silo root.
- **Lead-by-the-Hand UX:** Enhanced onboarding with strict multi-model verification (LLM, Vision, Embedding) before unlocking system actions.
- **Vision Authorization UX:** Implemented context-aware confirmation dialogs for visual analysis to manage compute cost expectations.

### Fixed
- **Structural Blindness:** Resolved an issue where Vantage assistants missed files that weren't direct vector matches by implementing the Silo Manifest.
- **Subfolder Indexing:** Resolved an issue where deeply nested files were being over-zealously ignored by the ingestion engine.
- **Processor Hijacking:** Restricted `PresenceProcessor` to ensure text and PDF files are correctly routed to deep-indexing processors.
- **Settings Initialization (Vision):** Addressed issues with vision model selections not being correctly initialized in the UI after `migrate:fresh`.
- **Vision Toggle Safety:** Fixed an issue where vision settings could be toggled while a folder was actively indexing, potentially causing state corruption.

## [0.0.5] - 2026-04-01

### Added
- **Atomic Partition Scoping:** Implemented a re-entrant locking mechanism in `MemoryService` to isolate Vektor partitions and prevent static race conditions.
- **State Integrity Hashing:** Added a `binary_hash` system (md5 of count/dimensions/update_time) to detect drift between SQLite SSOT and Vektor binary indices, eliminating redundant O(N) rebuilds.
- **Lead-by-the-Hand Onboarding:** Strict UI locking for folder authorization and indexing until recommended models are verified as installed.
- **Smart Model Matching:** Auto-detection of `mistral` vs `mistral:latest` and sane config-based fallbacks during first boot and settings initialization.
- **Non-Blocking Boot Checks:** Memory integrity verification is now dispatched `afterResponse()` to prevent UI hangs on startup.
- **Fail-Safe Locking:** Added 5-second non-blocking locks with timeouts to `MemoryService` to ensure application responsiveness under high load.

### Changed
- **Model Standardization:** Standardized all backend, frontend, and documentation prerequisites on the `:latest` tag (e.g., `mistral:latest`, `nomic-embed-text:latest`).
- **Ollama Model Guide:** Refined the settings guide to a vertical "smartphone-style" layout for better readability of long pull commands.
- **Settings Controller Refactoring:** Removed redundant detection logic in favor of a centralized "Smart Matching" service provider.

### Fixed
- **Memory Deadlocks:** Resolved self-deadlock issues in `MemoryService` where scoped methods attempted to re-acquire their own locks.
- **Maximum Execution Time Errors:** Fixed PHP timeouts during long-running binary index checks via non-blocking lock management.
- **Settings Initialization:** Fixed an issue where `migrate:fresh` left the UI with empty model selections despite models being present in Ollama.

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
