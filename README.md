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
2.  **Ollama:** Must be installed and running locally.
    ```bash
    brew install ollama
    ollama serve
    ```
3.  **Redis Stack:** A running instance of Redis Stack is required for vector search capabilities.
    ```bash
    docker run -d --name redis-stack -p 6379:6379 -p 8001:8001 redis/redis-stack:latest
    ```
4.  **PHP 8.2+** & **Composer**
5.  **Node.js** & **NPM**

### Installation

1.  Clone the repository:
    ```bash
    git clone https://github.com/yourusername/arkhein.git
    cd arkhein
    ```

2.  Install PHP dependencies:
    ```bash
    composer install
    ```

3.  Install Node dependencies:
    ```bash
    npm install
    ```

4.  Configure Environment:
    ```bash
    cp .env.example .env
    php artisan key:generate
    ```
    *Update `.env` with your Redis Stack credentials if necessary.*

5.  Run the application (Development):
    ```bash
    php artisan native:serve
    ```

## 🗺️ Roadmap

- [ ] **Core Setup:** Initialize NativePHP and Predis integration.
- [ ] **AI Connection:** Implement Ollama client for local inference.
- [ ] **Memory Layer:** Implement Redis Stack vector storage for conversation history.
- [ ] **UI/UX:** Build a chat interface with Vue/Inertia.
- [ ] **System Integration:** Add OS-level hooks (hotkeys, file access).

## 📄 License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
