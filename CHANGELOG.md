# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.1] - 2026-04-08

### Added
- **Hierarchical Auto-Recall:** Implemented a vector-optimized two-pass retrieval strategy (Discovery + Selective Recall) for the Global RAG.
- **Global Laboratory Protocol:** Enabled full multi-step Chain of Thought (CoT) for "The Archivist" using a durable `global` physical workspace for cross-silo reasoning.
- **Earthbeat Progress (7-Level):** Implemented granular progress tracking (10% to 95%) and status messages across the entire 7-level cognitive pipeline.

### Changed
- **Threshold-Based Indexing:** Optimized `ArchiveService` to prefer live insertions for small updates, only triggering full Vektor rebuilds after significant change thresholds.
- **Sovereign Pipeline Alignment:** Refactored all cognitive steps (Decomposition, Reasoning, Synthesis) to strictly follow the 7-level architecture and nomenclature.

### Fixed
- **Inverted Sync Logic:** Resolved a critical bug where incremental updates were skipping live vector indexing, making them unsearchable without a full rebuild.
- **Redundant Global Re-indexing:** Optimized `IndexFolderJob` and `HealSiloJob` to prevent unnecessary O(N) full-database vector rebuilds for small updates.
- **Archivist Structural Blindness:** Fixed `InventoryTool` and `CognitiveArbiter` to ensure global queries return 100% accurate file counts via Canopy identification.
- **Integrity Leakage:** Implemented cascading deletes for documents and knowledge fragments to prevent orphaned vector search results.
- **Self-Healing Completeness:** Updated `HealSiloJob` to force Vektor rebuilds when ghost documents are purged, ensuring index-to-disk consistency.


## [0.1.0] - 2026-04-07

### Added
- **Sovereign Tree (Hierarchical RAG):** Introduced Level 3 "Canopy" summaries for managed folders, enabling top-down discovery and broad semantic awareness across silos.
- **Canopy Discovery Layer:** Added `discover` capabilities to `RagService` and `GlobalRagService` to identify relevant silos before fragment retrieval.
- **LLM Response Caching:** Implemented a deterministic caching layer in `OllamaService` for completions and embeddings to significantly reduce latency and compute cost.
- **Shadow Rebuild Protocol:** Implemented zero-downtime index rebuilds using shadow partitions with atomic directory swaps.
- **CoT Lifecycle Management:** Added automated cleanup and size enforcement for physical scratchpads to prevent disk bloat.
- **Liquid Cognitive Pipeline:** Replaced the rigid reasoning stack with a dynamic Laravel Pipeline architecture, allowing simple queries to bypass heavy reasoning steps for maximum efficiency.
- **Semantic Perception Layer:** Introduced `DocumentPerceptionService` to autonomously classify documents (e.g., INVOICE, CONTRACT) and extract structured metadata during ingestion.
- **Stateful Reasoning:** Enabled iterative thinking by allowing the reasoning pipeline to read and evolve existing physical scratchpads in the laboratory.
- **Command Bus Architecture:** Decentralized Magic Command orchestration via a declarative `CommandRegistry` and autonomous command classes (`Create`, `Organize`, `Sync`, etc.).
- **Global Vision Control:** Added a global kill-switch for Vision Intelligence with prominent "Resource Killer" warnings to manage compute expectations.
- **Modular Frontend Architecture:** Deconstructed monolithic Vue components (`VantageCard`, `Settings`) into specialized, reusable UI modules (`ChatInterface`, `SiloStatusPanel`, `ManagedFolders`, etc.).
- **Data-Aware RAG Anchoring:** Enhanced `vector_anchor` logic to incorporate semantic document nature and extracted metadata, enabling precise retrieval even on small SLMs.
- **Autonomous Silo Integrity:** Introduced `SiloIntegrityService` for lightweight drift detection via disk signatures, triggering automatic background self-healing when manual changes are detected.
- **Self-Healing Protocol:** Implemented `HealSiloJob` to automatically purge ghost files and ingest new arrivals without operator intervention.
- **Sovereign Coordinator:** Introduced `InventoryTool` for 100% accurate, database-backed structural queries (counts, lists), bypassing probabilistic SLM hallucinations.
- **Sovereign Cognitive Stack:** Implemented a formal reasoning pipeline to significantly enhance SLM accuracy and logic.
- **System Task Registry:** Introduced a persistent `system_tasks` table and model to orchestrate and track complex, multi-item background operations with full transparency.
- **Level 0 Grounding:** Introduced `EnvironmentScanner` to generate persistent Silo Semantic Maps, detecting naming patterns and folder purpose for high-fidelity environmental awareness.
- **Physical Workspace Protocol:** Implemented on-disk reasoning scratchpads in internal application storage, providing a durable "Laboratory" for complex SLM calculations.
- **High-Resolution Architect:** Implemented a multi-stage Agentic Assembly loop for the `/create` command, resolving truncation issues via per-file fact harvesting.
- **Earthbeat Monitor v2:** Upgraded the system heartbeat into a real-time task monitor showing the entire background pipeline, including Queued, Running, and Drafting states.
- **Cognitive Complexity Detection:** Refined Level 1 Perception to dynamically trigger physical workspaces for high-reasoning tasks.
- **Selective Contextualization:** Optimized context injection to eliminate 'RAG Noise' by surgically providing either Manifest data or Deep fragments based on intent.
- **Silo Guard Protocol:** Implemented mathematically absolute path traversal protection in all filesystem tools via strict boundary checking and normalization.
- **Document Architect Protocol:** Introduced a highly structured generation protocol for the `/create` command, significantly improving file drafting quality on SLMs (Mistral/Qwen).
- **Contextual Arbiter:** Refined intent detection to be context-aware, preventing conversational affirmative tokens (e.g., "OK") from hijacking the file execution loop.
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
- **MIME Type Tracking:** Added `mime_type` columns to `documents` and `knowledge` tables for improved media data tracking.
- **Vision Model Support:** Integrated `qwen3-vl` vision model across default settings, seeder, and UI.
- **Strategic Scope UI:** Added a 'Global Search' tip to the Archivist interface to manage search depth expectations.

