# Arkhein - The Architect of the Shell

> **Status:** Alpha / Vertical Vantage Architecture Implemented

Arkhein is a private-first macOS agent designed to be your strategic partner. It lives entirely on your silicon, maintains an evolutionary digital memory, and commands your file system with precision—all while ensuring no data ever crosses the hardware boundary.

## 🗺️ System Map

### 🏛️ The Vantage Hub (Verticalized Intelligence)
- **Specialized AI Cards:** Deploy independent "Vantage" cards on your dashboard for isolated document analysis.
- **Multi-Format RAG:** Arkhein ingests `.pdf`, `.md`, and `.txt` files using a pure PHP pipeline.
- **Surgical Retrieval:** Query specific folders (e.g., "@invoices-2005") with 100% topical isolation. No interference from unrelated project files.
- **Source Verification:** Every answer from a Vantage card cites the specific files used as context.

### 🧠 The Mind (Intelligence & Identity)
- **Local Sovereignty:** Powered by **Ollama**, Arkhein performs all inference and vectorization locally.
- **Sovereign Directives:** Centrally managed identity and operational boundaries in `config/arkhein.php`.
- **Fast-Path Intent:** Instant recognition of file system commands via regex bypass, reducing latency for common tasks.
- **Asynchronous Reflection:** Heavy insight extraction and memory reconciliation run in background jobs via SQLite-backed queues.

### 🧠 The Memory (Self-Healing Architecture)
- **Hybrid Storage:** SQLite serves as the **Single Source of Truth (SSOT)**, while **Vektor** provides a high-performance binary index.
- **Self-Healing Index:** Arkhein automatically detects mismatches or corruption and rebuilds its vector index from the SSOT.
- **Adaptive Reconciliation:** Automatically merges or updates personal facts and habits based on conversation context.

### ✋ The Hand (Safe Operations)
- **Permission-First:** Arkhein only operates within **Managed Folders** authorized in the `nativephp` database.
- **Action Protocol:** System changes (create, move, delete) are proposed as JSON blocks requiring physical user approval in the UI.

## ⚡ Key Workflows

### 📂 Isolated Document Analysis
Drop a scientific paper or a folder of company policies into an authorized directory. Sync a Vantage card to perform deep, grounded RAG analysis without your data ever leaving your hardware.

### 🐚 The Intelligent Shell
A high-density chat interface supporting `@mentions` for path resolution and localized context injection, optimized for macOS performance.

### 🧪 Core Stability
A specialized **Pest** test suite verifies security boundaries, @mention resolution, and configuration integrity locally.

## 🚀 Quick Setup

1. **Environment:** macOS, [Ollama App](https://ollama.com/download), PHP 8.2+, Node 22+.
2. **Install:** `composer install && npm install`
3. **Initialize:** `php artisan migrate --database=nativephp`
4. **Configure:** Launch the app, go to **Settings** to choose your models and authorize your first folder.
5. **Launch:** `npm run native:dev`

---
*Arkhein: Command your Silicon. Own your Memory.*
