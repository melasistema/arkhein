# Arkhein - Private-First AI Assistant

> **Status:** Concept / Pre-Alpha

Arkhein is a NativePHP desktop application designed for macOS, focusing on privacy, local intelligence, and deep system integration. It leverages the power of local LLMs via Ollama and persistent vector storage with Redis Stack to provide a smart, conversational assistant that evolves with you.

## 🌟 Vision

Our goal is to build a "Private-First" assistant that operates entirely on your machine. No data leaves your computer for inference or storage. Arkhein is designed to be OS X-centric, integrating seamlessly with your workflow using natural language.

### Key Features

*   **🔒 Private by Design:** All data processing and inference happen locally. Your conversations and data never touch the cloud.
*   **🧠 Long-Term Memory:** Powered by **Redis Stack** (RediSearch + RedisJSON), Arkhein remembers context, preferences, and past interactions through vector embeddings.
*   **⚡ Native Performance:** Built with **NativePHP** (Laravel + Electron), offering a snappy, native macOS experience.
*   **🤖 Bring Your Own Model:** Integrates with **Ollama** to let you choose the best open-source models (Llama 3, Mistral, Gemma, etc.) for your needs.
*   **💬 Natural Language Interface:** Interact with your system and data naturally, just like talking to a human.

## 🛠️ Tech Stack

*   **Application Framework:** [Laravel 12](https://laravel.com)
*   **Desktop Wrapper:** [NativePHP](https://nativephp.com)
*   **Frontend:** [Vue 3](https://vuejs.org) + [Inertia.js](https://inertiajs.com)
*   **Data & Vector Store:** [Redis Stack](https://redis.io/docs/stack/) (via Predis)
*   **AI Inference:** [Ollama](https://ollama.com)

## 🚀 Getting Started

### Prerequisites

1.  **macOS:** Arkhein is optimized for macOS.
2.  **Docker:** Required for running the services via Laravel Sail.

### Installation & Setup

1.  Clone and install dependencies:
    ```bash
    git clone https://github.com/yourusername/arkhein.git
    cd arkhein
    composer install
    npm install
    ```

2.  Start the environment (Sail):
    ```bash
    ./vendor/bin/sail up -d
    ```

3.  Verify AI Connectivity:
    Pull the required models and run the test command:
    ```bash
    docker exec arkhein-ollama-1 ollama pull llama3.2:1b
    docker exec arkhein-ollama-1 ollama pull nomic-embed-text
    ./vendor/bin/sail artisan test:ai
    ```

4.  Run the application:
    ```bash
    ./vendor/bin/sail artisan native:serve
    ```

## 🗺️ Roadmap

- [x] **Core Setup:** Initialize NativePHP and Predis integration.
- [x] **AI Connection:** Implement Ollama client for local inference.
- [x] **Memory Layer:** Implement Redis Stack vector storage for conversation history.
- [x] **UI/UX:** Build a chat interface with Vue/Inertia.
- [ ] **System Integration:** Add OS-level hooks (hotkeys, file access).
- [ ] **File Indexing:** Allow Arkhein to read and index your local files for better context.
- [ ] **NativePHP Packaging:** Build and bundle the app for distribution.

## 📄 License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