### Changed
- **Non-Blocking Ingestion:** Refactored `MemoryService` locking to allow concurrent read access to primary partitions while shadow indices are being built.
- **Adaptive Context Retrieval:** Refined `ContextRetrievalStep` to dynamically adjust fragment limits (up to 50) based on query complexity and quantitative intent.
- **Granular Reasoning Progress:** Enhanced the reasoning pipeline with phase-specific progress tracking and internal error recovery.
- **Orchestration Decoupling:** Relieved `VerticalService` of its massive switch statement, delegating intent logic to the new Command Bus.
- **Extraction Purity:** Refactored `ActionExtractor` to be a pure tool-mapping worker, moving domain-specific Librarian logic to autonomous commands.
- **Workspace Lifecycle Management:** Integrated physical workspace management into `MemoryService`, ensuring automatic cleanup during silo de-authorization or global resets.
- **Strict Tool Isolation:** Refactored `ActionExtractor` to explicitly forbid dangerous 'Move/Delete' hallucinations during file creation and blocked wildcard usage in tools.
- **Ollama Context Expansion:** Increased default `num_ctx` to 16,384 across all intelligence modules for better long-context reasoning.
- **Operational Protocols Refactoring:** Renamed `boundaries` to `protocols` in configuration with balanced limits (`silo_recursion_limit: 20`, `inference_timeout: 600s`).
- **Archive Service Refactor:** Reworked `ArchiveService` to route content processing by MIME type and implemented throttled progress updates to reduce SQLite lock contention.
- **Ingestion Robustness:** Increased `IndexFolderJob` timeout to 1 hour and refined ignore logic to only skip junk folders at the silo root.
- **Lead-by-Hand UX:** Enhanced onboarding with strict multi-model verification (LLM, Vision, Embedding) before unlocking system actions.
- **Vision Authorization UX:** Implemented context-aware confirmation dialogs for visual analysis to manage compute cost expectations.

### Fixed
- **Dimensionality Drift:** Resolved critical "1536 vs 768" vector mismatch errors by enforcing strict dimensionality synchronization in the Vektor singleton.
- **Architect Hallucination:** Eliminated generic data generation in the `/create` command by implementing strict non-hallucination mandates and verbose harvesting status.
- **Perception Type Mismatch:** Resolved `TypeError` in the cognitive pipeline where LLMs occasionally returned intent or strategy as arrays instead of strings.
- **Indexing Timeout:** Fixed `Lock timeout` errors during ingestion by isolating shadow operations from the primary partition lock.
- **Cognitive Pipeline Resolution:** Fixed missing dependency injection and syntax errors in `CognitiveArbiter`.
- **Silo-less Operation Stability:** Resolved 500 errors and null-pointer exceptions when using Arkhein in "Local" mode (silo-less).
- **Inference Failure Consistency:** Synchronized engine failure messaging across the UI, service layer, and test suite for better UX.
- **Deep Creation Failure:** Resolved an issue where `/create` failed to populate files with RAG-driven content under the 'Efficient' profile.
- **Orchestrator Memory Leak:** Fixed a behavior where the assistant lost track of conversations after affirmative user input.
- **Shadow Rebuild Crash:** Resolved an "undefined method" error in `MemoryService` that broke the force reconciliation process.
- **Structural Blindness:** Resolved an issue where Vantage assistants missed files that weren't direct vector matches by implementing the Silo Manifest.
- **Subfolder Indexing:** Resolved an issue where deeply nested files were being over-zealously ignored by the ingestion engine.
- **Processor Hijacking:** Restricted `PresenceProcessor` to ensure text and PDF files are correctly routed to deep-indexing processors.
- **Settings Initialization (Vision):** Addressed issues with vision model selections not being correctly initialized in the UI after `migrate:fresh`.
- **Vision Toggle Safety:** Fixed an issue where vision settings could be toggled while a folder was actively indexing, potentially causing state corruption.
- **Model Selection UI:** Implemented `findBestMatch` in the frontend to ensure compute profile selection correctly identifies installed model variants.

## [0.0.5] - 2026-04-01

### Added
- **Atomic Partition Scoping:** Implemented a re-entrant locking mechanism in `MemoryService` to isolate Vektor partitions and prevent static race conditions.
- **State Integrity Hashing:** Added a `binary_hash` system (md5 of count/dimensions/update_time) to detect drift between SQLite SSOT and Vektor binary indices, eliminating redundant O(N) rebuilds.
- **Lead-by-Hand Onboarding:** Strict UI locking for folder authorization and indexing until recommended models are verified as installed.
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

[0.0.5]: https://github.com/melasistema/arkhein/compare/v0.0.4...v0.0.5
[0.0.4]: https://github.com/melasistema/arkhein/compare/v0.0.3...v0.0.4
