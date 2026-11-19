# LIVORA - GUIDELINES FOR AI CLI AGENTS (`AGENTS.md`)

> **IMPORTANT:** Read the following system constraints before generating any code or executing any task in this workspace.

---

## ⛔ CRITICAL SCOPE CONSTRAINTS (GOLDEN RULES)

1. **BANK SAMPAH OFFLINE ONLY (NO PICKUP):**
   - **DO NOT** create APIs, models, controllers, migrations, or UI for pickup services (`jemput_sampah`).
   - All waste deposits are done **offline (direct drop-off)** by the user at the Livora collection point.

2. **NO GPS / MAPS / THIRD-PARTY PARTNER LOCATIONS:**
   - **DO NOT** integrate Google Maps or driver tracking APIs. Livora operates a centralized offline location managed by Admin.

3. **ADMIN VS USER PANEL SEPARATION:**
   - **User App / Web:** For normal users (waste submit, wallet, quizzes, missions, redeem rewards).
   - **Admin CMS (`/admin`):** Separate portal for Admin role to verify waste deposits, approve rewards, manage categories, and publish articles.

4. **REWARD SIMULATION FOR DEMO:**
   - Digital e-wallet payouts (GoPay/DANA) and vouchers are **simulated via Admin approval workflow**. Real money deposit/transfer is NOT required for demo/presentation purposes.

5. **AUTHORITATIVE DOCUMENTATION:**
   Before creating or editing code, consult the following markdown files:
   - [`project.md`](file:///c:/Users/mfadh/Livora-Kelompok8/project.md) — System Overview & Core Rules
   - [`flow.md`](file:///c:/Users/mfadh/Livora-Kelompok8/flow.md) — Business Workflows & Sequence Diagrams
   - [`database.md`](file:///c:/Users/mfadh/Livora-Kelompok8/database.md) — Database Schema & Field Definitions
   - [`reward.md`](file:///c:/Users/mfadh/Livora-Kelompok8/reward.md) — Reward Mechanisms & E-Wallet Simulation
   - [`sprint.md`](file:///c:/Users/mfadh/Livora-Kelompok8/sprint.md) — Sprint Plan & Tasks Roadmap

---

## 🛠️ LARAVEL BOOST GUIDELINES

=== foundation rules ===

This application is a Laravel application running on PHP 8.3. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

### Conventions
- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

### Verification Scripts
- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

### Application Structure & Architecture
- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

### Frontend Bundling
- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

### Pint Code Formatter
- If you have modified any PHP files, run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
