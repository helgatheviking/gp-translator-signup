# Translator Sign-up for GlotPress

Lets visitors sign up for a GlotPress site as **translators** or **reviewers**, with the languages they can translate. Translators can start straight away; reviewers wait for your approval. No form plugin needed.

## How it works

1. **Sign-up page.** Visitors sign up at `/glotpress/join` (under your GlotPress base path). They choose a username and email, whether they want to translate or review, and their languages, from those that have a translation set. There's also an optional "Other languages" field. Reviewers can add a note about their translation experience. A hidden honeypot field blocks spam bots. WordPress emails them a link to set their password.
2. **Project pages.** Logged-out visitors see an invitation to sign up on each project page and translation page. On a translation page, the language is pre-selected. Signed-in translators see "Apply to review" instead. The header shows a "Sign up to translate" link while registration is open.
3. **Translators** can suggest translations as soon as they log in, like any logged-in user. They don't need approval.
4. **Reviewers** are marked **Reviewer (pending)** until you approve them. You get an email with their languages, the project they signed up from and their note. Until you approve them, they can suggest translations like translators. Translators can apply to review later from `/join` without making a new account.
5. **Approved reviewers** can approve translations in their languages, on every project, including projects added later. They get an email saying so.
6. **Blocked** users can no longer suggest translations.

Translators can't change their own languages; only admins can, from the user's profile. WordPress's own registration page (`wp-login.php?action=register`) gets the same fields, so sign-ups there work the same way.

## Setup

1. Activate the plugin (GlotPress must be active).
2. In **Settings > General**, tick **Anyone can register** and set **New User Default Role** to Subscriber.

## Managing translators

* **Users** has a **Pending reviewers** view and a **Translator** column. The column shows each user's status and languages and, for pending reviewers, the project they signed up from and their note.
* Links under each translator change their status: **Approve as reviewer**, **Decline** (they stay a translator), **Remove reviewer**, **Make reviewer**, **Block** and **Unblock**.
* A user's profile has a **Translator** section for changing their status and languages.

Reviewers' rights come from this plugin, not from GlotPress's permission records, so they don't appear on GlotPress's **Permissions** screens. Manage them from **Users**.

## Development

```bash
composer install   # also prefixes git-updater-lite into vendor-prefixed/ with Strauss
npm install
composer lint
```

## Releasing

```bash
npm version patch   # or minor / major
```

This bumps the version in `package.json` and the plugin header, commits, tags, and pushes. The tag starts the GitHub Actions release workflow, which builds the zip and attaches it to a GitHub release. The release notes are the commit subjects since the previous tag, so write commit subjects as changelog lines.

Updates are delivered with [Git Updater Lite](https://github.com/afragen/git-updater-lite), from the update server in the plugin's `Update URI` header.
