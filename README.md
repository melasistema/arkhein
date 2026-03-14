# Arkhein - The Architect of the Shell

> **Status:** Pre-Alpha / Strategic Architecture Pivot Complete

Arkhein is a sovereign, private-first macOS agent. It transforms your local computer into an active partner that understands your habits, commands your file system, and maintains a permanent, local digital memory—without a single byte ever leaving your machine.

## 🗺️ System Map: Core Architecture

Arkhein adheres to a **Deep Module Philosophy**: exposing a simple conversational shell while encapsulating heavy AI logic behind modular services.

### 1. The Mind (Modular Intelligence)
- **Local Inference:** Powered by **Ollama**. Support for any GGUF model (Llama 3.2, Mistral, Qwen).
- **Prompt Engineering:** Centralized in `config/prompts/`. Prompts are modular, allowing for hot-swapping personas and toggling "Vertical" capabilities.
- **Agentic Logic:** Separates **Generalist Reasoning** (dialogue) from **Vertical Actions** (system operations).

### 2. The Memory (Knowledge Base 2.0)
- **Thematic Sessions:** Conversations are organized into manually started sessions (e.g., "Project X Setup"), ensuring contextual isolation.
- **Reflection Pipeline:** After every interaction, Arkhein silently "reflects" on the session to extract high-density **User Insights** (habits, facts, preferences).
- **Unified Storage:** A polymorphic SQLite table acts as the **Source of Truth** for all chat history, file snippets, and insights.
- **Vektor Index:** A self-healing, pure-PHP HNSW index providing high-performance RAG (Retrieval-Augmented Generation).

### 3. The Hand (Sovereign Operations)
- **Managed Folders:** Permission-first access. Arkhein only operates within directories explicitly authorized via the native macOS picker.
- **Human-in-the-Loop:** All system actions (create, move, delete, organize) are presented as **Pending Actions**. No modification to the local silicon happens without your approved click.

## ⚡ User Experience: Key Flows

### A. Session-Based Workflows
Users manually initialize themed sessions. This provides a clean slate for the AI and allows the **Reflection Pipeline** to categorize insights specifically to that project or habit.

### B. Command & Mention System
The intelligent chat input supports:
- `/commands`: Instant system tasks (e.g., `/help`, `/sync`).
- `@mentions`: Quickly reference authorized folders and files for contextual groundedness.

### C. Proactive Partnering
Through the continuous extraction of `UserInsights`, Arkhein becomes proactive. It doesn't just wait for commands; it suggests actions based on your learned patterns (e.g., "It is 17:30. Shall I prepare the daily report in @work-folder?").

## 🚀 Quick Setup

1. **Prerequisites:** macOS, [Ollama App](https://ollama.com/download), PHP 8.4+, Node 22+.
2. **Install:** `composer install && npm install`
3. **Init:** `php artisan migrate:fresh --database=nativephp`
4. **Setup:** Go to **Settings** to choose your models and authorize your first folder.
5. **Launch:** `php artisan native:serve`

---
*Built with NativePHP, Laravel 12, and Vektor.*
