# Hendy Dịch Vụ Mạng — Neon Edition

This repository is initialized for the Hendy Dịch Vụ Mạng PHP application.

> **Source import status:** The full application source has not yet been pushed to this repository. The current commit contains the project bootstrap README, secret-safe .gitignore, and a Windows helper script. Do not deploy this repository until the application files (for example index.php, includes/, database.sql, and Dockerfile) are present.

## Publish the source ZIP from Windows

1. Download the sanitized source archive Hendy-Dich-Vu-Mang-Source.zip from the ChatGPT conversation.
2. Clone this repository:
   ```powershell
   git clone https://github.com/ngogiaidy56-eng/Hendy-Dich-Vu-Mang.git
   ```
3. Run the included helper, passing the path to the downloaded ZIP:
   ```powershell
   .\Hendy-Dich-Vu-Mang\push-source.ps1 -ZipPath "$env:USERPROFILE\Downloads\Hendy-Dich-Vu-Mang-Source.zip"
   ```
   Run it from a PowerShell session where Git is installed and authenticated with GitHub.

The helper extracts the ZIP, copies the complete project files into the repository checkout, creates a commit, and pushes to main.

## Neon configuration notes

The source package targets PHP 8.3 + PDO PostgreSQL and has configuration for Neon PostgreSQL, Neon Object Storage, Neon Functions, and Neon Realtime. The sanitized archive excludes the local .neon file and does not include runtime .env secrets.

After the source files are pushed, review the source package README for Neon provisioning, schema import, deployment and end-to-end test instructions.

## Repository security

The committed .gitignore excludes .env, .env.local, .neon, dependency directories, and uploaded screenshots. Never commit production connection strings, API keys, or other credentials.
