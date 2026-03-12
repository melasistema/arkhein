# Arkhein - Private-First AI Assistant

> **Status:** Concept / Pre-Alpha

Arkhein is a NativePHP desktop application for macOS that prioritizes privacy and local intelligence. It combines the power of local LLMs via **Ollama** with a high-performance, pure PHP vector store (**Vektor**) to create a conversational assistant that lives entirely on your machine.

## 🌟 Vision

Our goal is a "Private-First" experience. No cloud, no API keys, no data leakage. Arkhein is designed to be OS X-centric, integrating seamlessly with your workflow using natural language.

### Key Features

*   **🔒 100% Private:** Your data and conversations never leave your computer.
*   **🧠 Long-Term Memory:** Powered by **Vektor** (a native PHP HNSW vector database), Arkhein remembers context and past interactions locally.
*   **⚡ Native macOS App:** Built with **NativePHP** (Laravel + Electron) for a fast, native desktop experience.
*   **🤖 Local Inference:** Integrates with **Ollama**. Bring your own open-source models (Llama 3.2, Mistral, Gemma, etc.).
*   **💬 Natural Language:** Interface with your system and data naturally.

## 🛠️ Tech Stack

*   **Framework:** [Laravel 12](https://laravel.com)
*   **Desktop Shell:** [NativePHP](https://nativephp.com)
*   **Vector Database:** [Vektor](https://centamiv.github.io/vektor/) (Pure PHP Binary Storage)
*   **AI Engine:** [Ollama](https://ollama.com)
*   **Frontend:** [Vue 3](https://vuejs.org) + [Inertia.js](https://inertiajs.com) + [Shadcn UI](https://www.shadcn-vue.com/)

## 🚀 Getting Started

### Prerequisites

1.  **macOS:** Arkhein is optimized for Apple Silicon and Intel Macs.
2.  **Ollama for macOS:** [Download and install](https://ollama.com/download) the native app.
3.  **PHP 8.2+** & **Node 22+**

### Installation

1.  Clone and install:
    ```bash
    git clone https://github.com/yourusername/arkhein.git
    cd arkhein
    composer install
    npm install
    ```

2.  Initialize environment:
    ```bash
    cp .env.example .env
    php artisan key:generate
    touch database/database.sqlite
    php artisan migrate
    ```

3.  Prepare AI Models:
    Make sure Ollama is running and pull the defaults:
    ```bash
    ollama pull llama3.2:1b
    ollama pull nomic-embed-text
    ```

4.  Verify Infrastructure:
    ```bash
    php artisan test:ai
    ```

### Running the App

```bash
# Launch the NativePHP dev environment
php artisan native:serve
```

## 🗺️ Roadmap

- [x] **Core Setup:** Pure-PHP, zero-docker architecture.
- [x] **Local AI:** Seamless Ollama integration.
- [x] **Binary Memory:** Persistent Vektor-based long-term memory.
- [x] **Reactive UI:** Modern Chat interface.
- [ ] **Phase 2: File Indexing:** Index local project files for code-aware assistance.
- [ ] **Phase 2: OS Integration:** Global hotkeys and system-level actions.
- [ ] **Packaging:** One-click DMG installer for distribution.

## 📄 License

This project is licensed under the MIT license.
