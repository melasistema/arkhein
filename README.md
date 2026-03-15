# Arkhein - The Architect of the Shell

> **Status:** Alpha / Vertical Architecture Implemented

Arkhein is a private-first macOS agent designed to be your strategic partner. It lives entirely on your silicon, maintains an evolutionary digital memory, and commands your file system with precision—all while ensuring no data ever crosses the hardware boundary.

## 🗺️ System Map

### 🏛️ The Vantage Hub (Verticalized Intelligence)
- **Specialized AI Cards:** Deploy independent "Vantage" cards in the dedicated Hub for isolated document analysis.
- **Multi-Format RAG:** Arkhein ingests `.pdf`, `.md`, and `.txt` files using a pure PHP pipeline.
- **Surgical Retrieval:** Query specific folders (e.g., "@invoices-2005") with 100% topical isolation. No interference from unrelated project files.
- **Source Verification:** Every answer from a Vantage card cites the specific files used as context.

### 🧠 The Mind (Intelligence & Identity)
- **Local Sovereignty:** Powered by **Ollama**. Optimized for `qwen3:8b` (Assistant) and `qwen3-embedding:4b` (Memory).
- **Smart Onboarding:** Arkhein auto-detects recommended models and pre-configures itself for an instant start.
- **Sovereign Directives:** Centrally managed identity and operational boundaries in `config/arkhein.php`.

### 🧠 The Memory (Self-Healing Architecture)
- **Hybrid Storage:** SQLite serves as the **Single Source of Truth (SSOT)**, while **Vektor** provides a high-performance binary index.
- **Self-Healing Index:** Arkhein automatically detects mismatches or corruption and rebuilds its vector index from the SSOT.
- **Sovereign Persistence:** All document embeddings and metadata are stored locally in your `nativephp.sqlite` database.

### ✋ The Hand (Safe Operations)
- **Permission-First:** Arkhein only operates within **Managed Folders** authorized in the `nativephp` database.
- **Architectural Guardrails:** System actions are reserved for specialized vertical modules to ensure operational safety.

## ⚡ Key Workflows

### 📂 Isolated Document Analysis
Drop a scientific paper or a folder of company policies into an authorized directory. Sync a Vantage card to perform deep, grounded RAG analysis without your data ever leaving your hardware.

### 🐚 The System Help Guide
A high-density documentation module designed to explain the Arkhein architecture, configuration, and capabilities through a zero-friction linear chat.

### 🧪 Core Stability
A specialized **Pest** test suite verifies security boundaries and configuration integrity locally.

## 🚀 Quick Setup

1. **Environment:** macOS, [Ollama App](https://ollama.com/download), PHP 8.4+, Node 22+.
2. **Prepare Models:**
   - **The Easy Way:** Open the [Ollama Models Library](https://ollama.com/library), search for `qwen3` and `qwen3-embedding`, and follow the download instructions.
   - **The Pro Way:** Run these in your terminal:
     ```bash
     ollama pull qwen3:8b
     ollama pull qwen3-embedding:4b
     ```
3. **Install:** `composer install && npm install`
4. **Initialize:** `php artisan migrate --database=nativephp`
5. **Launch:** `npm run native:dev`
6. **Configure:** Go to **Settings** to authorize your first folder and verify your model "Safe-Lock" status.

---
*Arkhein: Command your Silicon. Own your Memory.*
