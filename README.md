# Arkhein: The Architect of the Shell

Status: Sovereign Architecture Active (Alpha)

Most "agents" are just thin wrappers for cloud-based surveillance. They rent your intelligence and sell your data. Arkhein is different. It is a local-first macOS agent that lives entirely on your silicon. No telemetry. No cloud gatekeepers. No data ever crosses the hardware boundary.

Deep complexity hidden behind a minimalist shell. Command your machine. Own your knowledge.

---

[![Arkhein Logo](resources/images/arkhein-image-header.png)](https://arkhein.melasistema.com/)

**Note:** This repository is for development and architectural audit. Operators looking for the production build can download the macOS `.dmg` from the official channels.

- **Website:** [arkhein.melasistema.com](https://arkhein.melasistema.com/)
- **Documentation:** [docs.arkhein.melasistema.com](https://docs.arkhein.melasistema.com/)

![Arkhein Settings](resources/images/arkhein-settings.png)

## The Architecture of Sovereignty

### The Vantage Hub (Verticalized Intelligence)
Arkhein does not dump your data into a single, leaking bucket. It organizes knowledge into Sovereign Silos. Every authorized folder is a unique physical partition.
- Silo Hierarchy: Knowledge is structured as Silo (Folder) -> Vessel (Document) -> Fragment (Chunk).
- Parent-Aware RAG: The agent understands the document summary and subfolder depth before it reads a single fragment.
- Isolated Retrieval: 100% topical isolation. Project A will never contaminate Project B.

### The Mind (Local Inference)
Powered by Ollama. Arkhein utilizes a Multi-Stage Sovereign Arbiter pipeline. It doesn't just guess; it thinks.
- Dispatcher: A JSON-based reasoning engine that classifies query intent before triggering retrieval.
- Polyglot Mirroring: Absolute alignment. The Archivist responds in the same language as the Operator.
- Compute Profiles:
  - Efficient: Optimized for 8GB-16GB RAM (Mistral + Nomic).
  - Elite: High-precision reasoning for 32GB+ RAM (Qwen3 Suite).

### The Memory (Atomic & State-Aware)
SQLite is the ultimate Single Source of Truth. Vektor is the disposable binary accelerator.
- Atomic Partition Scoping: Every operation is isolated via a re-entrant locking mechanism to prevent static race conditions between the UI and background workers.
- State Integrity Hashing: Uses a `binary_hash` system to detect drift between the SQLite SSOT and the Vektor binary, eliminating redundant O(N) rebuilds.
- Fail-Safe Stability: 5-second non-blocking locks and timeouts ensure the application remains responsive even during heavy indexing.

### The Hand (The Magic Touch)
Arkhein commands the filesystem with surgical precision but never without the Operator's intent.
- Strategic Commands: /create, /move, /organize, /delete.
- Guided Onboarding: "Lead-by-the-Hand" UX locks folder authorization and indexing until the necessary intelligence models are verified as installed.
- Human-in-the-Loop: Every action requires a verified Strategic Plan. You remain the final authority.

## Operation Protocol

1. Prerequisites: macOS (Silicon preferred), Ollama, PHP 8.4, Node 22.
2. Initialize Infrastructure:
   ```bash
   composer install && npm install
   php artisan native:migrate
   php artisan native:serve
   ```
3. Prepare the Efficient Profile (Standard):
   ```bash
   ollama pull mistral:latest
   ollama pull nomic-embed-text:latest
   ```
4. Configure: Launch Arkhein. The system will self-seed target defaults and guide you through the initial model verification. Authorized your silos and monitor the System Heartbeat.

## The Sovereign Archivist
A centralized module for global RAG and system intelligence. It is the librarian of your digital fortress. Use it to search across all authorized silos or to analyze the architecture itself.

---
Arkhein: Command your Silicon. Own your Memory.  
MIT License (c) 2026 Luca Visciola.
