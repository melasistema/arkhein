# Arkhein - The Architect of the Shell

> **Status:** Pre-Alpha / Core Architecture Established

Arkhein is a sovereign, private-first macOS agent. It transforms your local computer into an active partner that understands your habits, commands your file system, and maintains a permanent, local digital memory—without a single byte ever leaving your machine.

## 🗺️ System Map: How Arkhein Works

Arkhein is built on a **Deep Module Philosophy**: exposing a simple conversational shell while encapsulating complex AI pipelines.

### 1. The Mind (Inference & Persona)
- **Local Intelligence:** Powered by **Ollama**. It uses models like `Llama 3.2` or `Mistral` for reasoning and `Qwen2` or `Nomic` for high-dimensional embeddings.
- **The Architect Persona:** Arkhein is laconic, precise, and subversive against cloud dependency. It speaks with the clarity of a Unix manual.

### 2. The Memory (Memory 2.0)
- **Hybrid Engine:** Uses **SQLite** as the Single Source of Truth (SSOT) and **Vektor** (pure PHP HNSW) as a high-performance index.
- **Reflection Pipeline:** After every interaction, Arkhein silently "reflects" on what it learned about you (habits, facts, preferences) and saves it to the unified knowledge base.
- **Contextual RAG:** Automatically retrieves relevant memories and authorized file snippets to ground every response in your local reality.

### 3. The Hand (File Operations)
- **Managed Folders:** A permission-first system. Arkhein can only see and touch directories you explicitly authorize via the native macOS picker.
- **Human-in-the-Loop:** Every system action (create, move, delete, organize) is presented as a **Pending Action**. Arkhein will never touch your local silicon without your physical click of approval.

## ⚡ User Experience: Significant Flows

### A. Commanding the Archive
**User:** *"Organize my @downloads folder by file type"*
1. Arkhein maps the `@downloads` mention to your authorized path.
2. It generates a plan to move files into `mds/`, `pdfs/`, `images/`.
3. It presents a **[Approve/Deny]** panel with the exact JSON move commands.
4. Upon approval, it executes the operations and reports back.

### B. Learning Habits (Proactive Memory)
**User:** *"I like to start my daily report in @work-folder every day at 6 PM."*
1. Arkhein responds laconically: *"Confirmed. Archive updated."*
2. The **Reflection Pipeline** extracts a `habit` insight: *"User creates daily reports at 18:00 in @work-folder."*
3. Next time you chat near 6 PM, Arkhein will be proactive: *"It is 18:00. Shall I prepare the daily report in @work-folder?"*

## 📖 Real-World Scenario: Setting up a Project

**1. Authorization**
The user goes to Settings and authorizes `~/Documents/Projects/Arkhein`.

**2. Contextual Interaction**
**User:** *"I need to create a simple PHP script that returns today's date in @Arkhein/scripts/"*

**3. Strategic Proposing**
**Arkhein:** *"The scripts directory does not yet exist. I will first establish the structure and then commit the code to the archive."*

**4. Human-in-the-Loop Confirmation**
Arkhein presents two pending actions:
- `create_folder`: `{"path": "@Arkhein/scripts"}`
- `create_file`: `{"path": "@Arkhein/scripts/now.php", "content": "<?php echo date('Y-m-d'); ?>"}`

**5. Execution & Reflection**
The user clicks **Approve**. Arkhein executes the actions.
*Internal Reflection:* "The user is working on PHP scripts within the Arkhein project."

## 🚀 Quick Setup

1. **Prerequisites:** macOS, [Ollama App](https://ollama.com/download), PHP 8.4+, Node 22+.
2. **Install:** `composer install && npm install`
3. **Init:** `php artisan migrate --database=nativephp`
4. **Pull Models:** `ollama pull llama3.2:1b` & `ollama pull nomic-embed-text`
5. **Launch:** `php artisan native:serve`

---
*Built with NativePHP, Laravel 12, and Vektor.*
