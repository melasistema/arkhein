# Arkhein - The Architect of the Shell

> **Status:** Pre-Alpha / Core Sovereign Architecture Complete

Arkhein is a private-first macOS agent designed to be your strategic partner. It lives entirely on your silicon, maintains an evolutionary digital memory, and commands your file system with precision—all while ensuring no data ever crosses the hardware boundary.

## 🗺️ System Map

### 🧠 The Mind (Intelligence & Persona)
- **Local Sovereignty:** Powered by **Ollama**, Arkhein performs all inference locally using state-of-the-art GGUF models.
- **The Architect Persona:** Laconic, precise, and strategic. Arkhein views the OS as a living structure to be optimized and indexed.
- **Deep Modules:** All intelligence is encapsulated in specialized services, with modular prompts stored in `config/prompts/`.

### 🧠 The Memory (Evolutionary Architecture)
- **Hybrid Storage:** SQLite serves as the **Single Source of Truth (SSOT)** for all text and embeddings, while **Vektor** (pure PHP HNSW) provides a high-performance index.
- **Reflection Pipeline:** Arkhein automatically extracts habits, facts, and patterns from your conversations.
- **Reconciliation Loop:** Your habits aren't static. Arkhein identifies conflicts (e.g., a meeting time change) and automatically reconciles its memory to stay aligned with your current life.

### ✋ The Hand (Safe Operations)
- **Permission-First:** Arkhein only operates within **Managed Folders** that you explicitly authorize.
- **Human-in-the-Loop:** Every system action (create, move, delete, organize) is presented as a **Pending Action**. Arkhein will never modify your system without your physical click of approval.

## ⚡ Key Workflows

### 📁 Unified Archive Management
Index your local project folders. Arkhein resolves `@mentions` to absolute paths and uses RAG to answer questions about your files with deep contextual awareness.

### 🔔 Proactive Awareness
Arkhein doesn't just wait for you. Every 60 seconds, its **Proactive Heartbeat** checks your learned habits against the clock. When it's time for your "Daily Report" or "Focus Mode," Arkhein dispatches a native macOS notification to partner with you.

### 🐚 The Intelligent Shell
A Copilot-like chat input supporting `/commands` for system tasks and `@mentions` for context, optimized for zero-lag performance in the Electron environment.

## 🚀 Quick Setup

1. **Environment:** macOS, [Ollama App](https://ollama.com/download), PHP 8.4+, Node 22+.
2. **Install:** `composer install && npm install`
3. **Initialize:** `php artisan migrate:fresh --database=nativephp`
4. **Configure:** Launch the app, go to **Settings** to choose your models and authorize your first folder.
5. **Launch:** `php artisan native:serve`

---
*Arkhein: Command your Silicon. Own your Memory.*
